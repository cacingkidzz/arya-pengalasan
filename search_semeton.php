<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cari Semeton | Arya Pengalasan Pusat</title>
    <link rel="icon" type="image/png" href="admin/dist/assets/img/bg-logo.png">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        :root {
            --primary-color: #1e3c72;
            --secondary-color: #2a5298;
        }

        body {
            background-color: #f8fafc;
            font-family: 'Inter', sans-serif;
            color: #334155;
        }

        .header-search {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 40px 0 80px 0;
            border-radius: 0 0 30px 30px;
        }

        .search-card {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            padding: 30px;
            margin-top: -50px;
        }

        .search-input-group {
            position: relative;
        }

        .search-input-group i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .form-control-lg {
            padding-left: 45px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .form-control-lg:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.25rem rgba(42, 82, 152, 0.1);
        }

        .alert-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .back-btn {
            color: white;
            text-decoration: none;
            font-size: 0.9rem;
            opacity: 0.8;
            transition: 0.3s;
        }

        .back-btn:hover {
            opacity: 1;
            color: white;
        }

        /* Styling untuk hasil pencarian (asumsi search.php menghasilkan tabel atau list) */
        #result {
            margin-top: 20px;
        }
    </style>
</head>

<body>

    <header class="header-search text-center">
        <div class="container px-4">
            <a href="index.php" class="back-btn mb-3 d-inline-block"><i class="fas fa-arrow-left me-2"></i>Kembali ke Dashboard</a>
            <h2 class="fw-bold">PENDATAAN SEMETON</h2>
            <p class="opacity-75 small mb-0 mx-auto" style="max-width: 600px;">
                Cari nama Anda di database. Jika tidak ditemukan, Anda dapat menambahkan data baru untuk memastikan informasi keluarga besar tetap akurat.
            </p>
        </div>
    </header>

    <main class="container px-4 pb-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">

                <?php if (isset($_GET['pesan'])): ?>
                    <div class="mt-3">
                        <?php if ($_GET['pesan'] == 'sukses'): ?>
                            <div class="alert alert-success alert-custom d-flex align-items-center" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                <div>Data Berhasil diperbaharui!</div>
                            </div>
                        <?php elseif ($_GET['pesan'] == 'tambah'): ?>
                            <div class="alert alert-success alert-custom d-flex align-items-center" role="alert">
                                <i class="fas fa-plus-circle me-2"></i>
                                <div>Data Berhasil Ditambah. Silahkan Cari Nama Anda!</div>
                            </div>
                        <?php elseif ($_GET['pesan'] == 'nik_terdaftar'): ?>
                            <div class="alert alert-danger alert-custom d-flex align-items-center" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <div>NIK tersebut sudah dipakai anggota lain. Periksa kembali NIK Anda.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="search-card mt-4">
                    <h5 class="fw-bold mb-4 text-center">Temukan Data Anda</h5>
                    <div class="search-input-group">
                        <i class="fas fa-search"></i>
                        <input type="text" id="search" class="form-control form-control-lg" placeholder="Masukkan nama lengkap semeton...">
                    </div>

                    <div id="result">
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-users-viewfinder fa-3x mb-3 opacity-25"></i>
                            <p>Ketikkan nama pada kolom di atas untuk mulai mencari.</p>
                        </div>
                    </div>
                </div>

                <footer class="text-center mt-5">
                    <p class="text-muted small">&copy; <?php echo date('Y'); ?> Arya Pengalasan Pusat Bedulu</p>
                </footer>
            </div>
        </div>
    </main>

    <script>
        $(document).ready(function() {
            $("#search").on("keyup", function() {
                let query = $(this).val();
                if (query.length > 0) {
                    $.ajax({
                        url: "search.php",
                        method: "POST",
                        data: {
                            query: query
                        },
                        beforeSend: function() {
                            // Opsional: tampilkan loading kecil
                        },
                        success: function(data) {
                            $("#result").html(data);
                        }
                    });
                } else {
                    $("#result").html(`
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-users-viewfinder fa-3x mb-3 opacity-25"></i>
                            <p>Ketikkan nama pada kolom di atas untuk mulai mencari.</p>
                        </div>
                    `);
                }
            });
        });
    </script>

</body>

</html>