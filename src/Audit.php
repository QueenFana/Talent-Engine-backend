<?php
declare(strict_types=1);

final class Audit
{
    public static function log(string $action, ?string $ressource = null, ?int $id = null, ?int $userId = null): void
    {
        try {
            Db::exec('INSERT INTO journaux_audit (utilisateur_id, action, ressource, ressource_id, adresse_ip) VALUES (?,?,?,?,?)',
                [$userId, $action, $ressource !== null ? substr($ressource, 0, 190) : null, $id, Http::ip()]);
        } catch (Throwable $e) {
            error_log('audit: ' . $e->getMessage());
        }
    }

    /** Nombre d'événements récents (limitation de débit) */
    public static function count(string $action, int $minutes, ?string $ressource = null): int
    {
        $sql = 'SELECT COUNT(*) FROM journaux_audit WHERE action = ? AND created_at >= (NOW() - INTERVAL ' . (int) $minutes . ' MINUTE) AND ';
        if ($ressource !== null) {
            return (int) Db::val($sql . '(adresse_ip = ? OR ressource = ?)', [$action, Http::ip(), $ressource]);
        }
        return (int) Db::val($sql . 'adresse_ip = ?', [$action, Http::ip()]);
    }
}
?>
