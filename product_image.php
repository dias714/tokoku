<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product_images.php';
require_once __DIR__ . '/config/database.php';

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$productId || $productId < 1) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare('SELECT gambar FROM tbl_produk WHERE id_produk = :id_produk LIMIT 1');
$stmt->execute(['id_produk' => $productId]);
$imageName = $stmt->fetchColumn();
$path = is_string($imageName) ? productImageFilePath($imageName) : null;
if ($path === null || !is_file($path)) {
    $placeholder = __DIR__ . '/assets/images/product-placeholder.svg';
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');
    readfile($placeholder);
    exit;
}

$mimeTypes = [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];
$extension = pathinfo($path, PATHINFO_EXTENSION);
if (!isset($mimeTypes[$extension])) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');
readfile($path);
