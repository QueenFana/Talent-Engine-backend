<?php
declare(strict_types=1);

final class DashboardController
{
    public static function tierStats(array $u): array
    {
        [$sc, $sp] = Auth::scope($u);
        $r = Db::one("SELECT COUNT(*) total, COALESCE(SUM(c.tier='priority'),0) priority, COALESCE(SUM(c.tier='review'),0) review, COALESCE(SUM(c.tier='low'),0) low, COALESCE(AVG(c.score),0) avg_score
                      FROM candidatures c JOIN postes p ON p.id = c.poste_id WHERE $sc", $sp);
        return array_map(fn($v) => is_numeric($v) ? round((float) $v, 1) + 0 : $v, $r);
    }

    public static function index(): void
    {
        $u = Auth::need(['recruteur', 'admin']);
        [$sc, $sp] = Auth::scope($u);
        $stats = self::tierStats($u);
        $week = Db::one("SELECT COALESCE(SUM(c.applied_at >= NOW() - INTERVAL 7 DAY),0) cur, COALESCE(SUM(c.applied_at < NOW() - INTERVAL 7 DAY AND c.applied_at >= NOW() - INTERVAL 14 DAY),0) prev
                         FROM candidatures c JOIN postes p ON p.id = c.poste_id WHERE $sc", $sp);
        $b = Db::one("SELECT COALESCE(SUM(c.score<40),0) b0, COALESCE(SUM(c.score>=40 AND c.score<50),0) b1, COALESCE(SUM(c.score>=50 AND c.score<60),0) b2,
                      COALESCE(SUM(c.score>=60 AND c.score<70),0) b3, COALESCE(SUM(c.score>=70 AND c.score<80),0) b4, COALESCE(SUM(c.score>=80 AND c.score<90),0) b5,
                      COALESCE(SUM(c.score>=90),0) b6 FROM candidatures c JOIN postes p ON p.id = c.poste_id WHERE c.score IS NOT NULL AND $sc", $sp);
        $top = Db::all("SELECT c.id, c.score, c.statut, ca.nom, ca.prenom, ca.annees_experience, p.titre AS poste_titre FROM candidatures c
                        JOIN candidats ca ON ca.id = c.candidat_id JOIN postes p ON p.id = c.poste_id WHERE $sc ORDER BY c.score DESC, c.applied_at DESC LIMIT 5", $sp);
        $activity = Db::all("SELECT 'nouvelle' AS type, CONCAT(ca.prenom,' ',ca.nom) AS qui, p.titre AS detail, c.id AS candidature_id, c.applied_at AS at, c.score
                             FROM candidatures c JOIN candidats ca ON ca.id = c.candidat_id JOIN postes p ON p.id = c.poste_id WHERE $sc ORDER BY c.applied_at DESC LIMIT 6", $sp);
        $changes = Db::all("SELECT 'statut' AS type, CONCAT(ca.prenom,' ',ca.nom) AS qui, h.nouveau_statut AS detail, c.id AS candidature_id, h.created_at AS at, CONCAT(u2.prenom,' ',u2.nom) AS auteur
                            FROM historique_statuts_candidature h JOIN candidatures c ON c.id = h.candidature_id JOIN candidats ca ON ca.id = c.candidat_id JOIN postes p ON p.id = c.poste_id
                            LEFT JOIN utilisateurs u2 ON u2.id = h.modifie_par WHERE h.modifie_par IS NOT NULL AND $sc ORDER BY h.id DESC LIMIT 6", $sp);
        $feed = array_merge($activity, $changes);
        usort($feed, fn($a, $b) => strcmp($b['at'], $a['at']));
        $data = [
            'stats' => $stats, 'semaine' => ['courante' => (int) $week['cur'], 'precedente' => (int) $week['prev']],
            'distribution' => array_map('intval', array_values($b)), 'top' => $top, 'activite' => array_slice($feed, 0, 6),
            'postes_actifs' => (int) Db::val("SELECT COUNT(*) FROM postes p WHERE p.statut='publie' AND $sc", $sp),
            'regles' => ['tiers' => Scoring::config()['tiers']],
        ];
        if ($u['role'] === 'admin') {
            $data['admin'] = ['utilisateurs_actifs' => (int) Db::val("SELECT COUNT(*) FROM utilisateurs WHERE statut='actif'"), 'offres' => (int) Db::val('SELECT COUNT(*) FROM postes')];
        }
        Http::ok($data);
    }
}
?>