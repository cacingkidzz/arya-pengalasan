<?php
require_once __DIR__ . '/security.php';
kbap_guard(['admin', 'bendahara'], false); // laporan keuangan: wajib login

include("koneksi.php");
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Ambil data Piodalan
$stmt = $koneksi->prepare("SELECT * FROM odalan WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$odalan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$odalan) {
    http_response_code(404);
    exit('Piodalan tidak ditemukan.');
}

// Ambil data Punia
$stmt = $koneksi->prepare("SELECT * FROM dana_punia WHERE id_piodalan = ? ORDER BY tanggal_punia ASC");
$stmt->bind_param("i", $id);
$stmt->execute();
$result_punia = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Cetak Laporan Punia - <?= htmlspecialchars((string) $odalan['nama_odalan'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="dist/vendor/bootstrap/5.3.0/bootstrap.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 10px;
            /* Sedikit lebih kecil agar makin kompak */
            color: #000;
            background-color: #fff;
        }

        .kop-surat {
            border-bottom: 2px solid #000;
            margin-bottom: 15px;
            padding-bottom: 10px;
        }

        /* Pengaturan Margin Cetak */
        @media print {
            @page {
                size: A4;
                margin-top: 1.5cm;
                margin-bottom: 1.5cm;
                margin-left: 2cm;
                /* Margin Kiri 2cm */
                margin-right: 2cm;
                /* Margin Kanan 2cm */
            }

            .no-print {
                display: none;
            }

            body {
                -webkit-print-color-adjust: exact;
            }
        }

        .table-compact {
            width: 100%;
            border-collapse: collapse;
        }

        .table-compact th {
            background-color: #f2f2f2 !important;
            text-transform: uppercase;
            font-size: 9px;
            padding: 5px !important;
            border: 1px solid #000 !important;
        }

        .table-compact td {
            padding: 4px 6px !important;
            vertical-align: middle;
            border: 1px solid #000 !important;
        }

        .text-nominal {
            font-family: 'Courier New', Courier, monospace;
            font-weight: bold;
            white-space: nowrap;
        }

        .footer-section {
            margin-top: 25px;
        }

        .stamp-box {
            height: 50px;
        }
    </style>
</head>

<body onload="window.print()">

    <div class="container-fluid">
        <div class="kop-surat text-center">
            <h5 class="mb-0 fw-bold">LAPORAN DATA DANA PUNIA</h5>
            <h4 class="mb-0 fw-bold">ARYA PENGALASAN PUSAT BEDULU</h4>
            <h6 class="mb-1 text-uppercase"><?= htmlspecialchars((string) $odalan['nama_odalan'], ENT_QUOTES, 'UTF-8') ?></h6>
            <p class="mb-0 small">Bedulu - Gianyar</p>
        </div>

        <div class="d-flex justify-content-between mb-2">
            <div>
                <strong>Dicetak pada:</strong> <?= date('d/m/Y H:i') ?>
            </div>
            <div>
                <strong>Halaman:</strong> 1 dari 1
            </div>
        </div>

        <table class="table table-bordered table-compact">
            <thead class="text-center">
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 25%;">Nama Donatur</th>
                    <th style="width: 20%;">Alamat</th>
                    <th style="width: 20%;">Keterangan</th>
                    <th style="width: 10%;">Metode</th>
                    <th style="width: 10%;">Tanggal</th>
                    <th style="width: 15%;">Jumlah (Rp)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no = 1;
                $total = 0;
                while ($d = $result_punia->fetch_assoc()):
                    $total += (float) $d['jumlah_punia'];
                ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td class="fw-bold"><?= htmlspecialchars(strtoupper((string) $d['nama_donatur']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $d['alamat'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-muted italic"><?= htmlspecialchars((string) ($d['keterangan'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-center small text-uppercase"><?= htmlspecialchars((string) $d['metode_pembayaran'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-center"><?= $d['tanggal_punia'] ? date('d/m/y', strtotime($d['tanggal_punia'])) : '-' ?></td>
                        <td class="text-end text-nominal">
                            <?= number_format((float) $d['jumlah_punia'], 0, ',', '.') ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                <?php $stmt->close(); ?>
            </tbody>
            <tfoot>
                <tr class="fw-bold" style="background-color: #eee !important;">
                    <td colspan="6" class="text-end">TOTAL TERKUMPUL :</td>
                    <td class="text-end text-nominal">Rp <?= number_format($total, 0, ',', '.') ?></td>
                </tr>
            </tfoot>
        </table>

        <div class="row footer-section">
            <div class="col-7">
                <p style="font-size: 9px;">* Data ini merupakan rekapan resmi dana punia yang masuk melalui sistem informasi.</p>
            </div>
            <div class="col-5 text-center">
                <p class="mb-0">Bedulu, <?= date('d F Y') ?></p>
                <p class="fw-bold mb-4">Bendahara Panitia,</p>
                <div class="stamp-box"></div>
                <p class="text-decoration-underline fw-bold mb-0">( ........................................ )</p>
                <p class="small text-muted" style="font-size: 9px;">Dicetak oleh: Admin Sistem</p>
            </div>
        </div>
    </div>

    <div class="text-center no-print mt-4 pb-5">
        <hr>
        <button onclick="window.close()" class="btn btn-secondary btn-sm">Tutup</button>
        <button onclick="window.print()" class="btn btn-primary btn-sm">Cetak Sekarang</button>
    </div>

</body>

</html>