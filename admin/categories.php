<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_activity.php';

$pageTitle = 'Manajemen Kategori - Tokoku';
$success = $_SESSION['category_flash'] ?? '';
unset($_SESSION['category_flash']);
$errors = [];
$editingCategory = null;
$categoryName = '';

function categoryRedirect(): void
{
    header('Location: ' . appUrl('admin/categories.php'));
    exit;
}

function validCategoryCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $categoryName = trim((string) ($_POST['nama_kategori'] ?? ''));
    if (!validCategoryCsrf()) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman dan coba kembali.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create' || $action === 'update') {
            $id = (int) ($_POST['id_kategori'] ?? 0);
            if ($categoryName === '' || mb_strlen($categoryName) > 100) {
                $errors[] = 'Nama kategori wajib diisi dan maksimal 100 karakter.';
            }
            if ($action === 'update' && $id < 1) {
                $errors[] = 'ID kategori tidak valid.';
            }
            if (!$errors) {
                $duplicate = $pdo->prepare(
                    'SELECT id_kategori FROM tbl_kategori
                     WHERE nama_kategori = :nama_kategori AND id_kategori <> :id_kategori LIMIT 1'
                );
                $duplicate->execute([
                    'nama_kategori' => $categoryName,
                    'id_kategori' => $action === 'update' ? $id : 0,
                ]);
                if ($duplicate->fetch()) {
                    $errors[] = 'Nama kategori sudah digunakan.';
                }
            }
            if (!$errors) {
                if ($action === 'create') {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare('INSERT INTO tbl_kategori (nama_kategori) VALUES (:nama_kategori)');
                        $stmt->execute(['nama_kategori' => $categoryName]);
                        recordAdminActivity(
                            $pdo,
                            'Kategori ditambahkan',
                            'Menambahkan kategori ' . $categoryName,
                            (int) $pdo->lastInsertId()
                        );
                        $pdo->commit();
                        $_SESSION['category_flash'] = 'Kategori berhasil ditambahkan.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Tambah kategori: ' . $e->getMessage());
                        $errors[] = 'Kategori tidak dapat ditambahkan. Periksa log aplikasi untuk detailnya.';
                    }
                } else {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare(
                            'UPDATE tbl_kategori SET nama_kategori = :nama_kategori WHERE id_kategori = :id_kategori'
                        );
                        $stmt->execute(['nama_kategori' => $categoryName, 'id_kategori' => $id]);
                        recordAdminActivity(
                            $pdo,
                            'Kategori diperbarui',
                            'Memperbarui kategori ' . $categoryName,
                            $id
                        );
                        $pdo->commit();
                        $_SESSION['category_flash'] = 'Kategori berhasil diperbarui.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Perbarui kategori: ' . $e->getMessage());
                        $errors[] = 'Kategori tidak dapat diperbarui. Periksa log aplikasi untuk detailnya.';
                    }
                }
                if (!$errors) {
                    categoryRedirect();
                }
            }
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['id_kategori'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) {
                $errors[] = 'ID kategori tidak valid.';
            } else {
                $usage = $pdo->prepare('SELECT COUNT(*) FROM tbl_produk WHERE id_kategori = :id_kategori');
                $usage->execute(['id_kategori' => $id]);
                if ((int) $usage->fetchColumn() > 0) {
                    $errors[] = 'Kategori tidak dapat dihapus selama masih digunakan oleh produk.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $delete = $pdo->prepare('DELETE FROM tbl_kategori WHERE id_kategori = :id_kategori');
                        $delete->execute(['id_kategori' => $id]);
                        if ($delete->rowCount() === 0) {
                            $pdo->rollBack();
                            $errors[] = 'Kategori tidak ditemukan.';
                        } else {
                            recordAdminActivity($pdo, 'Kategori dihapus', 'Menghapus kategori #' . $id, $id);
                            $pdo->commit();
                            $_SESSION['category_flash'] = 'Kategori berhasil dihapus.';
                            categoryRedirect();
                        }
                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Hapus kategori: ' . $e->getMessage());
                        $errors[] = 'Kategori tidak dapat dihapus karena masih digunakan atau terjadi gangguan database.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Catat penghapusan kategori: ' . $e->getMessage());
                        $errors[] = 'Kategori tidak dapat dihapus. Periksa log aplikasi untuk detailnya.';
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
            'SELECT id_kategori, nama_kategori FROM tbl_kategori WHERE id_kategori = :id_kategori LIMIT 1'
        );
        $stmt->execute(['id_kategori' => $editId]);
        $editingCategory = $stmt->fetch() ?: null;
        if ($editingCategory) {
            $categoryName = $editingCategory['nama_kategori'];
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$searchSql = $search !== '' ? ' WHERE k.nama_kategori LIKE :search' : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM tbl_kategori k' . $searchSql);
if ($search !== '') {
    $countStmt->bindValue(':search', '%' . $search . '%');
}
$countStmt->execute();
$totalCategories = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCategories / $perPage));
$page = min($page, $totalPages);
$listStmt = $pdo->prepare(
    'SELECT k.id_kategori, k.nama_kategori, COUNT(p.id_produk) AS jumlah_produk
     FROM tbl_kategori k
     LEFT JOIN tbl_produk p ON p.id_kategori = k.id_kategori'
     . ($search !== '' ? ' WHERE k.nama_kategori LIKE :search' : '') .
    ' GROUP BY k.id_kategori, k.nama_kategori
     ORDER BY k.nama_kategori
     LIMIT :limit OFFSET :offset'
);
if ($search !== '') {
    $listStmt->bindValue(':search', '%' . $search . '%');
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$listStmt->execute();
$categories = $listStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
    <?php $activeAdminPage = 'categories'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    <main class="admin-main">
        <header class="admin-topbar">
            <div><div class="topbar-eyebrow">TOKOKU / ADMIN</div><h1>Manajemen Kategori</h1><p class="topbar-subtitle">Atur kelompok produk dan pantau produk pada setiap kategori.</p></div>
            <div class="topbar-profile"><div class="profile-avatar"><?= e(strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1))) ?></div><div><strong><?= e($_SESSION['name'] ?? 'Admin') ?></strong><span>Administrator</span></div></div>
        </header>
        <?php if ($success): ?><div class="user-alert user-alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="user-alert user-alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>
        <section class="category-management-grid">
            <section class="admin-panel category-form-panel">
                <div class="panel-heading"><div><h2><?= $editingCategory ? 'Edit Kategori' : 'Tambah Kategori' ?></h2><p><?= $editingCategory ? 'Ubah nama kategori produk.' : 'Buat kelompok baru untuk produk.' ?></p></div></div>
                <form method="post" action="<?= e(appUrl('admin/categories.php' . ($editingCategory ? '?edit=' . (int) $editingCategory['id_kategori'] : ''))) ?>" class="user-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="<?= $editingCategory ? 'update' : 'create' ?>">
                    <?php if ($editingCategory): ?><input type="hidden" name="id_kategori" value="<?= (int) $editingCategory['id_kategori'] ?>"><?php endif; ?>
                    <div class="user-field"><label for="nama_kategori">Nama kategori</label><input id="nama_kategori" name="nama_kategori" type="text" maxlength="100" required value="<?= e($categoryName) ?>" placeholder="Contoh: Peralatan Rumah"></div>
                    <div class="user-form-actions"><button class="user-btn user-btn-primary" type="submit"><?= $editingCategory ? 'Simpan Perubahan' : 'Tambah Kategori' ?></button><?php if ($editingCategory): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/categories.php')) ?>">Batal</a><?php endif; ?></div>
                    <p class="product-schema-note">Kategori yang masih dipakai produk tidak dapat dihapus.</p>
                </form>
            </section>
            <section class="admin-panel category-list-panel">
                <div class="panel-heading"><div><h2>Daftar Kategori</h2><p>Kategori yang digunakan untuk mengelompokkan produk.</p></div><span class="panel-count"><?= number_format($totalCategories) ?> kategori</span></div>
                <form method="get" class="user-filter-form">
                    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cari nama kategori..." aria-label="Cari nama kategori">
                    <button class="user-btn user-btn-primary" type="submit">Cari</button>
                    <?php if ($search !== ''): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/categories.php')) ?>">Reset</a><?php endif; ?>
                </form>
                <div class="table-wrap">
                    <table class="admin-table"><thead><tr><th>Kategori</th><th>Jumlah Produk</th><th>Aksi</th></tr></thead><tbody>
                    <?php if (!$categories): ?><tr><td colspan="3" class="table-empty">Tidak ada kategori yang cocok.</td></tr><?php else: foreach ($categories as $category): ?>
                        <tr>
                            <td><div class="table-user"><span class="product-table-icon">▤</span><strong><?= e($category['nama_kategori']) ?></strong></div></td>
                            <td><?= number_format((int) $category['jumlah_produk']) ?> produk</td>
                            <td><div class="user-row-actions"><a class="user-action-edit" href="<?= e(appUrl('admin/categories.php?edit=' . (int) $category['id_kategori'])) ?>">Edit</a><form method="post" onsubmit="return confirm('Yakin ingin menghapus kategori ini?')"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id_kategori" value="<?= (int) $category['id_kategori'] ?>"><button class="user-action-delete" type="submit" <?= (int) $category['jumlah_produk'] > 0 ? 'disabled title="Kategori masih digunakan"' : '' ?>>Hapus</button></form></div></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody></table>
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="product-pagination" aria-label="Pagination kategori">
                        <span>Halaman <?= $page ?> dari <?= $totalPages ?></span>
                        <div>
                            <?php if ($page > 1): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/categories.php?' . http_build_query(['q' => $search, 'page' => $page - 1]))) ?>">Sebelumnya</a><?php endif; ?>
                            <?php if ($page < $totalPages): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/categories.php?' . http_build_query(['q' => $search, 'page' => $page + 1]))) ?>">Berikutnya</a><?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            </section>
        </section>
        <footer class="admin-footer"><span>© <?= date('Y') ?> Tokoku</span><span>Manajemen Kategori</span></footer>
    </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
