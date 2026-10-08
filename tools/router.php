<?php
declare(strict_types=1);

// Development server router, not part of the deployment artifact.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/dav.php' || str_starts_with($path, '/dav.php/')) {
    require dirname(__DIR__) . '/dav.php';
} else {
    require dirname(__DIR__) . '/index.php';
}
