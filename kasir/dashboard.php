<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('kasir');
require_once __DIR__ . '/../config/database.php';

$pageTitle = 'Dashboard Kasir - Tokoku';
$todaySummary = ['transactions' => 0, 'turnover' => 0.0, 'units' => 0];
$inventorySummary = ['products' => 0, 'low_stock' => 0];
$recentTransactions = [];
$lowStockProducts = [];
$databaseError = false;

try {
    $summaryStmt = $pdo->prepare(
        'SELECT COUNT(*) AS transactions,
                COALESCE(SUM(total_belanja), 0) AS turnover
         FROM tbl_transaksi
         WHERE tanggal = :today AND id_kasir = :id_kasir'
    );
    $summaryStmt->execute([
        'today' => date('Y-m-d'),
        'id_kasir' => (int) $_SESSION['user_id'],
    ]);
    $todaySummary = array_merge($todaySummary, $summaryStmt->fetch());

    $unitsStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(d.jumlah), 0)
         FROM tbl_detail_transaksi d
         INNER JOIN tbl_transaksi t ON t.id_transaksi = d.id_transaksi
        WHERE t.tanggal = :today AND t.id_kasir = :id_kasir'
    );
    $unitsStmt->execute([
        'today' => date('Y-m-d'),
        'id_kasir' => (int) $_SESSION['user_id'],
    ]);
    $todaySummary['units'] = (int) $unitsStmt->fetchColumn();

    $inventorySummary = $pdo->query(
        'SELECT COUNT(*) AS products,
                COALESCE(SUM(CASE WHEN stok <= 5 THEN 1 ELSE 0 END), 0) AS low_stock
         FROM tbl_produk'
    )->fetch();

    $recentStmt = $pdo->prepare(
        'SELECT t.id_transaksi, t.tanggal, t.total_belanja, p.nama_pelanggan,
                COALESCE(SUM(d.jumlah), 0) AS jumlah_item
         FROM tbl_transaksi t
         LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
         LEFT JOIN tbl_detail_transaksi d ON d.id_transaksi = t.id_transaksi
         WHERE t.id_kasir = :id_kasir
         GROUP BY t.id_transaksi, t.tanggal, t.total_belanja, p.nama_pelanggan
         ORDER BY t.tanggal DESC, t.id_transaksi DESC
         LIMIT 5'
    );
    $recentStmt->execute(['id_kasir' => (int) $_SESSION['user_id']]);
    $recentTransactions = $recentStmt->fetchAll();

    $lowStockProducts = $pdo->query(
        'SELECT id_produk, nama_produk, stok
         FROM tbl_produk
         WHERE stok <= 5
         ORDER BY stok ASC, nama_produk ASC
         LIMIT 5'
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Dashboard Kasir: ' . $e->getMessage());
    $databaseError = true;
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout cashier-layout">
    <aside class="admin-sidebar cashier-sidebar">
        <a class="admin-brand" href="<?= e(appUrl('kasir/dashboard.php')) ?>">
            <span class="admin-brand-mark">T</span><span>Tokoku</span>
        </a>
        <span class="admin-sidebar-subtitle">Panel Kasir</span>
        <div class="sidebar-caption">OPERASIONAL</div>
        <nav class="admin-nav" aria-label="Navigasi kasir">
            <a class="admin-nav-link active" href="#ringkasan" aria-current="page"><span class="nav-icon">▦</span><span>Dashboard</span></a>
            <a class="admin-nav-link" href="<?= e(appUrl('kasir/checkout.php')) ?>"><span class="nav-icon">＋</span><span>Transaksi Baru</span></a>
            <a class="admin-nav-link" href="#transaksi"><span class="nav-icon">▧</span><span>Transaksi Terbaru</span></a>
            <a class="admin-nav-link" href="#stok"><span class="nav-icon">▣</span><span>Stok Menipis</span></a>
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <div class="user-avatar"><?= e(strtoupper(substr($_SESSION['name'] ?? 'K', 0, 1))) ?></div>
                <div class="sidebar-user-info"><strong><?= e($_SESSION['name'] ?? 'Kasir') ?></strong><span>Kasir</span></div>
            </div>
            <a class="sidebar-logout" href="<?= e(appUrl('index.php?logout=1')) ?>">Keluar dari akun</a>
        </div>
    </aside>

    <main class="admin-main cashier-main">
        <header class="admin-topbar">
            <div>
                <div class="topbar-eyebrow">TOKOKU / OPERASIONAL</div>
                <h1>Dashboard Kasir</h1>
                <p class="topbar-subtitle">Pantau ringkasan penjualan dan kondisi stok toko.</p>
            </div>
            <div class="topbar-profile">
                <div class="profile-avatar"><?= e(strtoupper(substr($_SESSION['name'] ?? 'K', 0, 1))) ?></div>
                <div><strong><?= e($_SESSION['name'] ?? 'Kasir') ?></strong><span>Kasir</span></div>
                <a class="user-btn user-btn-primary cashier-new-transaction" href="<?= e(appUrl('kasir/checkout.php')) ?>">＋ Transaksi Baru</a>
            </div>
        </header>

        <?php if ($databaseError): ?>
            <div class="admin-alert" role="alert">Ringkasan dashboard belum dapat dimuat. Periksa koneksi database; detail teknis dicatat pada PHP error log.</div>
        <?php endif; ?>

        <section class="dashboard-welcome cashier-welcome">
            <div class="welcome-content">
                <span class="welcome-label">RINGKASAN OPERASIONAL</span>
                <h2>Selamat bertugas, <?= e($_SESSION['name'] ?? 'Kasir') ?>.</h2>
                <p>Berikut aktivitas penjualan toko dan informasi stok per <?= e(date('d/m/Y')) ?>.</p>
            </div>
            <div class="welcome-decoration" aria-hidden="true">T</div>
        </section>

        <section class="dashboard-section" id="ringkasan" aria-labelledby="cashier-summary-title">
            <div class="section-heading">
                <div><h2 id="cashier-summary-title">Ringkasan Hari Ini</h2><p>Angka penjualan dari transaksi yang diproses oleh akun Anda.</p></div>
                <span class="access-label"><?= e(date('d/m/Y')) ?></span>
            </div>
            <div class="stats-grid cashier-stats-grid">
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-icon stat-icon-purple">▧</span><span class="stat-caption">TRANSAKSI</span></div>
                    <strong class="stat-number"><?= number_format((int) $todaySummary['transactions']) ?></strong>
                    <span class="stat-description">Transaksi hari ini</span>
                </article>
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-icon stat-icon-green">Rp</span><span class="stat-caption">PENJUALAN</span></div>
                    <strong class="stat-number cashier-currency">Rp <?= e(number_format((float) $todaySummary['turnover'], 0, ',', '.')) ?></strong>
                    <span class="stat-description">Total nilai transaksi hari ini</span>
                </article>
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-icon stat-icon-blue">▣</span><span class="stat-caption">UNIT TERJUAL</span></div>
                    <strong class="stat-number"><?= number_format((int) $todaySummary['units']) ?></strong>
                    <span class="stat-description">Jumlah produk terjual hari ini</span>
                </article>
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-icon stat-icon-orange">!</span><span class="stat-caption">STOK MENIPIS</span></div>
                    <strong class="stat-number"><?= number_format((int) ($inventorySummary['low_stock'] ?? 0)) ?></strong>
                    <span class="stat-description">Produk dengan stok 5 atau kurang</span>
                </article>
            </div>
        </section>

        <section class="cashier-data-grid">
            <section class="admin-panel cashier-panel" id="transaksi">
                <div class="panel-heading">
                    <div><h2>Transaksi Terbaru</h2><p>Catatan penjualan yang diproses oleh akun Anda.</p></div>
                    <span class="panel-count"><?= number_format((int) ($inventorySummary['products'] ?? 0)) ?> produk</span>
                </div>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Transaksi</th><th>Pelanggan</th><th>Item</th><th>Total</th><th>Nota</th></tr></thead>
                        <tbody>
                        <?php if (!$recentTransactions): ?>
                            <tr><td colspan="5" class="table-empty">Belum ada transaksi kasir. Mulai dari tombol Transaksi Baru.</td></tr>
                        <?php else: foreach ($recentTransactions as $transaction): ?>
                            <tr>
                                <td><strong>TRX-<?= str_pad((string) $transaction['id_transaksi'], 3, '0', STR_PAD_LEFT) ?><span class="cashier-date"><?= $transaction['tanggal'] ? e(date('d/m/Y', strtotime($transaction['tanggal']))) : '-' ?></span></strong></td>
                                <td><?= e($transaction['nama_pelanggan'] ?? 'Pelanggan') ?></td>
                                <td><?= number_format((int) $transaction['jumlah_item']) ?></td>
                                <td>Rp <?= e(number_format((float) $transaction['total_belanja'], 0, ',', '.')) ?></td>
                                <td><a class="user-action-edit" href="<?= e(appUrl('kasir/receipt.php?id=' . (int) $transaction['id_transaksi'])) ?>">Cetak Nota</a></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="cashier-note">Daftar ini hanya menampilkan transaksi yang dibuat oleh akun kasir Anda.</p>
            </section>

            <section class="admin-panel cashier-panel cashier-stock-panel" id="stok">
                <div class="panel-heading"><div><h2>Stok Menipis</h2><p>Produk yang perlu diperhatikan.</p></div></div>
                <div class="cashier-stock-list">
                    <?php if (!$lowStockProducts): ?>
                        <p class="cashier-stock-empty">Tidak ada produk dengan stok menipis.</p>
                    <?php else: foreach ($lowStockProducts as $product): ?>
                        <div class="cashier-stock-item">
                            <div><strong><?= e($product['nama_produk']) ?></strong><span>ID Produk #<?= (int) $product['id_produk'] ?></span></div>
                            <span class="stock-badge <?= (int) $product['stok'] <= 2 ? 'stock-low' : 'stock-normal' ?>"><?= number_format((int) $product['stok']) ?> tersisa</span>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </section>
        </section>

        <footer class="admin-footer"><span>© <?= date('Y') ?> Tokoku</span><span>Dashboard Kasir</span></footer>
    </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
