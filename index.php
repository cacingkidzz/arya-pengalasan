<?php
// Jika Anda ingin statistik tampil dinamis, sertakan koneksi di sini
// include 'admin/config.php'; 
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arya Pengalasan Pusat</title>
    <link rel="icon" type="image/png" href="admin/dist/assets/img/bg-logo.png">

    <link href="admin/dist/vendor/google-fonts/inter.css" rel="stylesheet">

    <link href="admin/dist/vendor/bootstrap/5.3.3/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin/dist/vendor/font-awesome/css/all.min.css">

    <style>
        :root {
            --primary-color: #1e3c72;
            --secondary-color: #2a5298;
            --accent-color: #f39c12;
        }

        body {
            background-color: #f8fafc;
            font-family: 'Inter', sans-serif;
            color: #334155;
        }

        /* Header / Hero Section */
        .hero-section {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 60px 0 100px 0;
            border-radius: 0 0 30px 30px;
        }

        .logo-img {
            width: 100px;
            filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.1));
            background: white;
            padding: 8px;
            border-radius: 50%;
            margin-bottom: 20px;
        }

        /* Card Menu Styling */
        .main-container {
            margin-top: -60px;
            padding-bottom: 50px;
        }

        .menu-card {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            padding: 30px;
        }

        .btn-action {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 15px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            margin-bottom: 15px;
            border: 1px solid transparent;
        }

        .btn-action i {
            font-size: 1.2rem;
        }

        /* Button Colors */
        .btn-pendataan {
            background-color: #e0f2fe;
            color: #0369a1;
        }

        .btn-pendataan:hover {
            background-color: #0369a1;
            color: white;
        }

        .btn-rekap {
            background-color: #dcfce7;
            color: #15803d;
        }

        .btn-rekap:hover {
            background-color: #15803d;
            color: white;
        }

        .btn-urunan {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .btn-urunan:hover {
            background-color: #b91c1c;
            color: white;
        }

        .btn-punia {
            background-color: #fef3c7;
            color: #b45309;
        }

        .btn-punia:hover {
            background-color: #b45309;
            color: white;
        }

        .btn-whatsapp {
            background-color: #25D366;
            color: white;
            margin-top: 20px;
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);
        }

        .btn-whatsapp:hover {
            background-color: #128C7E;
            color: white;
            transform: scale(1.02);
        }

        .info-text {
            font-size: 0.95rem;
            line-height: 1.6;
            color: #64748b;
        }

        .warning-label {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 5px 15px;
            border-radius: 50px;
            display: inline-block;
        }
    </style>
</head>

<body>
    <header class="hero-section text-center">
        <div class="container px-4">
            <img src="admin/dist/assets/img/bg-logo.png" alt="Logo APP" class="logo-img">
            <h1 class="fw-bold h3 mb-2">ARYA PENGALASAN PUSAT</h1>
            <p class="opacity-75 small mb-0">Bedulu, Gianyar, Bali</p>
        </div>
    </header>

    <main class="container main-container px-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="menu-card">
                    <div class="text-center mb-4">
                        <p class="info-text">
                            Platform digital sebagai pusat informasi dan pendataan bagi seluruh <strong>Semeton Arya Pengalasan Pusat</strong>.
                            Memudahkan komunikasi, menjaga transparansi, dan melestarikan warisan leluhur.
                        </p>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <a href="search_semeton.php" class="btn-action btn-pendataan">
                                <i class="fas fa-user-plus"></i> PENDATAAN SEMETON
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="rekap_semeton.php" class="btn-action btn-rekap">
                                <i class="fas fa-chart-line"></i> REKAP SEMETON APP
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="cek_hutang.php" class="btn-action btn-urunan">
                                <i class="fas fa-wallet"></i> CEK URUNAN
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="punia.php" class="btn-action btn-punia">
                                <i class="fas fa-hand-holding-heart"></i> DANA PUNIA ODALAN
                            </a>
                        </div>
                    </div>

                    <div class="mt-5 pt-4 border-top text-center">
                        <div class="p-3 rounded-4" style="background-color: #fffbeb; border: 1px dashed #f59e0b;">
                            <p class="small text-dark mb-2">Untuk informasi lebih lanjut, silakan bergabung dalam grup WhatsApp resmi kami:</p>
                            <a href="https://chat.whatsapp.com/JMOE2TESSOkEKZ5m1mvcJD" class="btn-action btn-whatsapp mb-2">
                                <i class="fab fa-whatsapp"></i> MASUK GROUP APP
                            </a>
                            <div class="mt-2">
                                <span class="badge bg-danger warning-label">Internal Only</span>
                                <p class="text-danger small mt-2 mb-0" style="font-size: 0.75rem;">Grup ini khusus untuk intern Arya Pengalasan Pusat!</p>
                            </div>
                        </div>
                    </div>
                </div>

                <footer class="text-center mt-4">
                    <p class="text-muted small">&copy; <?php echo date('Y'); ?> Arya Pengalasan Pusat Bedulu. <br> Mari menjaga solidaritas untuk generasi masa depan.</p>
                </footer>
            </div>
        </div>
    </main>

    <script src="admin/dist/vendor/bootstrap/5.3.3/bootstrap.bundle.min.js"></script>
</body>

</html>