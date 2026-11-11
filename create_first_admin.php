
<?php

// File ini hanya boleh dijalankan melalui terminal.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Akses ditolak. Jalankan melalui terminal PHP.');
}

require_once __DIR__ . '/config/database.php';

try {
    // Pastikan kolom role sudah tersedia.
    $roleColumn = $pdo->query(
        "SHOW COLUMNS FROM users LIKE 'role'"
    )->fetch();

    if (!$roleColumn) {
        exit("Kolom role belum tersedia. Jalankan ALTER TABLE terlebih dahulu.\n");
    }

    // Admin pertama hanya boleh dibuat saat users masih kosong.
    $totalUsers = (int) $pdo
        ->query("SELECT COUNT(*) FROM users")
        ->fetchColumn();

    if ($totalUsers > 0) {
        exit("Pembuatan Admin pertama sudah ditutup karena tabel users tidak kosong.\n");
    }

    echo "=== PEMBUATAN ADMIN PERTAMA TOKOKU ===\n\n";

    $name = trim(readline("Nama Admin: "));
    $email = strtolower(trim(readline("Email Admin: ")));
    $password = readline("Password Admin: ");
    $confirmation = readline("Ulangi Password: ");

    if ($name === '' || strlen($name) > 255) {
        exit("Nama wajib diisi dan maksimal 255 karakter.\n");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        exit("Format email tidak valid.\n");
    }

    if (strlen($email) > 255) {
        exit("Email maksimal 255 karakter.\n");
    }

    if (strlen($password) < 8) {
        exit("Password minimal 8 karakter.\n");
    }

    if ($password !== $confirmation) {
        exit("Konfirmasi password tidak cocok.\n");
    }

    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    $stmt = $pdo->prepare(
        "INSERT INTO users
            (name, email, password, role, created_at, updated_at)
         VALUES
            (:name, :email, :password, 'admin', NOW(), NOW())"
    );

    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'password' => $passwordHash
    ]);

    echo "\nAdmin berhasil dibuat!\n";
    echo "Email: {$email}\n";
    echo "Role: admin\n";
    echo "Password telah disimpan dalam bentuk hash.\n";

} catch (PDOException $e) {
    error_log($e->getMessage());
    echo "\nGagal membuat Admin. Periksa koneksi atau kemungkinan email sudah digunakan.\n";
}
