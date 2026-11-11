<?php

require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/product_images.php';

$pageTitle = 'Dashboard Admin - Tokoku';

$stats = [
    'total' => 0,
    'admin' => 0,
    'kasir' => 0,
    'pelanggan' => 0,
];
$recentUsers = [];
$recentProducts = [];
$databaseError = false;

try {
    $stats['total'] = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    $roleCounts = $pdo->query(
        'SELECT role, COUNT(*) AS total FROM users GROUP BY role'
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($roleCounts as $row) {
        if (array_key_exists($row['role'], $stats)) {
            $stats[$row['role']] = (int) $row['total'];
        }
    }

    $recentUsers = $pdo->query(
        'SELECT id, name, email, role, created_at FROM users ORDER BY id DESC LIMIT 5'
    )->fetchAll(PDO::FETCH_ASSOC);

    $recentProducts = $pdo->query(
        'SELECT id_produk, nama_produk, harga, stok, gambar
         FROM tbl_produk ORDER BY id_produk DESC LIMIT 5'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Dashboard Admin: ' . $e->getMessage());
    $databaseError = true;
}

$modules = [
    [
        'icon' => '♙',
        'title' => 'Manajemen Pengguna',
        'description' => 'Kelola akun Admin, Kasir, dan Pelanggan; cari serta filter berdasarkan role.',
        'status' => 'Sudah tersedia',
        'class' => 'module-ready',
        'href' => appUrl('admin/users.php'),
        'action' => 'Buka manajemen pengguna',
    ],
    [
        'icon' => '▣',
        'title' => 'Manajemen Produk',
        'description' => 'Kelola nama, kategori, harga, dan stok produk toko.',
        'status' => 'Sudah tersedia',
        'class' => 'module-ready',
        'href' => appUrl('admin/products.php'),
        'action' => 'Buka manajemen produk',
    ],
    [
        'icon' => '▤',
        'title' => 'Manajemen Kategori',
        'description' => 'Kelola kategori dan pengelompokan seluruh produk toko.',
        'status' => 'Sudah tersedia',
        'class' => 'module-ready',
        'href' => appUrl('admin/categories.php'),
        'action' => 'Buka manajemen kategori',
    ],
    [
        'icon' => '▧',
        'title' => 'Transaksi Penjualan',
        'description' => 'Pantau transaksi seluruh kasir, detail pembelian, dan status transaksi.',
        'status' => 'Sudah tersedia',
        'class' => 'module-ready',
        'href' => appUrl('admin/transactions.php'),
        'action' => 'Buka transaksi',
    ],
    [
        'icon' => '▥',
        'title' => 'Laporan Penjualan',
        'description' => 'Lihat rekap penjualan harian, bulanan, dan tahunan.',
        'status' => 'Sudah tersedia',
        'class' => 'module-ready',
        'href' => appUrl('admin/reports.php'),
        'action' => 'Buka laporan',
    ],
    [
        'icon' => '◷',
        'title' => 'Pemantauan Aktivitas',
        'description' => 'Pantau aktivitas pengguna dan kasir di seluruh sistem.',
        'status' => 'Sudah tersedia',
        'class' => 'module-ready',
        'href' => appUrl('admin/activities.php'),
        'action' => 'Buka aktivitas sistem',
    ],
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php $activeAdminPage = 'dashboard'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-topbar">
            <div>
                <div class="topbar-eyebrow">TOKOKU / ADMIN</div>
                <h1>Dashboard Admin</h1>
                <p class="topbar-subtitle">Pusat pengelolaan dan pemantauan seluruh aktivitas toko.</p>
            </div>
            <div class="topbar-profile">
                <div class="profile-avatar"><?= e(strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1))) ?></div>
                <div>
                    <strong><?= e($_SESSION['name'] ?? 'Admin') ?></strong>
                    <span>Akses administrator penuh</span>
                </div>
            </div>
        </header>

        <section class="dashboard-welcome">
            <div class="welcome-content">
                <span class="welcome-label">PUSAT KONTROL TOKOKU</span>
                <h2>Selamat datang, <?= e($_SESSION['name'] ?? 'Admin') ?>!</h2>
                <p>Admin dapat mengakses dan memantau seluruh data pengguna, produk, kategori, transaksi, laporan, serta aktivitas sistem.</p>
            </div>
            <div class="welcome-decoration" aria-hidden="true">T</div>
        </section>

        <?php if ($databaseError): ?>
            <div class="admin-alert" role="alert">
                Ringkasan dashboard belum dapat dimuat. Periksa koneksi dan skema database. Detail teknis dicatat pada PHP error log.
            </div>
        <?php endif; ?>

        <section class="dashboard-section" aria-labelledby="user-stat-title">
            <div class="section-heading">
                <div>
                    <h2 id="user-stat-title">Ringkasan Pengguna</h2>
                    <p>Statistik seluruh akun yang terdaftar di sistem.</p>
                </div>
                <a class="text-link" href="<?= e(appUrl('admin/users.php')) ?>">Kelola pengguna <span aria-hidden="true">→</span></a>
            </div>

            <div class="stats-grid" aria-label="Statistik pengguna">
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-icon stat-icon-purple">♙</span><span class="stat-caption">SELURUH AKUN</span></div>
                    <strong class="stat-number"><?= number_format($stats['total']) ?></strong>
                    <span class="stat-description">Total pengguna terdaftar</span>
                </article>
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-icon stat-icon-blue">◆</span><span class="stat-caption">ADMIN</span></div>
                    <strong class="stat-number"><?= number_format($stats['admin']) ?></strong>
                    <span class="stat-description">Administrator sistem</span>
                </article>
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-icon stat-icon-orange">♧</span><span class="stat-caption">KASIR</span></div>
                    <strong class="stat-number"><?= number_format($stats['kasir']) ?></strong>
                    <span class="stat-description">Petugas operasional</span>
                </article>
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-icon stat-icon-green">♙</span><span class="stat-caption">PELANGGAN</span></div>
                    <strong class="stat-number"><?= number_format($stats['pelanggan']) ?></strong>
                    <span class="stat-description">Akun pelanggan</span>
                </article>
            </div>
        </section>

        <section class="dashboard-section" aria-labelledby="monitoring-title">
            <div class="section-heading">
                <div>
                    <h2 id="monitoring-title">Pemantauan Seluruh Sistem</h2>
                    <p>Modul yang dapat dikelola Admin beserta status pengerjaannya.</p>
                </div>
                <span class="access-label">Akses penuh Admin</span>
            </div>

            <div class="module-grid">
                <?php foreach ($modules as $module): ?>
                    <article class="module-card">
                        <div class="module-card-top">
                            <span class="module-icon"><?= e($module['icon']) ?></span>
                            <span class="module-status <?= e($module['class']) ?>"><?= e($module['status']) ?></span>
                        </div>
                        <h3><?= e($module['title']) ?></h3>
                        <p><?= e($module['description']) ?></p>
                        <?php if ($module['href']): ?>
                            <a class="module-action" href="<?= e($module['href']) ?>"><?= e($module['action']) ?> <span aria-hidden="true">→</span></a>
                        <?php else: ?>
                            <span class="module-action module-action-disabled">Halaman belum dibuat</span>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="admin-panel" aria-labelledby="recent-users-title">
            <div class="panel-heading">
                <div>
                    <h2 id="recent-users-title">Pengguna Terbaru</h2>
                    <p>Lima akun terakhir dari seluruh role yang tercatat di database.</p>
                </div>
                <a class="text-link" href="<?= e(appUrl('admin/users.php')) ?>">Lihat semua <span aria-hidden="true">→</span></a>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Pengguna</th><th>Email</th><th>Role</th><th>Tanggal Dibuat</th></tr></thead>
                    <tbody>
                    <?php if (!$recentUsers): ?>
                        <tr><td colspan="4" class="table-empty">Belum ada data pengguna untuk ditampilkan.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentUsers as $user): ?>
                            <?php
                            $roleClass = [
                                'admin' => 'role-admin',
                                'kasir' => 'role-kasir',
                                'pelanggan' => 'role-pelanggan',
                            ][$user['role']] ?? 'role-default';
                            $createdAt = !empty($user['created_at'])
                                ? date('d/m/Y H:i', strtotime($user['created_at']))
                                : '-';
                            ?>
                            <tr>
                                <td><div class="table-user"><span class="table-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span><strong><?= e($user['name']) ?></strong></div></td>
                                <td><?= e($user['email']) ?></td>
                                <td><span class="role-badge <?= e($roleClass) ?>"><?= e(ucfirst($user['role'])) ?></span></td>
                                <td><?= e($createdAt) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-panel dashboard-products-panel" aria-labelledby="recent-products-title">
            <div class="panel-heading">
                <div>
                    <h2 id="recent-products-title">Produk Terbaru</h2>
                    <p>Produk dan gambar terkini dari katalog toko.</p>
                </div>
                <a class="text-link" href="<?= e(appUrl('admin/products.php')) ?>">Kelola produk <span aria-hidden="true">→</span></a>
            </div>
            <div class="dashboard-product-grid">
                <?php if (!$recentProducts): ?>
                    <p class="table-empty">Belum ada produk untuk ditampilkan.</p>
                <?php else: ?>
                    <?php foreach ($recentProducts as $product): ?>
                        <a class="dashboard-product-card" href="<?= e(appUrl('admin/products.php?edit=' . (int) $product['id_produk'])) ?>">
                            <img src="<?= e(productImageUrl($product)) ?>" alt="<?= e($product['nama_produk']) ?>" loading="lazy">
                            <span class="dashboard-product-info">
                                <strong><?= e($product['nama_produk']) ?></strong>
                                <span>Rp <?= e(number_format((float) $product['harga'], 0, ',', '.')) ?> · Stok <?= number_format((int) $product['stok']) ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <footer class="admin-footer">
            <span>© <?= date('Y') ?> Tokoku</span>
            <span>Panel Administrasi · Akses penuh Admin</span>
        </footer>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
