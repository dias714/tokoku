<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_activity.php';
require_once __DIR__ . '/../includes/product_images.php';

$pageTitle = 'Manajemen Produk - Tokoku';
$success = $_SESSION['product_flash'] ?? '';
unset($_SESSION['product_flash']);
$errors = [];
$editingProduct = null;
$formValues = [
    'nama_produk' => '',
    'id_kategori' => '',
    'harga' => '',
    'stok' => '',
    'deskripsi' => '',
];
$editingImage = null;

function productRedirect(): void
{
    header('Location: ' . appUrl('admin/products.php'));
    exit;
}

function validProductCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

$categories = $pdo->query(
    'SELECT id_kategori, nama_kategori FROM tbl_kategori ORDER BY nama_kategori'
)->fetchAll();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $formValues = [
        'nama_produk' => trim((string) ($_POST['nama_produk'] ?? '')),
        'id_kategori' => (string) ($_POST['id_kategori'] ?? ''),
        'harga' => trim((string) ($_POST['harga'] ?? '')),
        'stok' => trim((string) ($_POST['stok'] ?? '')),
        'deskripsi' => trim((string) ($_POST['deskripsi'] ?? '')),
    ];

    if (!validProductCsrf()) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman dan coba kembali.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create' || $action === 'update') {
            $id = (int) ($_POST['id_produk'] ?? 0);
            $name = $formValues['nama_produk'];
            $categoryId = $formValues['id_kategori'];
            $price = $formValues['harga'];
            $stock = $formValues['stok'];
            $description = $formValues['deskripsi'];
            $removeImage = ($_POST['hapus_gambar'] ?? '') === '1';
            $uploadedImage = null;
            $previousImage = null;
            $validCategoryIds = array_map(
                static fn (array $category): string => (string) $category['id_kategori'],
                $categories
            );

            if ($name === '' || mb_strlen($name) > 100) {
                $errors[] = 'Nama produk wajib diisi dan maksimal 100 karakter.';
            }
            if (
                $categoryId !== ''
                && (!ctype_digit($categoryId) || !in_array($categoryId, $validCategoryIds, true))
            ) {
                $errors[] = 'Kategori yang dipilih tidak valid.';
            }
            if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $price)) {
                $errors[] = 'Harga harus berupa angka non-negatif dengan maksimal 2 angka desimal.';
            }
            if (!ctype_digit($stock) || (float) $stock > 2147483647) {
                $errors[] = 'Stok harus berupa bilangan bulat non-negatif.';
            }
            if (mb_strlen($description) > 5000) {
                $errors[] = 'Deskripsi maksimal 5.000 karakter.';
            }
            if ($action === 'update' && $id < 1) {
                $errors[] = 'ID produk tidak valid.';
            }
            if (
                $removeImage
                && isset($_FILES['gambar'])
                && ($_FILES['gambar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            ) {
                $errors[] = 'Pilih gambar baru atau hapus gambar saat ini, tidak keduanya sekaligus.';
            }

            if (!$errors && $action === 'update') {
                $currentStmt = $pdo->prepare(
                    'SELECT gambar FROM tbl_produk WHERE id_produk = :id_produk LIMIT 1'
                );
                $currentStmt->execute(['id_produk' => $id]);
                $currentImage = $currentStmt->fetchColumn();
                if ($currentImage === false) {
                    $errors[] = 'Produk yang akan diperbarui tidak ditemukan.';
                } else {
                    $previousImage = is_string($currentImage) ? $currentImage : null;
                }
            }

            if (
                !$errors
                && isset($_FILES['gambar'])
                && ($_FILES['gambar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            ) {
                try {
                    $uploadedImage = saveProductImageUpload($_FILES['gambar']);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                } catch (Throwable $e) {
                    error_log('Validasi gambar produk: ' . $e->getMessage());
                    $errors[] = 'Gambar tidak dapat diproses. Periksa log aplikasi untuk detailnya.';
                }
            }

            if (!$errors) {
                $categoryValue = $categoryId === '' ? null : (int) $categoryId;
                $imageValue = $uploadedImage ?? ($removeImage ? null : $previousImage);
                if ($action === 'create') {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare(
                            'INSERT INTO tbl_produk
                             (nama_produk, id_kategori, harga, stok, deskripsi, gambar)
                             VALUES (:nama_produk, :id_kategori, :harga, :stok, :deskripsi, :gambar)'
                        );
                        $stmt->execute([
                            'nama_produk' => $name,
                            'id_kategori' => $categoryValue,
                            'harga' => $price,
                            'stok' => (int) $stock,
                            'deskripsi' => $description !== '' ? $description : null,
                            'gambar' => $imageValue,
                        ]);
                        recordAdminActivity(
                            $pdo,
                            'Produk ditambahkan',
                            'Menambahkan produk ' . $name,
                            (int) $pdo->lastInsertId()
                        );
                        $pdo->commit();
                        $_SESSION['product_flash'] = 'Produk berhasil ditambahkan.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        deleteProductImage($uploadedImage);
                        error_log('Tambah produk: ' . $e->getMessage());
                        $errors[] = 'Produk tidak dapat ditambahkan. Periksa log aplikasi untuk detailnya.';
                    }
                } else {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare(
                            'UPDATE tbl_produk
                             SET nama_produk = :nama_produk, id_kategori = :id_kategori,
                                 harga = :harga, stok = :stok, deskripsi = :deskripsi, gambar = :gambar
                             WHERE id_produk = :id_produk'
                        );
                        $stmt->execute([
                            'nama_produk' => $name,
                            'id_kategori' => $categoryValue,
                            'harga' => $price,
                            'stok' => (int) $stock,
                            'deskripsi' => $description !== '' ? $description : null,
                            'gambar' => $imageValue,
                            'id_produk' => $id,
                        ]);
                        recordAdminActivity($pdo, 'Produk diperbarui', 'Memperbarui produk ' . $name, $id);
                        $pdo->commit();
                        if ($uploadedImage !== null || $removeImage) {
                            deleteProductImage($previousImage);
                        }
                        $_SESSION['product_flash'] = 'Data produk berhasil diperbarui.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        deleteProductImage($uploadedImage);
                        error_log('Perbarui produk: ' . $e->getMessage());
                        $errors[] = 'Produk tidak dapat diperbarui. Periksa log aplikasi untuk detailnya.';
                    }
                }

                if (!$errors) {
                    productRedirect();
                }
            } elseif ($uploadedImage !== null) {
                deleteProductImage($uploadedImage);
            }
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) {
                $errors[] = 'ID produk tidak valid.';
            } else {
                $productStmt = $pdo->prepare(
                    'SELECT gambar FROM tbl_produk WHERE id_produk = :id_produk LIMIT 1'
                );
                $productStmt->execute(['id_produk' => $id]);
                $imageToDelete = $productStmt->fetchColumn();
                $reference = $pdo->prepare(
                    'SELECT COUNT(*) FROM tbl_detail_transaksi WHERE id_produk = :id_produk'
                );
                $reference->execute(['id_produk' => $id]);
                if ($imageToDelete === false) {
                    $errors[] = 'Produk tidak ditemukan.';
                } elseif ((int) $reference->fetchColumn() > 0) {
                    $errors[] = 'Produk tidak dapat dihapus karena sudah tercatat pada detail transaksi.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $delete = $pdo->prepare('DELETE FROM tbl_produk WHERE id_produk = :id_produk');
                        $delete->execute(['id_produk' => $id]);
                        if ($delete->rowCount() === 0) {
                            $pdo->rollBack();
                            $errors[] = 'Produk tidak ditemukan.';
                        } else {
                            recordAdminActivity($pdo, 'Produk dihapus', 'Menghapus produk #' . $id, $id);
                            $pdo->commit();
                            deleteProductImage(is_string($imageToDelete) ? $imageToDelete : null);
                            $_SESSION['product_flash'] = 'Produk berhasil dihapus.';
                            productRedirect();
                        }
                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Hapus produk: ' . $e->getMessage());
                        $errors[] = 'Produk tidak dapat dihapus karena masih digunakan atau terjadi gangguan database.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Catat penghapusan produk: ' . $e->getMessage());
                        $errors[] = 'Produk tidak dapat dihapus. Periksa log aplikasi untuk detailnya.';
                    }
                }
            }
        } else {
            $errors[] = 'Aksi tidak dikenal.';
        }
    }
}

if (isset($_GET['edit'])) {
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    if ($editId && $editId > 0) {
        $stmt = $pdo->prepare(
            'SELECT id_produk, nama_produk, id_kategori, harga, stok, deskripsi, gambar
             FROM tbl_produk WHERE id_produk = :id_produk LIMIT 1'
        );
        $stmt->execute(['id_produk' => $editId]);
        $editingProduct = $stmt->fetch() ?: null;
        if ($editingProduct) {
            $formValues = [
                'nama_produk' => $editingProduct['nama_produk'],
                'id_kategori' => (string) ($editingProduct['id_kategori'] ?? ''),
                'harga' => $editingProduct['harga'],
                'stok' => (string) $editingProduct['stok'],
                'deskripsi' => $editingProduct['deskripsi'] ?? '',
            ];
            $editingImage = $editingProduct['gambar'] ?? null;
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$filterCategory = (string) ($_GET['category'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$where = [];
$params = [];

if ($search !== '') {
    $where[] = 'p.nama_produk LIKE :search';
    $params['search'] = '%' . $search . '%';
}
if (
    $filterCategory !== ''
    && ctype_digit($filterCategory)
    && in_array($filterCategory, array_column($categories, 'id_kategori'), false)
) {
    $where[] = 'p.id_kategori = :id_kategori';
    $params['id_kategori'] = (int) $filterCategory;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_produk p' . $whereSql);
$countStmt->execute($params);
$totalProducts = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    'SELECT p.id_produk, p.nama_produk, p.id_kategori, p.harga, p.stok, p.gambar, k.nama_kategori
     FROM tbl_produk p
     LEFT JOIN tbl_kategori k ON k.id_kategori = p.id_kategori'
     . $whereSql .
    ' ORDER BY p.id_produk DESC LIMIT :limit OFFSET :offset'
);
foreach ($params as $key => $value) {
    $listStmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$products = $listStmt->fetchAll();

$paginationQuery = array_filter(
    ['q' => $search, 'category' => $filterCategory],
    static fn (string $value): bool => $value !== ''
);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
    <?php $activeAdminPage = 'products'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-topbar">
            <div><div class="topbar-eyebrow">TOKOKU / ADMIN</div><h1>Manajemen Produk</h1><p class="topbar-subtitle">Kelola nama, kategori, harga, dan stok produk toko.</p></div>
            <div class="topbar-profile"><div class="profile-avatar"><?= e(strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1))) ?></div><div><strong><?= e($_SESSION['name'] ?? 'Admin') ?></strong><span>Administrator</span></div></div>
        </header>

        <?php if ($success): ?><div class="user-alert user-alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="user-alert user-alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>

        <section class="product-management-grid">
            <section class="admin-panel product-form-panel">
                <div class="panel-heading"><div><h2><?= $editingProduct ? 'Edit Produk' : 'Tambah Produk' ?></h2><p><?= $editingProduct ? 'Perbarui informasi produk.' : 'Tambahkan produk ke katalog toko.' ?></p></div></div>
                <form method="post" enctype="multipart/form-data" action="<?= e(appUrl('admin/products.php' . ($editingProduct ? '?edit=' . (int) $editingProduct['id_produk'] : ''))) ?>" class="user-form product-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="<?= $editingProduct ? 'update' : 'create' ?>">
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= PRODUCT_IMAGE_MAX_BYTES ?>">
                    <?php if ($editingProduct): ?><input type="hidden" name="id_produk" value="<?= (int) $editingProduct['id_produk'] ?>"><?php endif; ?>
                    <div class="user-field"><label for="nama_produk">Nama produk</label><input id="nama_produk" name="nama_produk" type="text" maxlength="100" required value="<?= e($formValues['nama_produk']) ?>" placeholder="Masukkan nama produk"></div>
                    <div class="user-field"><label for="id_kategori">Kategori</label><select id="id_kategori" name="id_kategori"><option value="">Tanpa kategori</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id_kategori'] ?>" <?= $formValues['id_kategori'] === (string) $category['id_kategori'] ? 'selected' : '' ?>><?= e($category['nama_kategori']) ?></option><?php endforeach; ?></select></div>
                    <div class="user-field"><label for="harga">Harga (Rp)</label><input id="harga" name="harga" type="number" min="0" step="0.01" required value="<?= e($formValues['harga']) ?>" placeholder="Contoh: 150000"></div>
                    <div class="user-field"><label for="stok">Stok</label><input id="stok" name="stok" type="number" min="0" step="1" required value="<?= e($formValues['stok']) ?>" placeholder="Jumlah stok"></div>
                    <div class="user-field"><label for="deskripsi">Deskripsi</label><textarea id="deskripsi" name="deskripsi" maxlength="5000" rows="4" placeholder="Deskripsi produk (opsional)"><?= e($formValues['deskripsi']) ?></textarea></div>
                    <div class="user-field">
                        <span class="product-image-label">Gambar produk</span>
                        <div class="product-image-editor" data-default-src="<?= e(appUrl('assets/images/product-placeholder.svg')) ?>" data-current-image="<?= $editingImage ? '1' : '0' ?>">
                            <img class="product-image-preview" src="<?= e($editingProduct ? productImageUrl($editingProduct) : appUrl('assets/images/product-placeholder.svg')) ?>" alt="Preview gambar <?= e($editingProduct['nama_produk'] ?? 'produk') ?>">
                            <div class="product-image-controls">
                                <input class="product-image-input" id="gambar" name="gambar" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                <label class="user-btn user-btn-light product-image-select" for="gambar"><?= $editingImage ? 'Ganti Gambar' : 'Pilih Gambar' ?></label>
                                <button class="user-btn user-btn-light product-image-remove" type="button" <?= $editingImage ? '' : 'hidden' ?>>Hapus Gambar</button>
                                <input type="hidden" name="hapus_gambar" value="0">
                                <span class="product-image-filename">JPG, JPEG, PNG, WEBP · Maksimal 2 MB</span>
                            </div>
                        </div>
                    </div>
                    <div class="user-form-actions"><button class="user-btn user-btn-primary" type="submit"><?= $editingProduct ? 'Simpan Perubahan' : 'Tambah Produk' ?></button><?php if ($editingProduct): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/products.php')) ?>">Batal</a><?php endif; ?></div>
                    <p class="product-schema-note">Gambar diproses dan divalidasi di server sebelum disimpan.</p>
                </form>
            </section>

            <section class="admin-panel product-list-panel">
                <div class="panel-heading"><div><h2>Daftar Produk</h2><p>Data produk dari database toko.</p></div><span class="panel-count"><?= number_format($totalProducts) ?> produk</span></div>
                <form method="get" class="user-filter-form">
                    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cari nama produk..." aria-label="Cari nama produk">
                    <select name="category" aria-label="Filter kategori"><option value="">Semua kategori</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id_kategori'] ?>" <?= $filterCategory === (string) $category['id_kategori'] ? 'selected' : '' ?>><?= e($category['nama_kategori']) ?></option><?php endforeach; ?></select>
                    <button class="user-btn user-btn-primary" type="submit">Cari</button>
                    <?php if ($search !== '' || $filterCategory !== ''): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/products.php')) ?>">Reset</a><?php endif; ?>
                </form>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Produk</th><th>Kategori</th><th>Harga</th><th>Stok</th><th>Aksi</th></tr></thead>
                        <tbody>
                        <?php if (!$products): ?>
                            <tr><td colspan="5" class="table-empty">Tidak ada produk yang cocok dengan pencarian.</td></tr>
                        <?php else: foreach ($products as $product): ?>
                            <tr>
                                <td><div class="table-user"><img class="product-table-image" src="<?= e(productImageUrl($product)) ?>" alt="" loading="lazy"><strong><?= e($product['nama_produk']) ?></strong></div></td>
                                <td><?= e($product['nama_kategori'] ?? 'Tanpa kategori') ?></td>
                                <td>Rp <?= e(number_format((float) $product['harga'], 0, ',', '.')) ?></td>
                                <td><span class="stock-badge <?= (int) $product['stok'] <= 5 ? 'stock-low' : 'stock-normal' ?>"><?= number_format((int) $product['stok']) ?></span></td>
                                <td><div class="user-row-actions"><a class="user-action-edit" href="<?= e(appUrl('admin/products.php?edit=' . (int) $product['id_produk'])) ?>">Edit</a><form method="post" onsubmit="return confirm('Yakin ingin menghapus produk ini?')"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>"><button class="user-action-delete" type="submit">Hapus</button></form></div></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="product-pagination" aria-label="Pagination produk">
                        <span>Halaman <?= $page ?> dari <?= $totalPages ?></span>
                        <div>
                            <?php if ($page > 1): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/products.php?' . http_build_query($paginationQuery + ['page' => $page - 1]))) ?>">Sebelumnya</a><?php endif; ?>
                            <?php if ($page < $totalPages): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/products.php?' . http_build_query($paginationQuery + ['page' => $page + 1]))) ?>">Berikutnya</a><?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            </section>
        </section>

        <footer class="admin-footer"><span>© <?= date('Y') ?> Tokoku</span><span>Manajemen Produk</span></footer>
    </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
