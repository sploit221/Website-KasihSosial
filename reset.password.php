<?php
include_once 'koneksi.php';

// Redirect jika sudah login
if (!empty($_SESSION['user_id'])) {
    header("Location: index.php"); exit;
}

$token = $_GET['token'] ?? '';
$error = '';
$success = false;
$valid_token = false;
$user_id = null;

// Validasi token dengan prepared statement (anti SQL injection)
if (empty($token) || strlen($token) !== 64 || !preg_match('/^[a-f0-9]+$/', $token)) {
    $error = 'Token tidak valid.';
} else {
    $stmt = dbQuery(
        "SELECT pr.user_id, pr.expires_at, u.username, u.email 
         FROM password_resets pr 
         JOIN users u ON u.user_id = pr.user_id 
         WHERE pr.token = ? LIMIT 1",
        's', [$token]
    );
    $reset = $stmt->get_result()->fetch_assoc();

    if (!$reset) {
        $error = 'Token tidak valid atau sudah digunakan.';
    } elseif (strtotime($reset['expires_at']) < time()) {
        $error = 'Token sudah kadaluarsa. Silakan minta reset ulang.';
        // Hapus token expired
        dbQuery("DELETE FROM password_resets WHERE token = ?", 's', [$token]);
        error_log("Expired token used: " . substr($token, 0, 16) . "... for user_id: {$reset['user_id']}");
    } else {
        $valid_token = true;
        $user_id = $reset['user_id'];
    }
}

// Proses reset password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        // Validasi password
        if (mb_strlen($password) < 8) {
            $error = 'Password minimal 8 karakter.';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $error = 'Password harus mengandung minimal 1 huruf kapital.';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $error = 'Password harus mengandung minimal 1 huruf kecil.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $error = 'Password harus mengandung minimal 1 angka.';
        } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $error = 'Password harus mengandung minimal 1 karakter spesial (!@#$%^&*).';
        } elseif ($password !== $password2) {
            $error = 'Konfirmasi password tidak cocok.';
        } else {
            // Update password
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            dbQuery("UPDATE users SET password = ? WHERE user_id = ?", 'si', [$hash, $user_id]);
            
            // Hapus SEMUA token untuk user ini
            dbQuery("DELETE FROM password_resets WHERE user_id = ?", 'i', [$user_id]);
            
            // Log aktivitas
            error_log("Password successfully reset for user_id: {$user_id} from IP: {$_SERVER['REMOTE_ADDR']}");
            
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
    <title>Reset Password — KasihSosial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body { background: #f4f6f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; font-family: 'Segoe UI', sans-serif; }
        .card { width: 100%; max-width: 450px; border: none; border-radius: 20px; box-shadow: 0 8px 30px rgba(0,0,0,.1); overflow: hidden; }
        .card-header { background: linear-gradient(135deg, #4f46e5, #06b6d4); color: #fff; padding: 2rem; text-align: center; }
        .card-body { padding: 2rem; }
        .btn-primary { background: linear-gradient(135deg, #4f46e5, #7c3aed); border: none; border-radius: 10px; padding: .75rem; font-weight: bold; transition: filter .2s; }
        .btn-primary:hover { filter: brightness(1.1); }
        .password-strength { height: 4px; border-radius: 2px; margin-top: .5rem; transition: all .3s; }
        .strength-weak { background: #ef4444; width: 25%; }
        .strength-fair { background: #f59e0b; width: 50%; }
        .strength-good { background: #10b981; width: 75%; }
        .strength-strong { background: #059669; width: 100%; }
        .requirement { font-size: .8rem; color: #6b7280; margin-top: .3rem; }
        .requirement.met { color: #059669; }
        .requirement i { margin-right: .3rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            <h4 class="mb-0"><i class="bi bi-key me-2"></i>Reset Password</h4>
            <?php if ($valid_token): ?>
                <small class="opacity-75">Untuk: <?= e($reset['username']) ?></small>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?>
                </div>
                <a href="lupa.password.php" class="btn btn-primary w-100">Minta Reset Ulang</a>
                <div class="text-center mt-3">
                    <a href="login.php" class="text-decoration-none">Kembali ke Login</a>
                </div>
            <?php elseif ($success): ?>
                <div class="text-center">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:3rem;"></i>
                    <div class="alert alert-success mt-3">
                        <strong>Password berhasil diubah!</strong><br>
                        Silakan login dengan password baru Anda.
                    </div>
                    <a href="login.php" class="btn btn-primary w-100 mt-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Ke Halaman Login
                    </a>
                </div>
            <?php elseif ($valid_token): ?>
                <form method="POST" id="resetForm">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="fw-bold">Password Baru</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" name="password" id="password" 
                                   class="form-control" placeholder="Min. 8 karakter" required
                                   oninput="checkStrength()">
                            <span class="input-group-text toggle-password" onclick="togglePwd()" style="cursor:pointer;">
                                <i class="bi bi-eye" id="eye-icon"></i>
                            </span>
                        </div>
                        <div class="password-strength" id="strengthBar"></div>
                        <div class="requirement" id="req-length"><i class="bi bi-circle"></i> Minimal 8 karakter</div>
                        <div class="requirement" id="req-upper"><i class="bi bi-circle"></i> Minimal 1 huruf kapital</div>
                        <div class="requirement" id="req-lower"><i class="bi bi-circle"></i> Minimal 1 huruf kecil</div>
                        <div class="requirement" id="req-number"><i class="bi bi-circle"></i> Minimal 1 angka</div>
                        <div class="requirement" id="req-special"><i class="bi bi-circle"></i> Minimal 1 karakter spesial</div>
                    </div>
                    <div class="mb-4">
                        <label class="fw-bold">Konfirmasi Password Baru</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key"></i></span>
                            <input type="password" name="password2" id="password2" 
                                   class="form-control" placeholder="Ulangi password" required>
                        </div>
                        <div id="matchMsg" class="small mt-1"></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="submitBtn">
                        <i class="bi bi-shield-check me-1"></i>Simpan Password Baru
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePwd() {
            const pwd = document.getElementById('password');
            const icon = document.getElementById('eye-icon');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                pwd.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        }

        function checkStrength() {
            const pwd = document.getElementById('password').value;
            const bar = document.getElementById('strengthBar');
            
            // Cek requirements
            const hasLength = pwd.length >= 8;
            const hasUpper = /[A-Z]/.test(pwd);
            const hasLower = /[a-z]/.test(pwd);
            const hasNumber = /[0-9]/.test(pwd);
            const hasSpecial = /[^A-Za-z0-9]/.test(pwd);
            
            // Update requirement indicators
            updateReq('req-length', hasLength);
            updateReq('req-upper', hasUpper);
            updateReq('req-lower', hasLower);
            updateReq('req-number', hasNumber);
            updateReq('req-special', hasSpecial);
            
            const score = [hasLength, hasUpper, hasLower, hasNumber, hasSpecial].filter(Boolean).length;
            
            bar.className = 'password-strength';
            if (pwd.length === 0) bar.style.width = '0';
            else if (score <= 2) bar.classList.add('strength-weak');
            else if (score === 3) bar.classList.add('strength-fair');
            else if (score === 4) bar.classList.add('strength-good');
            else bar.classList.add('strength-strong');
        }

        function updateReq(id, met) {
            const el = document.getElementById(id);
            if (met) {
                el.classList.add('met');
                el.querySelector('i').className = 'bi bi-check-circle-fill';
            } else {
                el.classList.remove('met');
                el.querySelector('i').className = 'bi bi-circle';
            }
        }

        // Cek konfirmasi password
        document.getElementById('password2').addEventListener('input', function() {
            const pwd = document.getElementById('password').value;
            const pwd2 = this.value;
            const msg = document.getElementById('matchMsg');
            if (pwd2.length === 0) {
                msg.textContent = '';
            } else if (pwd === pwd2) {
                msg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Password cocok</span>';
            } else {
                msg.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> Password tidak cocok</span>';
            }
        });
    </script>
</body>
</html>