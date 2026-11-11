
<?php

require_once __DIR__ . '/includes/auth.php';

// ========================================
// LOGOUT
// ========================================

if (isset($_GET['logout'])) {
    logoutUser();
}

// ========================================
// REDIRECT IF ALREADY LOGGED IN
// ========================================

if (isset($_SESSION['user_id'])) {
    redirectByRole();
}

// ========================================
// MESSAGE
// ========================================

$errorMessage = $_SESSION['access_error'] ?? '';
unset($_SESSION['access_error']);

$emailValue = '';

// ========================================
// LOGIN PROCESS
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $emailValue = strtolower(trim($_POST['email'] ?? ''));
    $passwordInput = $_POST['password'] ?? '';
    $csrfInput = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfInput) ||
        !hash_equals($_SESSION['csrf_token'], $csrfInput)
    ) {

        $errorMessage = 'Permintaan tidak valid. Silakan coba lagi.';

    } elseif (
        $emailValue === '' ||
        $passwordInput === '' ||
        !filter_var($emailValue, FILTER_VALIDATE_EMAIL)
    ) {

        $errorMessage = 'Email dan password harus diisi dengan benar.';

    } else {

        try {

            require_once __DIR__ . '/config/database.php';

            $stmt = $pdo->prepare(
                "SELECT id, name, email, password, role
                 FROM users
                 WHERE email = :email
                 LIMIT 1"
            );

            $stmt->execute([
                'email' => $emailValue
            ]);

            $user = $stmt->fetch();

            $allowedRoles = [
                'admin',
                'kasir',
                'pelanggan'
            ];

            if (
                $user &&
                password_verify(
                    $passwordInput,
                    $user['password']
                ) &&
                in_array($user['role'], $allowedRoles, true)
            ) {

                // Regenerate session ID
                session_regenerate_id(true);

                // Save authentication data
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                // Refresh CSRF token
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                // Redirect based on role
                redirectByRole();

            } else {

                $errorMessage = 'Email atau password salah.';
            }

        } catch (Throwable $e) {

            error_log($e->getMessage());

            $errorMessage = 'Terjadi kesalahan saat memproses login.';
        }
    }
}

// ========================================
// PAGE
// ========================================

$pageTitle = 'Login - Tokoku';

require_once __DIR__ . '/includes/header.php';

?>

<main class="auth-container">

    <section class="login-card">

        <!-- BRANDING -->
        <div class="brand">

            <div class="brand-logo">

                <svg viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round">

                    <path d="M3 10h18"/>
                    <path d="M5 10V20H19V10"/>
                    <path d="M3 10L5 4H19L21 10"/>
                    <path d="M9 20V14H15V20"/>

                </svg>

            </div>

            <h1>Tokoku</h1>

            <p>Kelola Bisnis, Lebih Mudah.</p>

        </div>

        <div class="divider"></div>

        <!-- HEADING -->
        <div class="login-heading">

            <h2>Selamat Datang Kembali</h2>

            <p>
                Silakan masuk untuk melanjutkan ke akun Anda.
            </p>

        </div>

        <!-- ERROR MESSAGE -->
        <?php if ($errorMessage !== ''): ?>

            <div class="message error" role="alert">

                <?= e($errorMessage) ?>

            </div>

        <?php endif; ?>

        <!-- LOGIN FORM -->
        <form method="POST" action="<?= e(appUrl('index.php')) ?>" id="loginForm">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <!-- EMAIL -->
            <div class="form-group">

                <label for="email">Email</label>

                <div class="input-wrapper">

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="Masukkan email"
                        value="<?= e($emailValue) ?>"
                        maxlength="255"
                        autocomplete="username"
                        required
                    >

                </div>

            </div>

            <!-- PASSWORD -->
            <div class="form-group">

                <label for="password">Password</label>

                <div class="input-wrapper">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Masukkan password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        id="togglePassword"
                        aria-label="Tampilkan password"
                        aria-pressed="false"
                    >
                        Tampilkan
                    </button>

                </div>

            </div>

            <!-- LOGIN BUTTON -->
            <button
                type="submit"
                class="login-button"
                id="loginButton"
            >

                <span class="button-text">Masuk</span>

                <span class="button-loading">
                    Memproses...
                </span>

            </button>

        </form>

        <!-- FOOTER -->
        <div class="login-footer">

            <p>© 2026 Tokoku. All rights reserved.</p>

        </div>

    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
