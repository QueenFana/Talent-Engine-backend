<?php
declare(strict_types=1);

final class PosteController
{
    private const TYPES = ['CDI', 'CDD', 'Stage', 'Freelance', 'Alternance'];
    private const STATUTS = ['brouillon', 'publie', 'ferme', 'archive'];
    private const OPEN = "p.statut = 'publie' AND (p.date_limite IS NULL OR p.date_limite >= CURDATE())";

    public static function index(): void
    {
        [$page, $per, $off] = Http::page(9, 50);
        $w = [self::OPEN]; $p = [];
        if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') { $w[] = '(p.titre LIKE ? OR p.description LIKE ?)'; $p[] = $p[] = "%$q%"; }
        if (($l = trim((string) ($_GET['localisation'] ?? ''))) !== '') { $w[] = 'p.addresse LIKE ?'; $p[] = "%$l%"; }
        if (Validate::oneOf($_GET['type'] ?? null, self::TYPES)) { $w[] = 'p.type_contract = ?'; $p[] = $_GET['type']; }
        $where = implode(' AND ', $w);
        $total = (int) Db::val("SELECT COUNT(*) FROM postes p WHERE $where", $p);
        $rows = Db::all("SELECT p.id, p.titre, SUBSTRING(p.description,1,220) AS extrait, p.addresse, p.type_contract, p.experience, p.date_limite, p.created_at
                         FROM postes p WHERE $where ORDER BY p.created_at DESC LIMIT $per OFFSET $off", $p);
        Http::ok(['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per]);
    }

    public static function show(string $id): void
    {
        $r = Db::one('SELECT p.id, p.titre, p.description, p.addresse, p.type_contract, p.experience, p.date_limite, p.created_at FROM postes p WHERE p.id = ? AND ' . self::OPEN, [(int) $id]);
        if (!$r) throw new HttpException(404, 'Offre introuvable.');
        Http::ok($r);
    }

    /** Offres du recruteur connecté (toutes pour l'admin) */
    public static function mine(): void
    {
        $u = Auth::need(['recruteur', 'admin']);
        [$sc, $sp] = Auth::scope($u);
        $rows = Db::all("SELECT p.*, (SELECT COUNT(*) FROM candidatures c WHERE c.poste_id = p.id) AS nb_candidatures
                         FROM postes p WHERE $sc AND p.statut <> 'archive' ORDER BY p.created_at DESC LIMIT 200", $sp);
        Http::ok($rows);
    }

    private static function clean(array $in, bool $create): array
    {
        $err = [];
        $d = [
            'titre' => Validate::text($in, 'titre', 'Le titre', 3, 150, $err),
            'description' => Validate::text($in, 'description', 'La description', 20, 10000, $err),
            'addresse' => Validate::text($in, 'addresse', 'La localisation', 0, 150, $err),
        ];
        $d['type_contract'] = Validate::oneOf($in['type_contract'] ?? null, self::TYPES) ? $in['type_contract'] : null;
        if (!$d['type_contract']) $err['type_contract'] = 'Type de contrat invalide.';
        $exp = $in['experience'] ?? 0;
        if (!is_numeric($exp) || $exp < 0 || $exp > 40) $err['experience'] = 'Expérience requise invalide (0-40).';
        $d['experience'] = (int) $exp;
        $dl = $in['date_limite'] ?? null;
        $dl = ($dl === '' || $dl === null) ? null : (string) $dl;
        if (!Validate::date($dl)) $err['date_limite'] = 'Date limite invalide (AAAA-MM-JJ).';
        $d['date_limite'] = $dl;
        $st = $in['statut'] ?? ($create ? 'brouillon' : null);
        if ($st !== null && !Validate::oneOf($st, self::STATUTS)) $err['statut'] = 'Statut invalide.';
        $d['statut'] = $st;
        Validate::fail($err);
        return $d;
    }

    public static function create(): void
    {
        $u = Auth::need(['recruteur', 'admin']);
        $d = self::clean(Http::body(), true);
        $id = Db::insert('INSERT INTO postes (titre, description, addresse, type_contract, experience, date_limite, statut, created_by) VALUES (?,?,?,?,?,?,?,?)',
            [$d['titre'], $d['description'], $d['addresse'] ?: null, $d['type_contract'], $d['experience'], $d['date_limite'], $d['statut'], $u['id']]); // created_by issu du token
        Audit::log('poste_create', 'poste', $id, (int) $u['id']);
        Http::ok(['id' => $id], 'Offre créée.', 201);
    }

    private static function owned(string $id, array $u): array
    {
        $p = Db::one('SELECT * FROM postes WHERE id = ?', [(int) $id]);
        if (!$p) throw new HttpException(404, 'Offre introuvable.');
        if ($u['role'] !== 'admin' && (int) $p['created_by'] !== (int) $u['id']) throw new HttpException(403, 'Accès refusé.');
        return $p;
    }

    public static function update(string $id): void
    {
        $u = Auth::need(['recruteur', 'admin']);
        $old = self::owned($id, $u);
        $in = array_merge($old, Http::body());
        $d = self::clean($in, false);
        Db::exec('UPDATE postes SET titre=?, description=?, addresse=?, type_contract=?, experience=?, date_limite=?, statut=? WHERE id=?',
            [$d['titre'], $d['description'], $d['addresse'] ?: null, $d['type_contract'], $d['experience'], $d['date_limite'], $d['statut'] ?? $old['statut'], $old['id']]);
        Audit::log('poste_update', 'poste', (int) $old['id'], (int) $u['id']);
        Http::ok(null, 'Offre mise à jour.');
    }

    public static function archive(string $id): void
    {
        $u = Auth::need(['recruteur', 'admin']);
        $p = self::owned($id, $u);
        Db::exec("UPDATE postes SET statut='archive' WHERE id=?", [$p['id']]);
        Audit::log('poste_archive', 'poste', (int) $p['id'], (int) $u['id']);
        Http::ok(null, 'Offre archivée.');
    }

    /** Utilisé par la candidature : l'offre doit être ouverte */
    public static function openOrFail(int $id): array
    {
        $r = Db::one('SELECT p.* FROM postes p WHERE p.id = ? AND ' . self::OPEN, [$id]);
        if (!$r) throw new HttpException(404, 'Cette offre n\'accepte plus de candidatures.');
        return $r;
    }
}
?>