<?php
declare(strict_types=1);

final class Http
{
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') return [];
        $d = json_decode($raw, true);
        if (!is_array($d)) throw new HttpException(400, 'Corps JSON invalide.');
        return $d;
    }

    public static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    public static function cors(): void
    {
        $origin = Config::get('CORS_ORIGIN', 'http://localhost:5173');
        $req = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($req !== '' && in_array($req, array_map('trim', explode(',', $origin)), true)) {
            header('Access-Control-Allow-Origin: ' . $req);
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        }
    }

    public static function send(int $code, string $message, mixed $data = null, array $errors = []): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        $out = ['success' => $code < 400, 'message' => $message, 'data' => $data];
        if ($errors) $out['errors'] = $errors;
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function ok(mixed $data = null, string $message = 'Opération effectuée.', int $code = 200): never
    {
        self::send($code, $message, $data);
    }

    /** Pagination bornée */
    public static function page(int $defaultPer = 10, int $max = 200): array
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = min($max, max(1, (int) ($_GET['per_page'] ?? $defaultPer)));
        return [$page, $per, ($page - 1) * $per];
    }
}
?>