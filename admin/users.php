<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_activity.php';

$pageTitle = 'Manajemen Pengguna - Tokoku';
$roles = ['admin', 'kasir', 'pelanggan'];
$errors = [];
$success = $_SESSION['user_flash'] ?? '';
unset($_SESSION['user_flash']);
$editingUser = null;

function userRedirect(): void
{
    header('Location: ' . appUrl('admin/users.php'));
    exit;
}

function validUserCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validUserCsrf()) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman dan coba kembali.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create' || $action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $role = (string) ($_POST['role'] ?? 'pelanggan');
            $password = (string) ($_POST['password'] ?? '');

            if ($name === '' || mb_strlen($name) > 255) {
                $errors[] = 'Nama wajib diisi dan maksimal 255 karakter.';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                $errors[] = 'Masukkan alamat email yang valid (maksimal 255 karakter).';
            }
            if (!in_array($role, $roles, true)) {
                $errors[] = 'Role pengguna tidak valid.';
            }
            if ($action === 'create' && strlen($password) < 8) {
                $errors[] = 'Password untuk pengguna baru minimal 8 karakter.';
            }
            if ($action === 'update' && $password !== '' && strlen($password) < 8) {
                $errors[] = 'Password baru minimal 8 karakter atau kosongkan jika tidak ingin mengubahnya.';
            }

            if (!$errors) {
                $check = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
                $check->execute(['email' => $email, 'id' => $action === 'update' ? $id : 0]);
                if ($check->fetch()) {
                    $errors[] = 'Email tersebut sudah digunakan oleh pengguna lain.';
                }
            }

            if (!$errors) {
                if ($action === 'create') {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare(
                            'INSERT INTO users (name, email, password, role, created_at, updated_at)
                             VALUES (:name, :email, :password, :role, NOW(), NOW())'
                        );
                        $stmt->execute([
                            'name' => $name,
                            'email' => $email,
                            'password' => password_hash($password, PASSWORD_DEFAULT),
                            'role' => $role
                        ]);
                        recordAdminActivity(
                            $pdo,
                            'Pengguna ditambahkan',
                            'Menambahkan akun ' . $email . ' dengan role ' . $role,
                            (int) $pdo->lastInsertId()
                        );
                        $pdo->commit();
                        $_SESSION['user_flash'] = 'Pengguna baru berhasil ditambahkan.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Tambah pengguna: ' . $e->getMessage());
                        $errors[] = 'Pengguna tidak dapat ditambahkan. Periksa log aplikasi untuk detailnya.';
                    }
                } else {
                    if ($id < 1) {
                        $errors[] = 'ID pengguna tidak valid.';
                    } else {
                        $isSelf = $id === (int) ($_SESSION['user_id'] ?? 0);
                        $currentUserStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
                        $currentUserStmt->execute(['id' => $id]);
                        $currentUserRole = $currentUserStmt->fetchColumn();
                        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();

                        if ($isSelf && $role !== 'admin') {
                            $errors[] = 'Role akun yang sedang digunakan tidak dapat diubah dari Admin.';
                        } elseif ($currentUserRole === 'admin' && $role !== 'admin' && $adminCount <= 1) {
                            $errors[] = 'Role Admin terakhir tidak dapat diturunkan karena sistem harus memiliki minimal satu Admin.';
                        } else {
                            $pdo->beginTransaction();
                            try {
                                if ($password !== '') {
                                    $stmt = $pdo->prepare(
                                        'UPDATE users SET name = :name, email = :email, role = :role,
                                         password = :password, updated_at = NOW() WHERE id = :id'
                                    );
                                    $params = [
                                        'name' => $name, 'email' => $email, 'role' => $role,
                                        'password' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id
                                    ];
                                } else {
                                    $stmt = $pdo->prepare(
                                        'UPDATE users SET name = :name, email = :email, role = :role,
                                         updated_at = NOW() WHERE id = :id'
                                    );
                                    $params = ['name' => $name, 'email' => $email, 'role' => $role, 'id' => $id];
                                }
                                $stmt->execute($params);
                                recordAdminActivity(
                                    $pdo,
                                    'Pengguna diperbarui',
                                    'Memperbarui akun ' . $email . ' dengan role ' . $role,
                                    $id
                                );
                                $pdo->commit();
                                if ($isSelf) {
                                    $_SESSION['name'] = $name;
                                    $_SESSION['email'] = $email;
                                }
                                $_SESSION['user_flash'] = 'Data pengguna berhasil diperbarui.';
                            } catch (Throwable $e) {
                                if ($pdo->inTransaction()) {
                                    $pdo->rollBack();
                                }
                                error_log('Perbarui pengguna: ' . $e->getMessage());
                                $errors[] = 'Pengguna tidak dapat diperbarui. Periksa log aplikasi untuk detailnya.';
                            }
                        }
                    }
                }

                if (!$errors) {
                    userRedirect();
                }
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                $errors[] = 'ID pengguna tidak valid.';
            } elseif ($id === (int) ($_SESSION['user_id'] ?? 0)) {
                $errors[] = 'Akun yang sedang digunakan tidak dapat dihapus.';
            } else {
                $target = $pdo->prepare('SELECT id, email, role FROM users WHERE id = :id LIMIT 1');
                $target->execute(['id' => $id]);
                $user = $target->fetch();
                if (!$user) {
                    $errors[] = 'Pengguna tidak ditemukan.';
                } elseif ($user['role'] === 'admin') {
                    $countAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                    if ($countAdmins <= 1) {
                        $errors[] = 'Admin terakhir tidak dapat dihapus.';
                    }
                }
                if (!$errors) {
                    $pdo->beginTransaction();
                    try {
                        $delete = $pdo->prepare('DELETE FROM users WHERE id = :id');
                        $delete->execute(['id' => $id]);
                        recordAdminActivity(
                            $pdo,
                            'Pengguna dihapus',
                            'Menghapus akun ' . $user['email'],
                            (int) $user['id']
                        );
                        $pdo->commit();
                        $_SESSION['user_flash'] = 'Pengguna berhasil dihapus.';
                        userRedirect();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Hapus pengguna: ' . $e->getMessage());
                        $errors[] = 'Pengguna tidak dapat dihapus. Periksa log aplikasi untuk detailnya.';
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
    if ($editId) {
        $stmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $editId]);
        $editingUser = $stmt->fetch() ?: null;
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$filterRole = (string) ($_GET['role'] ?? '');
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE :search_name OR email LIKE :search_email)';
    $params['search_name'] = '%' . $search . '%';
    $params['search_email'] = '%' . $search . '%';
}
if (in_array($filterRole, $roles, true)) {
    $where[] = 'role = :role';
    $params['role'] = $filterRole;
}
$sql = 'SELECT id, name, email, role, created_at FROM users';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY id DESC';
$listStmt = $pdo->prepare($sql);
$listStmt->execute($params);
$users = $listStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
    <?php $activeAdminPage = 'users'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-topbar"><div><div class="topbar-eyebrow">TOKOKU / ADMIN</div><h1>Manajemen Pengguna</h1></div><div class="topbar-profile"><div class="profile-avatar"><?= e(strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1))) ?></div><div><strong><?= e($_SESSION['name'] ?? 'Admin') ?></strong><span>Administrator</span></div></div></header>

        <?php if ($success): ?><div class="user-alert user-alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="user-alert user-alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>

        <section class="user-management-grid">
            <section class="admin-panel user-form-panel">
                <div class="panel-heading"><div><h2><?= $editingUser ? 'Edit Pengguna' : 'Tambah Pengguna' ?></h2><p><?= $editingUser ? 'Perbarui informasi akun pengguna.' : 'Buat akun baru untuk sistem Tokoku.' ?></p></div></div>
                <form method="post" action="<?= e(appUrl('admin/users.php' . ($editingUser ? '?edit=' . (int) $editingUser['id'] : ''))) ?>" class="user-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="<?= $editingUser ? 'update' : 'create' ?>">
                    <?php if ($editingUser): ?><input type="hidden" name="id" value="<?= (int) $editingUser['id'] ?>"><?php endif; ?>
                    <div class="user-field"><label for="name">Nama lengkap</label><input id="name" name="name" type="text" maxlength="255" required value="<?= e($editingUser['name'] ?? '') ?>" placeholder="Masukkan nama pengguna"></div>
                    <div class="user-field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="255" required value="<?= e($editingUser['email'] ?? '') ?>" placeholder="nama@email.com"></div>
                    <div class="user-field"><label for="role">Role</label><select id="role" name="role" required><?php foreach ($roles as $role): ?><option value="<?= e($role) ?>" <?= ($editingUser['role'] ?? 'pelanggan') === $role ? 'selected' : '' ?>><?= e(ucfirst($role)) ?></option><?php endforeach; ?></select></div>
                    <div class="user-field"><label for="password">Password <?= $editingUser ? '(opsional)' : '' ?></label><input id="password" name="password" type="password" minlength="8" <?= $editingUser ? '' : 'required' ?> autocomplete="new-password" placeholder="<?= $editingUser ? 'Kosongkan jika tidak diubah' : 'Minimal 8 karakter' ?>"></div>
                    <div class="user-form-actions"><button class="user-btn user-btn-primary" type="submit"><?= $editingUser ? 'Simpan Perubahan' : 'Tambah Pengguna' ?></button><?php if ($editingUser): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/users.php')) ?>">Batal</a><?php endif; ?></div>
                </form>
            </section>

            <section class="admin-panel user-list-panel">
                <div class="panel-heading"><div><h2>Daftar Pengguna</h2><p>Kelola seluruh akun yang terdaftar.</p></div><span class="panel-count"><?= count($users) ?> pengguna</span></div>
                <form method="get" class="user-filter-form">
                    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cari nama atau email..." aria-label="Cari nama atau email">
                    <select name="role" aria-label="Filter role"><option value="">Semua role</option><?php foreach ($roles as $role): ?><option value="<?= e($role) ?>" <?= $filterRole === $role ? 'selected' : '' ?>><?= e(ucfirst($role)) ?></option><?php endforeach; ?></select>
                    <button class="user-btn user-btn-primary" type="submit">Cari</button>
                    <?php if ($search !== '' || $filterRole !== ''): ?><a class="user-btn user-btn-light" href="<?= e(appUrl('admin/users.php')) ?>">Reset</a><?php endif; ?>
                </form>
                <div class="table-wrap"><table class="admin-table"><thead><tr><th>Pengguna</th><th>Email</th><th>Role</th><th>Tanggal Dibuat</th><th>Aksi</th></tr></thead><tbody>
                <?php if (!$users): ?><tr><td colspan="5" class="table-empty">Tidak ada pengguna yang cocok dengan pencarian.</td></tr><?php else: foreach ($users as $user): ?>
                    <?php $roleClass = ['admin' => 'role-admin', 'kasir' => 'role-kasir', 'pelanggan' => 'role-pelanggan'][$user['role']] ?? 'role-default'; ?>
                    <tr>
                        <td><div class="table-user"><span class="table-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span><strong><?= e($user['name']) ?></strong></div></td>
                        <td><?= e($user['email']) ?></td>
                        <td><span class="role-badge <?= e($roleClass) ?>"><?= e(ucfirst($user['role'])) ?></span></td>
                        <td><?= $user['created_at'] ? e(date('d/m/Y', strtotime($user['created_at']))) : '-' ?></td>
                        <td><div class="user-row-actions"><a class="user-action-edit" href="<?= e(appUrl('admin/users.php?edit=' . (int) $user['id'])) ?>">Edit</a><?php if ((int) $user['id'] !== (int) ($_SESSION['user_id'] ?? 0)): ?><form method="post" onsubmit="return confirm('Yakin ingin menghapus pengguna ini?')"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><button class="user-action-delete" type="submit">Hapus</button></form><?php else: ?><span class="user-self-label">Akun Anda</span><?php endif; ?></div></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody></table></div>
            </section>
        </section>
        <footer class="admin-footer"><span>© <?= date('Y') ?> Tokoku</span><span>Manajemen Pengguna</span></footer>
    </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
