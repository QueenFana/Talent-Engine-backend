<?php
declare(strict_types=1);

final class AuthController
{
    public static function login(): void
    {
        $in = Http::body();
        $err = [];
        $email = Validate::email($in, $err);
        $pwd = (string) ($in['mot_de_passe'] ?? '');
        if ($err || $pwd === '' || strlen($pwd) > 200) throw new HttpException(401, 'Identifiants invalides.');
        Http::ok(Auth::login($email, $pwd), 'Connexion réussie.');
    }

    public static function logout(): void
    {
        Auth::logout();
        Http::ok(null, 'Déconnecté.');
    }

    public static function me(): void
    {
        Http::ok(Auth::pub(Auth::need()));
    }
}
?>