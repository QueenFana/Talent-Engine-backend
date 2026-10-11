<?php
declare(strict_types=1);

final class AdminController
{
    public static function users(): void
    {
        Auth::need(['admin']);
        [$page, $per, $off] = Http::page(20, 100);
        $rows = Db::all("SELECT id, nom, prenom, email, role, statut, created_at FROM utilisateurs ORDER BY id DESC LIMIT $per OFFSET $off");
        Http::ok(['items' => $rows, 'total' => (int) Db::val('SELECT COUNT(*) FROM utilisateurs'), 'page' => $page, 'per_page' => $per]);
    }

    public static function create(): void
    {
        $admin = Auth::need(['admin']);
        $in = Http::body(); $err = [];
        $nom = Validate::text($in, 'nom', 'Le nom', 1, 80, $err);
        $prenom = Validate::text($in, 'prenom', 'Le prénom', 1, 80, $err);
        $email = Validate::email($in, $err);
        $pwd = (string) ($in['mot_de_passe'] ?? '');
        if (strlen($pwd) < 10 || strlen($pwd) > 200) $err['mot_de_passe'] = 'Mot de passe de 10 caractères minimum.';
        $role = Validate::oneOf($in['role'] ?? null, ['admin', 'recruteur']) ? $in['role'] : null;
        if (!$role) $err['role'] = 'Rôle invalide.';
        Validate::fail($err);
        if (Db::val('SELECT COUNT(*) FROM utilisateurs WHERE email = ?', [$email])) throw new HttpException(409, 'Cette adresse e-mail est déjà utilisée.');
        $id = Db::insert('INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role) VALUES (?,?,?,?,?)', [$nom, $prenom, $email, password_hash($pwd, PASSWORD_DEFAULT), $role]);
        Audit::log('user_create', $role, $id, (int) $admin['id']);
        Http::ok(['id' => $id], 'Compte créé.', 201);
    }

    public static function update(string $id): void
    {
        $admin = Auth::need(['admin']);
        $u = Db::one('SELECT * FROM utilisateurs WHERE id = ?', [(int) $id]);
        if (!$u) throw new HttpException(404, 'Utilisateur introuvable.');
        $in = Http::body(); $err = [];
        $role = $in['role'] ?? $u['role']; $statut = $in['statut'] ?? $u['statut'];
        if (!Validate::oneOf($role, ['admin', 'recruteur'])) $err['role'] = 'Rôle invalide.';
        if (!Validate::oneOf($statut, ['actif', 'suspendu'])) $err['statut'] = 'Statut invalide.';
        if ((int) $u['id'] === (int) $admin['id'] && ($role !== 'admin' || $statut !== 'actif')) throw new HttpException(422, 'Vous ne pouvez pas modifier votre propre rôle ou statut.');
        Validate::fail($err);
        Db::exec('UPDATE utilisateurs SET role = ?, statut = ? WHERE id = ?', [$role, $statut, $u['id']]);
        if (!empty($in['mot_de_passe'])) {
            if (strlen((string) $in['mot_de_passe']) < 10) throw new HttpException(422, 'Mot de passe de 10 caractères minimum.');
            Db::exec('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?', [password_hash((string) $in['mot_de_passe'], PASSWORD_DEFAULT), $u['id']]);
        }
        if ($statut === 'suspendu') Db::exec('UPDATE tokens_auth SET revoque_at = NOW() WHERE utilisateur_id = ? AND revoque_at IS NULL', [$u['id']]);
        Audit::log('user_update', "$role/$statut", (int) $u['id'], (int) $admin['id']);
        Http::ok(null, 'Compte mis à jour.');
    }
}
?>