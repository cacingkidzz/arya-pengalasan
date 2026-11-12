<?php
include 'admin/koneksi.php';

// =========================================================
// Mode endpoint JSON untuk Select2 (dipanggil via AJAX ?q=...)
// =========================================================
if (isset($_GET['q'])) {
    header('Content-Type: application/json; charset=utf-8');

    $q = trim((string) $_GET['q']);
    if (strlen($q) < 2) {
        echo json_encode([]);
        exit;
    }

    $like = '%' . $q . '%';
    $stmt = $koneksi->prepare(
        "SELECT user_id, nama FROM semeton
         WHERE status = 'aktif' AND (nama LIKE ? OR user_id LIKE ?)
         ORDER BY nama ASC LIMIT 20"
    );
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();

    $results = [];
    while ($row = $res->fetch_assoc()) {
        $results[] = [
            'id'   => $row['user_id'],
            'text' => $row['nama'] . ' (' . $row['user_id'] . ')',
        ];
    }
    $stmt->close();

    echo json_encode($results, JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cek Urunan | Arya Pengalasan Pusat</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

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

        /* Custom Select2 Style */
        .select2-container--bootstrap-5 .select2-selection {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            min-height: 50px;
            line-height: 30px;
        }

        .btn-check {
            background: var(--primary-color);
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-weight: 700;
            transition: 0.3s;
            color: white;
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
            <a href="index.php" class="back-btn mb-3 d-inline-block"><i class="fas fa-arrow-left me-2"></i>Kembali ke Dashboard</a>
            <h2 class="fw-bold">CEK STATUS URUNAN</h2>
            <p class="opacity-75 small">Cari berdasarkan Nama atau ID Semeton.</p>
        </div>
    </header>

    <main class="container px-4 pb-5">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="cek-card">
                    <form id="hutangForm">
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted text-uppercase">Data Semeton</label>
                            <select name="id_semeton" id="id_semeton" class="form-select" required>
                                <option value="">Ketik Nama atau ID...</option>
                            </select>
                            <div class="form-text mt-2 small">Sistem akan mencari otomatis saat Anda mengetik minimal 2 huruf.</div>
                        </div>

                        <button type="submit" class="btn btn-check w-100 shadow-sm" id="btnSubmit">
                            <span class="btn-text text-uppercase"><i class="fas fa-search me-2"></i>Lihat Rincian</span>
                            <div class="spinner-border text-light ms-2" role="status"></div>
                        </button>
                    </form>

                    <div id="result" class="mt-4">
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        $(document).ready(function() {
            // Inisialisasi Select2 dengan AJAX
            $('#id_semeton').select2({
                theme: 'bootstrap-5',
                placeholder: 'Ketik Nama atau ID...',
                minimumInputLength: 2,
                ajax: {
                    url: 'cari_semeton.php',
                    dataType: 'json',
                    delay: 300,
                    data: function(params) {
                        return {
                            q: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data
                        };
                    },
                    cache: true
                }
            });

            // Submit Form
            $("#hutangForm").submit(function(e) {
                e.preventDefault();
                let user_id = $("#id_semeton").val();
                if (!user_id) return;

                $("#btnSubmit").prop("disabled", true);
                $(".spinner-border").show();
                $(".btn-text").css("opacity", "0.5");

                $.ajax({
                    url: "hasil_hutang.php",
                    method: "POST",
                    data: {
                        id_semeton: user_id
                    },
                    success: function(response) {
                        $("#result").hide().html(response).fadeIn(500);
                        $("#btnSubmit").prop("disabled", false);
                        $(".spinner-border").hide();
                        $(".btn-text").css("opacity", "1");
                    }
                });
            });
        });
    </script>
</body>

</html>