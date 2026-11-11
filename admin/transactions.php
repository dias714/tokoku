<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/product_images.php';

$pageTitle = 'Transaksi Penjualan - Tokoku';
$errors = [];
$search = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));
$viewId = filter_var($_GET['view'] ?? null, FILTER_VALIDATE_INT);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;

foreach (['from' => $dateFrom, 'to' => $dateTo] as $label => $dateValue) {
    if ($dateValue !== '') {
        $parsedDate = DateTime::createFromFormat('!Y-m-d', $dateValue);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateValue) {
            $errors[] = 'Format tanggal filter tidak valid.';
            break;
        }
    }
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $errors[] = 'Tanggal awal tidak boleh melewati tanggal akhir.';
}

$where = $errors ? ['1 = 0'] : [];
$params = [];
if ($search !== '') {
    $where[] = '(CAST(t.id_transaksi AS CHAR) LIKE :id_search OR p.nama_pelanggan LIKE :customer_search)';
    $params['id_search'] = '%' . $search . '%';
    $params['customer_search'] = '%' . $search . '%';
}
if (!$errors && $dateFrom !== '') {
    $where[] = 't.tanggal >= :date_from';
    $params['date_from'] = $dateFrom;
}
if (!$errors && $dateTo !== '') {
    $where[] = 't.tanggal <= :date_to';
    $params['date_to'] = $dateTo;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM tbl_transaksi t LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan'
    . $whereSql
);
$countStmt->execute($params);
$totalTransactions = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalTransactions / $perPage));
$page = min($page, $totalPages);
$listStmt = $pdo->prepare(
    'SELECT t.id_transaksi, t.tanggal, t.total_belanja,
            p.nama_pelanggan, u.name AS nama_kasir, COUNT(d.id_detail) AS jumlah_item
     FROM tbl_transaksi t
     LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
     LEFT JOIN users u ON u.id = t.id_kasir
     LEFT JOIN tbl_detail_transaksi d ON d.id_transaksi = t.id_transaksi'
     . $whereSql .
    ' GROUP BY t.id_transaksi, t.tanggal, t.total_belanja, p.nama_pelanggan, u.name
     ORDER BY t.tanggal DESC, t.id_transaksi DESC
     LIMIT :limit OFFSET :offset'
);
foreach ($params as $key => $value) {
    $listStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$listStmt->execute();
$transactions = $listStmt->fetchAll();

$transactionDetail = null;
$details = [];
if ($viewId && $viewId > 0) {
    $detailHeader = $pdo->prepare(
        'SELECT t.id_transaksi, t.tanggal, t.total_belanja, t.id_pelanggan,
                t.metode_pembayaran, t.uang_diterima, t.kembalian,
                p.nama_pelanggan, u.name AS nama_kasir
         FROM tbl_transaksi t
         LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
         LEFT JOIN users u ON u.id = t.id_kasir
         WHERE t.id_transaksi = :id_transaksi LIMIT 1'
    );
    $detailHeader->execute(['id_transaksi' => $viewId]);
    $transactionDetail = $detailHeader->fetch() ?: null;
    if ($transactionDetail) {
        $detailStmt = $pdo->prepare(
            'SELECT d.id_detail, d.id_produk, d.nama_produk, d.jumlah, d.harga, p.gambar,
                    (COALESCE(jumlah, 0) * COALESCE(harga, 0)) AS subtotal
             FROM tbl_detail_transaksi d
             LEFT JOIN tbl_produk p ON p.id_produk = d.id_produk
             WHERE d.id_transaksi = :id_transaksi
             ORDER BY d.id_detail'
        );
        $detailStmt->execute(['id_transaksi' => $viewId]);
        $details = $detailStmt->fetchAll();
    } else {
        $errors[] = 'Transaksi yang dipilih tidak ditemukan.';
    }
}

$paginationQuery = array_filter(
    ['q' => $search, 'from' => $dateFrom, 'to' => $dateTo],
    static fn (string $value): bool => $value !== ''
);
require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
    <?php $activeAdminPage = 'transactions'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    <main class="admin-main">
        <header class="admin-topbar">
            <div><div class="topbar-eyebrow">TOKOKU / ADMIN</div><h1>Transaksi Penjualan</h1><p class="topbar-subtitle">Pantau transaksi dan lihat rincian pembelian pelanggan.</p></div>
            <div class="topbar-profile"><div class="profile-avatar"><?= e(strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1))) ?></div><div><strong><?= e($_SESSION['name'] ?? 'Admin') ?></strong><span>Administrator</span></div></div>
        </header>
        <?php foreach ($errors as $error): ?><div class="user-alert user-alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>

        <?php if ($transactionDetail): ?>
            <section class="admin-panel transaction-detail-panel" id="detail">
                <div class="panel-heading">
                    <div><h2>Detail TRX-<?= str_pad((string) $transactionDetail['id_transaksi'], 3, '0', STR_PAD_LEFT) ?></h2><p><?= $transactionDetail['tanggal'] ? e(date('d/m/Y', strtotime($transactionDetail['tanggal']))) : 'Tanggal tidak tersedia' ?> · <?= e($transactionDetail['nama_pelanggan'] ?? 'Pelanggan tidak diketahui') ?></p></div>
                    <a class="user-btn user-btn-light" href="<?= e(appUrl('admin/transactions.php' . ($paginationQuery ? '?' . http_build_query($paginationQuery) : ''))) ?>">Kembali ke transaksi</a>
                </div>
                <div class="table-wrap">
                    <table class="admin-table"><thead><tr><th>Produk</th><th>Jumlah</th><th>Harga Satuan</th><th>Subtotal</th></tr></thead><tbody>
                    <?php if (!$details): ?><tr><td colspan="4" class="table-empty">Tidak ada rincian produk untuk transaksi ini.</td></tr><?php else: foreach ($details as $detail): ?>
                        <tr><td><div class="table-user"><img class="product-table-image" src="<?= e(productImageUrl($detail)) ?>" alt="" loading="lazy"><strong><?= e($detail['nama_produk'] ?? 'Produk tidak diketahui') ?></strong></div></td><td><?= number_format((int) $detail['jumlah']) ?></td><td>Rp <?= e(number_format((float) $detail['harga'], 0, ',', '.')) ?></td><td>Rp <?= e(number_format((float) $detail['subtotal'], 0, ',', '.')) ?></td></tr>
                    <?php endforeach; endif; ?>
                    </tbody></table>
                </div>
                <div class="transaction-total"><span>Total transaksi</span><strong>Rp <?= e(number_format((float) $transactionDetail['total_belanja'], 0, ',', '.')) ?></strong></div>
                <?php if ($transactionDetail['id_pelanggan'] !== null || $transactionDetail['metode_pembayaran'] !== null): ?>
                    <div class="cashier-receipt-admin-meta">
                        <span>Pelanggan: <?= e($transactionDetail['nama_pelanggan'] ?? 'Pelanggan umum') ?></span>
                        <span>Kasir: <?= e($transactionDetail['nama_kasir'] ?? 'Tidak tercatat') ?></span>
                        <span>Pembayaran: <?= e($transactionDetail['metode_pembayaran'] ?? 'Tidak tercatat') ?></span>
                        <?php if ($transactionDetail['uang_diterima'] !== null): ?><span>Uang diterima: Rp <?= e(number_format((float) $transactionDetail['uang_diterima'], 0, ',', '.')) ?></span><?php endif; ?>
                        <?php if ($transactionDetail['kembalian'] !== null): ?><span>Kembalian: Rp <?= e(number_format((float) $transactionDetail['kembalian'], 0, ',', '.')) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <p class="product-schema-note transaction-schema-note">Status pesanan terpisah belum tersedia pada skema transaksi.</p>
            </section>
        <?php endif; ?>

        <section class="admin-panel transaction-list-panel">
            <div class="panel-heading"><div><h2>Daftar Transaksi</h2><p>Data transaksi dan pelanggan dari database.</p></div><span class="panel-count"><?= number_format($totalTransactions) ?> transaksi</span></div>
            <form method="get" class="user-filter-form transaction-filter-form">
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cari ID atau nama pelanggan..." aria-label="Cari ID atau nama pelanggan">
                <input type="date" name="from" value="<?= e($dateFrom) ?>" aria-label="Tanggal mulai">
                <input type="date" name="to" value="<?= e($dateTo) ?>" aria-label="Tanggal akhir">
                <button class="user-btn user-btn-primary" type="submit">Filter</button>
                <?php if ($search !== '' || $dateFrom !== '' || $dateTo !== ''): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/transactions.php')) ?>">Reset</a><?php endif; ?>
            </form>
            <div class="table-wrap">
                <table class="admin-table"><thead><tr><th>ID</th><th>Tanggal</th><th>Pelanggan</th><th>Kasir</th><th>Jumlah Item</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
                <?php if (!$transactions): ?><tr><td colspan="8" class="table-empty">Tidak ada transaksi yang cocok dengan filter.</td></tr><?php else: foreach ($transactions as $transaction): ?>
                    <tr>
                        <td><strong>TRX-<?= str_pad((string) $transaction['id_transaksi'], 3, '0', STR_PAD_LEFT) ?></strong></td>
                        <td><?= $transaction['tanggal'] ? e(date('d/m/Y', strtotime($transaction['tanggal']))) : '-' ?></td>
                        <td><?= e($transaction['nama_pelanggan'] ?? 'Pelanggan tidak diketahui') ?></td>
                        <td><?= e($transaction['nama_kasir'] ?? '—') ?></td>
                        <td><?= number_format((int) $transaction['jumlah_item']) ?></td>
                        <td>Rp <?= e(number_format((float) $transaction['total_belanja'], 0, ',', '.')) ?></td>
                        <td><span class="role-badge role-pelanggan">Tercatat</span></td>
                        <td><a class="user-action-edit" href="<?= e(appUrl('admin/transactions.php?' . http_build_query($paginationQuery + ['page' => $page, 'view' => (int) $transaction['id_transaksi']]) . '#detail')) ?>">Detail</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody></table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="product-pagination" aria-label="Pagination transaksi">
                    <span>Halaman <?= $page ?> dari <?= $totalPages ?></span>
                    <div>
                        <?php if ($page > 1): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/transactions.php?' . http_build_query($paginationQuery + ['page' => $page - 1]))) ?>">Sebelumnya</a><?php endif; ?>
                        <?php if ($page < $totalPages): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/transactions.php?' . http_build_query($paginationQuery + ['page' => $page + 1]))) ?>">Berikutnya</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        </section>
        <footer class="admin-footer"><span>© <?= date('Y') ?> Tokoku</span><span>Transaksi Penjualan</span></footer>
    </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
