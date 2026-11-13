<?php
include 'admin/koneksi.php'; // Pastikan koneksi ke database sudah benar
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cek Urunan | Arya Pengalasan Pusat</title>
    <link rel="icon" type="image/png" href="admin/dist/assets/img/bg-logo.png">

    <link href="admin/dist/vendor/google-fonts/inter.css" rel="stylesheet">
    <link href="admin/dist/vendor/bootstrap/5.3.3/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin/dist/vendor/font-awesome/css/all.min.css">

    <link href="admin/dist/vendor/select2/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="admin/dist/vendor/select2/select2-bootstrap-5-theme.min.css" />

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

        .header-cek {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 50px 0 90px 0;
            border-radius: 0 0 30px 30px;
        }

        .cek-card {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            padding: 35px;
            margin-top: -60px;
        }

        /* FIX AGAR BISA DIKETIK & RAPI */
        .select2-container--bootstrap-5 .select2-selection {
            border: 2px solid #e2e8f0 !important;
            border-radius: 12px !important;
            height: 55px !important;
            display: flex !important;
            align-items: center !important;
        }

        .btn-check-data {
            background: var(--primary-color);
            border: none;
            border-radius: 12px;
            padding: 15px;
            font-weight: 700;
            color: white;
            transition: 0.3s;
            width: 100%;
        }

        .btn-check-data:hover {
            background: #16305a;
            transform: translateY(-2px);
        }

        .spinner-border {
            width: 1.2rem;
            height: 1.2rem;
            display: none;
        }
    </style>
</head>

<body>
    <header class="header-cek text-center">
        <div class="container px-4">
            <a href="index.php" class="text-white opacity-75 text-decoration-none mb-3 d-inline-block"><i class="fas fa-arrow-left me-2"></i>Kembali</a>
            <h2 class="fw-bold">CEK STATUS URUNAN</h2>
            <p class="opacity-75 small">Cari Nama / ID / Wilayah Semeton</p>
        </div>
    </header>

    <main class="container px-4 pb-5">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="cek-card">
                    <form id="hutangForm">
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted text-uppercase">Data Semeton</label>
                            <select name="id_semeton" id="id_semeton_select" class="form-select" required>
                                <option value="">-- Ketik Nama / ID / Wilayah --</option>
                                <?php
                                $sql = "SELECT s.id, s.user_id, s.nama, w.nama_wilayah, sw.nama_sub 
            FROM semeton s
            LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
            LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
            ORDER BY s.nama ASC";
                                $query = mysqli_query($koneksi, $sql);
                                while ($row = mysqli_fetch_assoc($query)) {
                                    $u_id    = $row['user_id']; // Ini yang akan dikirim (APPxxx)
                                    $nama    = strtoupper($row['nama']);
                                    $wilayah = !empty($row['nama_wilayah']) ? $row['nama_wilayah'] : '-';
                                    $sub     = !empty($row['nama_sub']) ? $row['nama_sub'] : '-';

                                    // VALUE menggunakan $u_id agar cocok dengan WHERE s.user_id di hasil_hutang.php
                                    echo "<option value='$u_id'>$u_id - $nama ( $wilayah - $sub )</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <button type="submit" class="btn-check-data shadow-sm" id="btnSubmit">
                            <span class="btn-text text-uppercase"><i class="fas fa-search me-2"></i>TAMPILKAN HASIL</span>
                            <div class="spinner-border text-light ms-2" role="status"></div>
                        </button>
                    </form>

                    <div id="result" class="mt-4">
                        <div class="text-center py-4 text-muted opacity-50 border-top mt-3">
                            <i class="fas fa-file-invoice-dollar fa-2x mb-2"></i>
                            <p class="small mb-0">Rincian akan muncul di sini setelah Anda menekan tombol</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="admin/dist/vendor/jquery/jquery-3.7.1.min.js"></script>
    <script src="admin/dist/vendor/bootstrap/5.3.3/bootstrap.bundle.min.js"></script>
    <script src="admin/dist/vendor/select2/select2.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#id_semeton_select').select2({
                theme: 'bootstrap-5',
                width: '100%'
            });

            $("#hutangForm").submit(function(e) {
                e.preventDefault();
                let id_sem = $("#id_semeton_select").val();
                if (!id_sem) return alert("Pilih nama!");

                $("#result").html('<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>');

                $.ajax({
                    url: "hasil_hutang.php",
                    method: "POST",
                    data: {
                        id_semeton: id_sem
                    }, // Mengirim APPxxx
                    success: function(response) {
                        $("#result").html(response);
                    }
                });
            });
        });
    </script>
</body>

</html>