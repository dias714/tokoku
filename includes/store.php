<?php
function validCustomerCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function customerCart(): array
{
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    $cart = [];
    foreach ($_SESSION['cart'] as $productId => $quantity) {
        $id = filter_var($productId, FILTER_VALIDATE_INT);
        $qty = filter_var($quantity, FILTER_VALIDATE_INT);
        if ($id && $id > 0 && $qty && $qty > 0) {
            $cart[$id] = min($qty, 999);
        }
    }
    $_SESSION['cart'] = $cart;

    return $cart;
}

function customerCartProducts(PDO $pdo, array $cart, bool $lockRows = false): array
{
    if (!$cart) {
        return [];
    }

    $ids = array_map('intval', array_keys($cart));
    sort($ids, SORT_NUMERIC);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'SELECT p.id_produk, p.nama_produk, p.deskripsi, p.harga, p.stok,
                   p.gambar, k.nama_kategori
            FROM tbl_produk p
            LEFT JOIN tbl_kategori k ON k.id_kategori = p.id_kategori
            WHERE p.id_produk IN (' . $placeholders . ')
            ORDER BY p.nama_produk';
    if ($lockRows) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $products = $stmt->fetchAll();
    foreach ($products as &$product) {
        $product['jumlah_keranjang'] = $cart[(int) $product['id_produk']] ?? 0;
        $product['subtotal'] = (float) $product['harga'] * $product['jumlah_keranjang'];
    }
    unset($product);

    return $products;
}

function customerProductCard(array $product): void
{
    $productUrl = appUrl('customer/product.php?id=' . (int) $product['id_produk']);
    $imageUrl = productImageUrl($product);
    $stock = (int) $product['stok'];
    ?>
    <article class="store-product-card">
        <a class="store-product-image-link" href="<?= e($productUrl) ?>">
            <img class="store-product-image" src="<?= e($imageUrl) ?>" alt="<?= e($product['nama_produk']) ?>" loading="lazy">
        </a>
        <div class="store-product-body">
            <span class="store-product-category"><?= e($product['nama_kategori'] ?? 'Tanpa kategori') ?></span>
            <h2><a href="<?= e($productUrl) ?>"><?= e($product['nama_produk']) ?></a></h2>
            <strong class="store-product-price">Rp <?= e(number_format((float) $product['harga'], 0, ',', '.')) ?></strong>
            <span class="store-product-stock <?= $stock > 0 ? 'is-available' : 'is-empty' ?>">
                <?= $stock > 0 ? 'Stok ' . number_format($stock) : 'Stok habis' ?>
            </span>
            <form method="post" action="<?= e(appUrl('customer/cart.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                <input type="hidden" name="quantity" value="1">
                <button class="user-btn user-btn-primary store-add-button" type="submit" <?= $stock < 1 ? 'disabled' : '' ?>>Tambahkan ke Keranjang</button>
            </form>
        </div>
    </article>
    <?php
}

function customerStoreHeader(string $activePage): void
{
    $cart = customerCart();
    $cartCount = array_sum($cart);
    ?>
    <header class="store-header">
        <a class="store-brand" href="<?= e(appUrl('customer/index.php')) ?>"><span class="admin-brand-mark">T</span><span>Tokoku</span></a>
        <nav class="store-navigation" aria-label="Navigasi pelanggan">
            <a class="<?= $activePage === 'shop' ? 'active' : '' ?>" href="<?= e(appUrl('customer/index.php')) ?>">Belanja</a>
            <a class="<?= $activePage === 'orders' ? 'active' : '' ?>" href="<?= e(appUrl('customer/orders.php')) ?>">Pesanan Saya</a>
            <a class="store-cart-link <?= $activePage === 'cart' ? 'active' : '' ?>" href="<?= e(appUrl('customer/cart.php')) ?>">Keranjang <span><?= number_format($cartCount) ?></span></a>
        </nav>
        <div class="store-account"><span><?= e($_SESSION['name'] ?? 'Pelanggan') ?></span><a href="<?= e(appUrl('index.php?logout=1')) ?>">Keluar</a></div>
    </header>
    <?php
}
