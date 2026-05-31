<?php
include_once 'koneksi.php';

// Redirect jika sudah login
if (!empty($_SESSION['user_id'])) {
    header("Location: index.php"); exit;
}

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_request'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid.';
    } else {
        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email tidak valid.';
        } else {
            // Cek apakah email terdaftar
            $stmt = dbQuery(
                "SELECT user_id, username FROM users WHERE email = ? LIMIT 1",
                's', [$email]
            );
            $user = $stmt->get_result()->fetch_assoc();

            if ($user) {
                // Generate token aman (64 karakter random)
                $token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Hapus token lama user ini (jika ada)
                dbQuery(
                    "DELETE FROM password_resets WHERE user_id = ?",
                    'i', [$user['user_id']]
                );

                // Simpan token baru
                dbQuery(
                    "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)",
                    'iss', [$user['user_id'], $token, $expires_at]
                );

                // Kirim email
                $reset_link = "https://" . $_SERVER['HTTP_HOST'] . "/reset.password.php?token=" . urlencode($token);
                $subject = "Reset Password - KasihSosial";
                $message = "
                    <html>
                    <body style='font-family: Arial, sans-serif;'>
                        <h2>Reset Password KasihSosial</h2>
                        <p>Halo <strong>{$user['username']}</strong>,</p>
                        <p>Anda menerima email ini karena ada permintaan reset password untuk akun Anda.</p>
                        <p>Klik tombol di bawah ini untuk mereset password Anda:</p>
                        <p style='text-align:center;'>
                            <a href='{$reset_link}' 
                               style='background:#4f46e5;color:#fff;padding:12px 24px;text-decoration:none;border-radius:8px;display:inline-block;'>
                                Reset Password
                            </a>
                        </p>
                        <p>Atau salin link ini: <a href='{$reset_link}'>{$reset_link}</a></p>
                        <p><small>Link ini akan kadaluarsa dalam 1 jam.</small></p>
                        <p><small>Jika Anda tidak meminta reset password, abaikan email ini.</small></p>
                    </body>
                    </html>
                ";

                $headers = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $headers .= "From: KasihSosial <noreply@kasihsosial.com>\r\n";
                $headers .= "Reply-To: support@kasihsosial.com\r\n";
                $headers .= "X-Mailer: PHP/" . phpversion();

                mail($email, $subject, $message, $headers);

                // Log aktivitas (opsional)
                error_log("Password reset requested for user_id: {$user['user_id']} from IP: {$_SERVER['REMOTE_ADDR']}");
            }

            // SELALU tampilkan pesan sukses (mencegah enumerasi email)
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password — KasihSosial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body { background: #f4f6f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; font-family: 'Segoe UI', sans-serif; }
        .card { width: 100%; max-width: 450px; border: none; border-radius: 20px; box-shadow: 0 8px 30px rgba(0,0,0,.1); }
        .card-header { background: linear-gradient(135deg, #4f46e5, #06b6d4); color: #fff; border-radius: 20px 20px 0 0; padding: 2rem; text-align: center; }
        .btn-primary { background: linear-gradient(135deg, #4f46e5, #7c3aed); border: none; border-radius: 10px; padding: .75rem; font-weight: bold; }
        .btn-primary:hover { filter: brightness(1.1); }
        .icon-mail { font-size: 3rem; display: block; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            <h4 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Lupa Password</h4>
        </div>
        <div class="card-body p-4">
            <?php if ($success): ?>
                <div class="text-center">
                    <i class="bi bi-envelope-check icon-mail text-success"></i>
                    <div class="alert alert-success">
                        <strong>Email terkirim!</strong><br>
                        Jika email terdaftar di sistem kami, Anda akan menerima link reset password.
                        Silakan cek inbox atau folder spam Anda.
                    </div>
                    <p class="text-muted small">Link berlaku selama 1 jam.</p>
                    <a href="login.php" class="btn btn-primary w-100 mt-3">
                        <i class="bi bi-arrow-left me-1"></i>Kembali ke Login
                    </a>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= e($error) ?></div>
                <?php endif; ?>

                <p class="text-muted mb-3">Masukkan email terdaftar Anda. Kami akan mengirimkan link reset password.</p>

                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="fw-bold">Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" name="email" class="form-control" 
                                   placeholder="contoh@email.com" required autofocus
                                   value="<?= e($_POST['email'] ?? '') ?>">
                        </div>
                    </div>
                    <button type="submit" name="reset_request" class="btn btn-primary w-100">
                        <i class="bi bi-send me-1"></i>Kirim Link Reset
                    </button>
                </form>

                <div class="text-center mt-3">
                    <a href="login.php" class="text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i>Kembali ke Login
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>