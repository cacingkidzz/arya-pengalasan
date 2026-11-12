<?php
include "admin/koneksi.php";

// 1. Cari Piodalan Terakhir/Terbaru
$query_odalan_terbaru = "SELECT id, nama_odalan FROM odalan ORDER BY id DESC LIMIT 1";
$res_odalan = $koneksi->query($query_odalan_terbaru);
$data_odalan = $res_odalan->fetch_assoc();

$id_piodalan_aktif = $data_odalan['id'] ?? 0;
$nama_odalan_aktif = $data_odalan['nama_odalan'] ?? "Tidak Ada Data";

// 2. Ambil data Dana Punia berdasarkan Piodalan tersebut
$query_punia = "SELECT * FROM dana_punia WHERE id_piodalan = '$id_piodalan_aktif' ORDER BY tanggal_punia DESC";
$result_punia = $koneksi->query($query_punia);

$data_list = [];
$total_punia = 0;
if ($result_punia) {
    while ($row = $result_punia->fetch_assoc()) {
        $data_list[] = $row;
        $total_punia += $row['jumlah_punia'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dana Punia | <?= $nama_odalan_aktif ?></title>
    <link rel="icon" type="image/png" href="admin/dist/assets/img/bg-logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

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

        .header-punia {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 50px 0 100px 0;
            border-radius: 0 0 30px 30px;
        }

        .punia-container {
            margin-top: -70px;
            padding-bottom: 50px;
        }

        .summary-card {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            border-left: 6px solid var(--accent-color);
        }

        .table-card {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .badge-metode {
            font-size: 0.7rem;
            padding: 5px 10px;
            border-radius: 50px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .metode-tunai {
            background-color: #dcfce7;
            color: #15803d;
        }

        .metode-transfer {
            background-color: #e0f2fe;
            color: #0369a1;
        }

        .metode-qris {
            background-color: #f3e8ff;
            color: #7e22ce;
        }

        .back-btn {
            color: white;
            text-decoration: none;
            opacity: 0.8;
        }
    </style>
</head>

<body>
    <header class="header-punia text-center">
        <div class="container px-4">
            <a href="index.php" class="back-btn mb-3 d-inline-block"><i class="fas fa-arrow-left me-2"></i>Kembali ke Dashboard</a>
            <h2 class="fw-bold">REKAP DANA PUNIA</h2>
            <h5 class="opacity-75 text-uppercase"><?= $nama_odalan_aktif ?></h5>
        </div>
    </header>

    <main class="container punia-container px-4">
        <div class="row justify-content-center">
            <div class="col-lg-11">

                <div class="card summary-card p-4 mb-4">
                    <div class="row align-items-center">
                        <div class="col-md-8 text-center text-md-start">
                            <h6 class="text-muted fw-bold mb-1">TOTAL TERKUMPUL</h6>
                            <h2 class="fw-bold text-success mb-0">Rp <?= number_format($total_punia, 0, ',', '.'); ?></h2>
                        </div>
                        <div class="col-md-4 text-md-end text-center mt-3 mt-md-0">
                            <div class="icon-box bg-warning-subtle d-inline-block p-3 rounded-circle">
                                <i class="fas fa-hand-holding-heart fa-2x text-warning"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card table-card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Donatur</th>
                                    <th>Alamat</th>
                                    <th class="text-center">Metode</th>
                                    <th class="text-end">Jumlah</th>
                                    <th class="text-center">Tanggal</th>
                                    <th>Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($data_list) > 0): ?>
                                    <?php foreach ($data_list as $row):
                                        $metode = strtolower($row['metode_pembayaran']);
                                    ?>
                                        <tr>
                                            <td class="ps-4">
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_donatur']); ?></div>
                                            </td>
                                            <td class="small text-muted"><?= htmlspecialchars($row['alamat']); ?></td>
                                            <td class="text-center">
                                                <span class="badge-metode metode-<?= $metode ?>"><?= $metode ?></span>
                                            </td>
                                            <td class="text-end fw-bold text-primary">Rp <?= number_format($row['jumlah_punia'], 0, ',', '.'); ?></td>
                                            <td class="text-center small"><?= date('d/m/Y', strtotime($row['tanggal_punia'])); ?></td>
                                            <td class="small italic text-muted"><?= $row['keterangan'] ?: '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <p class="text-muted mb-0">Belum ada data punia untuk acara ini.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <footer class="text-center mt-5">
                    <p class="text-muted small">&copy; <?= date('Y'); ?> Arya Pengalasan Pusat Bedulu</p>
                </footer>
            </div>
        </div>
    </main>
</body>

</html>