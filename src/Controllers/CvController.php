<?php
declare(strict_types=1);

final class CvController
{
    public static function analyze(): void
    {
        if (Audit::count('cv_analyze', 60) >= 10) throw new HttpException(429, 'Limite d\'analyses atteinte. Réessayez plus tard ou remplissez le formulaire manuellement.');
        Audit::log('cv_analyze');
        Http::ok(CvAnalyzer::analyze($_FILES['cv'] ?? null), 'Analyse terminée.');
    }
}
?>