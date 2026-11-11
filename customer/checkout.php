<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('pelanggan');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/product_images.php';
require_once __DIR__ . '/../includes/store.php';

$pageTitle = 'Checkout - Tokoku';
$cart = customerCart();
if (!$cart) {
    $_SESSION['cart_error'] = 'Keranjang Anda masih kosong.';
    header('Location: ' . appUrl('customer/cart.php'));
    exit;
}

$errors = [];
$city = trim((string) ($_POST['kota'] ?? ''));
if ($city === '') {
    $customerStmt = $pdo->prepare('SELECT kota FROM tbl_pelanggan WHERE id_user = :id_user LIMIT 1');
    $customerStmt->execute(['id_user' => (int) $_SESSION['user_id']]);
    $city = (string) ($customerStmt->fetchColumn() ?: '');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validCustomerCsrf()) {
        $errors[] = 'Sesi formulir tidak valid. Muat ulang halaman dan coba kembali.';
    }
    if ($city === '' || mb_strlen($city) > 50) {
        $errors[] = 'Kota wajib diisi dan maksimal 50 karakter.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $products = customerCartProducts($pdo, $cart, true);
            if (count($products) !== count($cart)) {
                throw new RuntimeException('Salah satu produk tidak lagi tersedia.');
            }

            $total = 0.0;
            foreach ($products as $product) {
                $quantity = (int) ($cart[(int) $product['id_produk']] ?? 0);
                if ($quantity < 1 || $quantity > (int) $product['stok']) {
                    throw new RuntimeException(
                        'Stok ' . $product['nama_produk'] . ' tidak mencukupi. Perbarui keranjang Anda.'
                    );
                }
                $total += (float) $product['harga'] * $quantity;
            }
            if (!is_finite($total) || $total > 9999999999.99) {
                throw new RuntimeException('Total belanja melebihi batas transaksi.');
            }

            $userId = (int) $_SESSION['user_id'];
            $customerStmt = $pdo->prepare(
                'SELECT id_pelanggan FROM tbl_pelanggan WHERE id_user = :id_user LIMIT 1 FOR UPDATE'
            );
            $customerStmt->execute(['id_user' => $userId]);
            $customerId = $customerStmt->fetchColumn();
            if ($customerId === false) {
                $createCustomer = $pdo->prepare(
                    'INSERT INTO tbl_pelanggan (nama_pelanggan, kota, id_user)
                     VALUES (:nama_pelanggan, :kota, :id_user)'
                );
                $createCustomer->execute([
                    'nama_pelanggan' => mb_substr((string) $_SESSION['name'], 0, 100),
                    'kota' => $city,
                    'id_user' => $userId,
                ]);
                $customerId = (int) $pdo->lastInsertId();
            } else {
                $customerId = (int) $customerId;
                $updateCustomer = $pdo->prepare(
                    'UPDATE tbl_pelanggan SET nama_pelanggan = :nama_pelanggan, kota = :kota
                     WHERE id_pelanggan = :id_pelanggan'
                );
                $updateCustomer->execute([
                    'nama_pelanggan' => mb_substr((string) $_SESSION['name'], 0, 100),
                    'kota' => $city,
                    'id_pelanggan' => $customerId,
                ]);
            }

            $createOrder = $pdo->prepare(
                'INSERT INTO tbl_transaksi (tanggal, id_pelanggan, total_belanja)
                 VALUES (CURDATE(), :id_pelanggan, :total_belanja)'
            );
            $createOrder->execute([
                'id_pelanggan' => $customerId,
                'total_belanja' => number_format($total, 2, '.', ''),
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $insertDetail = $pdo->prepare(
                'INSERT INTO tbl_detail_transaksi
                 (id_transaksi, nama_produk, jumlah, harga, id_produk)
                 VALUES (:id_transaksi, :nama_produk, :jumlah, :harga, :id_produk)'
            );
            $decreaseStock = $pdo->prepare(
                'UPDATE tbl_produk SET stok = stok - :quantity
                 WHERE id_produk = :id_produk AND stok >= :minimum_stock'
            );
            foreach ($products as $product) {
                $productId = (int) $product['id_produk'];
                $quantity = (int) $cart[$productId];
                $decreaseStock->execute([
                    'quantity' => $quantity,
                    'id_produk' => $productId,
                    'minimum_stock' => $quantity,
                ]);
                if ($decreaseStock->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Stok ' . $product['nama_produk'] . ' berubah. Silakan coba checkout kembali.'
                    );
                }
                $insertDetail->execute([
                    'id_transaksi' => $orderId,
                    'nama_produk' => $product['nama_produk'],
                    'jumlah' => $quantity,
                    'harga' => $product['harga'],
                    'id_produk' => $productId,
                ]);
            }

            $pdo->commit();
            $_SESSION['cart'] = [];
            $_SESSION['order_flash'] = 'Pesanan berhasil dibuat. Nomor pesanan TRX-'
                . str_pad((string) $orderId, 3, '0', STR_PAD_LEFT) . '.';
            header('Location: ' . appUrl('customer/order.php?id=' . $orderId));
            exit;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Checkout pelanggan: ' . $e->getMessage());
            $errors[] = 'Pesanan tidak dapat diproses saat ini. Silakan coba kembali.';
        }
    }
}

$products = customerCartProducts($pdo, $cart);
$total = array_sum(array_column($products, 'subtotal'));
require_once __DIR__ . '/../includes/header.php';
?>
<main class="store-page">
    <?php customerStoreHeader('cart'); ?>
    <div class="store-page-heading"><div><span class="welcome-label">TOKOKU · CHECKOUT</span><h1>Ringkasan Checkout</h1><p>Periksa gambar dan jumlah produk sebelum konfirmasi pesanan.</p></div></div>
    <?php foreach ($errors as $error): ?><div class="user-alert user-alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>
    <div class="checkout-grid">
        <section class="admin-panel checkout-products">
            <div class="panel-heading"><div><h2>Produk Pesanan</h2><p><?= number_format(array_sum($cart)) ?> item</p></div></div>
            <div class="checkout-product-list">
                <?php foreach ($products as $product): ?>
                    <article class="checkout-product">
                        <img src="<?= e(productImageUrl($product)) ?>" alt="<?= e($product['nama_produk']) ?>">
                        <div><strong><?= e($product['nama_produk']) ?></strong><span><?= number_format((int) $product['jumlah_keranjang']) ?> × Rp <?= e(number_format((float) $product['harga'], 0, ',', '.')) ?></span></div>
                        <strong>Rp <?= e(number_format((float) $product['subtotal'], 0, ',', '.')) ?></strong>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="transaction-total"><span>Total pesanan</span><strong>Rp <?= e(number_format($total, 0, ',', '.')) ?></strong></div>
        </section>
        <section class="admin-panel checkout-customer">
            <div class="panel-heading"><div><h2>Informasi Pelanggan</h2><p>Pesanan akan terhubung ke akun Anda.</p></div></div>
            <form method="post" class="user-form checkout-form">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <div class="user-field"><label>Nama</label><input type="text" value="<?= e($_SESSION['name'] ?? '') ?>" readonly></div>
                <div class="user-field"><label for="checkout-city">Kota</label><input id="checkout-city" name="kota" type="text" maxlength="50" required value="<?= e($city) ?>" placeholder="Masukkan kota"></div>
                <p class="product-schema-note">Pembayaran online belum tersedia; pesanan akan dicatat sebagai transaksi.</p>
                <button class="user-btn user-btn-primary" type="submit">Konfirmasi Pesanan</button>
                <a class="user-btn user-btn-light" href="<?= e(appUrl('customer/cart.php')) ?>">Kembali ke Keranjang</a>
            </form>
        </section>
    </div>
    <footer class="store-footer">© <?= date('Y') ?> Tokoku</footer>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
