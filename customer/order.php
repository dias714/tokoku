<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('pelanggan');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/product_images.php';
require_once __DIR__ . '/../includes/store.php';

$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$orderStmt = $pdo->prepare(
    'SELECT t.id_transaksi, t.tanggal, t.total_belanja,
            p.nama_pelanggan, p.kota
     FROM tbl_transaksi t
     INNER JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
     WHERE t.id_transaksi = :id_transaksi AND p.id_user = :id_user
     LIMIT 1'
);
$orderStmt->execute([
    'id_transaksi' => $orderId && $orderId > 0 ? $orderId : 0,
    'id_user' => (int) $_SESSION['user_id'],
]);
$order = $orderStmt->fetch();
if (!$order) {
    http_response_code(404);
}
$items = [];
if ($order) {
    $itemStmt = $pdo->prepare(
        'SELECT d.nama_produk, d.jumlah, d.harga, p.gambar,
                (COALESCE(d.jumlah, 0) * COALESCE(d.harga, 0)) AS subtotal,
                d.id_produk
         FROM tbl_detail_transaksi d
         LEFT JOIN tbl_produk p ON p.id_produk = d.id_produk
         WHERE d.id_transaksi = :id_transaksi
         ORDER BY d.id_detail'
    );
    $itemStmt->execute(['id_transaksi' => (int) $order['id_transaksi']]);
    $items = $itemStmt->fetchAll();
}
$pageTitle = $order
    ? 'Detail TRX-' . str_pad((string) $order['id_transaksi'], 3, '0', STR_PAD_LEFT) . ' - Tokoku'
    : 'Pesanan tidak ditemukan - Tokoku';
$success = $_SESSION['order_flash'] ?? '';
unset($_SESSION['order_flash']);

require_once __DIR__ . '/../includes/header.php';
?>
<main class="store-page">
    <?php customerStoreHeader('orders'); ?>
    <div class="store-page-heading"><div><span class="welcome-label">TOKOKU · PESANAN</span><h1><?= $order ? 'Detail TRX-' . str_pad((string) $order['id_transaksi'], 3, '0', STR_PAD_LEFT) : 'Pesanan tidak ditemukan' ?></h1><p><?= $order && $order['tanggal'] ? e(date('d/m/Y', strtotime($order['tanggal']))) . ' · ' . e($order['nama_pelanggan']) . ' · ' . e($order['kota'] ?? '') : 'Pesanan ini tidak tersedia pada akun Anda.' ?></p></div><a class="user-btn user-btn-light" href="<?= e(appUrl('customer/orders.php')) ?>">Kembali ke Pesanan</a></div>
    <?php if ($success): ?><div class="user-alert user-alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
    <?php if ($order): ?>
        <section class="admin-panel order-detail-panel">
            <div class="panel-heading"><div><h2>Produk Pesanan</h2><p><?= number_format(array_sum(array_column($items, 'jumlah'))) ?> item · status Tercatat</p></div></div>
            <div class="order-detail-items">
                <?php foreach ($items as $item): ?>
                    <article class="order-detail-item">
                        <img src="<?= e(productImageUrl($item)) ?>" alt="<?= e($item['nama_produk'] ?? 'Produk') ?>">
                        <div><strong><?= e($item['nama_produk'] ?? 'Produk tidak diketahui') ?></strong><span><?= number_format((int) $item['jumlah']) ?> × Rp <?= e(number_format((float) $item['harga'], 0, ',', '.')) ?></span></div>
                        <strong>Rp <?= e(number_format((float) $item['subtotal'], 0, ',', '.')) ?></strong>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="transaction-total"><span>Total pesanan</span><strong>Rp <?= e(number_format((float) $order['total_belanja'], 0, ',', '.')) ?></strong></div>
        </section>
    <?php endif; ?>
    <footer class="store-footer">© <?= date('Y') ?> Tokoku</footer>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
