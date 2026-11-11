<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('pelanggan');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/product_images.php';
require_once __DIR__ . '/../includes/store.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validCustomerCsrf()) {
        $_SESSION['cart_error'] = 'Sesi formulir tidak valid. Muat ulang halaman dan coba kembali.';
        header('Location: ' . appUrl('customer/cart.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $cart = customerCart();
    $productId = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);

    if ($action === 'add') {
        $quantity = filter_var($_POST['quantity'] ?? 1, FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('SELECT nama_produk, stok FROM tbl_produk WHERE id_produk = :id LIMIT 1');
        $stmt->execute(['id' => $productId && $productId > 0 ? $productId : 0]);
        $product = $stmt->fetch();
        if (!$product) {
            $_SESSION['cart_error'] = 'Produk tidak ditemukan.';
        } elseif (!$quantity || $quantity < 1 || $quantity > 999) {
            $_SESSION['cart_error'] = 'Jumlah produk tidak valid.';
        } elseif ((int) $product['stok'] < 1) {
            $_SESSION['cart_error'] = 'Stok produk sedang habis.';
        } elseif (($cart[$productId] ?? 0) + $quantity > (int) $product['stok']) {
            $_SESSION['cart_error'] = 'Jumlah yang diminta melebihi stok tersedia.';
        } else {
            $cart[$productId] = ($cart[$productId] ?? 0) + $quantity;
            $_SESSION['cart'] = $cart;
            $_SESSION['cart_flash'] = $product['nama_produk'] . ' ditambahkan ke keranjang.';
        }
    } elseif ($action === 'update') {
        $quantities = $_POST['quantities'] ?? [];
        if (!is_array($quantities)) {
            $_SESSION['cart_error'] = 'Data jumlah produk tidak valid.';
        } else {
            $products = customerCartProducts($pdo, $cart);
            $productById = [];
            foreach ($products as $product) {
                $productById[(int) $product['id_produk']] = $product;
            }
            foreach ($quantities as $id => $quantityValue) {
                $id = filter_var($id, FILTER_VALIDATE_INT);
                $quantity = filter_var($quantityValue, FILTER_VALIDATE_INT);
                if (!$id || !isset($cart[$id])) {
                    continue;
                }
                if ($quantity === 0) {
                    unset($cart[$id]);
                    continue;
                }
                if (
                    !$quantity
                    || $quantity < 1
                    || $quantity > 999
                    || !isset($productById[$id])
                    || $quantity > (int) $productById[$id]['stok']
                ) {
                    $_SESSION['cart_error'] = 'Jumlah harus sesuai stok yang tersedia untuk setiap produk.';
                    break;
                }
                $cart[$id] = $quantity;
            }
            if (!isset($_SESSION['cart_error'])) {
                $_SESSION['cart'] = $cart;
                $_SESSION['cart_flash'] = 'Keranjang berhasil diperbarui.';
            }
        }
    } elseif ($action === 'remove' && $productId && isset($cart[$productId])) {
        unset($cart[$productId]);
        $_SESSION['cart'] = $cart;
        $_SESSION['cart_flash'] = 'Produk dihapus dari keranjang.';
    } elseif ($action === 'clear') {
        $_SESSION['cart'] = [];
        $_SESSION['cart_flash'] = 'Keranjang berhasil dikosongkan.';
    } else {
        $_SESSION['cart_error'] = 'Aksi keranjang tidak valid.';
    }

    header('Location: ' . appUrl('customer/cart.php'));
    exit;
}

$pageTitle = 'Keranjang Belanja - Tokoku';
$cart = customerCart();
$products = customerCartProducts($pdo, $cart);
$foundIds = array_map(static fn (array $product): int => (int) $product['id_produk'], $products);
foreach (array_keys($cart) as $id) {
    if (!in_array((int) $id, $foundIds, true)) {
        unset($cart[$id]);
    }
}
$_SESSION['cart'] = $cart;
$total = array_sum(array_column($products, 'subtotal'));
$success = $_SESSION['cart_flash'] ?? '';
$error = $_SESSION['cart_error'] ?? '';
unset($_SESSION['cart_flash'], $_SESSION['cart_error']);

require_once __DIR__ . '/../includes/header.php';
?>
<main class="store-page">
    <?php customerStoreHeader('cart'); ?>
    <div class="store-page-heading"><div><span class="welcome-label">TOKOKU · BELANJA</span><h1>Keranjang Belanja</h1><p>Tinjau produk dan jumlah sebelum melanjutkan checkout.</p></div></div>
    <?php if ($success): ?><div class="user-alert user-alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="user-alert user-alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if (!$products): ?>
        <div class="store-empty-state"><img src="<?= e(appUrl('assets/images/product-placeholder.svg')) ?>" alt=""><h2>Keranjang masih kosong</h2><p>Pilih produk yang ingin Anda beli dari katalog.</p><a class="user-btn user-btn-primary" href="<?= e(appUrl('customer/index.php')) ?>">Mulai Belanja</a></div>
    <?php else: ?>
        <form method="post" class="store-cart-form">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update">
            <section class="admin-panel store-cart-panel">
                <div class="table-wrap">
                    <table class="admin-table store-cart-table"><thead><tr><th>Produk</th><th>Harga</th><th>Jumlah</th><th>Subtotal</th><th>Aksi</th></tr></thead><tbody>
                    <?php foreach ($products as $product): $quantity = (int) $product['jumlah_keranjang']; ?>
                        <tr>
                            <td><div class="store-cart-product"><img src="<?= e(productImageUrl($product)) ?>" alt="" loading="lazy"><div><a href="<?= e(appUrl('customer/product.php?id=' . (int) $product['id_produk'])) ?>"><?= e($product['nama_produk']) ?></a><span><?= e($product['nama_kategori'] ?? 'Tanpa kategori') ?></span></div></div></td>
                            <td>Rp <?= e(number_format((float) $product['harga'], 0, ',', '.')) ?></td>
                            <td><input class="store-cart-quantity" type="number" name="quantities[<?= (int) $product['id_produk'] ?>]" min="0" max="<?= min(999, (int) $product['stok']) ?>" value="<?= $quantity ?>" aria-label="Jumlah <?= e($product['nama_produk']) ?>"></td>
                            <td>Rp <?= e(number_format((float) $product['subtotal'], 0, ',', '.')) ?></td>
                            <td><button class="user-action-delete" type="submit" form="remove-product-<?= (int) $product['id_produk'] ?>">Hapus</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
                <div class="store-cart-actions"><a class="user-btn user-btn-light" href="<?= e(appUrl('customer/index.php')) ?>">Lanjut Belanja</a><button class="user-btn user-btn-light" type="submit">Perbarui Keranjang</button></div>
            </section>
        </form>
        <?php foreach ($products as $product): ?>
            <form method="post" id="remove-product-<?= (int) $product['id_produk'] ?>" class="store-hidden-form">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
            </form>
        <?php endforeach; ?>
        <section class="store-order-summary">
            <div><span>Total sementara</span><strong>Rp <?= e(number_format($total, 0, ',', '.')) ?></strong></div>
            <p>Stok dan harga akan diperiksa kembali saat checkout.</p>
            <a class="user-btn user-btn-primary" href="<?= e(appUrl('customer/checkout.php')) ?>">Lanjut ke Checkout</a>
        </section>
    <?php endif; ?>
    <footer class="store-footer">© <?= date('Y') ?> Tokoku</footer>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
