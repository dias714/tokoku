<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('pelanggan');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/product_images.php';
require_once __DIR__ . '/../includes/store.php';

$pageTitle = 'Pesanan Saya - Tokoku';
$stmt = $pdo->prepare(
    'SELECT t.id_transaksi, t.tanggal, t.total_belanja,
            COUNT(d.id_detail) AS jumlah_baris,
            COALESCE(SUM(d.jumlah), 0) AS jumlah_item
     FROM tbl_transaksi t
     INNER JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
     LEFT JOIN tbl_detail_transaksi d ON d.id_transaksi = t.id_transaksi
     WHERE p.id_user = :id_user
     GROUP BY t.id_transaksi, t.tanggal, t.total_belanja
     ORDER BY t.tanggal DESC, t.id_transaksi DESC'
);
$stmt->execute(['id_user' => (int) $_SESSION['user_id']]);
$orders = $stmt->fetchAll();
$success = $_SESSION['order_flash'] ?? '';
unset($_SESSION['order_flash']);

require_once __DIR__ . '/../includes/header.php';
?>
<main class="store-page">
    <?php customerStoreHeader('orders'); ?>
    <div class="store-page-heading"><div><span class="welcome-label">TOKOKU · AKUN</span><h1>Pesanan Saya</h1><p>Riwayat transaksi yang terhubung ke akun pelanggan Anda.</p></div></div>
    <?php if ($success): ?><div class="user-alert user-alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
    <?php if (!$orders): ?>
        <div class="store-empty-state"><img src="<?= e(appUrl('assets/images/product-placeholder.svg')) ?>" alt=""><h2>Belum ada pesanan</h2><p>Pesanan yang Anda buat akan muncul di sini.</p><a class="user-btn user-btn-primary" href="<?= e(appUrl('customer/index.php')) ?>">Mulai Belanja</a></div>
    <?php else: ?>
        <section class="admin-panel order-history-panel">
            <div class="table-wrap">
                <table class="admin-table"><thead><tr><th>Nomor Pesanan</th><th>Tanggal</th><th>Jumlah Item</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><strong>TRX-<?= str_pad((string) $order['id_transaksi'], 3, '0', STR_PAD_LEFT) ?></strong></td>
                        <td><?= $order['tanggal'] ? e(date('d/m/Y', strtotime($order['tanggal']))) : '-' ?></td>
                        <td><?= number_format((int) $order['jumlah_item']) ?></td>
                        <td>Rp <?= e(number_format((float) $order['total_belanja'], 0, ',', '.')) ?></td>
                        <td><span class="role-badge role-pelanggan">Tercatat</span></td>
                        <td><a class="user-action-edit" href="<?= e(appUrl('customer/order.php?id=' . (int) $order['id_transaksi'])) ?>">Lihat Detail</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        </section>
    <?php endif; ?>
    <footer class="store-footer">© <?= date('Y') ?> Tokoku</footer>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
