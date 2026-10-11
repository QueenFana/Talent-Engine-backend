<?php
declare(strict_types=1);

final class CandidatureController
{
    private const STATUTS = ['nouveau', 'enregistre', 'entretien', 'test', 'accepte', 'rejete'];

    /* ---------- Candidature publique (sans compte) ---------- */
    public static function submit(): void
    {
        $in = Http::body();
        $err = [];
        $poste = PosteController::openOrFail((int) ($in['poste_id'] ?? 0));
        $c = [
            'nom' => Validate::text($in, 'nom', 'Le nom', 1, 80, $err),
            'prenom' => Validate::text($in, 'prenom', 'Le prénom', 1, 80, $err),
            'email' => Validate::email($in, $err),
            'tel' => Validate::text($in, 'tel', 'Le téléphone', 0, 30, $err),
            'addresse' => Validate::text($in, 'addresse', 'La localisation', 0, 150, $err),
            'linkedin' => Validate::url($in, 'linkedin', $err),
            'github' => Validate::url($in, 'github', $err),
            'portfolio' => Validate::url($in, 'portfolio', $err),
        ];
        $lettre = Validate::text($in, 'lettre_motivation', 'La lettre', 0, 5000, $err);
        $annees = $in['annees_experience'] ?? 0;
        if (!is_numeric($annees) || $annees < 0 || $annees > 60) $err['annees_experience'] = 'Années d\'expérience invalides.';
        $dispo = (int) ($in['disponibilite_jours'] ?? 30);
        if ($dispo < 0 || $dispo > 365) $err['disponibilite_jours'] = 'Disponibilité invalide.';
        if (empty($in['consentement'])) $err['consentement'] = 'Votre consentement au traitement des données est requis.';
        if (!empty($c['tel']) && !preg_match('/^[+\d][\d .\-()]{5,28}$/', $c['tel'])) $err['tel'] = 'Numéro de téléphone invalide.';

        $skills = []; foreach (array_slice((array) ($in['competences'] ?? []), 0, 40) as $s) { $s = mb_substr(trim((string) $s), 0, 80); if ($s !== '') $skills[mb_strtolower($s)] = $s; }
        $exps = []; foreach (array_slice((array) ($in['experiences'] ?? []), 0, 15) as $e) {
            if (!is_array($e) || trim((string) ($e['poste'] ?? '')) === '') continue;
            $exps[] = [mb_substr(trim($e['poste']), 0, 150), mb_substr(trim((string) ($e['entreprise'] ?? '')), 0, 150), mb_substr(trim((string) ($e['periode'] ?? '')), 0, 80), mb_substr(trim((string) ($e['description'] ?? '')), 0, 2000)];
        }
        $forms = []; foreach (array_slice((array) ($in['formations'] ?? []), 0, 10) as $f) {
            if (!is_array($f) || trim((string) ($f['diplome'] ?? '')) === '') continue;
            $d = mb_substr(trim($f['diplome']), 0, 150);
            $forms[] = [$d, mb_substr(trim((string) ($f['etablissement'] ?? '')), 0, 150), mb_substr(trim((string) ($f['periode'] ?? '')), 0, 80), Scoring::niveau($d)];
        }
        Validate::fail($err);

        // CV préalablement analysé (fichier temporaire), jamais un chemin fourni par le client
        $upload = (string) ($in['upload_id'] ?? '');
        $tmpPdf = $tmpMeta = null; $meta = null;
        if ($upload !== '') {
            if (!preg_match('/^[a-f0-9]{32}$/', $upload) || !is_file(Config::tmpDir() . "/$upload.pdf")) throw new HttpException(422, 'Le CV téléversé a expiré, veuillez le renvoyer.');
            $tmpPdf = Config::tmpDir() . "/$upload.pdf"; $tmpMeta = Config::tmpDir() . "/$upload.json";
            $meta = json_decode((string) @file_get_contents($tmpMeta), true) ?: [];
        }

        // Doublon : même e-mail normalisé + même offre (message volontairement générique)
        $dup = Db::val('SELECT COUNT(*) FROM candidatures c JOIN candidats ca ON ca.id = c.candidat_id WHERE ca.email = ? AND c.poste_id = ?', [$c['email'], $poste['id']]);
        if ((int) $dup > 0) throw new HttpException(409, 'Une candidature a déjà été enregistrée pour cette offre avec ces informations.');

        $db = Db::pdo();
        $finalPath = null;
        try {
            $db->beginTransaction();
            if ($tmpPdf) {
                $name = bin2hex(random_bytes(20)) . '.pdf';
                if (!is_dir(Config::cvDir())) mkdir(Config::cvDir(), 0750, true);
                $finalPath = Config::cvDir() . '/' . $name;
                if (!rename($tmpPdf, $finalPath)) throw new RuntimeException('Stockage du CV impossible');
                @unlink($tmpMeta);
            }
            $existing = Db::one('SELECT id FROM candidats WHERE email = ? ORDER BY id DESC LIMIT 1', [$c['email']]);
            $params = [$c['nom'], $c['prenom'], $c['tel'] ?: null, $c['addresse'] ?: null, $c['linkedin'], $c['github'], $c['portfolio'], (float) $annees];
            if ($existing) {
                $cid = (int) $existing['id'];
                Db::exec('UPDATE candidats SET nom=?, prenom=?, tel=?, addresse=?, linkedin=?, github=?, portfolio=?, annees_experience=? WHERE id=?', [...$params, $cid]);
                foreach (['competences', 'experiences', 'formations'] as $t) Db::exec("DELETE FROM $t WHERE candidat_id = ?", [$cid]);
            } else {
                $cid = Db::insert('INSERT INTO candidats (nom, prenom, tel, addresse, linkedin, github, portfolio, annees_experience, email) VALUES (?,?,?,?,?,?,?,?,?)', [...$params, $c['email']]);
            }
            if ($finalPath) Db::exec('UPDATE candidats SET cv_original=?, cv_path=?, cv_text=? WHERE id=?', [$meta['original'] ?? 'cv.pdf', basename($finalPath), $meta['text'] ?? null, $cid]);
            foreach ($skills as $s) Db::exec('INSERT INTO competences (candidat_id, nom) VALUES (?,?)', [$cid, $s]);
            foreach ($exps as $e) Db::exec('INSERT INTO experiences (candidat_id, poste, entreprise, periode, description) VALUES (?,?,?,?,?)', [$cid, $e[0], $e[1] ?: null, $e[2] ?: null, $e[3] ?: null]);
            foreach ($forms as $f) Db::exec('INSERT INTO formations (candidat_id, diplome, etablissement, periode, niveau) VALUES (?,?,?,?,?)', [$cid, $f[0], $f[1] ?: null, $f[2] ?: null, $f[3]]);
            $id = Db::insert('INSERT INTO candidatures (candidat_id, poste_id, lettre_motivation, disponibilite_jours, autorisation_travail) VALUES (?,?,?,?,?)',
                [$cid, $poste['id'], $lettre ?: null, $dispo, empty($in['autorisation_travail']) ? 0 : 1]);
            Db::exec('INSERT INTO historique_statuts_candidature (candidature_id, ancien_statut, nouveau_statut, commentaire) VALUES (?,?,?,?)', [$id, null, 'nouveau', 'Candidature reçue']);
            Scoring::persist($id);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($finalPath && is_file($finalPath)) @unlink($finalPath);
            throw $e;
        }
        Http::ok(['reference' => sprintf('TE-%06d', $id)], 'Candidature enregistrée.', 201);
    }

    /* ---------- Espace recruteur ---------- */
    private static function authorized(int $id, array $u): array
    {
        $r = Db::one('SELECT c.*, p.created_by, p.titre AS poste_titre, p.experience AS poste_experience FROM candidatures c JOIN postes p ON p.id = c.poste_id WHERE c.id = ?', [$id]);
        if (!$r) throw new HttpException(404, 'Candidature introuvable.');
        if ($u['role'] !== 'admin' && (int) $r['created_by'] !== (int) $u['id']) throw new HttpException(403, 'Accès refusé.');
        return $r;
    }

    public static function index(): void
    {
        $u = Auth::need(['recruteur', 'admin']);
        [$sc, $sp] = Auth::scope($u);
        [$page, $per, $off] = Http::page(10, 200);
        $w = [$sc]; $p = $sp;
        if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
            $w[] = '(ca.nom LIKE ? OR ca.prenom LIKE ? OR ca.email LIKE ? OR EXISTS (SELECT 1 FROM competences k WHERE k.candidat_id = ca.id AND k.nom LIKE ?))';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if ((int) ($_GET['poste_id'] ?? 0) > 0) { $w[] = 'c.poste_id = ?'; $p[] = (int) $_GET['poste_id']; }
        if (Validate::oneOf($_GET['tier'] ?? null, ['priority', 'review', 'low'])) { $w[] = 'c.tier = ?'; $p[] = $_GET['tier']; }
        if (Validate::oneOf($_GET['statut'] ?? null, self::STATUTS)) { $w[] = 'c.statut = ?'; $p[] = $_GET['statut']; }
        if (is_numeric($_GET['min_score'] ?? null)) { $w[] = 'c.score >= ?'; $p[] = (int) $_GET['min_score']; }
        if (is_numeric($_GET['min_exp'] ?? null)) { $w[] = 'ca.annees_experience >= ?'; $p[] = (float) $_GET['min_exp']; }
        $where = implode(' AND ', $w);
        $order = match ($_GET['sort'] ?? 'score') { 'date' => 'c.applied_at DESC', 'nom' => 'ca.nom ASC', default => 'c.score DESC, c.applied_at DESC' };
        $from = 'FROM candidatures c JOIN candidats ca ON ca.id = c.candidat_id JOIN postes p ON p.id = c.poste_id';
        $total = (int) Db::val("SELECT COUNT(*) $from WHERE $where", $p);
        $rows = Db::all("SELECT c.id, c.statut, c.score, c.tier, c.applied_at, c.disponibilite_jours, ca.id AS candidat_id, ca.nom, ca.prenom, ca.email, ca.addresse, ca.annees_experience,
                         p.id AS poste_id, p.titre AS poste_titre $from WHERE $where ORDER BY $order LIMIT $per OFFSET $off", $p);

        $cfg = Scoring::config();
        $known = array_map('mb_strtolower', array_merge($cfg['essential_skills'], $cfg['bonus_skills']));
        $ids = array_values(array_unique(array_map(fn($r) => (int) $r['candidat_id'], $rows)));
        $skills = [];
        if ($ids) {
            foreach (Db::all('SELECT candidat_id, nom FROM competences WHERE candidat_id IN (' . implode(',', $ids) . ') ORDER BY nom') as $s) {
                $skills[$s['candidat_id']][] = ['nom' => $s['nom'], 'match' => in_array(mb_strtolower($s['nom']), $known, true)];
            }
        }
        foreach ($rows as $i => &$r) {
            $list = $skills[$r['candidat_id']] ?? [];
            usort($list, fn($a, $b) => $b['match'] <=> $a['match']);
            $r['competences'] = array_slice($list, 0, 4);
            $r['rang'] = $off + $i + 1;
        }
        unset($r);
        Http::ok(['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per, 'stats' => DashboardController::tierStats($u)]);
    }

    public static function show(string $id): void
    {
        $u = Auth::need(['recruteur', 'admin']);
        $c = self::authorized((int) $id, $u);
        $cand = Db::one('SELECT id, nom, prenom, email, tel, addresse, linkedin, github, portfolio, annees_experience, cv_original, (cv_path IS NOT NULL) AS has_cv FROM candidats WHERE id = ?', [$c['candidat_id']]);
        $rank = $c['score'] === null ? null : 1 + (int) Db::val('SELECT COUNT(*) FROM candidatures WHERE poste_id = ? AND score > ?', [$c['poste_id'], $c['score']]);
        Http::ok([
            'id' => (int) $c['id'], 'statut' => $c['statut'], 'score' => $c['score'] === null ? null : (int) $c['score'], 'tier' => $c['tier'],
            'detail' => json_decode((string) $c['score_detail'], true), 'applied_at' => $c['applied_at'],
            'lettre_motivation' => $c['lettre_motivation'], 'disponibilite_jours' => (int) $c['disponibilite_jours'],
            'poste' => ['id' => (int) $c['poste_id'], 'titre' => $c['poste_titre'], 'experience' => (int) $c['poste_experience']],
            'rang' => $rank, 'total_poste' => (int) Db::val('SELECT COUNT(*) FROM candidatures WHERE poste_id = ?', [$c['poste_id']]),
            'candidat' => $cand,
            'competences' => array_column(Db::all('SELECT nom FROM competences WHERE candidat_id = ? ORDER BY nom', [$c['candidat_id']]), 'nom'),
            'experiences' => Db::all('SELECT poste, entreprise, periode, description FROM experiences WHERE candidat_id = ? ORDER BY id', [$c['candidat_id']]),
            'formations' => Db::all('SELECT diplome, etablissement, periode, niveau FROM formations WHERE candidat_id = ? ORDER BY id', [$c['candidat_id']]),
            'historique' => Db::all('SELECT h.ancien_statut, h.nouveau_statut, h.commentaire, h.created_at, CONCAT(u.prenom, \' \', u.nom) AS auteur FROM historique_statuts_candidature h LEFT JOIN utilisateurs u ON u.id = h.modifie_par WHERE h.candidature_id = ? ORDER BY h.id DESC', [$c['id']]),
            'notes' => Db::all('SELECT n.id, n.contenu, n.tags, n.created_at, CONCAT(u.prenom, \' \', u.nom) AS auteur FROM notes_candidature n JOIN utilisateurs u ON u.id = n.utilisateur_id WHERE n.candidature_id = ? ORDER BY n.id DESC', [$c['id']]),
        ]);
    }

    public static function updateStatut(string $id): void
    {
        $u = Auth::need(['recruteur', 'admin']);
        $c = self::authorized((int) $id, $u);
        $in = Http::body();
        if (!Validate::oneOf($in['statut'] ?? null, self::STATUTS)) throw new HttpException(422, 'Statut invalide.', ['statut' => 'Valeur non autorisée.']);
        $err = [];
        $com = Validate::text($in, 'commentaire', 'Le commentaire', 0, 255, $err);
        Validate::fail($err);
        if ($c['statut'] !== $in['statut']) {
            Db::exec('UPDATE candidatures SET statut = ? WHERE id = ?', [$in['statut'], $c['id']]);
            Db::exec('INSERT INTO historique_statuts_candidature (candidature_id, ancien_statut, nouveau_statut, modifie_par, commentaire) VALUES (?,?,?,?,?)',
                [$c['id'], $c['statut'], $in['statut'], $u['id'], $com ?: null]);
            Audit::log('candidature_statut', $in['statut'], (int) $c['id'], (int) $u['id']);
        }
        Http::ok(['statut' => $in['statut']], 'Statut mis à jour.');
    }

    public static function addNote(string $id): void
    {
        $u = Auth::need(['recruteur', 'admin']);
        $c = self::authorized((int) $id, $u);
        $in = Http::body();
        $err = [];
        $txt = Validate::text($in, 'contenu', 'La note', 1, 2000, $err);
        Validate::fail($err);
        $tags = implode(',', array_slice(array_filter(array_map(fn($t) => mb_substr(trim((string) $t), 0, 30), (array) ($in['tags'] ?? []))), 0, 5));
        Db::insert('INSERT INTO notes_candidature (candidature_id, utilisateur_id, contenu, tags) VALUES (?,?,?,?)', [$c['id'], $u['id'], $txt, $tags ?: null]);
        Http::ok(null, 'Note enregistrée.', 201);
    }

    /** Téléchargement du CV privé via contrôle d'accès (jamais d'URL publique) */
    public static function cv(string $id): void
    {
        $u = Auth::need(['recruteur', 'admin']);
        $c = self::authorized((int) $id, $u);
        $cand = Db::one('SELECT cv_path, cv_original FROM candidats WHERE id = ?', [$c['candidat_id']]);
        $file = $cand && $cand['cv_path'] ? Config::cvDir() . '/' . basename($cand['cv_path']) : null;
        if (!$file || !is_file($file)) throw new HttpException(404, 'Aucun CV disponible.');
        Audit::log('cv_download', 'candidature', (int) $c['id'], (int) $u['id']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="cv-' . (int) $c['id'] . '.pdf"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }
}
?>