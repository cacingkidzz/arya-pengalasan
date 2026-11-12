<?php
/**
 * laporan_rekap_odalan.php  (baru 2026-11-11)
 *
 * Laporan cetak siap print: rekap pembayaran urunan odalan + pembayaran
 * tunggakan odalan sebelumnya yang dibayar pada periode kas odalan tersebut.
 *
 * Dasar pemisahan sama dengan admin/konten/rekap_hari.php (tanpa ubah skema):
 *   pembayaran.id_piodalan = odalan yang TAGIHANNYA dilunasi.
 *     id_piodalan = odalan acara -> "Odalan ini"
 *     id_piodalan < odalan acara -> "Tunggakan"
 *     id_piodalan > odalan acara -> "Bayar di muka"
 * Klasifikasi memakai tanggal_bayar (periode kas), bukan selisih hari.
 *
 * Halaman ini standalone (punya <!DOCTYPE html> + CSS cetak sendiri),
 * dibuka langsung / lewat target="_blank" — TIDAK lewat router konten.php.
 */

require_once __DIR__ . '/../security.php';
kbap_guard_menu('laporan_rekap_odalan'); // laporan keuangan: admin + bendahara

require_once __DIR__ . '/../koneksi.php';

$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$rupiah = static function ($n) {
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
};
$tanggal_teks = static function ($tgl): string {
    if (empty($tgl)) {
        return '-';
    }
    $ts = strtotime((string) $tgl);
    return $ts ? date('d-m-Y', $ts) : '-';
};
$tanggal_indo_panjang = static function ($tgl): string {
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];
    $ts = strtotime((string) $tgl);
    if (!$ts) {
        return '-';
    }
    return date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
};

// ---------------------------------------------------------------------
// 1. Daftar odalan (terbaru di atas) + validasi pilihan
// ---------------------------------------------------------------------
$odalan_list = [];
if ($res = $koneksi->query('SELECT id, nama_odalan, tanggal FROM odalan ORDER BY tanggal DESC, id DESC')) {
    while ($r = $res->fetch_assoc()) {
        $odalan_list[(int) $r['id']] = $r;
    }
}

$f_odalan = (isset($_GET['id_odalan']) && is_scalar($_GET['id_odalan'])) ? (int) $_GET['id_odalan'] : 0;
if ($f_odalan <= 0 || !isset($odalan_list[$f_odalan])) {
    $f_odalan = $odalan_list ? array_key_first($odalan_list) : 0;
}

$nama_acara      = $f_odalan > 0 ? (string) $odalan_list[$f_odalan]['nama_odalan'] : '—';
$tanggal_acara   = $f_odalan > 0 ? (string) $odalan_list[$f_odalan]['tanggal'] : date('Y-m-d');

// ---------------------------------------------------------------------
// 2. Periode kas. Default: tanggal odalan −30 hari s.d. +30 hari
//    (panitia biasanya menagih sebelum acara dan menghitung uang
//    sesudahnya). Semua nilai bisa diubah lewat GET.
// ---------------------------------------------------------------------
$acara_dt = DateTime::createFromFormat('Y-m-d', $tanggal_acara);
if (!($acara_dt instanceof DateTime) || $acara_dt->format('Y-m-d') !== $tanggal_acara) {
    $acara_dt = new DateTime('today');
}
$default_dari   = (clone $acara_dt)->modify('-30 days')->format('Y-m-d');
$default_sampai = (clone $acara_dt)->modify('+30 days')->format('Y-m-d');

$baca_tanggal = static function ($nilai, string $default): string {
    if (!is_scalar($nilai)) {
        return $default;
    }
    $teks = trim((string) $nilai);
    $dt   = DateTime::createFromFormat('Y-m-d', $teks);
    return ($dt instanceof DateTime && $dt->format('Y-m-d') === $teks) ? $teks : $default;
};
$f_dari   = $baca_tanggal($_GET['dari'] ?? null, $default_dari);
$f_sampai = $baca_tanggal($_GET['sampai'] ?? null, $default_sampai);
if ($f_dari > $f_sampai) {
    [$f_dari, $f_sampai] = [$f_sampai, $f_dari];
}

$f_detail = (isset($_GET['detail']) && is_scalar($_GET['detail']) && (string) $_GET['detail'] === '1');

// ---------------------------------------------------------------------
// 3. Filter petugas (opsional) — hanya petugas yang mencatat pada periode
// ---------------------------------------------------------------------
$f_petugas = (isset($_GET['petugas']) && is_scalar($_GET['petugas'])) ? (int) $_GET['petugas'] : 0;

$petugas_list = [];
if ($stmt = $koneksi->prepare('SELECT DISTINCT p.id_user, u.username
                               FROM pembayaran p
                               LEFT JOIN user u ON u.id_user = p.id_user
                               WHERE DATE(p.tanggal_bayar) BETWEEN ? AND ? AND p.dibatalkan_at IS NULL
                               ORDER BY u.username ASC')) {
    $stmt->bind_param('ss', $f_dari, $f_sampai);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $petugas_list[(int) $r['id_user']] = $r['username'] !== null
            ? (string) $r['username']
            : ('User #' . (int) $r['id_user']);
    }
    $stmt->close();
}
if ($f_petugas > 0 && !array_key_exists($f_petugas, $petugas_list)) {
    $f_petugas = 0;
}

// ---------------------------------------------------------------------
// 4. WHERE dinamis (selalu prepared)
// ---------------------------------------------------------------------
$where = ['DATE(p.tanggal_bayar) BETWEEN ? AND ?', 'p.dibatalkan_at IS NULL'];
$tipe  = 'ss';
$nilai = [$f_dari, $f_sampai];
if ($f_petugas > 0) {
    $where[] = 'p.id_user = ?';
    $tipe   .= 'i';
    $nilai[] = $f_petugas;
}
$where_sql = ' WHERE ' . implode(' AND ', $where);

/**
 * Hitung jumlah transaksi + nominal untuk satu kondisi tambahan.
 * @return array{0:int,1:float}
 */
$hitung = static function (string $kondisi, array $param, string $tipe_tambahan) use ($koneksi, $where_sql, $tipe, $nilai): array {
    $sql = 'SELECT COUNT(*) AS jml, COALESCE(SUM(p.jumlah_bayar), 0) AS total
            FROM pembayaran p' . $where_sql;
    if ($kondisi !== '') {
        $sql .= ' AND ' . $kondisi;
    }
    $tipe_gabung  = $tipe . $tipe_tambahan;
    $nilai_gabung = array_merge($nilai, $param);

    if ($stmt = $koneksi->prepare($sql)) {
        $stmt->bind_param($tipe_gabung, ...$nilai_gabung);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($baris) {
            return [(int) $baris['jml'], (float) $baris['total']];
        }
    }
    return [0, 0.0];
};

[$jml_masuk, $total_masuk] = $hitung('', [], '');

$jml_ini = $jml_tunggakan = $jml_muka = 0;
$total_ini = $total_tunggakan = $total_muka = 0.0;
if ($f_odalan > 0) {
    [$jml_ini, $total_ini]             = $hitung('p.id_piodalan = ?', [$f_odalan], 'i');
    [$jml_tunggakan, $total_tunggakan] = $hitung('p.id_piodalan < ?', [$f_odalan], 'i');
    [$jml_muka, $total_muka]           = $hitung('p.id_piodalan > ?', [$f_odalan], 'i');
}

$porsi = static function (float $bagian, float $total): string {
    return $total > 0 ? number_format(($bagian / $total) * 100, 1, ',', '.') . '%' : '0,0%';
};

// ---------------------------------------------------------------------
// 5. Rekap per odalan yang dibayar
// ---------------------------------------------------------------------
$rekap_odalan = [];
$sql = 'SELECT p.id_piodalan, o.nama_odalan, o.tanggal,
               COUNT(*) AS jml, COALESCE(SUM(p.jumlah_bayar), 0) AS total
        FROM pembayaran p
        JOIN odalan o ON o.id = p.id_piodalan'
        . $where_sql . '
        GROUP BY p.id_piodalan, o.nama_odalan, o.tanggal
        ORDER BY o.tanggal ASC, p.id_piodalan ASC';
if ($stmt = $koneksi->prepare($sql)) {
    $stmt->bind_param($tipe, ...$nilai);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $rekap_odalan[] = $r;
    }
    $stmt->close();
}

// ---------------------------------------------------------------------
// 6. Rekap per metode pembayaran
// ---------------------------------------------------------------------
$rekap_metode = [];
$sql = 'SELECT p.metode_pembayaran, COUNT(*) AS jml, COALESCE(SUM(p.jumlah_bayar), 0) AS total
        FROM pembayaran p' . $where_sql . '
        GROUP BY p.metode_pembayaran
        ORDER BY total DESC, p.metode_pembayaran ASC';
if ($stmt = $koneksi->prepare($sql)) {
    $stmt->bind_param($tipe, ...$nilai);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $rekap_metode[] = $r;
    }
    $stmt->close();
}

// ---------------------------------------------------------------------
// 7. Daftar pembayaran tunggakan (id_piodalan < odalan acara)
// ---------------------------------------------------------------------
$baris_tunggakan = [];
if ($f_odalan > 0) {
    $sql = 'SELECT p.id_pembayaran, p.id_piodalan, p.jumlah_bayar, p.metode_pembayaran,
                   p.tanggal_bayar, o.nama_odalan, o.tanggal AS tanggal_odalan,
                   s.user_id, s.nama, w.nama_wilayah, sw.nama_sub, u.username
            FROM pembayaran p
            JOIN semeton s ON s.id = p.id_semeton
            JOIN odalan o ON o.id = p.id_piodalan
            LEFT JOIN wilayah w ON w.id_wilayah = s.wilayah
            LEFT JOIN sub_wilayah sw ON sw.id_sub = s.sub_wilayah
            LEFT JOIN user u ON u.id_user = p.id_user'
            . $where_sql . ' AND p.id_piodalan < ?
            ORDER BY o.tanggal ASC, w.nama_wilayah ASC, sw.nama_sub ASC, s.nama ASC, p.id_pembayaran ASC';
    if ($stmt = $koneksi->prepare($sql)) {
        $tipe_gabung  = $tipe . 'i';
        $nilai_gabung = array_merge($nilai, [$f_odalan]);
        $stmt->bind_param($tipe_gabung, ...$nilai_gabung);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $baris_tunggakan[] = $r;
        }
        $stmt->close();
    }
}

// ---------------------------------------------------------------------
// 8. Rincian seluruh transaksi (opsional, ?detail=1)
// ---------------------------------------------------------------------
$baris_detail = [];
if ($f_detail) {
    $sql = 'SELECT p.id_pembayaran, p.id_piodalan, p.jumlah_bayar, p.metode_pembayaran,
                   p.tanggal_bayar, o.nama_odalan, o.tanggal AS tanggal_odalan,
                   s.user_id, s.nama, w.nama_wilayah, sw.nama_sub, u.username
            FROM pembayaran p
            JOIN semeton s ON s.id = p.id_semeton
            JOIN odalan o ON o.id = p.id_piodalan
            LEFT JOIN wilayah w ON w.id_wilayah = s.wilayah
            LEFT JOIN sub_wilayah sw ON sw.id_sub = s.sub_wilayah
            LEFT JOIN user u ON u.id_user = p.id_user'
            . $where_sql . '
            ORDER BY p.tanggal_bayar ASC, p.id_pembayaran ASC';
    if ($stmt = $koneksi->prepare($sql)) {
        $stmt->bind_param($tipe, ...$nilai);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $baris_detail[] = $r;
        }
        $stmt->close();
    }
}

// Label jenis pembayaran berdasarkan odalan acara
$jenis_pembayaran = static function (int $id_od) use ($f_odalan): array {
    if ($f_odalan > 0 && $id_od === $f_odalan) {
        return ['Odalan ini', 'jenis-ini'];
    }
    if ($f_odalan > 0 && $id_od < $f_odalan) {
        return ['Tunggakan', 'jenis-tunggakan'];
    }
    if ($f_odalan > 0 && $id_od > $f_odalan) {
        return ['Bayar di muka', 'jenis-muka'];
    }
    return ['-', 'jenis-netral'];
};

$judul_periode = ($f_dari === $f_sampai)
    ? $tanggal_teks($f_dari)
    : $tanggal_teks($f_dari) . ' s.d. ' . $tanggal_teks($f_sampai);

$nama_petugas = $f_petugas > 0 ? $petugas_list[$f_petugas] : 'Semua petugas';
$dicetak_oleh = $_SESSION['username'] ?? ($_SESSION['nama_user'] ?? ('User #' . ($_SESSION['id_user'] ?? '-')));
$waktu_cetak  = date('d-m-Y H:i');

// URL "Kembali" ke halaman kasir dengan filter yang sama
$url_kembali = '../index.php?' . http_build_query([
    'menu'      => 'rekap_hari',
    'id_odalan' => $f_odalan,
    'dari'      => $f_dari,
    'sampai'    => $f_sampai,
    'petugas'   => $f_petugas > 0 ? $f_petugas : null,
]);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Rekap Urunan &amp; Tunggakan — <?= $esc($nama_acara) ?></title>
    <style>
        :root {
            --hitam: #111;
            --garis: #333;
            --redup: #555;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            background: #eef1f5;
            color: var(--hitam);
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            font-size: 10pt;
        }

        a {
            color: #0d6efd;
        }

        /* ---------------- Toolbar (tidak ikut tercetak) ---------------- */
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 20;
            background: #fff;
            border-bottom: 1px solid #cfd6e0;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .08);
            padding: 10px 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
            justify-content: space-between;
        }

        .toolbar form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }

        .toolbar label {
            display: flex;
            flex-direction: column;
            gap: 3px;
            font-size: 8.5pt;
            color: var(--redup);
            font-weight: 600;
        }

        .toolbar select,
        .toolbar input[type="date"] {
            font: inherit;
            font-size: 9pt;
            padding: 4px 6px;
            border: 1px solid #b9c2cf;
            border-radius: 4px;
            background: #fff;
            min-width: 130px;
        }

        .toolbar .cek {
            flex-direction: row;
            align-items: center;
            gap: 6px;
            padding-bottom: 5px;
            font-weight: 500;
            color: var(--hitam);
        }

        .btn {
            font: inherit;
            font-size: 9pt;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 5px;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #0d6efd;
            color: #fff;
        }

        .btn-dark {
            background: #212529;
            color: #fff;
        }

        .btn-outline {
            background: #fff;
            color: #212529;
            border-color: #9aa4b2;
        }

        .toolbar-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .toolbar-info {
            width: 100%;
            font-size: 8pt;
            color: var(--redup);
            margin: 0;
        }

        /* ---------------- Kertas A4 ---------------- */
        .page {
            background: #fff;
            width: 210mm;
            min-height: 297mm;
            margin: 16px auto;
            padding: 12mm 12mm 16mm;
            box-shadow: 0 0 8px rgba(0, 0, 0, .15);
        }

        .kop {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .kop .logo {
            width: 22mm;
            height: auto;
            flex: 0 0 auto;
        }

        .kop-teks {
            flex: 1 1 auto;
            text-align: center;
        }

        .kop-teks .instansi {
            font-size: 11pt;
            font-weight: 700;
            letter-spacing: .4px;
        }

        .kop-teks .judul {
            font-size: 13pt;
            font-weight: 700;
            margin-top: 2px;
        }

        .kop-teks .subjudul {
            font-size: 10pt;
            font-weight: 600;
            color: var(--redup);
        }

        .garis {
            border-top: 2.5px solid var(--garis);
            border-bottom: 1px solid var(--garis);
            height: 3px;
            margin: 8px 0 12px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        table.info {
            margin-bottom: 12px;
            font-size: 9pt;
        }

        table.info td {
            padding: 1px 4px;
            vertical-align: top;
        }

        table.info td.label {
            color: var(--redup);
            white-space: nowrap;
            width: 1%;
        }

        table.info td.titik {
            width: 1%;
            padding-right: 10px;
        }

        table.info td.isi {
            font-weight: 600;
        }

        /* ---------------- Kotak ringkasan ---------------- */
        table.ringkasan {
            border: 1px solid var(--garis);
            margin-bottom: 14px;
        }

        table.ringkasan td.box {
            width: 25%;
            padding: 8px 10px;
            border-right: 1px solid var(--garis);
            vertical-align: top;
        }

        table.ringkasan td.box:last-child {
            border-right: 0;
        }

        table.ringkasan .lbl {
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: var(--redup);
            font-weight: 700;
        }

        table.ringkasan .nilai {
            font-size: 13pt;
            font-weight: 700;
            margin-top: 2px;
        }

        table.ringkasan .ket {
            font-size: 8pt;
            color: var(--redup);
        }

        table.ringkasan .box.total .nilai {
            color: #0b5ed7;
        }

        table.ringkasan .box.ini .nilai {
            color: #157347;
        }

        table.ringkasan .box.tunggakan .nilai {
            color: #b02a37;
        }

        table.ringkasan .box.muka .nilai {
            color: #0aa2c0;
        }

        /* ---------------- Tabel data ---------------- */
        h2.section {
            font-size: 10pt;
            font-weight: 700;
            margin: 16px 0 6px;
            padding: 4px 8px;
            background: #e9edf3;
            border-left: 4px solid #34495e;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        table.data {
            font-size: 8.5pt;
        }

        table.data th,
        table.data td {
            border: 1px solid var(--garis);
            padding: 3px 5px;
            vertical-align: top;
        }

        table.data thead th {
            background: #dfe5ec;
            font-size: 8pt;
            text-align: left;
            vertical-align: middle;
        }

        table.data tfoot th,
        table.data tfoot td {
            background: #eef1f5;
            font-weight: 700;
        }

        table.data .num {
            text-align: right;
            white-space: nowrap;
        }

        table.data .ctr {
            text-align: center;
            white-space: nowrap;
        }

        table.data tbody tr:nth-child(even) {
            background: #fafbfc;
        }

        .jenis {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 8px;
            font-size: 7.5pt;
            font-weight: 700;
            white-space: nowrap;
        }

        .jenis-ini {
            background: #d1e7dd;
            color: #0f5132;
        }

        .jenis-tunggakan {
            background: #f8d7da;
            color: #842029;
        }

        .jenis-muka {
            background: #cff4fc;
            color: #055160;
        }

        .jenis-netral {
            background: #e2e3e5;
            color: #41464b;
        }

        .kosong {
            border: 1px dashed #9aa4b2;
            background: #f8f9fa;
            color: var(--redup);
            padding: 8px 10px;
            font-size: 9pt;
            font-style: italic;
            margin: 0;
        }

        .catatan {
            margin-top: 14px;
            font-size: 8pt;
            color: var(--redup);
            line-height: 1.45;
        }

        .catatan strong {
            color: var(--hitam);
        }

        /* ---------------- Tanda tangan ---------------- */
        table.ttd {
            margin-top: 22px;
            font-size: 9pt;
            page-break-inside: avoid;
        }

        table.ttd td {
            width: 50%;
            text-align: center;
            vertical-align: top;
            padding: 4px 10px;
            line-height: 1.5;
        }

        table.ttd .ruang {
            height: 22mm;
        }

        table.ttd .nama {
            font-weight: 700;
            text-decoration: underline;
        }

        /* ---------------- Cetak ---------------- */
        @page {
            size: A4 portrait;
            margin: 10mm 10mm 12mm;
        }

        @media print {
            html,
            body {
                background: #fff;
            }

            .no-print {
                display: none !important;
            }

            .page {
                width: auto;
                min-height: 0;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            body {
                font-size: 8.5pt;
            }

            table.data {
                font-size: 7.5pt;
            }

            table.data th,
            table.data td {
                padding: 2px 3px;
            }

            table.data thead {
                display: table-header-group;
            }

            table.data tr {
                page-break-inside: avoid;
            }

            h2.section {
                page-break-after: avoid;
            }

            .jenis {
                border: 0;
                padding: 0 3px;
            }

            a {
                color: var(--hitam);
                text-decoration: none;
            }
        }

        @media screen and (max-width: 900px) {
            .page {
                width: 100%;
                padding: 10mm;
            }

            .toolbar {
                position: static;
            }
        }
    </style>
</head>

<body>

    <!-- ================= TOOLBAR (tidak tercetak) ================= -->
    <div class="toolbar no-print">
        <form method="GET" action="laporan_rekap_odalan.php">
            <label>Odalan acara
                <select name="id_odalan">
                    <?php foreach ($odalan_list as $id_o => $o) : ?>
                        <option value="<?= (int) $id_o ?>" <?= $f_odalan === (int) $id_o ? 'selected' : '' ?>>
                            <?= $esc($o['nama_odalan']) ?> (<?= $esc($tanggal_teks($o['tanggal'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Dari
                <input type="date" name="dari" value="<?= $esc($f_dari) ?>">
            </label>
            <label>Sampai
                <input type="date" name="sampai" value="<?= $esc($f_sampai) ?>">
            </label>
            <label>Petugas
                <select name="petugas">
                    <option value="0" <?= $f_petugas === 0 ? 'selected' : '' ?>>Semua</option>
                    <?php foreach ($petugas_list as $id_p => $nama_p) : ?>
                        <option value="<?= (int) $id_p ?>" <?= $f_petugas === (int) $id_p ? 'selected' : '' ?>>
                            <?= $esc($nama_p) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="cek">
                <input type="checkbox" name="detail" value="1" <?= $f_detail ? 'checked' : '' ?>>
                Sertakan rincian transaksi
            </label>
            <button type="submit" class="btn btn-primary">Terapkan</button>
        </form>
        <div class="toolbar-actions">
            <button type="button" class="btn btn-dark" onclick="window.print()">Cetak / Simpan PDF</button>
            <a class="btn btn-outline" href="<?= $esc($url_kembali) ?>">Kembali ke Kasir</a>
        </div>
        <p class="toolbar-info">
            Pada dialog cetak pilih <strong>A4</strong>, skala <strong>100%</strong>, dan aktifkan
            <strong>Background graphics</strong> agar warna badge ikut tercetak. Untuk arsip digital pilih
            <em>Save as PDF</em>.
        </p>
    </div>

    <!-- ================= KERTAS LAPORAN ================= -->
    <div class="page">

        <div class="kop">
            <img src="../dist/assets/img/bg-logo.png" alt="Logo Arya Pengalasan" class="logo">
            <div class="kop-teks">
                <div class="instansi">PARUMAN ARYA PENGALASAN PUSAT BEDULU</div>
                <div class="judul">LAPORAN REKAP PEMBAYARAN URUNAN ODALAN</div>
                <div class="subjudul">Beserta Pembayaran Tunggakan Odalan Sebelumnya</div>
            </div>
        </div>
        <div class="garis"></div>

        <table class="info">
            <tr>
                <td class="label">Odalan acara</td>
                <td class="titik">:</td>
                <td class="isi"><?= $esc($nama_acara) ?></td>
                <td class="label">Periode kas</td>
                <td class="titik">:</td>
                <td class="isi"><?= $esc($judul_periode) ?></td>
            </tr>
            <tr>
                <td class="label">Tanggal odalan</td>
                <td class="titik">:</td>
                <td class="isi"><?= $esc($tanggal_indo_panjang($tanggal_acara)) ?></td>
                <td class="label">Petugas</td>
                <td class="titik">:</td>
                <td class="isi"><?= $esc($nama_petugas) ?></td>
            </tr>
            <tr>
                <td class="label">Dicetak</td>
                <td class="titik">:</td>
                <td class="isi"><?= $esc($waktu_cetak) ?></td>
                <td class="label">Oleh</td>
                <td class="titik">:</td>
                <td class="isi"><?= $esc($dicetak_oleh) ?></td>
            </tr>
        </table>

        <!-- ---------- Ringkasan ---------- -->
        <table class="ringkasan">
            <tr>
                <td class="box total">
                    <div class="lbl">Total Masuk</div>
                    <div class="nilai"><?= $rupiah($total_masuk) ?></div>
                    <div class="ket"><?= number_format($jml_masuk, 0, ',', '.') ?> transaksi</div>
                </td>
                <td class="box ini">
                    <div class="lbl">Odalan Ini</div>
                    <div class="nilai"><?= $rupiah($total_ini) ?></div>
                    <div class="ket"><?= number_format($jml_ini, 0, ',', '.') ?> trx &middot; <?= $porsi($total_ini, $total_masuk) ?></div>
                </td>
                <td class="box tunggakan">
                    <div class="lbl">Tunggakan</div>
                    <div class="nilai"><?= $rupiah($total_tunggakan) ?></div>
                    <div class="ket"><?= number_format($jml_tunggakan, 0, ',', '.') ?> trx &middot; <?= $porsi($total_tunggakan, $total_masuk) ?></div>
                </td>
                <td class="box muka">
                    <div class="lbl">Bayar di Muka</div>
                    <div class="nilai"><?= $rupiah($total_muka) ?></div>
                    <div class="ket"><?= number_format($jml_muka, 0, ',', '.') ?> trx &middot; <?= $porsi($total_muka, $total_masuk) ?></div>
                </td>
            </tr>
        </table>

        <!-- ---------- A. Rekap per odalan yang dibayar ---------- -->
        <h2 class="section">A. Rekap Pembayaran per Odalan yang Dibayar</h2>
        <?php if (empty($rekap_odalan)) : ?>
            <p class="kosong">Tidak ada pembayaran tercatat pada periode ini.</p>
        <?php else : ?>
            <table class="data">
                <thead>
                    <tr>
                        <th class="ctr" style="width:6%;">No</th>
                        <th>Odalan yang Dibayar</th>
                        <th class="ctr" style="width:13%;">Tgl Odalan</th>
                        <th class="ctr" style="width:14%;">Jenis</th>
                        <th class="num" style="width:10%;">Transaksi</th>
                        <th class="num" style="width:17%;">Nominal (Rp)</th>
                        <th class="num" style="width:10%;">Porsi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($rekap_odalan as $ro) :
                        [$jenis_teks, $jenis_kelas] = $jenis_pembayaran((int) $ro['id_piodalan']);
                        ?>
                        <tr>
                            <td class="ctr"><?= $no++ ?></td>
                            <td><?= $esc($ro['nama_odalan']) ?></td>
                            <td class="ctr"><?= $esc($tanggal_teks($ro['tanggal'])) ?></td>
                            <td class="ctr"><span class="jenis <?= $esc($jenis_kelas) ?>"><?= $esc($jenis_teks) ?></span></td>
                            <td class="num"><?= number_format((int) $ro['jml'], 0, ',', '.') ?></td>
                            <td class="num"><?= number_format((float) $ro['total'], 0, ',', '.') ?></td>
                            <td class="num"><?= $porsi((float) $ro['total'], $total_masuk) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="num">TOTAL MASUK</td>
                        <td class="num"><?= number_format($jml_masuk, 0, ',', '.') ?></td>
                        <td class="num"><?= number_format($total_masuk, 0, ',', '.') ?></td>
                        <td class="num">100,0%</td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>

        <!-- ---------- B. Rekap per metode ---------- -->
        <h2 class="section">B. Rekap per Metode Pembayaran</h2>
        <?php if (empty($rekap_metode)) : ?>
            <p class="kosong">Tidak ada pembayaran tercatat pada periode ini.</p>
        <?php else : ?>
            <table class="data">
                <thead>
                    <tr>
                        <th class="ctr" style="width:6%;">No</th>
                        <th>Metode Pembayaran</th>
                        <th class="num" style="width:14%;">Transaksi</th>
                        <th class="num" style="width:22%;">Nominal (Rp)</th>
                        <th class="num" style="width:12%;">Porsi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($rekap_metode as $rm) : ?>
                        <tr>
                            <td class="ctr"><?= $no++ ?></td>
                            <td><?= $esc(ucfirst(strtolower((string) $rm['metode_pembayaran']))) ?></td>
                            <td class="num"><?= number_format((int) $rm['jml'], 0, ',', '.') ?></td>
                            <td class="num"><?= number_format((float) $rm['total'], 0, ',', '.') ?></td>
                            <td class="num"><?= $porsi((float) $rm['total'], $total_masuk) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="num">TOTAL</td>
                        <td class="num"><?= number_format($jml_masuk, 0, ',', '.') ?></td>
                        <td class="num"><?= number_format($total_masuk, 0, ',', '.') ?></td>
                        <td class="num">100,0%</td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>

        <!-- ---------- C. Daftar tunggakan ---------- -->
        <h2 class="section">C. Daftar Pembayaran Tunggakan Odalan Sebelumnya</h2>
        <?php if (empty($baris_tunggakan)) : ?>
            <p class="kosong">Tidak ada pembayaran tunggakan odalan sebelumnya pada periode ini.</p>
        <?php else : ?>
            <table class="data">
                <thead>
                    <tr>
                        <th class="ctr" style="width:4%;">No</th>
                        <th style="width:8%;">User ID</th>
                        <th style="width:15%;">Nama Semeton</th>
                        <th style="width:13%;">Wilayah / Sub</th>
                        <th style="width:17%;">Odalan Dibayar</th>
                        <th class="ctr" style="width:9%;">Tgl Odalan</th>
                        <th class="ctr" style="width:9%;">Tgl Bayar</th>
                        <th class="num" style="width:10%;">Nominal (Rp)</th>
                        <th class="ctr" style="width:6%;">Metode</th>
                        <th style="width:9%;">Petugas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($baris_tunggakan as $bt) : ?>
                        <tr>
                            <td class="ctr"><?= $no++ ?></td>
                            <td><?= $esc($bt['user_id']) ?></td>
                            <td><?= $esc($bt['nama']) ?></td>
                            <td>
                                <?= $esc($bt['nama_wilayah'] !== null ? $bt['nama_wilayah'] : '-') ?>
                                <?= $bt['nama_sub'] !== null ? ' / ' . $esc($bt['nama_sub']) : '' ?>
                            </td>
                            <td><?= $esc($bt['nama_odalan']) ?></td>
                            <td class="ctr"><?= $esc($tanggal_teks($bt['tanggal_odalan'])) ?></td>
                            <td class="ctr"><?= $esc($tanggal_teks($bt['tanggal_bayar'])) ?></td>
                            <td class="num"><?= number_format((float) $bt['jumlah_bayar'], 0, ',', '.') ?></td>
                            <td class="ctr"><?= $esc(ucfirst(strtolower((string) $bt['metode_pembayaran']))) ?></td>
                            <td><?= $esc($bt['username'] !== null ? $bt['username'] : '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7" class="num">TOTAL TUNGGAKAN</td>
                        <td class="num"><?= number_format($total_tunggakan, 0, ',', '.') ?></td>
                        <td colspan="2" class="num"><?= number_format($jml_tunggakan, 0, ',', '.') ?> trx</td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>

        <!-- ---------- D. Rincian transaksi (opsional) ---------- -->
        <?php if ($f_detail) : ?>
            <h2 class="section">D. Rincian Seluruh Transaksi pada Periode Ini</h2>
            <?php if (empty($baris_detail)) : ?>
                <p class="kosong">Tidak ada transaksi pada periode ini.</p>
            <?php else : ?>
                <table class="data">
                    <thead>
                        <tr>
                            <th class="ctr" style="width:4%;">No</th>
                            <th class="ctr" style="width:9%;">Tgl Bayar</th>
                            <th style="width:8%;">User ID</th>
                            <th style="width:15%;">Nama Semeton</th>
                            <th style="width:12%;">Wilayah / Sub</th>
                            <th style="width:18%;">Odalan Dibayar</th>
                            <th class="ctr" style="width:9%;">Jenis</th>
                            <th class="num" style="width:10%;">Nominal (Rp)</th>
                            <th class="ctr" style="width:7%;">Metode</th>
                            <th style="width:8%;">Petugas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($baris_detail as $bd) :
                            [$jenis_teks, $jenis_kelas] = $jenis_pembayaran((int) $bd['id_piodalan']);
                            ?>
                            <tr>
                                <td class="ctr"><?= $no++ ?></td>
                                <td class="ctr"><?= $esc($tanggal_teks($bd['tanggal_bayar'])) ?></td>
                                <td><?= $esc($bd['user_id']) ?></td>
                                <td><?= $esc($bd['nama']) ?></td>
                                <td>
                                    <?= $esc($bd['nama_wilayah'] !== null ? $bd['nama_wilayah'] : '-') ?>
                                    <?= $bd['nama_sub'] !== null ? ' / ' . $esc($bd['nama_sub']) : '' ?>
                                </td>
                                <td><?= $esc($bd['nama_odalan']) ?></td>
                                <td class="ctr"><span class="jenis <?= $esc($jenis_kelas) ?>"><?= $esc($jenis_teks) ?></span></td>
                                <td class="num"><?= number_format((float) $bd['jumlah_bayar'], 0, ',', '.') ?></td>
                                <td class="ctr"><?= $esc(ucfirst(strtolower((string) $bd['metode_pembayaran']))) ?></td>
                                <td><?= $esc($bd['username'] !== null ? $bd['username'] : '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7" class="num">TOTAL</td>
                            <td class="num"><?= number_format($total_masuk, 0, ',', '.') ?></td>
                            <td colspan="2" class="num"><?= number_format($jml_masuk, 0, ',', '.') ?> trx</td>
                        </tr>
                    </tfoot>
                </table>
            <?php endif; ?>
        <?php endif; ?>

        <div class="catatan">
            <strong>Keterangan:</strong>
            <strong>Odalan ini</strong> = pembayaran untuk tagihan odalan yang dipilih.
            <strong>Tunggakan</strong> = pembayaran tagihan odalan yang lebih lama, dibayar pada periode kas ini.
            <strong>Bayar di muka</strong> = pembayaran tagihan odalan yang lebih baru.
            Klasifikasi memakai <em>tanggal bayar</em>, bukan tanggal odalan, dan hanya menghitung
            pembayaran yang sah (tidak dibatalkan).
            <br>
            <strong>Total Masuk</strong> = Odalan ini + Tunggakan + Bayar di muka.
            Periode kas pada laporan ini adalah <?= $esc($judul_periode) ?>.
        </div>

        <table class="ttd">
            <tr>
                <td>
                    Mengetahui,<br>
                    Ketua Panitia Odalan
                    <div class="ruang"></div>
                    (________________________)
                </td>
                <td>
                    Bedulu, <?= $esc($tanggal_indo_panjang(date('Y-m-d'))) ?><br>
                    Bendahara
                    <div class="ruang"></div>
                    (________________________)
                </td>
            </tr>
        </table>

    </div>
</body>

</html>
