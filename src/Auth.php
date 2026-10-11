<?php
declare(strict_types=1);

final class Auth
{
    private const TTL_HOURS = 8;
    private static ?array $user = null;
    private static ?string $tokenHash = null;

    private static function bearer(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($h === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower($k) === 'authorization') $h = $v;
            }
        }
        return preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $h, $m) ? $m[1] : null;
    }

    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        if (Audit::count('login_failed', 15, $email) >= 5) {
            throw new HttpException(429, 'Trop de tentatives. Réessayez dans quelques minutes.');
        }
        $u = Db::one('SELECT * FROM utilisateurs WHERE email = ?', [$email]);
        // hash factice pour égaliser le temps de réponse si le compte n'existe pas
        $hash = $u['mot_de_passe'] ?? '$2y$10$usesomesillystringforsalt.abcdefghijklmnopqrstuvwxyzABCDE';
        $valid = password_verify($password, $hash) && $u !== null;
        if (!$valid || $u['statut'] !== 'actif') {
            Audit::log('login_failed', $email);
            throw new HttpException(401, 'Identifiants invalides.');
        }
        $token = bin2hex(random_bytes(32));
        Db::exec('INSERT INTO tokens_auth (utilisateur_id, token_hash, expires_at) VALUES (?,?,NOW() + INTERVAL ' . self::TTL_HOURS . ' HOUR)',
            [$u['id'], hash('sha256', $token)]);
        Audit::log('login_ok', 'utilisateur', (int) $u['id'], (int) $u['id']);
        return ['token' => $token, 'user' => self::pub($u)];
    }

    public static function pub(array $u): array
    {
        return ['id' => (int) $u['id'], 'nom' => $u['nom'], 'prenom' => $u['prenom'], 'email' => $u['email'], 'role' => $u['role']];
    }

    public static function user(): ?array
    {
        if (self::$user !== null) return self::$user;
        $t = self::bearer();
        if ($t === null) return null;
        $h = hash('sha256', $t);
        $u = Db::one('SELECT u.* FROM tokens_auth t JOIN utilisateurs u ON u.id = t.utilisateur_id
                      WHERE t.token_hash = ? AND t.revoque_at IS NULL AND t.expires_at > NOW() AND u.statut = \'actif\'', [$h]);
        if ($u) { self::$user = $u; self::$tokenHash = $h; }
        return $u;
    }

    /** @param string[] $roles vide = tout utilisateur connecté */
    public static function need(array $roles = []): array
    {
        $u = self::user();
        if ($u === null) throw new HttpException(401, 'Authentification requise.');
        if ($roles && !in_array($u['role'], $roles, true)) throw new HttpException(403, 'Accès refusé.');
        return $u;
    }

    public static function logout(): void
    {
        self::need();
        Db::exec('UPDATE tokens_auth SET revoque_at = NOW() WHERE token_hash = ?', [self::$tokenHash]);
    }

    /** Fragment SQL limitant aux offres du recruteur (admin = tout) */
    public static function scope(array $u, string $alias = 'p'): array
    {
        return $u['role'] === 'admin' ? ['1=1', []] : ["$alias.created_by = ?", [(int) $u['id']]];
    }
}
?>