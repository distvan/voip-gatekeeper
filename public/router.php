<?php

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if (is_string($requestPath)) {
        $publicFile = __DIR__ . $requestPath;

        if ($requestPath !== '/' && is_file($publicFile)) {
            return false;
        }
    }
}

require_once __DIR__ . '/index.php';
