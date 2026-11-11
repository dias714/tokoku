# Tokoku

## Gambar produk dan belanja pelanggan

Migrasi untuk menambahkan deskripsi/gambar produk dan menghubungkan pelanggan ke riwayat transaksinya:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe database\migrations\2026_11_11_add_product_media_and_customer_link.php
```

Server PHP Laragon perlu mengaktifkan ekstensi `fileinfo` dan `gd` (termasuk dukungan WEBP). Admin mengelola foto melalui formulir **Admin → Produk**; file gambar tervalidasi disimpan di `uploads\products\` dan disajikan melalui `product_image.php`. Folder upload tidak dapat dieksekusi atau dijelajahi langsung.

Pelanggan dapat melihat katalog, detail produk, keranjang, checkout, dan riwayat pesanan setelah masuk dengan role `pelanggan`. Checkout membuat transaksi dan mengurangi stok dalam satu transaksi database. Skema saat ini belum memiliki status pesanan atau pembayaran online.

## Transaksi kasir dan nota

Jalankan migrasi berikut satu kali untuk menyimpan kasir, uang diterima, dan kembalian pada transaksi:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe database\migrations\2026_11_11_add_cashier_payment_details.php
```

Setelah login dengan role `kasir`, buka **Transaksi Baru** untuk memilih produk, pelanggan (opsional), dan jumlah uang tunai. Sistem memeriksa stok/harga kembali dan menyimpan transaksi serta pengurangan stok secara atomik. Total, subtotal barang, dan kembalian diperbarui langsung saat jumlah produk atau pembayaran diubah. Sebelum dialog cetak dibuka, aplikasi mengukur isi nota dan menetapkan ukuran kertas khusus selebar 50 mm dengan tinggi sesuai isi. Pada dialog cetak, pilih ukuran roll/custom 50 mm, skala 100%/Actual size, margin tanpa batas, dan matikan **Headers and footers**. Jika pilihan kertas hanya A4, ubah paper size pada preferensi/driver printer; CSS halaman web tidak dapat mengubah kertas yang dipaksakan driver. Riwayat dashboard hanya menampilkan transaksi kasir yang sedang login.