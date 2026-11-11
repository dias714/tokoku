<?php
const PRODUCT_IMAGE_MAX_BYTES = 2097152;
const PRODUCT_IMAGE_MAX_DIMENSION = 6000;
const PRODUCT_IMAGE_MAX_PIXELS = 40000000;

function productImageStorageDirectory(): string
{
    return __DIR__ . '/../uploads/products';
}

function productImageUrl(array $product): string
{
    $imageName = $product['gambar'] ?? '';
    if (
        is_string($imageName)
        && preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $imageName)
    ) {
        return appUrl(
            'product_image.php?id=' . (int) ($product['id_produk'] ?? 0)
            . '&v=' . rawurlencode($imageName)
        );
    }

    return appUrl('assets/images/product-placeholder.svg');
}

function productImageFilePath(?string $imageName): ?string
{
    if (
        $imageName === null
        || !preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $imageName)
    ) {
        return null;
    }

    return productImageStorageDirectory() . DIRECTORY_SEPARATOR . $imageName;
}

function deleteProductImage(?string $imageName): void
{
    $path = productImageFilePath($imageName);
    if ($path !== null && is_file($path) && !unlink($path)) {
        error_log('Gambar produk lama tidak dapat dihapus: ' . basename($path));
    }
}

function saveProductImageUpload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Silakan pilih gambar terlebih dahulu.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $message = match ((int) $file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran gambar melebihi batas upload server.',
            UPLOAD_ERR_PARTIAL => 'Gambar hanya terunggah sebagian. Silakan coba kembali.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server tidak dapat menyimpan file sementara.',
            default => 'Upload gambar gagal. Silakan coba kembali.',
        };
        throw new RuntimeException($message);
    }

    $temporaryPath = $file['tmp_name'] ?? '';
    $fileSize = (int) ($file['size'] ?? 0);
    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    if (!is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('File gambar tidak berasal dari proses upload yang valid.');
    }
    if ($fileSize < 1 || $fileSize > PRODUCT_IMAGE_MAX_BYTES) {
        throw new RuntimeException('Ukuran gambar maksimal 2 MB.');
    }
    if (!isset($allowedTypes[$extension])) {
        throw new RuntimeException('Format gambar harus JPG, JPEG, PNG, atau WEBP.');
    }
    if (!class_exists('finfo') || !function_exists('getimagesize')) {
        throw new RuntimeException('Validasi gambar tidak tersedia pada konfigurasi PHP server.');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);
    $imageInfo = getimagesize($temporaryPath);
    if (
        $mimeType !== $allowedTypes[$extension]
        || $imageInfo === false
        || ($imageInfo['mime'] ?? '') !== $mimeType
    ) {
        throw new RuntimeException('Isi file tidak cocok dengan format gambar yang diizinkan.');
    }

    $width = (int) $imageInfo[0];
    $height = (int) $imageInfo[1];
    if (
        $width < 1
        || $height < 1
        || $width > PRODUCT_IMAGE_MAX_DIMENSION
        || $height > PRODUCT_IMAGE_MAX_DIMENSION
        || $width * $height > PRODUCT_IMAGE_MAX_PIXELS
    ) {
        throw new RuntimeException('Dimensi gambar terlalu besar untuk diproses dengan aman.');
    }
    if (!extension_loaded('gd')) {
        throw new RuntimeException('Ekstensi GD harus aktif pada PHP server untuk memproses gambar.');
    }

    $decoder = match ($mimeType) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        default => null,
    };
    if ($decoder === null || !function_exists($decoder)) {
        throw new RuntimeException('Format gambar ini tidak didukung oleh ekstensi GD server.');
    }

    $image = @$decoder($temporaryPath);
    if ($image === false) {
        throw new RuntimeException('Gambar tidak dapat dibaca. Pilih file gambar yang valid.');
    }

    $storageDirectory = productImageStorageDirectory();
    if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0750, true) && !is_dir($storageDirectory)) {
        imagedestroy($image);
        throw new RuntimeException('Folder penyimpanan gambar tidak dapat dibuat.');
    }

    $safeExtension = match ($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    };
    $imageName = bin2hex(random_bytes(16)) . '.' . $safeExtension;
    $destination = $storageDirectory . DIRECTORY_SEPARATOR . $imageName;

    try {
        if ($mimeType === 'image/png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $saved = imagepng($image, $destination, 7);
        } elseif ($mimeType === 'image/webp') {
            $saved = imagewebp($image, $destination, 85);
        } else {
            $saved = imagejpeg($image, $destination, 88);
        }
    } finally {
        imagedestroy($image);
    }

    if (!$saved || !is_file($destination)) {
        if (is_file($destination)) {
            unlink($destination);
        }
        throw new RuntimeException('Gambar yang sudah divalidasi tidak dapat disimpan.');
    }

    return $imageName;
}
