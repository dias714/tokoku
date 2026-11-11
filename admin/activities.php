<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/database.php';

$pageTitle = 'Aktivitas Sistem - Tokoku';
$search = trim((string) ($_GET['q'] ?? ''));
$source = (string) ($_GET['source'] ?? '');
$sources = [
    '' => 'Semua sumber',
    'sistem' => 'Log sistem',
    'pelanggan' => 'Aktivitas pelanggan',
    'perubahan' => 'Perubahan data pelanggan',
];
if (!array_key_exists($source, $sources)) {
    $source = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$activityUnion = <<<'SQL'
    SELECT 'sistem' AS source_key, 'Log sistem' AS source_label,
           COALESCE(aktivitas, 'Aktivitas sistem') AS activity,
           COALESCE(keterangan, 'Tidak ada keterangan tambahan') AS description,
           waktu AS occurred_at, id_referensi AS reference_id, id_log AS sort_id
    FROM tbl_log
    UNION ALL
    SELECT 'pelanggan', 'Aktivitas pelanggan',
           COALESCE(aktivitas, 'Aktivitas pelanggan'),
           CONCAT('Pelanggan #', COALESCE(id_pelanggan, 'tidak diketahui')),
           waktu, id_pelanggan, id_log
    FROM log_pelanggan
    UNION ALL
    SELECT 'perubahan', 'Perubahan data pelanggan',
           'Perubahan data pelanggan',
           CONCAT('Pelanggan #', COALESCE(id_pelanggan, 'tidak diketahui'),
                  ': ', COALESCE(nama_lama, '(kosong)'), ' → ', COALESCE(nama_baru, '(kosong)')),
           waktu, id_pelanggan, id_log
    FROM log_perubahan_pelanggan
SQL;
$conditions = [];
$params = [];
if ($source !== '') {
    $conditions[] = 'activity_entries.source_key = :source';
    $params['source'] = $source;
}
if ($search !== '') {
    $conditions[] = '(activity_entries.activity LIKE :activity_search OR activity_entries.description LIKE :description_search)';
    $params['activity_search'] = '%' . $search . '%';
    $params['description_search'] = '%' . $search . '%';
}
$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$countStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM (' . $activityUnion . ') AS activity_entries' . $whereSql
);
$countStmt->execute($params);
$totalActivities = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalActivities / $perPage));
$page = min($page, $totalPages);
$listStmt = $pdo->prepare(
    'SELECT activity_entries.source_key, activity_entries.source_label,
            activity_entries.activity, activity_entries.description,
            activity_entries.occurred_at, activity_entries.reference_id
     FROM (' . $activityUnion . ') AS activity_entries'
     . $whereSql .
    ' ORDER BY activity_entries.occurred_at DESC, activity_entries.sort_id DESC
     LIMIT :limit OFFSET :offset'
);
foreach ($params as $key => $value) {
    $listStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$listStmt->execute();
$activities = $listStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
    <?php $activeAdminPage = 'activities'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    <main class="admin-main">
        <header class="admin-topbar">
            <div><div class="topbar-eyebrow">TOKOKU / ADMIN</div><h1>Aktivitas Sistem</h1><p class="topbar-subtitle">Riwayat aktivitas dan perubahan data yang dicatat database.</p></div>
            <div class="topbar-profile"><div class="profile-avatar"><?= e(strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1))) ?></div><div><strong><?= e($_SESSION['name'] ?? 'Admin') ?></strong><span>Administrator</span></div></div>
        </header>
        <section class="admin-panel activity-list-panel">
            <div class="panel-heading"><div><h2>Riwayat Aktivitas</h2><p><?= number_format($totalActivities) ?> catatan dari log sistem dan log pelanggan.</p></div><span class="panel-count"><?= number_format($totalActivities) ?> aktivitas</span></div>
            <form method="get" class="user-filter-form activity-filter-form">
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cari aktivitas atau keterangan..." aria-label="Cari aktivitas">
                <select name="source" aria-label="Filter sumber"><?php foreach ($sources as $key => $label): ?><option value="<?= e($key) ?>" <?= $source === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <button class="user-btn user-btn-primary" type="submit">Filter</button>
                <?php if ($search !== '' || $source !== ''): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/activities.php')) ?>">Reset</a><?php endif; ?>
            </form>
            <div class="table-wrap">
                <table class="admin-table"><thead><tr><th>Aktivitas</th><th>Sumber</th><th>Keterangan</th><th>Waktu</th><th>Referensi</th></tr></thead><tbody>
                <?php if (!$activities): ?><tr><td colspan="5" class="table-empty">Belum ada aktivitas yang cocok dengan filter.</td></tr><?php else: foreach ($activities as $activity): ?>
                    <tr>
                        <td><strong><?= e($activity['activity']) ?></strong></td>
                        <td><span class="role-badge <?= $activity['source_key'] === 'sistem' ? 'role-admin' : 'role-pelanggan' ?>"><?= e($activity['source_label']) ?></span></td>
                        <td><?= e($activity['description']) ?></td>
                        <td><?= $activity['occurred_at'] ? e(date('d/m/Y H:i', strtotime($activity['occurred_at']))) : '-' ?></td>
                        <td><?= $activity['reference_id'] !== null ? '#' . number_format((int) $activity['reference_id']) : '-' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody></table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="product-pagination" aria-label="Pagination aktivitas">
                    <span>Halaman <?= $page ?> dari <?= $totalPages ?></span>
                    <div>
                        <?php if ($page > 1): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/activities.php?' . http_build_query(['q' => $search, 'source' => $source, 'page' => $page - 1]))) ?>">Sebelumnya</a><?php endif; ?>
                        <?php if ($page < $totalPages): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/activities.php?' . http_build_query(['q' => $search, 'source' => $source, 'page' => $page + 1]))) ?>">Berikutnya</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        </section>
        <p class="product-schema-note activity-note">Struktur log saat ini tidak mencatat akun pelaku untuk semua peristiwa. Nama Admin/Kasir hanya ditampilkan jika tersedia pada data sumber.</p>
        <footer class="admin-footer"><span>© <?= date('Y') ?> Tokoku</span><span>Aktivitas Sistem</span></footer>
    </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
