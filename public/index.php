<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Controllers/PosteController.php';
require_once __DIR__ . '/../src/Controllers/CvController.php';
require_once __DIR__ . '/../src/Controllers/CandidatureController.php';
require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Controllers/DashboardController.php';
require_once __DIR__ . '/../src/Controllers/ScoringController.php';
require_once __DIR__ . '/../src/Controllers/AdminController.php';



$root = dirname(__DIR__);
spl_autoload_register(function (string $c) use ($root) {
    foreach (["$root/src/$c.php", "$root/src/Controllers/$c.php"] as $f) { if (is_file($f)) { require $f; return; } }
});
if (is_file("$root/vendor/autoload.php")) require "$root/vendor/autoload.php";

// Load core classes explicitly to satisfy static analysis and ensure runtime availability.
if (is_file("$root/src/Config.php")) require_once "$root/src/Config.php";
if (is_file("$root/src/Http.php")) require_once "$root/src/Http.php";

if (!class_exists('HttpException', false)) {
    class HttpException extends \Exception {
        public int $status;
        public array $errors;

        public function __construct(int $status, string $message = '', array $errors = [], ?\Throwable $previous = null) {
            $this->status = $status;
            $this->errors = $errors;
            parent::__construct($message, 0, $previous);
        }
    }
}

// Serveur interne PHP : laisser passer les fichiers statiques éventuels
Config::load("$root/.env");
Http::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
header('X-Content-Type-Options: nosniff');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$pos = strpos($path, '/api');
$path = '/' . trim($pos === false ? $path : substr($path, $pos + 4), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$routes = [
    ['GET',   '#^/postes$#',                          static fn() => call_user_func('PosteController::index')],
    ['GET',   '#^/postes/(\d+)$#',                    static fn($id) => call_user_func('PosteController::show', $id)],
    ['POST',  '#^/postes$#',                          static fn() => call_user_func('PosteController::create')],
    ['PUT',   '#^/postes/(\d+)$#',                    static fn($id) => call_user_func('PosteController::update', $id)],
    ['PATCH', '#^/postes/(\d+)$#',                    static fn($id) => call_user_func('PosteController::update', $id)],
    ['POST',  '#^/postes/(\d+)/archive$#',            static fn($id) => call_user_func('PosteController::archive', $id)],
    ['POST',  '#^/cv/analyze$#',                      static fn() => call_user_func('CvController::analyze')],
    ['POST',  '#^/candidatures$#',                    static fn() => call_user_func('CandidatureController::submit')],
    ['POST',  '#^/auth/login$#',                      static fn() => call_user_func('AuthController::login')],
    ['POST',  '#^/auth/logout$#',                     static fn() => call_user_func('AuthController::logout')],
    ['GET',   '#^/auth/me$#',                         static fn() => call_user_func('AuthController::me')],
    ['GET',   '#^/recruteur/postes$#',                static fn() => call_user_func('PosteController::mine')],
    ['GET',   '#^/recruteur/candidatures$#',          static fn() => call_user_func('CandidatureController::index')],
    ['GET',   '#^/recruteur/dashboard$#',             static fn() => call_user_func('DashboardController::index')],
    ['GET',   '#^/candidatures/(\d+)$#',              static fn($id) => call_user_func('CandidatureController::show', $id)],
    ['GET',   '#^/candidatures/(\d+)/cv$#',           static fn($id) => call_user_func('CandidatureController::cv', $id)],
    ['PATCH', '#^/candidatures/(\d+)/statut$#',       static fn($id) => call_user_func('CandidatureController::updateStatut', $id)],
    ['POST',  '#^/candidatures/(\d+)/notes$#',        static fn($id) => call_user_func('CandidatureController::addNote', $id)],
    ['GET',   '#^/scoring-rules$#',                   static fn() => call_user_func('ScoringController::show')],
    ['PUT',   '#^/scoring-rules$#',                   static fn() => call_user_func('ScoringController::update')],
    ['POST',  '#^/scoring-rules/recalculate$#',       static fn() => call_user_func('ScoringController::recalculate')],
    ['GET',   '#^/admin/utilisateurs$#',              static fn() => call_user_func('AdminController::users')],
    ['POST',  '#^/admin/utilisateurs$#',              static fn() => call_user_func('AdminController::create')],
    ['PATCH', '#^/admin/utilisateurs/(\d+)$#',        static fn($id) => call_user_func('AdminController::update', $id)],
];

try {
    $pathMatched = false;
    foreach ($routes as [$m, $re, $h]) {
        if (preg_match($re, $path, $mm)) {
            $pathMatched = true;
            if ($m === $method) { array_shift($mm); $h(...$mm); }
        }
    }
    throw $pathMatched ? new HttpException(405, 'Méthode non autorisée.') : new HttpException(404, 'Route introuvable.');
} catch (HttpException $e) {
    Http::send($e->status, $e->getMessage(), null, $e->errors);
} catch (Throwable $e) {
    error_log('[' . get_class($e) . '] ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    Http::send(500, 'Erreur interne du serveur.', null, Config::debug() ? ['debug' => $e->getMessage()] : []);
}
?>