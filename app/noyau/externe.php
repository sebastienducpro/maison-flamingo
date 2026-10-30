<?php
// Maison Flamingo · la copie de chaque sauvegarde hors de l'hébergeur.
//
// Une copie qui ne vit que chez l'hébergeur disparaît avec lui. Chaque nuit, la dernière sauvegarde part donc vers un
// stockage objet compatible S3 (Backblaze B2, Cloudflare R2, OVH…), compressée puis chiffrée ici, avant de partir :
// le fournisseur ne lit rien de ce qu'il garde.
//
// Trois règles :
// - la clé d'accès donnée au stockage n'a que le droit d'écrire, ni de lire ni d'effacer : un intrus sur le serveur
//   ne pourrait pas détruire les copies externes. L'effacement des anciennes copies se règle chez le fournisseur ;
// - la clé de chiffrement est montrée une seule fois, à sa création, pour être gardée hors du serveur : si le serveur
//   disparaît, c'est elle qui rouvre les copies ;
// - un secret ne se relit jamais : l'écran dit seulement s'il est posé.

declare(strict_types=1);

const EXTERNE_FICHIER = 'stockage-externe.json';
const EXTERNE_BLOC = 8388608;           // 8 Mo : la mémoire reste bornée, même pour une base de plusieurs Go
const EXTERNE_ENTETE = "FLMGSV1\n";

function externe_config(): ?array
{
    $f = dossier_donnees() . '/' . EXTERNE_FICHIER;
    if (!is_file($f)) {
        return null;
    }
    $c = json_decode((string) file_get_contents($f), true);
    foreach (['point', 'region', 'seau', 'cle_id', 'cle_secrete', 'chiffrement'] as $k) {
        if (!is_array($c) || !is_string($c[$k] ?? null) || $c[$k] === '') {
            return null;   // une configuration incomplète vaut une configuration absente : rien ne part
        }
    }
    return $c;
}

// Compresse puis chiffre par blocs (AES-256-GCM, un nonce par bloc) : chaque bloc est authentifié, un octet changé se voit.
// Format : en-tête, puis pour chaque bloc [longueur 4 octets][nonce 12][étiquette 16][données chiffrées].
function externe_chiffrer(string $source, string $cible, string $cle): void
{
    $in = fopen($source, 'rb');
    $out = fopen($cible, 'wb');
    if (!$in || !$out) {
        throw new RuntimeException('fichier inaccessible');
    }
    fwrite($out, EXTERNE_ENTETE);
    $n = 0;
    while (!feof($in)) {
        $bloc = fread($in, EXTERNE_BLOC);
        if ($bloc === '' || $bloc === false) {
            break;
        }
        $nonce = random_bytes(12);
        $etiquette = '';
        // Le numéro du bloc est authentifié avec lui : on ne peut ni retirer ni réordonner des blocs sans que ça se voie.
        $chiffre = openssl_encrypt((string) gzencode($bloc, 6), 'aes-256-gcm', $cle, OPENSSL_RAW_DATA, $nonce, $etiquette, pack('N', $n));
        if ($chiffre === false) {
            throw new RuntimeException('chiffrement impossible');
        }
        fwrite($out, pack('N', strlen($chiffre)) . $nonce . $etiquette . $chiffre);
        $n++;
    }
    fclose($in);
    fclose($out);
}

function externe_dechiffrer(string $source, string $cible, string $cle): void
{
    $in = fopen($source, 'rb');
    $out = fopen($cible, 'wb');
    if (!$in || !$out || fread($in, strlen(EXTERNE_ENTETE)) !== EXTERNE_ENTETE) {
        throw new RuntimeException('ce n\'est pas une sauvegarde Maison Flamingo');
    }
    $n = 0;
    while (($l = fread($in, 4)) !== false && strlen($l) === 4) {
        $taille = unpack('N', $l)[1];
        $nonce = fread($in, 12);
        $etiquette = fread($in, 16);
        $clair = openssl_decrypt((string) fread($in, $taille), 'aes-256-gcm', $cle, OPENSSL_RAW_DATA, $nonce, $etiquette, pack('N', $n));
        if ($clair === false) {
            throw new RuntimeException("bloc $n illisible : mauvaise clé ou fichier abîmé");
        }
        fwrite($out, (string) gzdecode($clair));
        $n++;
    }
    fclose($in);
    fclose($out);
}

// Signature AWS version 4, telle que la décrit la documentation S3 : sans bibliothèque, pour ne rien ajouter au serveur.
function externe_signer(array $c, string $methode, string $cle_objet, string $empreinte, string $quand, array $entetes = []): array
{
    $hote = parse_url($c['point'], PHP_URL_HOST);
    $chemin = '/' . rawurlencode($c['seau']) . '/' . implode('/', array_map('rawurlencode', explode('/', $cle_objet)));
    $jour = substr($quand, 0, 8);
    $entetes = array_change_key_case($entetes + ['host' => $hote, 'x-amz-content-sha256' => $empreinte, 'x-amz-date' => $quand], CASE_LOWER);
    ksort($entetes);
    $canoniques = '';
    foreach ($entetes as $k => $v) {
        $canoniques .= $k . ':' . trim((string) $v) . "\n";
    }
    $signes = implode(';', array_keys($entetes));
    $requete = "$methode\n$chemin\n\n$canoniques\n$signes\n$empreinte";
    $portee = "$jour/{$c['region']}/s3/aws4_request";
    $a_signer = "AWS4-HMAC-SHA256\n$quand\n$portee\n" . hash('sha256', $requete);
    $k = hash_hmac('sha256', $jour, 'AWS4' . $c['cle_secrete'], true);
    foreach ([$c['region'], 's3', 'aws4_request'] as $morceau) {
        $k = hash_hmac('sha256', $morceau, $k, true);
    }
    $entetes['authorization'] = "AWS4-HMAC-SHA256 Credential={$c['cle_id']}/$portee, SignedHeaders=$signes, Signature=" . hash_hmac('sha256', $a_signer, $k);
    return ['url' => rtrim($c['point'], '/') . $chemin, 'entetes' => $entetes];
}

function externe_envoyer_fichier(array $c, string $fichier, string $cle_objet): array
{
    $empreinte = hash_file('sha256', $fichier);
    $taille = (int) filesize($fichier);
    $s = externe_signer($c, 'PUT', $cle_objet, $empreinte, gmdate('Ymd\THis\Z'), ['content-length' => (string) $taille, 'content-type' => 'application/octet-stream']);
    $h = fopen($fichier, 'rb');
    $ch = curl_init($s['url']);
    $reponse = [];
    curl_setopt_array($ch, [
        CURLOPT_UPLOAD => true, CURLOPT_INFILE => $h, CURLOPT_INFILESIZE => $taille, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1800,
        CURLOPT_HTTPHEADER => array_map(fn ($k, $v) => "$k: $v", array_keys($s['entetes']), $s['entetes']),
        CURLOPT_HEADERFUNCTION => function ($ch, $ligne) use (&$reponse) {
            if (str_contains($ligne, ':')) {
                [$k, $v] = explode(':', $ligne, 2);
                $reponse[strtolower(trim($k))] = trim($v);
            }
            return strlen($ligne);
        },
    ]);
    $corps = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erreur = curl_error($ch);
    curl_close($ch);
    fclose($h);
    // Le contrôle sans droit de lecture : l'ETag d'un envoi simple est l'empreinte MD5 de ce que le stockage a reçu.
    $recu = trim($reponse['etag'] ?? '', '"');
    if ($code !== 200 || $recu !== md5_file($fichier)) {
        throw new RuntimeException($code ? "stockage : réponse $code " . mb_substr(strip_tags($corps), 0, 160) : "stockage injoignable : $erreur");
    }
    return ['octets' => $taille, 'empreinte' => $empreinte];
}

// Envoie une sauvegarde déjà faite et relue. Une configuration absente n'est pas un échec : c'est un état, que le tableau signale.
function envoyer_hors_hebergeur(string $nom): array
{
    $c = externe_config();
    if (!$c) {
        return ['ok' => false, 'erreur' => 'non_configure'];
    }
    $source = dossier_sauvegardes() . '/' . basename($nom);
    $chiffre = $source . '.flmg';
    try {
        externe_chiffrer($source, $chiffre, base64_decode($c['chiffrement']));
        $r = externe_envoyer_fichier($c, $chiffre, 'sauvegardes/' . basename($nom) . '.flmg');
        base()->prepare("UPDATE sauvegardes SET externe_le = ?, externe_statut = 'ok', externe_detail = ? WHERE fichier = ?")
            ->execute([maintenant(), $r['octets'] . ' octets chiffrés, reçus intacts', basename($nom)]);
        tracer('systeme', 'copie hors hébergeur', basename($nom) . ', ' . $r['octets'] . ' octets');
        return ['ok' => true, 'octets' => $r['octets']];
    } catch (Throwable $e) {
        base()->prepare("UPDATE sauvegardes SET externe_le = ?, externe_statut = 'echec', externe_detail = ? WHERE fichier = ?")
            ->execute([maintenant(), mb_substr($e->getMessage(), 0, 300), basename($nom)]);
        tracer('systeme', 'copie hors hébergeur échouée', $e->getMessage());
        return ['ok' => false, 'erreur' => 'envoi', 'detail' => mb_substr($e->getMessage(), 0, 200)];
    } finally {
        @unlink($chiffre);
    }
}

function derniere_copie_externe(): ?array
{
    $r = base()->query("SELECT * FROM sauvegardes WHERE externe_statut = 'ok' ORDER BY id DESC LIMIT 1")->fetch();
    return $r ?: null;
}

function a_stockage_etat(): void
{
    exiger_equipe(['superadmin']);
    $c = externe_config();
    sortir(200, ['ok' => true, 'configure' => (bool) $c, 'point' => $c['point'] ?? '', 'region' => $c['region'] ?? '', 'seau' => $c['seau'] ?? '',
        'cle_id_fin' => $c ? '…' . substr($c['cle_id'], -4) : '', 'derniere' => derniere_copie_externe()]);
}

function a_stockage_configurer(): void
{
    $u = exiger_equipe(['superadmin']);
    exiger_post();
    $d = entree();
    $v = ['point' => texte($d['point'] ?? '', 200), 'region' => texte($d['region'] ?? '', 40), 'seau' => texte($d['seau'] ?? '', 80),
        'cle_id' => texte($d['cle_id'] ?? '', 200), 'cle_secrete' => texte($d['cle_secrete'] ?? '', 200)];
    // HTTPS obligatoire ; le http n'est admis que vers la machine elle-même, pour le banc d'essai.
    if (!preg_match('#^(https://[a-z0-9.-]+|http://(localhost|127\.0\.0\.1))(:\d+)?/?$#i', $v['point']) || !preg_match('/^[a-z0-9-]{1,40}$/', $v['region'])
        || !preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/', $v['seau']) || $v['cle_id'] === '' || $v['cle_secrete'] === '') {
        sortir(200, ['ok' => false, 'erreur' => 'champs']);
    }
    $avant = externe_config();
    $nouvelle = null;
    // La clé de chiffrement se crée une fois et ne change plus : changer de stockage ne doit pas rendre illisibles les copies déjà faites.
    if ($avant) {
        $v['chiffrement'] = $avant['chiffrement'];
    } else {
        $nouvelle = base64_encode(random_bytes(32));
        $v['chiffrement'] = $nouvelle;
    }
    $f = dossier_donnees() . '/' . EXTERNE_FICHIER;
    file_put_contents($f, json_encode($v), LOCK_EX);
    @chmod($f, 0600);
    tracer($u, 'stockage externe configuré', $v['point'] . ' / ' . $v['seau']);
    sortir(200, ['ok' => true, 'cle_chiffrement' => $nouvelle]);
}

function a_stockage_essayer(): void
{
    $u = exiger_equipe(['superadmin']);
    exiger_post();
    $d = derniere_sauvegarde() ?? faire_sauvegarde('manuelle');
    if (empty($d['fichier']) || ($d['ok'] ?? true) === false) {
        sortir(200, ['ok' => false, 'erreur' => 'sauvegarde']);
    }
    $nom = $d['fichier'];
    $r = envoyer_hors_hebergeur($nom);
    tracer($u, 'copie hors hébergeur lancée', $nom . ($r['ok'] ? '' : ' : ' . ($r['detail'] ?? $r['erreur'])));
    sortir(200, $r);
}
