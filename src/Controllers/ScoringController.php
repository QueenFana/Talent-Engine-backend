<?php
declare(strict_types=1);

final class ScoringController
{
    public static function show(): void
    {
        Auth::need(['recruteur', 'admin']);
        Http::ok(Scoring::config());
    }

    public static function update(): void
    {
        $u = Auth::need(['admin']);
        $err = [];
        $cfg = Scoring::validateConfig(Http::body(), $err);
        Validate::fail($err);
        Db::exec('INSERT INTO regles_scoring (id, config, updated_by) VALUES (1,?,?) ON DUPLICATE KEY UPDATE config = VALUES(config), updated_by = VALUES(updated_by)',
            [json_encode($cfg, JSON_UNESCAPED_UNICODE), $u['id']]);
        Audit::log('scoring_update', 'regles', 1, (int) $u['id']);
        Http::ok($cfg, 'Règles enregistrées. Pensez à recalculer les scores.');
    }

    public static function recalculate(): void
    {
        $u = Auth::need(['admin']);
        $cfg = Scoring::config();
        $n = 0;
        foreach (Db::all('SELECT id FROM candidatures') as $r) { Scoring::persist((int) $r['id'], $cfg); $n++; }
        Audit::log('scoring_recalc', (string) $n, null, (int) $u['id']);
        Http::ok(['recalcules' => $n], "$n candidature(s) recalculée(s).");
    }
}
?>