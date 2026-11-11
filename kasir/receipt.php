<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('kasir');
require_once __DIR__ . '/../config/database.php';

$transactionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$receiptStmt = $pdo->prepare(
    'SELECT t.id_transaksi, t.tanggal, t.total_belanja, t.metode_pembayaran,
            t.uang_diterima, t.kembalian, p.nama_pelanggan,
            u.name AS nama_kasir
     FROM tbl_transaksi t
     LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
     LEFT JOIN users u ON u.id = t.id_kasir
     WHERE t.id_transaksi = :id_transaksi AND t.id_kasir = :id_kasir
     LIMIT 1'
);
$receiptStmt->execute([
    'id_transaksi' => $transactionId && $transactionId > 0 ? $transactionId : 0,
    'id_kasir' => (int) $_SESSION['user_id'],
]);
$receipt = $receiptStmt->fetch();

if (!$receipt) {
    http_response_code(404);
}

$items = [];
if ($receipt) {
    $itemStmt = $pdo->prepare(
        'SELECT nama_produk, jumlah, harga,
                (COALESCE(jumlah, 0) * COALESCE(harga, 0)) AS subtotal
         FROM tbl_detail_transaksi
         WHERE id_transaksi = :id_transaksi
         ORDER BY id_detail'
    );
    $itemStmt->execute(['id_transaksi' => (int) $receipt['id_transaksi']]);
    $items = $itemStmt->fetchAll();
}

$pageTitle = $receipt
    ? 'Nota TRX-' . str_pad((string) $receipt['id_transaksi'], 3, '0', STR_PAD_LEFT) . ' - Tokoku'
    : 'Nota tidak ditemukan - Tokoku';
require_once __DIR__ . '/../includes/header.php';
?>
<main class="receipt-page" <?= $receipt ? 'data-printable="true"' : '' ?>>
    <div class="receipt-actions">
        <a class="user-btn user-btn-light" href="<?= e(appUrl('kasir/dashboard.php')) ?>">Kembali ke Dashboard</a>
        <?php if ($receipt): ?><button class="user-btn user-btn-primary" type="button" data-print-receipt>Cetak Nota</button><?php endif; ?>
    </div>
    <?php if ($receipt): ?>
        <p class="receipt-print-help">Gunakan kertas roll 50 mm. Di pengaturan printer pilih ukuran 50 mm/custom, skala 100%, margin tanpa batas, dan matikan header/footer browser.</p>
    <?php endif; ?>
    <?php if (!$receipt): ?>
        <section class="admin-panel cashier-receipt receipt-not-found">
            <h1>Nota tidak ditemukan</h1>
            <p>Nota tidak tersedia atau bukan transaksi yang dibuat oleh akun kasir ini.</p>
        </section>
    <?php else: ?>
        <article class="cashier-receipt">
            <header class="receipt-header">
                <span class="receipt-brand-mark">T</span>
                <div><h1>TOKOKU</h1><p>Nota Pembayaran</p></div>
            </header>
            <div class="receipt-rule"></div>
            <div class="receipt-meta">
                <div><span>Nomor transaksi</span><strong>TRX-<?= str_pad((string) $receipt['id_transaksi'], 3, '0', STR_PAD_LEFT) ?></strong></div>
                <div><span>Tanggal</span><strong><?= $receipt['tanggal'] ? e(date('d/m/Y', strtotime($receipt['tanggal']))) : '-' ?></strong></div>
                <div><span>Kasir</span><strong><?= e($receipt['nama_kasir'] ?? $_SESSION['name'] ?? 'Kasir') ?></strong></div>
                <div><span>Pelanggan</span><strong><?= e($receipt['nama_pelanggan'] ?: 'Pelanggan umum') ?></strong></div>
            </div>
            <div class="receipt-rule"></div>
            <table class="receipt-items">
                <thead><tr><th>Produk</th><th>Qty</th><th>Harga</th><th>Subtotal</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= e($item['nama_produk'] ?? 'Produk') ?></td>
                        <td><?= number_format((int) $item['jumlah']) ?></td>
                        <td>Rp <?= e(number_format((float) $item['harga'], 0, ',', '.')) ?></td>
                        <td>Rp <?= e(number_format((float) $item['subtotal'], 0, ',', '.')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="receipt-rule"></div>
            <div class="receipt-totals">
                <div><span>Total</span><strong>Rp <?= e(number_format((float) $receipt['total_belanja'], 0, ',', '.')) ?></strong></div>
                <div><span>Metode pembayaran</span><strong><?= e($receipt['metode_pembayaran'] ?? 'Tunai') ?></strong></div>
                <div><span>Uang diterima</span><strong>Rp <?= e(number_format((float) $receipt['uang_diterima'], 0, ',', '.')) ?></strong></div>
                <div class="receipt-change"><span>Kembalian</span><strong>Rp <?= e(number_format((float) $receipt['kembalian'], 0, ',', '.')) ?></strong></div>
            </div>
            <div class="receipt-rule"></div>
            <footer class="receipt-footer"><strong>Terima kasih telah berbelanja</strong><span>Barang yang sudah dibeli dapat ditukar sesuai kebijakan toko.</span></footer>
        </article>
    <?php endif; ?>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
