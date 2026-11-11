<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('pelanggan');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/product_images.php';
require_once __DIR__ . '/../includes/store.php';

$pageTitle = 'Belanja - Tokoku';
$search = trim((string) ($_GET['q'] ?? ''));
$categoryId = trim((string) ($_GET['category'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$categories = $pdo->query(
    'SELECT id_kategori, nama_kategori FROM tbl_kategori ORDER BY nama_kategori'
)->fetchAll();

$where = ['p.stok > 0'];
$params = [];
if ($search !== '') {
    $where[] = '(p.nama_produk LIKE :search OR p.deskripsi LIKE :search_description)';
    $params['search'] = '%' . $search . '%';
    $params['search_description'] = '%' . $search . '%';
}
if (
    $categoryId !== ''
    && ctype_digit($categoryId)
    && in_array($categoryId, array_column($categories, 'id_kategori'), false)
) {
    $where[] = 'p.id_kategori = :id_kategori';
    $params['id_kategori'] = (int) $categoryId;
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_produk p' . $whereSql);
$countStmt->execute($params);
$totalProducts = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$listStmt = $pdo->prepare(
    'SELECT p.id_produk, p.nama_produk, p.deskripsi, p.harga, p.stok,
            p.gambar, k.nama_kategori
     FROM tbl_produk p
     LEFT JOIN tbl_kategori k ON k.id_kategori = p.id_kategori'
     . $whereSql .
    ' ORDER BY p.nama_produk LIMIT :limit OFFSET :offset'
);
foreach ($params as $key => $value) {
    $listStmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$listStmt->execute();
$products = $listStmt->fetchAll();
$success = $_SESSION['cart_flash'] ?? '';
$error = $_SESSION['cart_error'] ?? '';
unset($_SESSION['cart_flash'], $_SESSION['cart_error']);

require_once __DIR__ . '/../includes/header.php';
?>
<main class="store-page">
    <?php customerStoreHeader('shop'); ?>
    <section class="store-hero customer-hero">
        <div class="customer-hero-content">
            <span class="welcome-label">TOKOKU · TOKO ONLINE</span>
            <h1>Belanja kebutuhan Anda, lebih mudah.</h1>
            <p>Halo, <?= e($_SESSION['name'] ?? 'Pelanggan') ?>. Temukan produk pilihan, cek stok, lalu pesan dengan nyaman.</p>
            <div class="customer-hero-actions">
                <a class="user-btn user-btn-light" href="#katalog">Jelajahi Produk <span aria-hidden="true">→</span></a>
                <a class="customer-hero-secondary" href="<?= e(appUrl('customer/orders.php')) ?>">Lihat pesanan saya</a>
            </div>
        </div>
        <div class="customer-hero-aside" aria-label="Informasi toko">
            <span class="customer-hero-icon" aria-hidden="true">T</span>
            <strong>Belanja praktis di Tokoku</strong>
            <span>Produk dan ketersediaan stok diperbarui dari katalog toko.</span>
        </div>
    </section>
    <?php if ($success): ?><div class="user-alert user-alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="user-alert user-alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="store-section" id="katalog">
        <div class="store-section-heading"><div><h2>Katalog Produk</h2><p><?= number_format($totalProducts) ?> produk tersedia</p></div>
            <form method="get" class="user-filter-form store-filter-form">
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cari produk..." aria-label="Cari produk">
                <select name="category" aria-label="Pilih kategori"><option value="">Semua kategori</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id_kategori'] ?>" <?= $categoryId === (string) $category['id_kategori'] ? 'selected' : '' ?>><?= e($category['nama_kategori']) ?></option><?php endforeach; ?></select>
                <button class="user-btn user-btn-primary" type="submit">Cari</button>
                <?php if ($search !== '' || $categoryId !== ''): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('customer/index.php')) ?>">Reset</a><?php endif; ?>
            </form>
        </div>
        <?php if ($categories): ?>
            <nav class="customer-category-links" aria-label="Pilih kategori produk">
                <a class="<?= $categoryId === '' ? 'active' : '' ?>" href="<?= e(appUrl('customer/index.php' . ($search !== '' ? '?' . http_build_query(['q' => $search]) : ''))) ?>">Semua produk</a>
                <?php foreach ($categories as $category): ?>
                    <?php
                    $categoryQuery = array_filter(
                        ['q' => $search, 'category' => (string) $category['id_kategori']],
                        static fn (string $value): bool => $value !== ''
                    );
                    $categoryUrl = appUrl('customer/index.php?' . http_build_query($categoryQuery));
                    ?>
                    <a class="<?= $categoryId === (string) $category['id_kategori'] ? 'active' : '' ?>" href="<?= e($categoryUrl) ?>"><?= e($category['nama_kategori']) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <?php if (!$products): ?>
            <div class="store-empty-state"><img src="<?= e(appUrl('assets/images/product-placeholder.svg')) ?>" alt=""><h2>Produk belum tersedia</h2><p>Coba ubah pencarian atau kembali lagi nanti.</p></div>
        <?php else: ?>
            <div class="store-product-grid"><?php foreach ($products as $product): customerProductCard($product); endforeach; ?></div>
        <?php endif; ?>
        <?php if ($totalPages > 1): ?>
            <nav class="product-pagination store-pagination" aria-label="Pagination produk">
                <span>Halaman <?= $page ?> dari <?= $totalPages ?></span>
                <div>
                    <?php if ($page > 1): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('customer/index.php?' . http_build_query(['q' => $search, 'category' => $categoryId, 'page' => $page - 1]))) ?>">Sebelumnya</a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('customer/index.php?' . http_build_query(['q' => $search, 'category' => $categoryId, 'page' => $page + 1]))) ?>">Berikutnya</a><?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    </section>
    <footer class="store-footer">© <?= date('Y') ?> Tokoku · Belanja dengan nyaman</footer>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
