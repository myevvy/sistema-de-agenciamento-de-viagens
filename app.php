<?php
declare(strict_types=1);

/*
 * Porta de entrada protegida do index.php original: exige login e executa o index.php sem alterá-lo.
 * O .htaccess (Apache/XAMPP) ou o router.php (php -S) direcionam as requisições de index.php para cá.
 */

require __DIR__ . '/includes/auth.php';

authStart();
$authUser = authRequireInternal();
$authCsrf = authCsrfToken();
session_write_close();               // o index.php abre a sessão novamente

ob_start(static fn(string $html): string => authDecorateLegacyHtml($html, $authUser, $authCsrf));
require __DIR__ . '/index.php';
