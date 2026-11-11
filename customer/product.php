<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('pelanggan');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/product_images.php';
require_once __DIR__ . '/../includes/store.php';

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = $pdo->prepare(
    'SELECT p.id_produk, p.nama_produk, p.deskripsi, p.harga, p.stok,
            p.gambar, k.nama_kategori
     FROM tbl_produk p
     LEFT JOIN tbl_kategori k ON k.id_kategori = p.id_kategori
     WHERE p.id_produk = :id_produk LIMIT 1'
);
$stmt->execute(['id_produk' => $productId && $productId > 0 ? $productId : 0]);
$product = $stmt->fetch();
if (!$product) {
    http_response_code(404);
}
$pageTitle = $product ? $product['nama_produk'] . ' - Tokoku' : 'Produk tidak ditemukan - Tokoku';
$success = $_SESSION['cart_flash'] ?? '';
$error = $_SESSION['cart_error'] ?? '';
unset($_SESSION['cart_flash'], $_SESSION['cart_error']);

require_once __DIR__ . '/../includes/header.php';
?>
<main class="store-page">
    <?php customerStoreHeader('shop'); ?>
    <div class="store-breadcrumb"><a href="<?= e(appUrl('customer/index.php')) ?>">Belanja</a><span>/</span><span><?= e($product['nama_produk'] ?? 'Produk tidak ditemukan') ?></span></div>
    <?php if ($success): ?><div class="user-alert user-alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="user-alert user-alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($product): ?>
        <article class="store-product-detail">
            <div class="store-detail-image-wrap"><img class="store-detail-image" src="<?= e(productImageUrl($product)) ?>" alt="<?= e($product['nama_produk']) ?>"></div>
            <div class="store-detail-content">
                <span class="store-product-category"><?= e($product['nama_kategori'] ?? 'Tanpa kategori') ?></span>
                <h1><?= e($product['nama_produk']) ?></h1>
                <strong class="store-detail-price">Rp <?= e(number_format((float) $product['harga'], 0, ',', '.')) ?></strong>
                <span class="store-product-stock <?= (int) $product['stok'] > 0 ? 'is-available' : 'is-empty' ?>"><?= (int) $product['stok'] > 0 ? 'Stok tersedia · ' . number_format((int) $product['stok']) : 'Stok habis' ?></span>
                <div class="store-detail-description"><?= $product['deskripsi'] !== null && trim($product['deskripsi']) !== '' ? nl2br(e($product['deskripsi'])) : 'Belum ada deskripsi untuk produk ini.' ?></div>
                <form method="post" action="<?= e(appUrl('customer/cart.php')) ?>" class="store-detail-add-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                    <label class="store-quantity-field">Jumlah<input type="number" name="quantity" min="1" max="<?= (int) $product['stok'] ?>" value="1" required></label>
                    <button class="user-btn user-btn-primary" type="submit" <?= (int) $product['stok'] < 1 ? 'disabled' : '' ?>>Tambahkan ke Keranjang</button>
                </form>
                <a class="store-back-link" href="<?= e(appUrl('customer/index.php')) ?>">← Kembali ke katalog</a>
            </div>
        </article>
    <?php else: ?>
        <div class="store-empty-state"><img src="<?= e(appUrl('assets/images/product-placeholder.svg')) ?>" alt=""><h1>Produk tidak ditemukan</h1><a class="user-btn user-btn-primary" href="<?= e(appUrl('customer/index.php')) ?>">Kembali ke katalog</a></div>
    <?php endif; ?>
    <footer class="store-footer">© <?= date('Y') ?> Tokoku</footer>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
