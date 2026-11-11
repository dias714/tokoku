<?php
require_once __DIR__ . '/../../config/database.php';

$columns = [];
$statement = $pdo->query('SHOW COLUMNS FROM tbl_transaksi');
foreach ($statement->fetchAll() as $column) {
    $columns[$column['Field']] = true;
}

$addColumns = [];
if (!isset($columns['metode_pembayaran'])) {
    $addColumns[] = 'ADD COLUMN metode_pembayaran VARCHAR(20) NULL';
}
if (!isset($columns['uang_diterima'])) {
    $addColumns[] = 'ADD COLUMN uang_diterima DECIMAL(12,2) NULL';
}
if (!isset($columns['kembalian'])) {
    $addColumns[] = 'ADD COLUMN kembalian DECIMAL(12,2) NULL';
}
if (!isset($columns['id_kasir'])) {
    $addColumns[] = 'ADD COLUMN id_kasir BIGINT UNSIGNED NULL';
    $addColumns[] = 'ADD KEY idx_tbl_transaksi_id_kasir (id_kasir)';
    $addColumns[] = 'ADD CONSTRAINT fk_tbl_transaksi_kasir
        FOREIGN KEY (id_kasir) REFERENCES users (id) ON DELETE SET NULL';
}

if ($addColumns) {
    $pdo->exec('ALTER TABLE tbl_transaksi ' . implode(', ', $addColumns));
}

echo "Migrasi data pembayaran dan kasir selesai.\n";
