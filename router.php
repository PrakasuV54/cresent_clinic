<?php
/**
 * Router script for PHP built-in server
 */
$uri = decodeURI($_SERVER['REQUEST_URI']);
$path = parse_url($uri, PHP_URL_PATH);

function decodeURI($uri) {
    return rawurldecode($uri);
}

// Serve static files directly
if (preg_match('/\.(?:png|jpg|jpeg|gif|css|js|svg|pdf)$/', $path)) {
    $filePath = __DIR__ . $path;
    if (file_exists($filePath)) {
        // Simple mime type detection
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $mimes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf'
        ];
        if (isset($mimes[$extension])) {
            header("Content-Type: " . $mimes[$extension]);
        }
        readfile($filePath);
        return true;
    }
}

// Otherwise, route to index.php
require_once __DIR__ . '/index.php';
