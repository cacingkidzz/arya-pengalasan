<?php
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bendahara'], false); // laporan keuangan: wajib login

// Pastikan jalur koneksi ke database sudah benar.
require_once __DIR__ . '/../koneksi.php';

// ----------------------------------------------------
// CEK KONEKSI DATABASE
// ----------------------------------------------------
if ($koneksi->connect_error) {
    die("<p class='text-center text-danger'>❌ Koneksi Database Gagal: " . $koneksi->connect_error . "</p>");
}

// ----------------------------------------------------
// FUNGSI TRANSLASI TANGGAL KE BAHASA INDONESIA (SOLUSI)
// ----------------------------------------------------
function format_tanggal_indo($tanggal_raw, $cetak_hari = false)
{
    // Jika tanggal kosong atau '-', kembalikan '-'
    if (empty($tanggal_raw) || $tanggal_raw === '-') {
        return '-';
    }

    $hari = array(
        1 =>     'Senin',
        'Selasa',
        'Rabu',
        'Kamis',
        'Jumat',
        'Sabtu',
        'Minggu'
    );
    $bulan = array(
        1 =>     'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    );
    // Ubah tanggal mentah (YYYY-MM-DD) menjadi timestamp
    $split     = explode('-', $tanggal_raw);
    $tgl_indo = $split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];

    if ($cetak_hari) {
        $num = date('N', strtotime($tanggal_raw));
        return $hari[$num] . ', ' . $tgl_indo;
    }
    return $tgl_indo;
}


// ----------------------------------------------------
// 1. Ambil ID dan detail Piodalan TERBARU
// ----------------------------------------------------
$query_latest_odalan = "SELECT id, nama_odalan, iuran_ngarep, iuran_ngempi, tanggal FROM odalan ORDER BY tanggal DESC, id DESC LIMIT 1";
$result_latest_odalan = mysqli_query($koneksi, $query_latest_odalan);

if (mysqli_num_rows($result_latest_odalan) == 0) {
    $nama_odalan_terbaru = "Belum Ada Piodalan";
    $tanggal_indo = "Tanggal Tidak Tersedia";
    $id_piodalan_terbaru = 0;
    $tanggal_odalan_raw = '0000-00-00';
    $odalan_terbaru = ['iuran_ngarep' => 0, 'iuran_ngempi' => 0]; // Default value
} else {
    $odalan_terbaru = mysqli_fetch_assoc($result_latest_odalan);
    $id_piodalan_terbaru = $odalan_terbaru['id'];
    $nama_odalan_terbaru = $odalan_terbaru['nama_odalan'];
    $tanggal_odalan_raw = $odalan_terbaru['tanggal'];

    // Ganti menggunakan fungsi format_tanggal_indo()
    $tanggal_indo = format_tanggal_indo($tanggal_odalan_raw);
}

// ----------------------------------------------------
// 2. QUERY UTAMA: Mengambil data Semeton + Hitungan Hutang Sebelum 
// ----------------------------------------------------

$query = "
    SELECT 
        s.id AS id_semeton,
        COALESCE(w.nama_wilayah, 'Tidak Ada Wilayah') AS nama_wilayah, 
        COALESCE(sw.nama_sub, 'Tidak Ada Sub') AS nama_sub, 
        s.user_id, 
        s.nama AS nama_semeton, 
        s.jenis_user, 
        s.status,
        
        -- C. Total Iuran Wajib AKUMULATIF dari SEMUA Odalan SEBELUM Odalan Terbaru
        (
            SELECT COALESCE(SUM(
                CASE
                    WHEN s.status = 'aktif' AND s.jenis_user = 'ngarep' THEN o.iuran_ngarep
                    WHEN s.status = 'aktif' AND s.jenis_user = 'ngempi' THEN o.iuran_ngempi
                    ELSE 0
                END
            ), 0)
            FROM odalan o
            WHERE o.tanggal < '$tanggal_odalan_raw'
              AND s.status = 'aktif'
              AND s.jenis_user IN ('ngarep', 'ngempi')
              -- Hanya semeton yang memang punya tagihan di odalan tersebut.
              AND EXISTS (
                  SELECT 1 FROM hutang h
                  WHERE h.id_semeton = s.id AND h.id_odalan = o.id
              )
        ) AS total_iuran_wajib_sebelum,

        -- D. Total Pembayaran AKUMULATIF dari SEMUA Pembayaran untuk Odalan SEBELUM Terbaru
        (
            SELECT COALESCE(SUM(p.jumlah_bayar), 0)
            FROM pembayaran p
            JOIN odalan o2 ON o2.id = p.id_piodalan
            WHERE o2.tanggal < '$tanggal_odalan_raw' AND p.id_semeton = s.id
              AND p.dibatalkan_at IS NULL
        ) AS total_pembayaran_sebelum

    FROM semeton s
    LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
    LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
    
    GROUP BY 
        s.id, nama_wilayah, nama_sub, s.user_id, s.nama, s.jenis_user, s.status
        
    ORDER BY nama_wilayah, nama_sub, s.nama ASC
";

$result = mysqli_query($koneksi, $query);

if (!$result) {
    die("<p class='text-center text-danger'>Execute Query Utama Gagal: " . mysqli_error($koneksi) . "</p>");
}

$grouped_data = [];
$total_tunggakan_keseluruhan_prior = 0;
$total_semeton_keseluruhan = 0;

// Mengelompokkan data dan menghitung tunggakan final (Hanya Prior Debt)
while ($row = mysqli_fetch_assoc($result)) {
    // 1. Hitung Tunggakan Akumulatif SEBELUM Odalan Terbaru (Prior Debt)
    // Gunakan max(0, ...) untuk memastikan nilai tidak negatif (overpaid dianggap lunas=0)
    $row['tunggakan_sebelum'] = max(0, $row['total_iuran_wajib_sebelum'] - $row['total_pembayaran_sebelum']);

    // 2. Hitung Iuran Wajib untuk Odalan Terbaru (untuk ditampilkan di kolom Iuran Wajib)
    $iuran_wajib_terbaru = 0;
    if ($row['status'] == 'aktif' && $id_piodalan_terbaru > 0) {
        if ($row['jenis_user'] == 'ngarep') $iuran_wajib_terbaru = $odalan_terbaru['iuran_ngarep'];
        if ($row['jenis_user'] == 'ngempi') $iuran_wajib_terbaru = $odalan_terbaru['iuran_ngempi'];
    }
    $row['iuran_wajib_terbaru'] = $iuran_wajib_terbaru;

    // 3. Akumulasi Total Tunggakan Keseluruhan (Hanya Prior Debt)
    if ($row['tunggakan_sebelum'] > 0) {
        $total_tunggakan_keseluruhan_prior += $row['tunggakan_sebelum'];
    }

    $wilayah = $row['nama_wilayah'];
    $sub_wilayah = $row['nama_sub'];

    $grouped_data[$wilayah][$sub_wilayah][] = $row;
    $total_semeton_keseluruhan++;
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Status Iuran Odalan Terbaru + Tunggakan Sebelumnya</title>
    <link href="../dist/vendor/bootstrap/5.3.0/bootstrap.min.css" rel="stylesheet">
    <style>
        /* CSS Umum (Dipertahankan) */
        .header-bg {
            background-color: #343a40;
            color: white;
            padding: 5px;
            margin-top: 15px;
        }

        .text-tunggakan {
            font-weight: bold;
            color: #dc3545;
        }

        /* Tambahkan CSS untuk rincian tunggakan */
        .detail-list {
            margin: 0;
            padding-left: 10px;
            font-size: 7.5pt;
            line-height: 1.2;
            list-style-type: none;
            /* Hilangkan bullet default */
        }

        .detail-list li:before {
            content: '- ';
            /* Ganti dengan dash atau bullet sederhana */
        }


        /* -------------------------------------- */
        /* PENGATURAN CETAK (PRINT) */
        /* -------------------------------------- */
        @media print {

            /* Pengaturan Dasar A4 */
            @page {
                size: A4 portrait;
                margin: 8mm;
            }

            body {
                font-size: 8pt;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            /* --- Halaman Cover (Halaman 1) --- */
            .cover-page {
                height: 297mm;
                width: 210mm;
                margin: 0;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                text-align: center;
                page-break-after: always;
            }

            .cover-page h1 {
                font-size: 30pt !important;
                margin-top: 50px;
            }

            .cover-page h2 {
                font-size: 16pt !important;
            }

            .cover-page h6 {
                font-size: 10pt !important;
                margin-top: 80mm !important;
            }

            /* --- Halaman Laporan (Halaman 2 dst) --- */
            .laporan-content {
                padding-top: 0;
            }

            table {
                font-size: 7.5pt;
            }

            th,
            td {
                border: 1px solid black !important;
                padding: 2px 3px;
            }

            .header-bg,
            h2 {
                font-size: 10pt !important;
                color: black !important;
                padding: 4px;
            }

            h1 {
                font-size: 11pt !important;
                padding: 4px !important;
            }

            .text-tunggakan {
                font-weight: bold;
                color: #dc3545 !important;
            }

            h6 {
                font-size: 9pt;
                margin: 4px 0 2px 0;
            }

            .detail-list {
                font-size: 7.5pt;
                line-height: 1.2;
            }
        }
    </style>
</head>

<body>
    <div class="d-print-none sticky-top bg-white border-bottom shadow-sm py-2 mb-3">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <div class="small text-muted">
                <i class="bi bi-file-earmark-text"></i>
                Laporan penagihan &mdash; gunakan tombol cetak untuk versi kertas.
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer"></i> Cetak
                </button>
                <a href="index.php?menu=pembayaran" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Kembali
                </a>
            </div>
        </div>
    </div>
    <div class="container-fluid cover-page">
        <div class="p-5">
            <img src="../dist/assets/img/bg-logo.png" alt="Logo" width="200" class="d-block mx-auto mb-4">
            <h1 class="text-uppercase fw-bold text-center mb-0">
                DATA URUNAN SEMETON ARYA PENGALASAN PUSAT BEDULU
            </h1>

            <h2 class="text-center mt-5">
                Wewedalan/Urunan Wali Tingkat
            </h2>
            <h2 class="text-center text-primary fw-bold">
                <?= htmlspecialchars($nama_odalan_terbaru) ?>
            </h2>

            <h4 class="text-center mt-5" style="margin-top: 150px !important;">
                BEDULU, <?= $tanggal_indo ?>
            </h4>
        </div>
    </div>

    <div class="container-fluid laporan-content">
        <img src="../dist/assets/img/bg-logo.png" alt="Logo" width="40" class="d-block mx-auto">

        <h5 class="mb-0 text-center">STATUS IURAN & TUNGGAKAN SEMETON</h5>

        <h5 class="mb-0 text-center">Arya Pengalasan Pusat Bedulu</h5>
        <p class="mb-3 text-center">Status Pembayaran Iuran **<?= htmlspecialchars($nama_odalan_terbaru) ?>** dan Rincian Tunggakan Sebelumnya</p>

        <?php
        if (empty($grouped_data)): ?>
            <p class='text-center alert alert-info'>ℹ️ Tidak ada data semeton yang tercatat.</p>
        <?php else: ?>
            <?php
            $tunggakan_wilayah = 0;
            foreach ($grouped_data as $wilayah => $data_per_wilayah):
                $tunggakan_wilayah = 0; // Reset untuk setiap Wilayah
            ?>
                <h2 class="header-bg">Wilayah: <?= htmlspecialchars($wilayah) ?></h2>

                <?php foreach ($data_per_wilayah as $sub_wilayah => $data_per_sub): ?>
                    <h6>Sub Wilayah: <?= htmlspecialchars($sub_wilayah) ?></h6>
                    <table class="table table-bordered table-sm">
                        <thead class="table-dark">
                            <tr>
                                <th width="3%">No</th>
                                <th width="10%">User ID</th>
                                <th width="20%">Nama Semeton</th>
                                <th width="10%">Jenis User</th>

                                <th width="10%" class="text-end">IURAN WAJIB (Rp) (<?= htmlspecialchars($nama_odalan_terbaru) ?>)</th>

                                <th width="25%" class="text-end">TUNGGAKAN SEBELUMNYA (Rp)</th>

                                <th width="11%">Tgl Bayar</th>
                                <th width="11%">Ket</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no = 1;
                            $tunggakan_sub_prior = 0; // Reset untuk setiap Sub Wilayah
                            ?>
                            <?php foreach ($data_per_sub as $item): ?>
                                <?php $tunggakan_sebelum = $item['tunggakan_sebelum']; ?>

                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= htmlspecialchars($item['user_id']) ?></td>
                                    <td><?= htmlspecialchars($item['nama_semeton']) ?></td>
                                    <td><?= htmlspecialchars($item['jenis_user']) ?></td>

                                    <td class="text-end">
                                        <?= number_format($item['iuran_wajib_terbaru'], 0, ',', '.') ?>
                                    </td>

                                    <td class="text-end <?= $tunggakan_sebelum > 0 ? 'text-tunggakan' : '' ?>">
                                        <?php if ($tunggakan_sebelum > 0): ?>
                                            <?= number_format($tunggakan_sebelum, 0, ',', '.') ?>
                                            <ul class="detail-list text-start">
                                                <?php
                                                // 2. QUERY DETAIL TUNGGAKAN SEBELUM
                                                // Menggunakan Prepared Statement untuk keamanan
                                                $query_detail_prior = "
                                                     SELECT 
                                                        o.nama_odalan,
                                                        -- Hitung Iuran Wajib untuk Piodalan ini
                                                        CASE
                                                            WHEN s.status = 'aktif' AND s.jenis_user = 'ngarep' THEN o.iuran_ngarep
                                                            WHEN s.status = 'aktif' AND s.jenis_user = 'ngempi' THEN o.iuran_ngempi
                                                            ELSE 0
                                                        END AS iuran_wajib_piodalan,
                                                        -- Total Bayar untuk Piodalan ini
                                                        (
                                                            SELECT COALESCE(SUM(p.jumlah_bayar), 0) 
                                                            FROM pembayaran p 
                                                            WHERE p.id_piodalan = o.id AND p.id_semeton = ?
                                                              AND p.dibatalkan_at IS NULL
                                                        ) AS total_bayar_piodalan
                                                    FROM odalan o
                                                    JOIN semeton s ON s.id = ?
                                                    WHERE o.tanggal < '$tanggal_odalan_raw'
                                                    AND (s.status = 'aktif' AND s.jenis_user IN ('ngarep', 'ngempi'))
                                                    AND EXISTS (
                                                        SELECT 1 FROM hutang h
                                                        WHERE h.id_semeton = s.id AND h.id_odalan = o.id
                                                    )
                                                    ORDER BY o.tanggal ASC;
                                                ";

                                                $stmt_detail = $koneksi->prepare($query_detail_prior);
                                                // Perlu diperhatikan bahwa variabel $id_semeton di atas harus berupa integer (i)
                                                // ID semeton diambil dari $item['id_semeton']
                                                $stmt_detail->bind_param("ii", $item['id_semeton'], $item['id_semeton']);
                                                $stmt_detail->execute();
                                                $result_detail = $stmt_detail->get_result();

                                                while ($detail = $result_detail->fetch_assoc()) {
                                                    $tunggakan_detail = $detail['iuran_wajib_piodalan'] - $detail['total_bayar_piodalan'];
                                                    if ($tunggakan_detail > 0) {
                                                        echo "<li>{$detail['nama_odalan']} : Rp " . number_format($tunggakan_detail, 0, ',', '.') . "</li>";
                                                    }
                                                }
                                                // Pastikan statement ditutup
                                                $stmt_detail->close();
                                                ?>
                                            </ul>
                                        <?php else: ?>
                                            LUNAS
                                        <?php endif; ?>
                                    </td>

                                    <td></td>

                                    <td></td>
                                </tr>
                            <?php
                                if ($tunggakan_sebelum > 0) {
                                    $tunggakan_sub_prior += $tunggakan_sebelum;
                                    $tunggakan_wilayah += $tunggakan_sebelum;
                                }
                            endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr class="footer-total">
                                <td colspan="5" class="text-end"><strong>Total Tunggakan Sebelumnya Sub Wilayah:</strong></td>
                                <td class="text-end text-tunggakan"><strong>Rp <?= number_format($tunggakan_sub_prior, 0, ',', '.') ?></strong></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                <?php endforeach; ?>
                <h6 class="text-end mt-2"><strong>Total Tunggakan Wilayah: Rp <?= number_format($tunggakan_wilayah, 0, ',', '.') ?></strong></h6>
            <?php endforeach; ?>

            <h1 class="text-end mt-4 header-bg">
                **Total Tunggakan Sebelumnya Keseluruhan: Rp <?= number_format($total_tunggakan_keseluruhan_prior, 0, ',', '.') ?>**
            </h1>
            <p class="text-end">Total Anggota Terdaftar: <?= $total_semeton_keseluruhan ?> Orang</p>
        <?php endif; ?>

        <table class="table table-borderless mt-5">
            <tr>
                <td style="width: 50%;">Dibuat oleh,<br>Ketua Panitia Odalan <br><br><br><br>(______________________________)</td>
                <td style="width: 50%;">
                    <div id="tanggal-laporan"></div>
                    Bendahara <br><br><br><br>(______________________________)
                </td>
            </tr>
        </table>
    </div>
</body>
<script>
    // Script untuk mengisi tanggal di halaman laporan (Halaman 2 dst)
    const now = new Date();
    const options = {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    };
    // Menggunakan ID yang berbeda: tanggal-laporan
    document.getElementById('tanggal-laporan').innerText = 'Bedulu, ' + now.toLocaleDateString('id-ID', options);
</script>

</html>