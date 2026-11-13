<!doctype html>
<?php require_once __DIR__ . '/security.php'; ?>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>APP | Login</title>
    <link rel="icon" type="image/png" href="dist/assets/img/bg-logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="author" content="CacingKidZZ" />

    <link rel="stylesheet" href="dist/vendor/fontsource/source-sans-3/index.css" />
    <link rel="stylesheet" href="dist/vendor/bootstrap-icons/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="dist/vendor/bootstrap/5.3.3/bootstrap.min.css" />

    <style>
        :root {
            --primary-dark: #212529;
            --accent-color: #343a40;
        }

        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Source Sans 3', sans-serif;
            margin: 0;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: none;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            padding: 40px;
            transition: transform 0.3s ease;
        }

        .brand-section {
            text-align: center;
            margin-bottom: 30px;
        }

        .brand-section img {
            max-width: 120px;
            height: auto;
            margin-bottom: 15px;
            filter: drop-shadow(0 5px 10px rgba(0, 0, 0, 0.1));
        }

        .brand-section h1 {
            font-size: 22px;
            font-weight: 700;
            color: var(--primary-dark);
            margin: 0;
            letter-spacing: 1px;
        }

        .brand-section p {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 1px solid #dee2e6;
            background-color: #f8f9fa;
        }

        .form-control:focus {
            box-shadow: 0 0 0 3px rgba(33, 37, 41, 0.1);
            border-color: var(--primary-dark);
        }

        .input-group-text {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            color: #6c757d;
        }

        /* Styling Khusus untuk Input Password */
        .input-group-password {
            position: relative;
        }

        .input-group-password .form-control {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }

        /* Styling untuk Tombol Mata */
        .password-toggle {
            cursor: pointer;
            border-left: none;
            /* Menghilangkan border kiri agar menyatu */
            color: #adb5bd;
            /* Warna default abu-abu muda */
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: var(--primary-dark);
            /* Warna gelap saat hover */
        }

        .btn-login {
            background-color: var(--primary-dark);
            color: white;
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            border: none;
            margin-top: 10px;
        }

        .btn-login:hover {
            background-color: #000;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .alert {
            border-radius: 12px;
            font-size: 14px;
            border: none;
        }
    </style>
</head>

<body>

    <div class="login-card">
        <div class="brand-section">
            <img src="dist/assets/img/bg-logo.png" alt="Logo">
            <h1>Admin<span class="fw-light">APP</span></h1>
            <p>Arya Pengalasan Pusat</p>
        </div>

        <?php if (isset($_GET['pesan'])): ?>
            <?php if ($_GET['pesan'] == "gagal"): ?>
                <div class="alert alert-danger text-center">
                    <i class="bi bi-exclamation-circle me-2"></i> Username/Password salah!
                </div>
            <?php elseif ($_GET['pesan'] == "logout"): ?>
                <div class="alert alert-success text-center">
                    <i class="bi bi-check-circle me-2"></i> Berhasil Log Out!
                </div>
            <?php elseif ($_GET['pesan'] == "belum_login"): ?>
                <div class="alert alert-warning text-center">
                    <i class="bi bi-shield-lock me-2"></i> Silakan login terlebih dahulu.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form action="cek_login.php" method="post">
            <?= kbap_csrf_field() ?>
            <div class="mb-3">
                <label class="form-label small fw-bold text-secondary">USERNAME</label>
                <div class="input-group">
                    <input type="text" class="form-control" placeholder="Enter username" name="username" required />
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-bold text-secondary">PASSWORD</label>
                <div class="input-group input-group-password">
                    <input type="password" class="form-control" placeholder="Enter password" name="password" id="passwordInput" required />
                    <span class="input-group-text password-toggle" id="togglePassword">
                        <i class="bi bi-eye-slash" id="eyeIcon"></i>
                    </span>
                </div>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-login">Sign In</button>
            </div>
        </form>


    </div>

    <script src="dist/vendor/popper/popper.min.js"></script>
    <script src="dist/vendor/bootstrap/5.3.3/bootstrap.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('passwordInput');
            const togglePassword = document.getElementById('togglePassword');
            const eyeIcon = document.getElementById('eyeIcon');

            togglePassword.addEventListener('click', function() {
                // Periksa tipe input saat ini
                if (passwordInput.type === 'password') {
                    // Ganti ke text (lihat password)
                    passwordInput.type = 'text';
                    // Ganti icon menjadi mata terbuka
                    eyeIcon.classList.remove('bi-eye-slash');
                    eyeIcon.classList.add('bi-eye');
                } else {
                    // Ganti kembali ke password (sembunyikan)
                    passwordInput.type = 'password';
                    // Ganti icon menjadi mata tertutup (coret)
                    eyeIcon.classList.remove('bi-eye');
                    eyeIcon.classList.add('bi-eye-slash');
                }
            });
        });
    </script>
</body>

</html>