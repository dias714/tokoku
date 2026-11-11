<?php
require_once __DIR__ . '/../../config/database.php';

$columns = [];
$columnStatement = $pdo->query('SHOW COLUMNS FROM tbl_produk');
foreach ($columnStatement->fetchAll() as $column) {
    $columns[$column['Field']] = true;
}

$addProductColumns = [];
if (!isset($columns['deskripsi'])) {
    $addProductColumns[] = 'ADD COLUMN deskripsi TEXT NULL';
}
if (!isset($columns['gambar'])) {
    $addProductColumns[] = 'ADD COLUMN gambar VARCHAR(255) NULL';
}
if ($addProductColumns) {
    $pdo->exec('ALTER TABLE tbl_produk ' . implode(', ', $addProductColumns));
}

$customerColumns = [];
$customerColumnStatement = $pdo->query('SHOW COLUMNS FROM tbl_pelanggan');
foreach ($customerColumnStatement->fetchAll() as $column) {
    $customerColumns[$column['Field']] = true;
}
if (!isset($customerColumns['id_user'])) {
    $pdo->exec(
        'ALTER TABLE tbl_pelanggan
         ADD COLUMN id_user BIGINT UNSIGNED NULL,
         ADD UNIQUE KEY uq_tbl_pelanggan_id_user (id_user),
         ADD CONSTRAINT fk_tbl_pelanggan_user
             FOREIGN KEY (id_user) REFERENCES users (id) ON DELETE SET NULL'
    );
}

echo "Migrasi gambar produk dan relasi pelanggan selesai.\n";
