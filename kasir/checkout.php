<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('kasir');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_activity.php';

$pageTitle = 'Transaksi Baru - Tokoku';
$errors = [];
$quantities = [];
$customerId = '';
$guestName = '';
$amountReceived = '';
$success = $_SESSION['cashier_flash'] ?? '';
unset($_SESSION['cashier_flash']);

function cashierMoneyToCents(string $amount): ?int
{
    if (!preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/', $amount)) {
        return null;
    }

    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

function cashierFormatCents(int $amount): string
{
    return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
}

function cashierRedirect(string $path): void
{
    header('Location: ' . appUrl($path));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $customerInput = $_POST['id_pelanggan'] ?? '';
    $guestInput = $_POST['nama_pelanggan'] ?? '';
    $receivedInput = $_POST['uang_diterima'] ?? '';
    $customerId = is_string($customerInput) ? trim($customerInput) : '';
    $guestName = is_string($guestInput) ? trim($guestInput) : '';
    $amountReceived = is_string($receivedInput) ? trim($receivedInput) : '';
    $postedQuantities = $_POST['quantities'] ?? [];

    if (!is_string($customerInput) || !is_string($guestInput) || !is_string($receivedInput)) {
        $errors[] = 'Data pelanggan atau pembayaran tidak valid.';
    }
    $token = $_POST['csrf_token'] ?? '';
    if (
        !is_string($token)
        || !isset($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $errors[] = 'Sesi formulir tidak valid. Muat ulang halaman dan coba kembali.';
    }
    if (!is_array($postedQuantities)) {
        $errors[] = 'Daftar jumlah produk tidak valid.';
        $postedQuantities = [];
    }
    if (mb_strlen($guestName) > 100) {
        $errors[] = 'Nama pelanggan maksimal 100 karakter.';
    }
    if ($customerId !== '' && !ctype_digit($customerId)) {
        $errors[] = 'Pelanggan yang dipilih tidak valid.';
    }
    if ($customerId !== '' && $guestName !== '') {
        $errors[] = 'Pilih pelanggan terdaftar atau isi nama pelanggan umum, bukan keduanya.';
    }

    foreach ($postedQuantities as $productId => $quantity) {
        if (!is_scalar($quantity) || !ctype_digit((string) $productId) || !ctype_digit((string) $quantity)) {
            $errors[] = 'Jumlah produk tidak valid.';
            break;
        }
        $quantity = (int) $quantity;
        if ($quantity < 0 || $quantity > 999) {
            $errors[] = 'Jumlah setiap produk harus antara 0 dan 999.';
            break;
        }
        if ($quantity > 0) {
            $quantities[(int) $productId] = $quantity;
        }
    }
    if (!$quantities) {
        $errors[] = 'Pilih minimal satu produk dengan jumlah lebih dari nol.';
    }
    $receivedCents = cashierMoneyToCents($amountReceived);
    if ($receivedCents === null) {
        $errors[] = 'Jumlah uang diterima wajib diisi dengan angka maksimal 2 desimal.';
    }

    if (!$errors) {
        $ids = array_keys($quantities);
        sort($ids, SORT_NUMERIC);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        try {
            $pdo->beginTransaction();
            $productStmt = $pdo->prepare(
                'SELECT id_produk, nama_produk, harga, stok
                 FROM tbl_produk
                 WHERE id_produk IN (' . $placeholders . ')
                 ORDER BY id_produk
                 FOR UPDATE'
            );
            $productStmt->execute($ids);
            $products = $productStmt->fetchAll();
            if (count($products) !== count($quantities)) {
                throw new RuntimeException('Salah satu produk tidak lagi tersedia.');
            }

            $totalCents = 0;
            foreach ($products as $product) {
                $id = (int) $product['id_produk'];
                if ($quantities[$id] > (int) $product['stok']) {
                    throw new RuntimeException('Stok ' . $product['nama_produk'] . ' tidak mencukupi.');
                }
                $priceCents = cashierMoneyToCents((string) $product['harga']);
                if ($priceCents === null || $priceCents > intdiv(999999999999 - $totalCents, $quantities[$id])) {
                    throw new RuntimeException('Total transaksi melebihi batas yang dapat diproses.');
                }
                $totalCents += $priceCents * $quantities[$id];
            }
            if ($receivedCents < $totalCents) {
                throw new RuntimeException('Uang diterima kurang dari total belanja.');
            }

            $selectedCustomerId = null;
            if ($customerId !== '') {
                $customerStmt = $pdo->prepare(
                    'SELECT id_pelanggan FROM tbl_pelanggan
                     WHERE id_pelanggan = :id_pelanggan LIMIT 1 FOR UPDATE'
                );
                $customerStmt->execute(['id_pelanggan' => (int) $customerId]);
                $foundCustomerId = $customerStmt->fetchColumn();
                if ($foundCustomerId === false) {
                    throw new RuntimeException('Pelanggan yang dipilih tidak ditemukan.');
                }
                $selectedCustomerId = (int) $foundCustomerId;
            } elseif ($guestName !== '') {
                $createCustomer = $pdo->prepare(
                    'INSERT INTO tbl_pelanggan (nama_pelanggan) VALUES (:nama_pelanggan)'
                );
                $createCustomer->execute(['nama_pelanggan' => $guestName]);
                $selectedCustomerId = (int) $pdo->lastInsertId();
            }

            $changeCents = $receivedCents - $totalCents;
            $transactionStmt = $pdo->prepare(
                'INSERT INTO tbl_transaksi
                 (tanggal, id_pelanggan, total_belanja, metode_pembayaran,
                  uang_diterima, kembalian, id_kasir)
                 VALUES (CURDATE(), :id_pelanggan, :total_belanja, :metode_pembayaran,
                         :uang_diterima, :kembalian, :id_kasir)'
            );
            $transactionStmt->execute([
                'id_pelanggan' => $selectedCustomerId,
                'total_belanja' => cashierFormatCents($totalCents),
                'metode_pembayaran' => 'Tunai',
                'uang_diterima' => cashierFormatCents($receivedCents),
                'kembalian' => cashierFormatCents($changeCents),
                'id_kasir' => (int) $_SESSION['user_id'],
            ]);
            $transactionId = (int) $pdo->lastInsertId();

            $detailStmt = $pdo->prepare(
                'INSERT INTO tbl_detail_transaksi
                 (id_transaksi, nama_produk, jumlah, harga, id_produk)
                 VALUES (:id_transaksi, :nama_produk, :jumlah, :harga, :id_produk)'
            );
            $stockStmt = $pdo->prepare(
                'UPDATE tbl_produk SET stok = stok - :quantity
                 WHERE id_produk = :id_produk AND stok >= :minimum_stock'
            );
            foreach ($products as $product) {
                $id = (int) $product['id_produk'];
                $quantity = $quantities[$id];
                $stockStmt->execute([
                    'quantity' => $quantity,
                    'id_produk' => $id,
                    'minimum_stock' => $quantity,
                ]);
                if ($stockStmt->rowCount() !== 1) {
                    throw new RuntimeException('Stok ' . $product['nama_produk'] . ' berubah. Coba kembali.');
                }
                $detailStmt->execute([
                    'id_transaksi' => $transactionId,
                    'nama_produk' => $product['nama_produk'],
                    'jumlah' => $quantity,
                    'harga' => $product['harga'],
                    'id_produk' => $id,
                ]);
            }
            recordAdminActivity(
                $pdo,
                'Transaksi kasir',
                'Mencatat transaksi TRX-' . str_pad((string) $transactionId, 3, '0', STR_PAD_LEFT),
                $transactionId
            );
            $pdo->commit();

            cashierRedirect('kasir/receipt.php?id=' . $transactionId);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Database transaksi kasir: ' . $e->getMessage());
            $errors[] = 'Transaksi tidak dapat disimpan karena terjadi gangguan database.';
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Transaksi kasir: ' . $e->getMessage());
            $errors[] = 'Transaksi tidak dapat diproses. Periksa log aplikasi untuk detailnya.';
        }
    }
}

$products = $pdo->query(
    'SELECT id_produk, nama_produk, harga, stok
     FROM tbl_produk
     ORDER BY nama_produk'
)->fetchAll();
$customers = $pdo->query(
    'SELECT id_pelanggan, nama_pelanggan
     FROM tbl_pelanggan
     ORDER BY nama_pelanggan, id_pelanggan'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
    <aside class="admin-sidebar cashier-sidebar">
        <a class="admin-brand" href="<?= e(appUrl('kasir/dashboard.php')) ?>"><span class="admin-brand-mark">T</span><span>Tokoku</span></a>
        <span class="admin-sidebar-subtitle">Panel Kasir</span>
        <div class="sidebar-caption">OPERASIONAL</div>
        <nav class="admin-nav" aria-label="Navigasi kasir">
            <a class="admin-nav-link" href="<?= e(appUrl('kasir/dashboard.php')) ?>"><span class="nav-icon">▦</span><span>Dashboard</span></a>
            <a class="admin-nav-link active" href="<?= e(appUrl('kasir/checkout.php')) ?>" aria-current="page"><span class="nav-icon">＋</span><span>Transaksi Baru</span></a>
            <a class="admin-nav-link" href="<?= e(appUrl('kasir/dashboard.php#transaksi')) ?>"><span class="nav-icon">▧</span><span>Transaksi Terbaru</span></a>
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-user"><div class="user-avatar"><?= e(strtoupper(substr($_SESSION['name'] ?? 'K', 0, 1))) ?></div><div class="sidebar-user-info"><strong><?= e($_SESSION['name'] ?? 'Kasir') ?></strong><span>Kasir</span></div></div>
            <a class="sidebar-logout" href="<?= e(appUrl('index.php?logout=1')) ?>">Keluar dari akun</a>
        </div>
    </aside>
    <main class="admin-main cashier-main">
        <header class="admin-topbar">
            <div><div class="topbar-eyebrow">TOKOKU / OPERASIONAL</div><h1>Transaksi Baru</h1><p class="topbar-subtitle">Pilih produk, catat pembayaran tunai, lalu cetak nota.</p></div>
            <div class="topbar-profile"><div class="profile-avatar"><?= e(strtoupper(substr($_SESSION['name'] ?? 'K', 0, 1))) ?></div><div><strong><?= e($_SESSION['name'] ?? 'Kasir') ?></strong><span>Kasir</span></div></div>
        </header>
        <?php if ($success): ?><div class="user-alert user-alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="user-alert user-alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>

        <form method="post" class="cashier-checkout-form">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <section class="admin-panel cashier-product-panel">
                <div class="panel-heading"><div><h2>Pilih Produk</h2><p>Masukkan jumlah barang yang dibeli.</p></div><span class="panel-count"><?= number_format(count($products)) ?> produk</span></div>
                <div class="table-wrap">
                    <table class="admin-table cashier-product-table">
                        <thead><tr><th>Produk</th><th>Harga</th><th>Stok</th><th>Jumlah</th><th>Subtotal</th></tr></thead>
                        <tbody>
                        <?php foreach ($products as $product): $productId = (int) $product['id_produk']; ?>
                            <tr>
                                <td><strong><?= e($product['nama_produk']) ?></strong></td>
                                <td>Rp <?= e(number_format((float) $product['harga'], 0, ',', '.')) ?></td>
                                <td><?= number_format((int) $product['stok']) ?></td>
                                <td><input class="cashier-quantity-input" type="number" name="quantities[<?= $productId ?>]" min="0" max="<?= min(999, (int) $product['stok']) ?>" value="<?= (int) ($quantities[$productId] ?? 0) ?>" data-price-cents="<?= (int) cashierMoneyToCents((string) $product['harga']) ?>" data-subtotal-target="cashier-subtotal-<?= $productId ?>" aria-label="Jumlah <?= e($product['nama_produk']) ?>" <?= (int) $product['stok'] < 1 ? 'disabled' : '' ?>></td>
                                <td class="cashier-line-subtotal" id="cashier-subtotal-<?= $productId ?>" data-cents="0">Rp 0</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$products): ?><tr><td colspan="5" class="table-empty">Belum ada produk di katalog.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="cashier-bottom-grid">
                <section class="admin-panel cashier-payment-panel">
                    <div class="panel-heading"><div><h2>Pelanggan & Pembayaran</h2><p>Pelanggan dapat dikosongkan untuk transaksi umum.</p></div></div>
                    <div class="user-form cashier-payment-form">
                        <dl class="cashier-live-summary" data-cashier-summary aria-live="polite">
                            <div><dt>Jumlah item</dt><dd data-cashier-items>0</dd></div>
                            <div class="cashier-live-total"><dt>Total belanja</dt><dd data-cashier-total>Rp 0</dd></div>
                            <div><dt>Uang diterima</dt><dd data-cashier-received>Rp 0</dd></div>
                            <div><dt>Kembalian</dt><dd data-cashier-change>Rp 0</dd></div>
                            <p class="cashier-live-message" data-cashier-message>Pilih produk untuk menghitung total.</p>
                        </dl>
                        <div class="user-field">
                            <label for="id_pelanggan">Pelanggan terdaftar (opsional)</label>
                            <select id="id_pelanggan" name="id_pelanggan">
                                <option value="">Pelanggan umum / tanpa nama</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= (int) $customer['id_pelanggan'] ?>" <?= $customerId === (string) $customer['id_pelanggan'] ? 'selected' : '' ?>><?= e($customer['nama_pelanggan'] ?: 'Pelanggan') ?> · #<?= (int) $customer['id_pelanggan'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="user-field"><label for="nama_pelanggan">Atau nama pelanggan umum</label><input id="nama_pelanggan" name="nama_pelanggan" type="text" maxlength="100" value="<?= e($guestName) ?>" placeholder="Opsional"></div>
                        <div class="user-field"><label for="uang_diterima">Uang diterima (Rp)</label><input id="uang_diterima" name="uang_diterima" type="number" min="0" step="0.01" value="<?= e($amountReceived) ?>" required placeholder="Contoh: 100000"></div>
                        <button class="user-btn user-btn-primary cashier-submit-button" type="submit">Proses Transaksi & Cetak Nota</button>
                    </div>
                </section>
                <aside class="cashier-payment-note">
                    <span class="cashier-payment-note-icon" aria-hidden="true">✓</span>
                    <strong>Stok dan pembayaran aman</strong>
                    <p>Harga dan stok diambil ulang dari database. Transaksi, pengurangan stok, kasir, dan kembalian disimpan bersama atau dibatalkan seluruhnya.</p>
                </aside>
            </section>
        </form>
        <footer class="admin-footer"><span>© <?= date('Y') ?> Tokoku</span><span>Transaksi Kasir</span></footer>
    </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
