<?php
// Maison Flamingo · qui entre, et ce qu'il a le droit de voir.
//
// Règle de toute la maison : le cookie dit QUI, la base dit QUOI. Le rôle,
// l'agence et le compte actif sont relus en base à chaque requête : un compte
// désactivé à 10 h ne fait plus rien à 10 h 01, même avec un téléphone connecté.

declare(strict_types=1);

/* ---------- Freins contre les essais en rafale ---------- */

function empreinte_ip(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|flamingo');
}

function freine(string $cle, int $max): bool
{
    $db = base();
    $db->prepare('DELETE FROM tentatives WHERE quand < ?')->execute([time() - 900]);
    $q = $db->prepare('SELECT COUNT(*) FROM tentatives WHERE empreinte = ?');
    $q->execute([$cle]);
    return (int) $q->fetchColumn() >= $max;
}

function noter_echec(string $cle): void
{
    base()->prepare('INSERT INTO tentatives (empreinte, quand) VALUES (?,?)')->execute([$cle, time()]);
}

function cle_compte(string $courriel): string
{
    return 'compte:' . hash('sha256', mb_strtolower($courriel));
}

function exiger_origine(): void
{
    // Une écriture doit porter cet en-tête : un autre site ne peut pas l'ajouter
    // sans une vérification préalable que le navigateur refusera.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['HTTP_X_FLAMINGO'] ?? '') !== '1') {
        sortir(403, ['ok' => false, 'erreur' => 'origine']);
    }
}

function exiger_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        sortir(405, ['ok' => false, 'erreur' => 'methode']);
    }
    exiger_origine();
}

function hacher(string $motdepasse): string
{
    return password_hash($motdepasse, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
}

function motdepasse_valable(string $m): bool
{
    return strlen($m) >= 12 && strlen($m) <= 200;
}

/* ---------- Cookies ---------- */

function poser_cookie(string $nom, string $valeur, int $expire): void
{
    $local = str_starts_with(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), 'localhost');
    setcookie($nom, $valeur, [
        'expires' => $expire, 'path' => '/', 'secure' => !$local, 'httponly' => true, 'samesite' => 'Strict',
    ]);
}

/* ---------- L'équipe ---------- */

function ouvrir_session_equipe(array $u, bool $double_facteur_fait): void
{
    $brut = jeton(32);
    $expire = time() + (DUREES_SESSION[$u['role']] ?? 86400);
    $db = base();
    $db->prepare('INSERT INTO sessions (jeton, utilisateur_id, expire, totp_ok, cree_le) VALUES (?,?,?,?,?)')
        ->execute([hash('sha256', $brut), $u['id'], $expire, $double_facteur_fait ? 1 : 0, maintenant()]);
    $db->prepare('DELETE FROM sessions WHERE expire < ?')->execute([time()]);
    poser_cookie('flamingo_s', $brut, $expire);
}

function session_equipe(): ?array
{
    $c = $_COOKIE['flamingo_s'] ?? '';
    if (!is_string($c) || strlen($c) < 20) {
        return null;
    }
    $q = base()->prepare("SELECT u.id, u.nom, u.courriel, u.telephone, u.photo, u.role, u.agence_id, u.totp_actif, u.totp_secret, u.totp_dernier,
            s.totp_ok, s.jeton AS session, a.nom AS agence_nom
        FROM sessions s JOIN utilisateurs u ON u.id = s.utilisateur_id LEFT JOIN agences a ON a.id = u.agence_id
        WHERE s.jeton = ? AND s.expire > ? AND u.actif = 1 AND u.hash <> ''");
    $q->execute([hash('sha256', $c), time()]);
    $u = $q->fetch();
    return $u ?: null;
}

function double_facteur_requis(array $u): bool
{
    return in_array($u['role'], ROLES_DOUBLE_FACTEUR, true) || (int) $u['totp_actif'] === 1;
}

function exiger_equipe(array $roles = ROLES_EQUIPE): array
{
    exiger_origine();
    $u = session_equipe();
    if (!$u) {
        sortir(401, ['ok' => false, 'erreur' => 'connexion']);
    }
    if (!in_array($u['role'], ROLES_EQUIPE, true)) {
        tracer($u, 'accès refusé', 'rôle inconnu');
        sortir(403, ['ok' => false, 'erreur' => 'droits']);
    }
    if (double_facteur_requis($u) && (int) $u['totp_ok'] !== 1) {
        sortir(401, ['ok' => false, 'erreur' => 'double_facteur']);
    }
    if (!in_array($u['role'], $roles, true)) {
        tracer($u, 'accès refusé', (string) ($_GET['action'] ?? ''));
        sortir(403, ['ok' => false, 'erreur' => 'droits']);
    }
    return $u;
}

function public_utilisateur(array $u): array
{
    return [
        'id' => (int) $u['id'], 'nom' => $u['nom'], 'courriel' => $u['courriel'], 'role' => $u['role'],
        'agence_id' => $u['agence_id'] !== null ? (int) $u['agence_id'] : null, 'agence' => $u['agence_nom'] ?? null,
        'double_facteur' => (int) $u['totp_actif'] === 1, 'telephone' => $u['telephone'] ?? '', 'photo' => ($u['photo'] ?? '') !== '',
    ];
}

/* ---------- Le second facteur (codes à six chiffres, RFC 6238) ---------- */

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_encoder(string $octets): string
{
    $bits = '';
    foreach (str_split($octets) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $sortie = '';
    foreach (str_split($bits, 5) as $morceau) {
        $sortie .= BASE32[bindec(str_pad($morceau, 5, '0'))];
    }
    return $sortie;
}

function base32_decoder(string $texte): string
{
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $texte) ?? '')) as $c) {
        $bits .= str_pad(decbin(strpos(BASE32, $c)), 5, '0', STR_PAD_LEFT);
    }
    $sortie = '';
    foreach (str_split($bits, 8) as $octet) {
        if (strlen($octet) === 8) {
            $sortie .= chr(bindec($octet));
        }
    }
    return $sortie;
}

function totp_code(string $secret, int $pas): string
{
    $h = hash_hmac('sha1', pack('N2', 0, $pas), $secret, true);
    $o = ord($h[19]) & 0x0F;
    $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

// Rend le pas de temps reconnu, ou null. Un code déjà utilisé ne resert jamais :
// quelqu'un qui le voit par-dessus l'épaule arrive trop tard.
function totp_verifier(string $secret_b32, string $code, int $dernier): ?int
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6 || $secret_b32 === '') {
        return null;
    }
    $secret = base32_decoder($secret_b32);
    $maintenant = intdiv(time(), 30);
    foreach ([0, -1, 1] as $d) {
        $pas = $maintenant + $d;
        if ($pas > $dernier && hash_equals(totp_code($secret, $pas), $code)) {
            return $pas;
        }
    }
    return null;
}

/* ---------- Invitations : un lien à usage unique, jamais un mot de passe transmis ---------- */

function creer_invitation(string $genre, int $cible, ?int $par): string
{
    $brut = jeton(24);
    $db = base();
    // Une nouvelle invitation annule les précédentes pour la même personne.
    $db->prepare('UPDATE invitations SET utilisee_le = ? WHERE genre = ? AND cible_id = ? AND utilisee_le IS NULL')
        ->execute(['remplacée ' . maintenant(), $genre, $cible]);
    $db->prepare('INSERT INTO invitations (jeton, genre, cible_id, cree_par, cree_le, expire) VALUES (?,?,?,?,?,?)')
        ->execute([hash('sha256', $brut), $genre, $cible, $par, maintenant(), time() + DUREE_INVITATION]);
    return $brut;
}

function lire_invitation(string $brut): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{24,64}$/', $brut)) {
        return null;
    }
    $q = base()->prepare('SELECT * FROM invitations WHERE jeton = ? AND utilisee_le IS NULL AND expire > ?');
    $q->execute([hash('sha256', $brut), time()]);
    return $q->fetch() ?: null;
}

/* ---------- Les clients de l'espace client ---------- */

function session_client(): ?array
{
    $c = $_COOKIE['flamingo_c'] ?? '';
    if (!is_string($c) || strlen($c) < 20) {
        return null;
    }
    $q = base()->prepare("SELECT cc.id AS compte_id, cc.courriel AS compte_courriel, c.*
        FROM sessions_clients s JOIN comptes_clients cc ON cc.id = s.compte_id JOIN clients c ON c.id = cc.client_id
        WHERE s.jeton = ? AND s.expire > ? AND cc.actif = 1");
    $q->execute([hash('sha256', $c), time()]);
    $r = $q->fetch();
    return $r ?: null;
}

function exiger_client_connecte(): array
{
    exiger_origine();
    $c = session_client();
    if (!$c) {
        sortir(401, ['ok' => false, 'erreur' => 'connexion']);
    }
    return $c;
}

/* ---------- Portée : quels clients chacun voit ---------- */

// Rend une condition SQL et ses paramètres. Tout rôle non prévu ne voit rien.
function portee_clients(array $u, string $a = 'c'): array
{
    switch ($u['role']) {
        case 'superadmin':
            return ['1=1', []];
        case 'chef':
            return ["$a.agence_id = ?", [(int) $u['agence_id']]];
        case 'technicien':
            return ["$a.agence_id = ? AND $a.technicien_id = ?", [(int) $u['agence_id'], (int) $u['id']]];
        default:
            return ['0=1', []];
    }
}

function client_autorise(array $u, int $id): ?array
{
    [$w, $p] = portee_clients($u);
    $q = base()->prepare("SELECT c.* FROM clients c WHERE c.id = ? AND ($w)");
    $q->execute([$id, ...$p]);
    return $q->fetch() ?: null;
}

function exiger_client_autorise(array $u, int $id): array
{
    $c = client_autorise($u, $id);
    if (!$c) {
        // Même réponse pour « n'existe pas » et « pas à vous » : on ne confirme pas l'existence d'un client.
        tracer($u, 'client refusé', (string) $id);
        sortir(404, ['ok' => false, 'erreur' => 'introuvable']);
    }
    return $c;
}

// Un chef ne gère que les techniciens de son agence ; la direction gère tout le monde sauf elle-même.
function membre_gerable(array $u, int $id): ?array
{
    $q = base()->prepare('SELECT * FROM utilisateurs WHERE id = ?');
    $q->execute([$id]);
    $m = $q->fetch();
    if (!$m || (int) $m['id'] === (int) $u['id']) {
        return null;
    }
    if ($u['role'] === 'superadmin' && $m['role'] !== 'superadmin') {
        return $m;
    }
    if ($u['role'] === 'chef' && $m['role'] === 'technicien' && (int) $m['agence_id'] === (int) $u['agence_id']) {
        return $m;
    }
    return null;
}
