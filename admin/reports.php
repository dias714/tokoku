<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/database.php';

$pageTitle = 'Laporan Penjualan - Tokoku';
$errors = [];
$period = (string) ($_GET['period'] ?? 'month');
if (!in_array($period, ['day', 'month', 'year'], true)) {
    $period = 'month';
    $errors[] = 'Periode laporan tidak dikenal.';
}
$value = trim((string) ($_GET['value'] ?? ''));
$today = date('Y-m-d');
if ($value === '') {
    $value = match ($period) {
        'day' => $today,
        'year' => date('Y'),
        default => date('Y-m'),
    };
}

$startDate = '';
$endDate = '';
if ($period === 'day') {
    $parsed = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        $errors[] = 'Tanggal laporan tidak valid.';
    } else {
        $startDate = $endDate = $value;
    }
} elseif ($period === 'month') {
    $parsed = DateTime::createFromFormat('!Y-m', $value);
    if (!$parsed || $parsed->format('Y-m') !== $value) {
        $errors[] = 'Bulan laporan tidak valid.';
    } else {
        $startDate = $parsed->format('Y-m-01');
        $endDate = $parsed->format('Y-m-t');
    }
} else {
    if (!preg_match('/^\d{4}$/', $value) || (int) $value < 1000 || (int) $value > 9999) {
        $errors[] = 'Tahun laporan tidak valid.';
    } else {
        $startDate = $value . '-01-01';
        $endDate = $value . '-12-31';
    }
}

$summary = ['transactions' => 0, 'turnover' => 0.0, 'units' => 0];
$dailyRows = [];
if (!$errors) {
    $summaryStmt = $pdo->prepare(
        'SELECT COUNT(*) AS transactions,
                COALESCE(SUM(total_belanja), 0) AS turnover
         FROM tbl_transaksi
         WHERE tanggal BETWEEN :start_date AND :end_date'
    );
    $summaryStmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $summary = array_merge($summary, $summaryStmt->fetch());

    $unitsStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(d.jumlah), 0)
         FROM tbl_detail_transaksi d
         INNER JOIN tbl_transaksi t ON t.id_transaksi = d.id_transaksi
         WHERE t.tanggal BETWEEN :start_date AND :end_date'
    );
    $unitsStmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $summary['units'] = (int) $unitsStmt->fetchColumn();

    $dailyStmt = $pdo->prepare(
        'SELECT t.tanggal, COUNT(*) AS transactions,
                COALESCE(SUM(t.total_belanja), 0) AS turnover,
                COALESCE(SUM(daily_units.units), 0) AS units
         FROM tbl_transaksi t
         LEFT JOIN (
             SELECT id_transaksi, SUM(jumlah) AS units
             FROM tbl_detail_transaksi
             GROUP BY id_transaksi
         ) daily_units ON daily_units.id_transaksi = t.id_transaksi
         WHERE t.tanggal BETWEEN :start_date AND :end_date
         GROUP BY t.tanggal
         ORDER BY t.tanggal DESC'
    );
    $dailyStmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $dailyRows = $dailyStmt->fetchAll();
}

$periodLabels = ['day' => 'Harian', 'month' => 'Bulanan', 'year' => 'Tahunan'];
$periodInputTypes = ['day' => 'date', 'month' => 'month', 'year' => 'number'];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
    <?php $activeAdminPage = 'reports'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    <main class="admin-main">
        <header class="admin-topbar">
            <div><div class="topbar-eyebrow">TOKOKU / ADMIN</div><h1>Laporan Penjualan</h1><p class="topbar-subtitle">Ringkasan omzet transaksi berdasarkan periode yang dipilih.</p></div>
            <div class="topbar-profile"><div class="profile-avatar"><?= e(strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1))) ?></div><div><strong><?= e($_SESSION['name'] ?? 'Admin') ?></strong><span>Administrator</span></div></div>
        </header>
        <?php foreach ($errors as $error): ?><div class="user-alert user-alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>

        <section class="admin-panel report-filter-panel">
            <div class="panel-heading"><div><h2>Filter Periode</h2><p>Pilih laporan harian, bulanan, atau tahunan.</p></div></div>
            <form method="get" class="user-filter-form report-filter-form">
                <label class="report-filter-field">Periode<select name="period" id="report-period"><?php foreach ($periodLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label class="report-filter-field">Tanggal<input class="report-period-input" data-period="day" type="date" value="<?= $period === 'day' ? e($value) : '' ?>" aria-label="Pilih tanggal laporan"></label>
                <label class="report-filter-field">Bulan<input class="report-period-input" data-period="month" type="month" value="<?= $period === 'month' ? e($value) : '' ?>" aria-label="Pilih bulan laporan"></label>
                <label class="report-filter-field">Tahun<input class="report-period-input" data-period="year" type="number" min="1000" max="9999" value="<?= $period === 'year' ? e($value) : '' ?>" aria-label="Pilih tahun laporan"></label>
                <input type="hidden" name="value" id="report-value" value="<?= e($value) ?>">
                <button class="user-btn user-btn-primary" type="submit">Tampilkan</button>
            </form>
        </section>

        <section class="dashboard-section" aria-labelledby="report-summary-title">
            <div class="section-heading"><div><h2 id="report-summary-title">Ringkasan <?= e($periodLabels[$period]) ?></h2><p><?= $startDate !== '' ? e(date('d/m/Y', strtotime($startDate))) . ' – ' . e(date('d/m/Y', strtotime($endDate))) : 'Periode belum valid' ?></p></div></div>
            <div class="stats-grid report-stats-grid">
                <article class="stat-card"><div class="stat-card-top"><span class="stat-icon stat-icon-purple">▧</span><span class="stat-caption">TRANSAKSI</span></div><strong class="stat-number"><?= number_format((int) $summary['transactions']) ?></strong><span class="stat-description">Jumlah transaksi pada periode</span></article>
                <article class="stat-card"><div class="stat-card-top"><span class="stat-icon stat-icon-green">Rp</span><span class="stat-caption">OMZET</span></div><strong class="stat-number report-currency">Rp <?= e(number_format((float) $summary['turnover'], 0, ',', '.')) ?></strong><span class="stat-description">Total nilai transaksi</span></article>
                <article class="stat-card"><div class="stat-card-top"><span class="stat-icon stat-icon-blue">▣</span><span class="stat-caption">UNIT TERJUAL</span></div><strong class="stat-number"><?= number_format((int) $summary['units']) ?></strong><span class="stat-description">Total unit pada detail transaksi</span></article>
            </div>
        </section>

        <section class="admin-panel report-table-panel">
            <div class="panel-heading"><div><h2>Rincian Penjualan per Hari</h2><p>Omzet dihitung dari total transaksi tersimpan.</p></div></div>
            <div class="table-wrap">
                <table class="admin-table"><thead><tr><th>Tanggal</th><th>Transaksi</th><th>Unit Terjual</th><th>Omzet</th></tr></thead><tbody>
                <?php if (!$dailyRows): ?><tr><td colspan="4" class="table-empty">Tidak ada transaksi pada periode ini.</td></tr><?php else: foreach ($dailyRows as $row): ?>
                    <tr><td><?= $row['tanggal'] ? e(date('d/m/Y', strtotime($row['tanggal']))) : '-' ?></td><td><?= number_format((int) $row['transactions']) ?></td><td><?= number_format((int) $row['units']) ?></td><td>Rp <?= e(number_format((float) $row['turnover'], 0, ',', '.')) ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody></table>
            </div>
            <p class="product-schema-note report-note">Laba belum dapat dihitung karena database belum menyimpan harga modal atau biaya operasional.</p>
        </section>
        <footer class="admin-footer"><span>© <?= date('Y') ?> Tokoku</span><span>Laporan Penjualan</span></footer>
    </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
