<?php
declare(strict_types=1);

/**
 * Scoring transparent et déterministe (aide à la décision uniquement).
 * Chaque critère expose ses points et sa justification; aucune décision automatique.
 */
final class Scoring
{
    public const DEFAULTS = [
        'weights' => ['skills' => 40, 'experience' => 25, 'education' => 15, 'availability' => 10, 'completeness' => 10],
        'tiers' => ['priority' => 75, 'review' => 50],
        'multipliers' => ['essential' => 2.0, 'bonus' => 1.25],
        'essential_skills' => ['TypeScript', 'React', 'System Design', 'Node.js'],
        'bonus_skills' => ['GraphQL', 'Docker', 'Kubernetes', 'PostgreSQL'],
        'education_multipliers' => ['aucun' => 0.3, 'bac' => 0.6, 'licence' => 1.0, 'master' => 1.2, 'doctorat' => 1.4],
        'knockouts' => ['work_authorization' => true, 'min_experience' => 4, 'require_cv' => true],
    ];

    public static function config(): array
    {
        $row = Db::one('SELECT config FROM regles_scoring WHERE id = 1');
        $cfg = $row ? json_decode($row['config'], true) : null;
        return is_array($cfg) ? array_replace_recursive(self::DEFAULTS, $cfg) : self::DEFAULTS;
    }

    public static function niveau(string $diplome): string
    {
        $d = mb_strtolower($diplome);
        if (preg_match('/ph\.?d|doctorat/u', $d)) return 'doctorat';
        if (preg_match('/master|m\.?sc|ing[ée]nieur|mba|bac\s*\+\s*5|\bm[12]\b/u', $d)) return 'master';
        if (preg_match('/licence|bachelor|b\.?sc|bac\s*\+\s*[234]|\bbts\b|\bdut\b|\bbut\b/u', $d)) return 'licence';
        if (preg_match('/\bbac\b|baccalaur/u', $d)) return 'bac';
        return 'aucun';
    }

    private static function has(string $haystack, string $needle): bool
    {
        return (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/iu', $haystack);
    }

    public static function compute(array $cand, array $skills, array $formations, array $candidature, array $poste, array $cfg): array
    {
        $w = $cfg['weights'];
        $hay = implode(' | ', $skills) . ' | ' . (string) ($cand['cv_text'] ?? '');
        $crit = [];

        // 1. Compétences (essentielles x2.0, bonus x1.25)
        $ess = array_values($cfg['essential_skills']);
        $bon = array_values($cfg['bonus_skills']);
        $mE = array_values(array_filter($ess, fn($s) => self::has($hay, $s)));
        $mB = array_values(array_filter($bon, fn($s) => self::has($hay, $s)));
        $max = count($ess) * $cfg['multipliers']['essential'] + count($bon) * $cfg['multipliers']['bonus'];
        $got = count($mE) * $cfg['multipliers']['essential'] + count($mB) * $cfg['multipliers']['bonus'];
        $r = $max > 0 ? min(1, $got / $max) : 1;
        $crit['skills'] = ['points' => round($w['skills'] * $r, 1), 'max' => $w['skills'],
            'note' => count($mE) . '/' . count($ess) . ' compétences essentielles' . ($mB ? ', bonus : ' . implode(', ', $mB) : '') . '.',
            'matched' => array_merge($mE, $mB)];

        // 2. Expérience vs seuil de l'offre
        $years = (float) $cand['annees_experience'];
        $req = max(0, (int) ($poste['experience'] ?? 0));
        $r = $req > 0 ? min(1, $years / $req) : 1;
        $crit['experience'] = ['points' => round($w['experience'] * $r, 1), 'max' => $w['experience'],
            'note' => rtrim(rtrim(number_format($years, 1), '0'), '.') . ' an(s) déclarés pour ' . $req . ' an(s) requis.'];

        // 3. Formation (meilleur niveau)
        $order = ['aucun', 'bac', 'licence', 'master', 'doctorat'];
        $best = 'aucun';
        foreach ($formations as $f) {
            if (array_search($f['niveau'], $order) > array_search($best, $order)) $best = $f['niveau'];
        }
        $mult = (float) $cfg['education_multipliers'][$best];
        $crit['education'] = ['points' => round($w['education'] * min(1, $mult / 1.2), 1), 'max' => $w['education'],
            'note' => 'Niveau retenu : ' . $best . ' (x' . $mult . ').'];

        // 4. Disponibilité
        $d = (int) $candidature['disponibilite_jours'];
        $r = $d <= 14 ? 1 : ($d <= 30 ? 0.9 : ($d <= 60 ? 0.6 : 0.3));
        $crit['availability'] = ['points' => round($w['availability'] * $r, 1), 'max' => $w['availability'],
            'note' => $d <= 14 ? 'Disponibilité immédiate (≤ 2 semaines).' : "Préavis de $d jours."];

        // 5. Complétude du dossier
        $fields = ['tel', 'addresse', 'linkedin', 'github', 'portfolio', 'cv_path'];
        $filled = count(array_filter($fields, fn($f) => !empty($cand[$f]))) + (!empty($candidature['lettre_motivation']) ? 1 : 0);
        $r = $filled / (count($fields) + 1);
        $crit['completeness'] = ['points' => round($w['completeness'] * $r, 1), 'max' => $w['completeness'],
            'note' => "$filled/" . (count($fields) + 1) . ' éléments renseignés.'];

        $score = (int) round(array_sum(array_column($crit, 'points')));
        $score = max(0, min(100, $score));
        $tier = $score >= $cfg['tiers']['priority'] ? 'priority' : ($score >= $cfg['tiers']['review'] ? 'review' : 'low');

        // Knock-out : signalement uniquement, la décision reste humaine
        $ko = [];
        $k = $cfg['knockouts'];
        if (!empty($k['work_authorization'])) {
            $ok = (int) $candidature['autorisation_travail'] === 1;
            $ko[] = ['key' => 'work_authorization', 'label' => 'Autorisation de travail (sans sponsoring)', 'passed' => $ok, 'detail' => $ok ? 'Validée' : 'Non confirmée'];
        }
        if (!empty($k['min_experience'])) {
            $ok = $years >= (float) $k['min_experience'];
            $ko[] = ['key' => 'min_experience', 'label' => 'Minimum ' . (int) $k['min_experience'] . ' ans d\'expérience', 'passed' => $ok, 'detail' => $ok ? 'Dépassé (' . $years . ' ans)' : 'Insuffisant (' . $years . ' ans)'];
        }
        if (!empty($k['require_cv'])) {
            $ok = !empty($cand['cv_path']);
            $ko[] = ['key' => 'require_cv', 'label' => 'CV exploitable fourni', 'passed' => $ok, 'detail' => $ok ? 'CV présent' : 'CV absent'];
        }
        $flagged = count(array_filter($ko, fn($x) => !$x['passed'])) > 0;

        return ['score' => $score, 'tier' => $tier, 'criteria' => $crit, 'knockouts' => $ko, 'flagged' => $flagged];
    }

    public static function persist(int $candidatureId, ?array $cfg = null): ?array
    {
        $cfg ??= self::config();
        $c = Db::one('SELECT * FROM candidatures WHERE id = ?', [$candidatureId]);
        if (!$c) return null;
        $cand = Db::one('SELECT * FROM candidats WHERE id = ?', [$c['candidat_id']]);
        $poste = Db::one('SELECT * FROM postes WHERE id = ?', [$c['poste_id']]);
        $skills = array_column(Db::all('SELECT nom FROM competences WHERE candidat_id = ?', [$c['candidat_id']]), 'nom');
        $form = Db::all('SELECT niveau FROM formations WHERE candidat_id = ?', [$c['candidat_id']]);
        $res = self::compute($cand, $skills, $form, $c, $poste, $cfg);
        Db::exec('UPDATE candidatures SET score = ?, tier = ?, score_detail = ? WHERE id = ?',
            [$res['score'], $res['tier'], json_encode($res, JSON_UNESCAPED_UNICODE), $candidatureId]);
        return $res;
    }

    public static function validateConfig(array $c, array &$err): array
    {
        $clean = self::DEFAULTS;
        $sum = 0;
        foreach (array_keys(self::DEFAULTS['weights']) as $k) {
            $v = $c['weights'][$k] ?? null;
            if (!is_numeric($v) || $v < 0 || $v > 100) { $err['weights'] = 'Chaque poids doit être entre 0 et 100.'; break; }
            $clean['weights'][$k] = (int) $v; $sum += (int) $v;
        }
        if (!isset($err['weights']) && $sum !== 100) $err['weights'] = "La somme des poids doit valoir 100 (actuellement $sum).";
        $p = (int) ($c['tiers']['priority'] ?? 0); $r = (int) ($c['tiers']['review'] ?? 0);
        if ($p <= $r || $r < 1 || $p > 100) $err['tiers'] = 'Seuils incohérents (priorité > revue ≥ 1).';
        $clean['tiers'] = ['priority' => $p, 'review' => $r];
        foreach (['essential_skills', 'bonus_skills'] as $k) {
            $list = array_values(array_unique(array_filter(array_map(fn($s) => trim((string) $s), (array) ($c[$k] ?? [])), fn($s) => $s !== '' && mb_strlen($s) <= 40)));
            $clean[$k] = array_slice($list, 0, 30);
        }
        $ko = (array) ($c['knockouts'] ?? []);
        $clean['knockouts'] = [
            'work_authorization' => !empty($ko['work_authorization']),
            'min_experience' => max(0, min(40, (int) ($ko['min_experience'] ?? 0))),
            'require_cv' => !empty($ko['require_cv']),
        ];
        return $clean;
    }
}
?>