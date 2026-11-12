<?php
require_once __DIR__ . '/security.php';
kbap_guard(['admin', 'bendahara'], false);

include 'koneksi.php';

$user_id   = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$id_odalan = isset($_GET['piodalan']) ? (int) $_GET['piodalan'] : 0;

if ($user_id === '' || $id_odalan <= 0) {
    http_response_code(400);
    exit('Parameter nota tidak lengkap.');
}

$stmt = $koneksi->prepare(
    "SELECT s.nama, s.user_id, s.jenis_user, o.nama_odalan, o.tanggal,
            p.id_pembayaran, p.jumlah_bayar, p.metode_pembayaran, p.tanggal_bayar
     FROM semeton s
     JOIN odalan o ON o.id = ?
     LEFT JOIN pembayaran p ON p.id_semeton = s.id AND p.id_piodalan = o.id AND p.dibatalkan_at IS NULL
     WHERE s.user_id = ?
     ORDER BY p.id_pembayaran DESC
     LIMIT 1"
);
$stmt->bind_param('is', $id_odalan, $user_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) {
    http_response_code(404);
    exit('Data nota tidak ditemukan.');
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Nota Pembayaran <?= htmlspecialchars($data['user_id'], ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #222;
            margin: 24px;
        }

        .nota {
            max-width: 560px;
            margin: 0 auto;
            border: 1px solid #999;
            padding: 24px;
        }

        h2 {
            margin: 0 0 4px;
            font-size: 18px;
        }

        .muted {
            color: #666;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
        }

        th,
        td {
            border: 1px solid #999;
            padding: 8px;
            font-size: 13px;
            text-align: left;
        }

        th {
            background: #f2f2f2;
        }

        .total {
            font-weight: bold;
        }

        .ttd {
            margin-top: 40px;
            text-align: right;
            font-size: 13px;
        }

        @media print {
            body {
                margin: 0;
            }

            .no-print {
                display: none;
            }

            .nota {
                border: none;
            }
        }
    </style>
</head>

<body onload="window.print()">
    <div class="nota">
        <h2>NOTA PEMBAYARAN URUNAN</h2>
        <div class="muted">Arya Pengalasan Pusat Bedulu</div>

        <table>
            <tr>
                <th style="width: 35%;">Nama</th>
                <td><?= htmlspecialchars($data['nama'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <tr>
                <th>User ID</th>
                <td><?= htmlspecialchars($data['user_id'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <tr>
                <th>Jenis</th>
                <td><?= htmlspecialchars(ucfirst((string) $data['jenis_user']), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <tr>
                <th>Odalan</th>
                <td><?= htmlspecialchars($data['nama_odalan'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <tr>
                <th>Tanggal Bayar</th>
                <td><?= $data['tanggal_bayar'] ? date('d/m/Y H:i', strtotime($data['tanggal_bayar'])) : '-' ?></td>
            </tr>
            <tr>
                <th>Metode</th>
                <td><?= htmlspecialchars((string) ($data['metode_pembayaran'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <tr>
                <th>Jumlah</th>
                <td class="total">Rp <?= number_format((float) ($data['jumlah_bayar'] ?? 0), 0, ',', '.') ?></td>
            </tr>
            <tr>
                <th>No. Nota</th>
                <td><?= $data['id_pembayaran'] ? '#' . (int) $data['id_pembayaran'] : '-' ?></td>
            </tr>
        </table>

        <div class="ttd">
            Bedulu, <?= date('d/m/Y') ?><br>
            Bendahara
            <br><br><br>
            (................................)
        </div>

        <p class="no-print" style="margin-top:24px;">
            <button onclick="window.print()">Cetak</button>
            <button onclick="window.close()">Tutup</button>
        </p>
    </div>
</body>

</html>
