<?php
declare(strict_types=1);

/* Para o servidor embutido do PHP:  php -S localhost:8000 router.php */

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/app.php';
    return true;
}
if (preg_match('#^/(config(\.example)?\.php|includes/|storage/)#', $path)) {
    http_response_code(403);
    echo 'Acesso negado.';
    return true;
}
return false;
