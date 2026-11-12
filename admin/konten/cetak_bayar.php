<?php
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bendahara'], false);

// Catatan: Hapus baris di bawah ini (ini_set) setelah Anda yakin kode berfungsi di server hosting.
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . '/../koneksi.php'; // Pastikan jalur koneksi ke database sudah benar

// ----------------------------------------------------
// CEK KONEKSI DATABASE
// ----------------------------------------------------
if ($koneksi->connect_error) {
    die("<p class='text-center text-danger'>❌ Koneksi Database Gagal: " . $koneksi->connect_error . "</p>");
}

// Ambil ID Piodalan dari parameter URL
$id_piodalan = isset($_GET['id_odalan']) ? intval($_GET['id_odalan']) : 0;

if ($id_piodalan <= 0) {
    die("<p class='text-center text-danger'>ID Piodalan tidak valid. Pastikan URL memiliki parameter **?id_odalan=X**</p>");
}

// 1. Ambil nama odalan berdasarkan ID Piodalan
$query_odalan = "SELECT nama_odalan FROM odalan WHERE id = ?";
$stmt_odalan = $koneksi->prepare($query_odalan);

if (!$stmt_odalan) {
    die("<p class='text-center text-danger'>Prepare Query Odalan Gagal: " . $koneksi->error . "</p>");
}

$stmt_odalan->bind_param("i", $id_piodalan);
$stmt_odalan->execute();
$result_odalan = $stmt_odalan->get_result();
$odalan = $result_odalan->fetch_assoc();
$stmt_odalan->close();

if (!$odalan) {
    die("<p class='text-center text-warning'>Piodalan dengan ID: **$id_piodalan** tidak ditemukan.</p>");
}
$nama_odalan = $odalan['nama_odalan'];

// 2. Query utama untuk mengambil data SEMUA SEMETON beserta status iuran
$query = "
    SELECT 
        COALESCE(w.nama_wilayah, 'Tidak Ada Wilayah') AS nama_wilayah, 
        COALESCE(sw.nama_sub, 'Tidak Ada Sub') AS nama_sub, 
        s.user_id, 
        s.nama AS nama_semeton, 
        s.jenis_user,
        COALESCE(h.jumlah_hutang, 0) AS sisa_hutang, 
        COALESCE(SUM(p.jumlah_bayar), 0) AS total_bayar, 
        CASE
            WHEN h.id_semeton IS NOT NULL THEN
                CASE
                    WHEN COALESCE(h.jumlah_hutang, 0) > 0 THEN 'Belum Lunas' 
                    ELSE 'Lunas' 
                END
            ELSE 'Tidak Ada Kewajiban' 
        END AS status_iuran
    FROM semeton s
    LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
    LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
    
    -- Perbaikan kolom yang sebelumnya 'id_piodalan'
    LEFT JOIN hutang h ON s.id = h.id_semeton AND h.id_odalan = ? 
    
    LEFT JOIN pembayaran p ON s.id = p.id_semeton AND p.id_piodalan = ? AND p.dibatalkan_at IS NULL
    
    GROUP BY 
        s.id, nama_wilayah, nama_sub
    ORDER BY nama_wilayah, nama_sub, s.nama ASC
";

$stmt = $koneksi->prepare($query);

if (!$stmt) {
    die("<p class='text-center text-danger'>Prepare Query Utama Gagal: " . $koneksi->error . "</p>");
}

$stmt->bind_param("ii", $id_piodalan, $id_piodalan);
$stmt->execute();

if ($stmt->error) {
    die("<p class='text-center text-danger'>Execute Query Utama Gagal: " . $stmt->error . "</p>");
}

$result = $stmt->get_result();

$total_bayar_keseluruhan = 0;
$grouped_data = [];

// Mengelompokkan data
while ($row = $result->fetch_assoc()) {
    $wilayah = $row['nama_wilayah'];
    $sub_wilayah = $row['nama_sub'];

    $grouped_data[$wilayah][$sub_wilayah][] = $row;
    $total_bayar_keseluruhan += $row['total_bayar'];
}

$stmt->close();
$koneksi->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Pembayaran – <?= htmlspecialchars($nama_odalan) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Gaya CSS untuk print */
        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm;
            }

            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                font-family: Arial, sans-serif;
                font-size: 10pt;
                /* Ukuran font dasar sedikit lebih kecil */
            }

            .no-print {
                display: none;
            }

            /* Mengatur Ukuran Font pada Tabel */
            table {
                border-collapse: collapse;
                width: 100%;
                font-size: 9pt;
                /* Ukuran font tabel yang nyaman */
                page-break-inside: auto;
            }

            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-footer-group;
            }

            /* Mengatur Padding dan Border Tabel */
            th,
            td {
                border: 1px solid black !important;
                padding: 3px 4px;
                /* Padding sedikit dikurangi */
                vertical-align: top;
            }

            thead {
                background-color: #ddd !important;
            }

            /* PERUBAHAN KRUSIAL: MENGECILKAN UKURAN FONT HEADING */
            h6 {
                font-size: 11pt;
                margin: 6px 0 2px 0;
            }

            /* Sub Wilayah */
            h5 {
                font-size: 12pt;
                margin: 0;
            }

            /* Judul Atas */
            h4 {
                font-size: 12pt;
                margin: 8px 0;
            }

            /* Total Wilayah */
            h2 {
                /* Wilayah Group */
                font-size: 12pt;
                background-color: #343a40 !important;
                color: white !important;
                padding: 4px;
                text-align: center;
                margin-top: 10px;
            }

            h1 {
                /* Total Keseluruhan */
                font-size: 14pt;
                background-color: #343a40 !important;
                color: white !important;
                padding: 6px !important;
                text-align: right;
                margin-top: 15px;
            }

            p.mb-3 {
                font-size: 11pt;
            }
        }

        /* Gaya Non-Print untuk tampilan di browser */
        .header-bg {
            background-color: #343a40;
            color: white;
            padding: 5px;
            margin-top: 15px;
        }

        .footer-total {
            background-color: #e9ecef;
        }
    </style>
</head>

<body onload="window.print();">
    <div class="container-fluid mt-4">
        <img src="../dist/assets/img/bg-logo.png" alt="" width="50" class="d-block mx-auto">
        <h5 class="mb-0 text-center">Laporan Rekap Iuran Anggota Piodalan</h5>
        <h5 class="mb-0 text-center">Arya Pengalasan Pusat Bedulu</h5>
        <p class="mb-3 text-center">Piodalan: **<?= htmlspecialchars($nama_odalan) ?>**</p>

        <?php
        if (empty($grouped_data)): ?>
            <p class='text-center text-info'>ℹ️ Tidak ada data semeton atau pembayaran yang tercatat untuk Piodalan ini.</p>
        <?php else: ?>
            <?php
            // 3. Looping untuk Tampilan Berdasarkan Grouping
            foreach ($grouped_data as $wilayah => $data_per_wilayah):
                $total_bayar_wilayah = 0;
            ?>
                <h2 class="header-bg">Wilayah: <?= htmlspecialchars($wilayah) ?></h2>

                <?php foreach ($data_per_wilayah as $sub_wilayah => $data_per_sub): ?>
                    <h6 class="mt-3">Sub Wilayah: <?= htmlspecialchars($sub_wilayah) ?></h6>
                    <table class="table table-bordered table-sm">
                        <thead class="table-dark">
                            <tr>
                                <th width="5%">No</th>
                                <th width="12%">User ID</th>
                                <th width="25%">Nama Semeton</th>
                                <th width="10%">Jenis User</th>
                                <th width="15%" class="text-end">Total Bayar (Rp)</th>
                                <th width="15%" class="text-end">Sisa Hutang (Rp)</th>
                                <th width="18%">Status Iuran</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no = 1;
                            $sub_total_bayar = 0;
                            ?>
                            <?php foreach ($data_per_sub as $item): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= htmlspecialchars($item['user_id']) ?></td>
                                    <td><?= htmlspecialchars($item['nama_semeton']) ?></td>
                                    <td><?= htmlspecialchars($item['jenis_user']) ?></td>
                                    <td class="text-end"><?= number_format($item['total_bayar'], 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($item['sisa_hutang'], 0, ',', '.') ?></td>
                                    <td>
                                        <?php
                                        $status_iuran = htmlspecialchars($item['status_iuran']);
                                        echo $status_iuran;

                                        // Hitung total bayar sub
                                        $sub_total_bayar += $item['total_bayar'];
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr class="footer-total">
                                <td colspan="4" class="text-end"><strong>Total Pembayaran Sub Wilayah:</strong></td>
                                <td class="text-end"><strong>Rp <?= number_format($sub_total_bayar, 0, ',', '.') ?></strong></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                    <?php $total_bayar_wilayah += $sub_total_bayar; ?>
                <?php endforeach; ?>
                <h4 class="text-end mt-2"><strong>Total Pembayaran Wilayah: Rp <?= number_format($total_bayar_wilayah, 0, ',', '.') ?></strong></h4>
            <?php endforeach; ?>

            <h1 class="text-end mt-4 header-bg">**Total Pembayaran Keseluruhan: Rp <?= number_format($total_bayar_keseluruhan, 0, ',', '.') ?>**</h1>
        <?php endif; ?>

        <table class="table table-borderless mt-5">
            <tr>
                <td style="width: 50%;">Mengetahui,<br>Ketua Panitia Odalan <br><br><br><br>(______________________________)</td>
                <td style="width: 50%;">
                    <div id="tanggal"></div>
                    Bendahara Odalan <br><br><br><br>(______________________________)
                </td>
            </tr>

        </table>
    </div>
</body>
<script>
    // Script untuk menampilkan tanggal hari ini di bagian tanda tangan
    const now = new Date();
    const options = {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    };
    document.getElementById('tanggal').innerText = 'Bedulu, ' + now.toLocaleDateString('id-ID', options);
</script>

</html>