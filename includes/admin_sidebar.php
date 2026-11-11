<?php
$activeAdminPage = $activeAdminPage ?? '';
$adminNavigationGroups = [
    'MENU UTAMA' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => '▦', 'path' => 'admin/dashboard.php'],
    ],
    'MANAJEMEN' => [
        ['key' => 'users', 'label' => 'Pengguna', 'icon' => '♙', 'path' => 'admin/users.php'],
        ['key' => 'products', 'label' => 'Produk', 'icon' => '▣', 'path' => 'admin/products.php'],
        ['key' => 'categories', 'label' => 'Kategori', 'icon' => '▤', 'path' => 'admin/categories.php'],
    ],
    'PENJUALAN' => [
        ['key' => 'transactions', 'label' => 'Transaksi', 'icon' => '▧', 'path' => 'admin/transactions.php'],
    ],
    'ANALISIS' => [
        ['key' => 'reports', 'label' => 'Laporan Penjualan', 'icon' => '▥', 'path' => 'admin/reports.php'],
        ['key' => 'activities', 'label' => 'Aktivitas Sistem', 'icon' => '◷', 'path' => 'admin/activities.php'],
    ],
];
?>
<aside class="admin-sidebar">
    <a class="admin-brand" href="<?= e(appUrl('admin/dashboard.php')) ?>">
        <span class="admin-brand-mark">T</span><span>Tokoku</span>
    </a>
    <span class="admin-sidebar-subtitle">Administrator Panel</span>
    <?php foreach ($adminNavigationGroups as $groupTitle => $items): ?>
        <div class="sidebar-caption"><?= e($groupTitle) ?></div>
        <nav class="admin-nav" aria-label="<?= e($groupTitle) ?>">
            <?php foreach ($items as $item): ?>
                <a
                    class="admin-nav-link <?= $activeAdminPage === $item['key'] ? 'active' : '' ?>"
                    href="<?= e(appUrl($item['path'])) ?>"
                    <?= $activeAdminPage === $item['key'] ? 'aria-current="page"' : '' ?>
                >
                    <span class="nav-icon"><?= e($item['icon']) ?></span><span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endforeach; ?>
    <div class="sidebar-bottom">
        <div class="sidebar-user">
            <div class="user-avatar"><?= e(strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1))) ?></div>
            <div class="sidebar-user-info">
                <strong><?= e($_SESSION['name'] ?? 'Admin') ?></strong>
                <span>Administrator</span>
            </div>
        </div>
        <a class="sidebar-logout" href="<?= e(appUrl('index.php?logout=1')) ?>">Keluar dari akun</a>
    </div>
</aside>
