<?php
declare(strict_types=1);

final class Validate
{
    public static function text(array $in, string $key, string $label, int $min, int $max, array &$err): string
    {
        $v = $in[$key] ?? '';
        $v = is_string($v) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? '') : '';
        $len = mb_strlen($v);
        if ($len < $min) $err[$key] = $min > 0 ? "$label est obligatoire." : '';
        elseif ($len > $max) $err[$key] = "$label dépasse $max caractères.";
        if (($err[$key] ?? null) === '') unset($err[$key]);
        return $v;
    }

    public static function email(array $in, array &$err): string
    {
        $v = strtolower(trim((string) ($in['email'] ?? '')));
        if (!filter_var($v, FILTER_VALIDATE_EMAIL) || strlen($v) > 190) $err['email'] = 'Adresse e-mail invalide.';
        return $v;
    }

    public static function url(array $in, string $key, array &$err): ?string
    {
        $v = trim((string) ($in[$key] ?? ''));
        if ($v === '') return null;
        if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
        if (!filter_var($v, FILTER_VALIDATE_URL) || strlen($v) > 255) { $err[$key] = 'URL invalide.'; return null; }
        return $v;
    }

    public static function oneOf(mixed $v, array $allowed): bool
    {
        return is_string($v) && in_array($v, $allowed, true);
    }

    public static function date(?string $v): bool
    {
        if ($v === null || $v === '') return true;
        $d = DateTime::createFromFormat('Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v;
    }

    public static function fail(array $err): void
    {
        if ($err) throw new HttpException(422, 'Données invalides.', $err);
    }
}
?>