<?php
/**
 * home.php — Dashboard ringkasan KBAP.
 *
 * Hanya membaca data (SELECT). Tidak ada operasi tulis ke database.
 * Angka utama: semeton, odalan terakhir, kas hari ini, keuangan per odalan,
 * pembayaran ngarep vs ngempi, tren 12 bulan, pembayaran terbaru, dan
 * tunggakan per wilayah.
 */
require_once __DIR__ . '/../security.php';
kbap_guard_menu('home'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

/* ------------------------------------------------------------------ */
/* Helper lokal (dibungkus function_exists agar aman bila di-include)  */
/* ------------------------------------------------------------------ */
if (!function_exists('kbap_home_rp')) {
    function kbap_home_rp($angka, int $desimal = 0): string
    {
        return 'Rp ' . number_format((float) $angka, $desimal, ',', '.');
    }
}

if (!function_exists('kbap_home_e')) {
    function kbap_home_e($teks): string
    {
        return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('kbap_home_persen')) {
    function kbap_home_persen($bagian, $total): float
    {
        $total = (float) $total;
        if ($total <= 0) {
            return 0.0;
        }
        $p = ((float) $bagian / $total) * 100;
        $p = max(0.0, min(100.0, $p));
        return round($p, 1);
    }
}

if (!function_exists('kbap_home_angka')) {
    function kbap_home_angka($angka): string
    {
        return number_format((float) $angka, 0, ',', '.');
    }
}

if (!function_exists('kbap_home_persen_teks')) {
    function kbap_home_persen_teks($bagian, $total): string
    {
        return number_format(kbap_home_persen($bagian, $total), 1, ',', '.');
    }
}

if (!function_exists('kbap_home_pct')) {
    function kbap_home_pct($nilai): string
    {
        return number_format((float) $nilai, 1, ',', '.');
    }
}

$hari_ini = date('Y-m-d');

/* Hak akses untuk tautan cepat (agar tidak menampilkan tombol yang 403). */
$role_aktif  = kbap_role();
$menu_boleh  = kbap_allowed_menus($role_aktif);
$boleh_menu  = static function (string $menu) use ($menu_boleh): bool {
    return in_array($menu, $menu_boleh, true);
};

/* ------------------------------------------------------------------ */
/* 1. Data semeton                                                     */
/* ------------------------------------------------------------------ */
$stat_semeton = ['aktif' => 0, 'tidak_aktif' => 0, 'ngarep' => 0, 'ngempi' => 0, 'pemangku' => 0];

if ($res = $koneksi->query(
    "SELECT
        COALESCE(SUM(status = 'aktif'), 0)                                AS aktif,
        COALESCE(SUM(status = 'tidak_aktif'), 0)                          AS tidak_aktif,
        COALESCE(SUM(status = 'aktif' AND jenis_user = 'ngarep'), 0)      AS ngarep,
        COALESCE(SUM(status = 'aktif' AND jenis_user = 'ngempi'), 0)      AS ngempi,
        COALESCE(SUM(status = 'aktif' AND jenis_user = 'pemangku'), 0)    AS pemangku
     FROM semeton"
)) {
    $baris = $res->fetch_assoc() ?: [];
    foreach ($stat_semeton as $kunci => $nilai) {
        $stat_semeton[$kunci] = (int) ($baris[$kunci] ?? 0);
    }
    $res->free();
}

$total_semeton_baru = (int) ($koneksi->query("SELECT COUNT(*) AS c FROM semeton_baru")
    ->fetch_assoc()['c'] ?? 0);

$grand_total_semeton = $stat_semeton['aktif'] + $total_semeton_baru;

/* Anggota aktif yang belum punya wilayah (perlu dilengkapi petugas). */
$tanpa_wilayah = (int) ($koneksi->query(
    "SELECT COUNT(*) AS c FROM semeton WHERE status = 'aktif' AND wilayah IS NULL"
)->fetch_assoc()['c'] ?? 0);

/* ------------------------------------------------------------------ */
/* 2. Odalan terakhir + capaian tagihannya                             */
/* ------------------------------------------------------------------ */
$odalan_terakhir = $koneksi->query(
    "SELECT id, nama_odalan, deskripsi, tanggal, iuran_ngarep, iuran_ngempi
     FROM odalan
     ORDER BY tanggal DESC, id DESC
     LIMIT 1"
)->fetch_assoc();

$id_last_odalan = $odalan_terakhir ? (int) $odalan_terakhir['id'] : 0;

$hutang_odalan = ['jml' => 0, 'tagihan' => 0.0, 'sisa' => 0.0, 'lunas' => 0, 'belum' => 0];
if ($id_last_odalan > 0 && ($stmt = $koneksi->prepare(
    "SELECT COUNT(*)                                   AS jml,
            COALESCE(SUM(jumlah_tagihan_asli), 0)       AS tagihan,
            COALESCE(SUM(jumlah_hutang), 0)             AS sisa,
            COALESCE(SUM(status = 'lunas'), 0)          AS lunas,
            COALESCE(SUM(status = 'belum_lunas'), 0)    AS belum
     FROM hutang
     WHERE id_odalan = ?"
))) {
    $stmt->bind_param('i', $id_last_odalan);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $hutang_odalan = [
        'jml'     => (int) ($r['jml'] ?? 0),
        'tagihan' => (float) ($r['tagihan'] ?? 0),
        'sisa'    => (float) ($r['sisa'] ?? 0),
        'lunas'   => (int) ($r['lunas'] ?? 0),
        'belum'   => (int) ($r['belum'] ?? 0),
    ];
}

$bayar_odalan = ['trx' => 0, 'total' => 0.0];
if ($id_last_odalan > 0 && ($stmt = $koneksi->prepare(
    "SELECT COUNT(*) AS trx, COALESCE(SUM(jumlah_bayar), 0) AS total
     FROM pembayaran
     WHERE id_piodalan = ? AND dibatalkan_at IS NULL"
))) {
    $stmt->bind_param('i', $id_last_odalan);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $bayar_odalan = [
        'trx'   => (int) ($r['trx'] ?? 0),
        'total' => (float) ($r['total'] ?? 0),
    ];
}

$punia_odalan = 0.0;
if ($id_last_odalan > 0 && ($stmt = $koneksi->prepare(
    "SELECT COALESCE(SUM(jumlah_punia), 0) AS total FROM dana_punia WHERE id_piodalan = ?"
))) {
    $stmt->bind_param('i', $id_last_odalan);
    $stmt->execute();
    $punia_odalan = (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}

$persen_odalan = kbap_home_persen($bayar_odalan['total'], $hutang_odalan['tagihan']);

/* Sisa hari menuju odalan terakhir (positif = belum berlangsung). */
$selisih_hari = null;
if ($odalan_terakhir && !empty($odalan_terakhir['tanggal']) && $odalan_terakhir['tanggal'] !== '0000-00-00') {
    $tgl_odalan = new DateTime($odalan_terakhir['tanggal']);
    $tgl_kini   = new DateTime($hari_ini);
    $selisih_hari = (int) $tgl_kini->diff($tgl_odalan)->format('%r%a');
}

/* ------------------------------------------------------------------ */
/* 3. Kas hari ini (urunan, punia, pengeluaran)                        */
/* ------------------------------------------------------------------ */
$kas = [
    'urunan'    => 0.0,
    'ini'       => 0.0,
    'tunggakan' => 0.0,
    'muka'      => 0.0,
    'trx'       => 0,
    'punia'     => 0.0,
    'belanja'   => 0.0,
];

if ($stmt = $koneksi->prepare(
    "SELECT COUNT(*) AS trx,
            COALESCE(SUM(p.jumlah_bayar), 0) AS total,
            COALESCE(SUM(CASE WHEN p.id_piodalan = ? THEN p.jumlah_bayar ELSE 0 END), 0) AS ini,
            COALESCE(SUM(CASE WHEN p.id_piodalan < ? THEN p.jumlah_bayar ELSE 0 END), 0) AS tunggakan,
            COALESCE(SUM(CASE WHEN p.id_piodalan > ? THEN p.jumlah_bayar ELSE 0 END), 0) AS muka
     FROM pembayaran p
     WHERE DATE(p.tanggal_bayar) = ? AND p.dibatalkan_at IS NULL"
)) {
    $stmt->bind_param('iiis', $id_last_odalan, $id_last_odalan, $id_last_odalan, $hari_ini);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $kas['urunan']    = (float) ($r['total'] ?? 0);
    $kas['trx']       = (int) ($r['trx'] ?? 0);
    $kas['ini']       = (float) ($r['ini'] ?? 0);
    $kas['tunggakan'] = (float) ($r['tunggakan'] ?? 0);
    $kas['muka']      = (float) ($r['muka'] ?? 0);
}

if ($stmt = $koneksi->prepare(
    "SELECT COALESCE(SUM(jumlah_punia), 0) AS total FROM dana_punia WHERE DATE(tanggal_punia) = ?"
)) {
    $stmt->bind_param('s', $hari_ini);
    $stmt->execute();
    $kas['punia'] = (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}

if ($stmt = $koneksi->prepare(
    "SELECT COALESCE(SUM(jumlah), 0) AS total FROM pengeluaran WHERE tanggal_pengeluaran = ?"
)) {
    $stmt->bind_param('s', $hari_ini);
    $stmt->execute();
    $kas['belanja'] += (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}

if ($stmt = $koneksi->prepare(
    "SELECT COALESCE(SUM(d.total), 0) AS total
     FROM transaksi t
     JOIN detail_transaksi d ON d.id_transaksi = t.id_transaksi
     WHERE t.tanggal = ?"
)) {
    $stmt->bind_param('s', $hari_ini);
    $stmt->execute();
    $kas['belanja'] += (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}

/* ------------------------------------------------------------------ */
/* 4. Keuangan per odalan (urunan, punia, belanja, saldo)              */
/* ------------------------------------------------------------------ */
$keuangan = [];
$total_keuangan = [
    'tagihan' => 0.0,
    'bayar'   => 0.0,
    'sisa'    => 0.0,
    'punia'   => 0.0,
    'belanja' => 0.0,
    'saldo'   => 0.0,
];

$sql_keuangan = "SELECT o.id,
                        o.nama_odalan,
                        o.tanggal,
                        o.iuran_ngarep,
                        o.iuran_ngempi,
                        COALESCE(h.tagihan, 0) AS tagihan,
                        COALESCE(h.sisa, 0)    AS sisa,
                        COALESCE(h.lunas, 0)   AS lunas,
                        COALESCE(h.belum, 0)   AS belum,
                        COALESCE(h.jml, 0)     AS jml,
                        COALESCE(p.bayar, 0)   AS bayar,
                        COALESCE(p.trx, 0)     AS trx,
                        COALESCE(pu.punia, 0)  AS punia,
                        COALESCE(pl.lama, 0) + COALESCE(pb.baru, 0) AS belanja
                 FROM odalan o
                 LEFT JOIN (
                     SELECT id_odalan,
                            SUM(jumlah_tagihan_asli) AS tagihan,
                            SUM(jumlah_hutang)       AS sisa,
                            SUM(status = 'lunas')    AS lunas,
                            SUM(status = 'belum_lunas') AS belum,
                            COUNT(*)                 AS jml
                     FROM hutang
                     GROUP BY id_odalan
                 ) h ON h.id_odalan = o.id
                 LEFT JOIN (
                     SELECT id_piodalan,
                            SUM(jumlah_bayar) AS bayar,
                            COUNT(*)          AS trx
                     FROM pembayaran
                     WHERE dibatalkan_at IS NULL
                     GROUP BY id_piodalan
                 ) p ON p.id_piodalan = o.id
                 LEFT JOIN (
                     SELECT id_piodalan, SUM(jumlah_punia) AS punia
                     FROM dana_punia
                     GROUP BY id_piodalan
                 ) pu ON pu.id_piodalan = o.id
                 LEFT JOIN (
                     SELECT id_piodalan, SUM(jumlah) AS lama
                     FROM pengeluaran
                     GROUP BY id_piodalan
                 ) pl ON pl.id_piodalan = o.id
                 LEFT JOIN (
                     SELECT t.id_piodalan, SUM(d.total) AS baru
                     FROM transaksi t
                     JOIN detail_transaksi d ON d.id_transaksi = t.id_transaksi
                     GROUP BY t.id_piodalan
                 ) pb ON pb.id_piodalan = o.id
                 ORDER BY o.tanggal ASC, o.id ASC";

if ($res = $koneksi->query($sql_keuangan)) {
    while ($row = $res->fetch_assoc()) {
        $row['tagihan'] = (float) $row['tagihan'];
        $row['sisa']    = (float) $row['sisa'];
        $row['bayar']   = (float) $row['bayar'];
        $row['punia']   = (float) $row['punia'];
        $row['belanja'] = (float) $row['belanja'];
        $row['lunas']   = (int) $row['lunas'];
        $row['belum']   = (int) $row['belum'];
        $row['jml']     = (int) $row['jml'];
        $row['trx']     = (int) $row['trx'];
        $row['saldo']   = $row['bayar'] + $row['punia'] - $row['belanja'];
        $row['persen']  = kbap_home_persen($row['bayar'], $row['tagihan']);

        $total_keuangan['tagihan'] += $row['tagihan'];
        $total_keuangan['bayar']   += $row['bayar'];
        $total_keuangan['sisa']    += $row['sisa'];
        $total_keuangan['punia']   += $row['punia'];
        $total_keuangan['belanja'] += $row['belanja'];
        $total_keuangan['saldo']   += $row['saldo'];

        $keuangan[] = $row;
    }
    $res->free();
}

$persen_total = kbap_home_persen($total_keuangan['bayar'], $total_keuangan['tagihan']);

/* ------------------------------------------------------------------ */
/* 4b. Pembayaran per jenis semeton (ngarep vs ngempi) per odalan      */
/*     Pembayaran yang dibatalkan tidak dihitung.                      */
/* ------------------------------------------------------------------ */
$jenis_bayar = [];
$jenis_total = [
    'ngarep_bayar'   => 0.0, 'ngarep_trx'   => 0, 'ngarep_tagihan'   => 0.0,
    'ngempi_bayar'   => 0.0, 'ngempi_trx'   => 0, 'ngempi_tagihan'   => 0.0,
    'lain_bayar'     => 0.0, 'lain_trx'     => 0, 'lain_tagihan'     => 0.0,
];

foreach ($keuangan as $k) {
    $jenis_bayar[(int) $k['id']] = [
        'ngarep_bayar'   => 0.0, 'ngarep_trx'   => 0, 'ngarep_tagihan'   => 0.0,
        'ngempi_bayar'   => 0.0, 'ngempi_trx'   => 0, 'ngempi_tagihan'   => 0.0,
        'lain_bayar'     => 0.0, 'lain_trx'     => 0, 'lain_tagihan'     => 0.0,
    ];
}

/* Realisasi pembayaran, dikelompokkan menurut jenis_user semeton pembayar. */
if ($res = $koneksi->query(
    "SELECT o.id,
            COALESCE(SUM(CASE WHEN s.jenis_user = 'ngarep' THEN p.jumlah_bayar ELSE 0 END), 0) AS ngarep_bayar,
            COALESCE(SUM(CASE WHEN s.jenis_user = 'ngarep' THEN 1 ELSE 0 END), 0)               AS ngarep_trx,
            COALESCE(SUM(CASE WHEN s.jenis_user = 'ngempi' THEN p.jumlah_bayar ELSE 0 END), 0) AS ngempi_bayar,
            COALESCE(SUM(CASE WHEN s.jenis_user = 'ngempi' THEN 1 ELSE 0 END), 0)               AS ngempi_trx,
            COALESCE(SUM(CASE WHEN s.jenis_user IS NULL OR s.jenis_user NOT IN ('ngarep','ngempi')
                              THEN p.jumlah_bayar ELSE 0 END), 0)                              AS lain_bayar,
            COALESCE(SUM(CASE WHEN s.jenis_user IS NULL OR s.jenis_user NOT IN ('ngarep','ngempi')
                              THEN 1 ELSE 0 END), 0)                                           AS lain_trx
     FROM odalan o
     LEFT JOIN pembayaran p ON p.id_piodalan = o.id AND p.dibatalkan_at IS NULL
     LEFT JOIN semeton s ON s.id = p.id_semeton
     GROUP BY o.id"
)) {
    while ($r = $res->fetch_assoc()) {
        $id_o = (int) $r['id'];
        if (!isset($jenis_bayar[$id_o])) {
            continue;
        }
        $jenis_bayar[$id_o]['ngarep_bayar'] = (float) $r['ngarep_bayar'];
        $jenis_bayar[$id_o]['ngarep_trx']   = (int) $r['ngarep_trx'];
        $jenis_bayar[$id_o]['ngempi_bayar'] = (float) $r['ngempi_bayar'];
        $jenis_bayar[$id_o]['ngempi_trx']   = (int) $r['ngempi_trx'];
        $jenis_bayar[$id_o]['lain_bayar']   = (float) $r['lain_bayar'];
        $jenis_bayar[$id_o]['lain_trx']     = (int) $r['lain_trx'];
    }
    $res->free();
}

/* Tagihan (nominal asli) per jenis, untuk menghitung capaian tiap jenis. */
if ($res = $koneksi->query(
    "SELECT h.id_odalan,
            COALESCE(SUM(CASE WHEN s.jenis_user = 'ngarep' THEN h.jumlah_tagihan_asli ELSE 0 END), 0) AS ngarep_tagihan,
            COALESCE(SUM(CASE WHEN s.jenis_user = 'ngempi' THEN h.jumlah_tagihan_asli ELSE 0 END), 0) AS ngempi_tagihan,
            COALESCE(SUM(CASE WHEN s.jenis_user IS NULL OR s.jenis_user NOT IN ('ngarep','ngempi')
                              THEN h.jumlah_tagihan_asli ELSE 0 END), 0)                                AS lain_tagihan
     FROM hutang h
     JOIN semeton s ON s.id = h.id_semeton
     GROUP BY h.id_odalan"
)) {
    while ($r = $res->fetch_assoc()) {
        $id_o = (int) $r['id_odalan'];
        if (!isset($jenis_bayar[$id_o])) {
            continue;
        }
        $jenis_bayar[$id_o]['ngarep_tagihan'] = (float) $r['ngarep_tagihan'];
        $jenis_bayar[$id_o]['ngempi_tagihan'] = (float) $r['ngempi_tagihan'];
        $jenis_bayar[$id_o]['lain_tagihan']   = (float) $r['lain_tagihan'];
    }
    $res->free();
}

foreach ($jenis_bayar as $j) {
    foreach ($jenis_total as $kunci => $nilai) {
        $jenis_total[$kunci] += $j[$kunci];
    }
}

$jenis_grand_total = $jenis_total['ngarep_bayar'] + $jenis_total['ngempi_bayar'] + $jenis_total['lain_bayar'];
$ada_jenis_lain   = ($jenis_total['lain_bayar'] > 0 || $jenis_total['lain_tagihan'] > 0);

/* ------------------------------------------------------------------ */
/* 5. Tren 12 bulan terakhir (urunan + punia)                          */
/* ------------------------------------------------------------------ */
$nama_bulan_singkat = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
    7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
];

$tren = [];
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime(date('Y-m-01') . ' -' . $i . ' months');
    $tren[date('Y-m', $ts)] = [
        'label'  => $nama_bulan_singkat[(int) date('n', $ts)] . ' ' . date('y', $ts),
        'urunan' => 0.0,
        'punia'  => 0.0,
    ];
}

$awal_tren = date('Y-m-01', strtotime('-11 months'));

if ($stmt = $koneksi->prepare(
    "SELECT DATE_FORMAT(tanggal_bayar, '%Y-%m') AS bln, SUM(jumlah_bayar) AS total
     FROM pembayaran
     WHERE dibatalkan_at IS NULL AND tanggal_bayar >= ?
     GROUP BY bln"
)) {
    $stmt->bind_param('s', $awal_tren);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        if (isset($tren[$r['bln']])) {
            $tren[$r['bln']]['urunan'] = (float) $r['total'];
        }
    }
    $stmt->close();
}

if ($stmt = $koneksi->prepare(
    "SELECT DATE_FORMAT(tanggal_punia, '%Y-%m') AS bln, SUM(jumlah_punia) AS total
     FROM dana_punia
     WHERE tanggal_punia >= ?
     GROUP BY bln"
)) {
    $stmt->bind_param('s', $awal_tren);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        if (isset($tren[$r['bln']])) {
            $tren[$r['bln']]['punia'] = (float) $r['total'];
        }
    }
    $stmt->close();
}

$maks_tren = 0.0;
foreach ($tren as $b) {
    $maks_tren = max($maks_tren, $b['urunan'], $b['punia']);
}

/* ------------------------------------------------------------------ */
/* 6. Pembayaran terbaru                                               */
/* ------------------------------------------------------------------ */
$pembayaran_terbaru = [];
if ($res = $koneksi->query(
    "SELECT p.id_pembayaran, p.tanggal_bayar, p.jumlah_bayar, p.metode_pembayaran,
            s.nama, s.user_id, o.nama_odalan, u.nama_user
     FROM pembayaran p
     JOIN semeton s ON s.id = p.id_semeton
     JOIN odalan  o ON o.id = p.id_piodalan
     LEFT JOIN user u ON u.id_user = p.id_user
     WHERE p.dibatalkan_at IS NULL
     ORDER BY p.tanggal_bayar DESC, p.id_pembayaran DESC
     LIMIT 8"
)) {
    while ($row = $res->fetch_assoc()) {
        $pembayaran_terbaru[] = $row;
    }
    $res->free();
}

/* ------------------------------------------------------------------ */
/* 7. Sebaran & tunggakan per wilayah                                  */
/* ------------------------------------------------------------------ */
$per_wilayah = [];
$total_tanpa_wilayah_sisa = 0.0;

if ($res = $koneksi->query(
    "SELECT w.id_wilayah,
            w.nama_wilayah,
            COALESCE(SUM(CASE WHEN sm.status = 'aktif' THEN 1 ELSE 0 END), 0) AS total,
            COALESCE(SUM(CASE WHEN sm.status = 'aktif' AND sm.jenis_user = 'ngarep' THEN 1 ELSE 0 END), 0) AS ngarep,
            COALESCE(SUM(CASE WHEN sm.status = 'aktif' AND sm.jenis_user = 'ngempi' THEN 1 ELSE 0 END), 0) AS ngempi,
            COALESCE(SUM(h.sisa), 0) AS sisa
     FROM wilayah w
     LEFT JOIN semeton sm ON sm.wilayah = w.id_wilayah
     LEFT JOIN (
         SELECT id_semeton, SUM(jumlah_hutang) AS sisa
         FROM hutang
         GROUP BY id_semeton
     ) h ON h.id_semeton = sm.id
     GROUP BY w.id_wilayah, w.nama_wilayah
     ORDER BY total DESC, w.nama_wilayah ASC"
)) {
    while ($row = $res->fetch_assoc()) {
        $row['total']  = (int) $row['total'];
        $row['ngarep'] = (int) $row['ngarep'];
        $row['ngempi'] = (int) $row['ngempi'];
        $row['sisa']   = (float) $row['sisa'];
        $per_wilayah[] = $row;
    }
    $res->free();
}

if ($stmt = $koneksi->prepare(
    "SELECT COALESCE(SUM(h.jumlah_hutang), 0) AS sisa
     FROM hutang h
     JOIN semeton s ON s.id = h.id_semeton
     WHERE s.wilayah IS NULL"
)) {
    $stmt->execute();
    $total_tanpa_wilayah_sisa = (float) ($stmt->get_result()->fetch_assoc()['sisa'] ?? 0);
    $stmt->close();
}

$tunggakan_wilayah = array_values(array_filter($per_wilayah, static function (array $w): bool {
    return $w['sisa'] > 0;
}));
usort($tunggakan_wilayah, static function (array $a, array $b): int {
    return $b['sisa'] <=> $a['sisa'];
});
$tunggakan_wilayah_top = array_slice($tunggakan_wilayah, 0, 8);
$maks_sisa_wilayah = 0.0;
foreach ($tunggakan_wilayah_top as $w) {
    $maks_sisa_wilayah = max($maks_sisa_wilayah, $w['sisa']);
}
$total_sisa_wilayah = 0.0;
foreach ($per_wilayah as $w) {
    $total_sisa_wilayah += $w['sisa'];
}
$total_sisa_wilayah += $total_tanpa_wilayah_sisa;

$total_aktif_wilayah = 0;
foreach ($per_wilayah as $w) {
    $total_aktif_wilayah += $w['total'];
}

$jam_data = date('d M Y H:i');
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h3 class="mb-1 fw-bold text-primary">Dashboard</h3>
                    <p class="text-muted small mb-0">
                        Ringkasan semeton, urunan, punia, dan pengeluaran Arya Pengalasan.
                    </p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge bg-light text-dark border p-2">
                        <i class="bi bi-calendar3 me-1"></i><?= kbap_home_e(date('d F Y')) ?>
                    </span>
                    <?php if ($boleh_menu('pembayaran')) : ?>
                        <a href="index.php?menu=pembayaran" class="btn btn-primary btn-sm shadow-sm">
                            <i class="bi bi-cash-coin me-1"></i>Catat Pembayaran
                        </a>
                    <?php endif; ?>
                    <?php if ($boleh_menu('semeton')) : ?>
                        <a href="index.php?menu=semeton" class="btn btn-outline-primary btn-sm shadow-sm">
                            <i class="bi bi-person-plus me-1"></i>Data Semeton
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <!-- ================= KPI SEMETON ================= -->
            <div class="row g-3 mb-3">
                <div class="col-xl-3 col-md-6">
                    <div class="small-box text-bg-primary shadow-sm border-0 mb-0">
                        <div class="inner">
                            <h3><?= kbap_home_angka($stat_semeton['aktif']) ?></h3>
                            <p>Semeton Aktif</p>
                            <span class="small opacity-75">
                                <?= kbap_home_angka($stat_semeton['tidak_aktif']) ?> nonaktif ·
                                <?= kbap_home_angka($stat_semeton['pemangku']) ?> pemangku
                            </span>
                        </div>
                        <div class="icon"><i class="bi bi-people-fill"></i></div>
                        <?php if ($boleh_menu('semeton')) : ?>
                            <a href="index.php?menu=semeton" class="small-box-footer">
                                Lihat data semeton <i class="bi bi-arrow-right-circle"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="small-box text-bg-info shadow-sm border-0 mb-0">
                        <div class="inner">
                            <h3><?= kbap_home_angka($stat_semeton['ngarep']) ?></h3>
                            <p>Semeton Ngarep</p>
                            <span class="small opacity-75">
                                <?= kbap_home_persen_teks($stat_semeton['ngarep'], $stat_semeton['aktif']) ?>% dari anggota aktif
                            </span>
                        </div>
                        <div class="icon"><i class="bi bi-house-door-fill"></i></div>
                        <?php if ($boleh_menu('rekap_urunan')) : ?>
                            <a href="index.php?menu=rekap_urunan" class="small-box-footer">
                                Rekap urunan <i class="bi bi-arrow-right-circle"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="small-box text-bg-secondary shadow-sm border-0 mb-0">
                        <div class="inner">
                            <h3><?= kbap_home_angka($stat_semeton['ngempi']) ?></h3>
                            <p>Semeton Ngempi</p>
                            <span class="small opacity-75">
                                <?= kbap_home_persen_teks($stat_semeton['ngempi'], $stat_semeton['aktif']) ?>% dari anggota aktif
                            </span>
                        </div>
                        <div class="icon"><i class="bi bi-house-fill"></i></div>
                        <?php if ($boleh_menu('rekap_bayar_piodalan')) : ?>
                            <a href="index.php?menu=rekap_bayar_piodalan" class="small-box-footer">
                                Rekap pembayaran <i class="bi bi-arrow-right-circle"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="small-box text-bg-warning shadow-sm border-0 mb-0">
                        <div class="inner">
                            <h3><?= kbap_home_angka($total_semeton_baru) ?></h3>
                            <p>Usulan Semeton Baru</p>
                            <span class="small opacity-75">
                                Total data: <?= kbap_home_angka($grand_total_semeton) ?> jiwa
                            </span>
                        </div>
                        <div class="icon"><i class="bi bi-person-plus-fill"></i></div>
                        <?php if ($boleh_menu('semeton_baru')) : ?>
                            <a href="index.php?menu=semeton_baru" class="small-box-footer">
                                Tinjau usulan <i class="bi bi-arrow-right-circle"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ================= ODALAN TERAKHIR + KAS HARI INI ================= -->
            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <div class="card border-0 shadow-sm h-100 mb-0">
                        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h5 class="card-title fw-bold mb-0">
                                <i class="bi bi-calendar-event me-2 text-primary"></i>Odalan Terakhir
                            </h5>
                            <?php if ($odalan_terakhir && $selisih_hari !== null) : ?>
                                <?php if ($selisih_hari > 0) : ?>
                                    <span class="badge bg-info text-dark"><?= kbap_home_angka($selisih_hari) ?> hari lagi</span>
                                <?php elseif ($selisih_hari === 0) : ?>
                                    <span class="badge bg-success">Hari ini</span>
                                <?php else : ?>
                                    <span class="badge bg-light text-dark border">
                                        <?= kbap_home_angka(abs($selisih_hari)) ?> hari lalu
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php if (!$odalan_terakhir) : ?>
                            <div class="card-body">
                                <div class="alert alert-info border-0 mb-0">
                                    Belum ada data odalan. Tambahkan odalan lewat menu
                                    <strong>Piodalan</strong> untuk mulai menagih urunan.
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                    <div>
                                        <h4 class="fw-bold mb-1"><?= kbap_home_e($odalan_terakhir['nama_odalan']) ?></h4>
                                        <div class="text-muted small">
                                            <i class="bi bi-calendar3 me-1"></i>
                                            <?= kbap_home_e(date('d F Y', strtotime($odalan_terakhir['tanggal']))) ?>
                                            <span class="mx-2">·</span>
                                            Iuran Ngarep <strong><?= kbap_home_rp($odalan_terakhir['iuran_ngarep']) ?></strong>
                                            <span class="mx-1">/</span>
                                            Ngempi <strong><?= kbap_home_rp($odalan_terakhir['iuran_ngempi']) ?></strong>
                                        </div>
                                    </div>
                                    <?php if ($boleh_menu('rekap_hari')) : ?>
                                        <a href="index.php?menu=rekap_hari&amp;id_odalan=<?= (int) $id_last_odalan ?>"
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-receipt me-1"></i>Kasir odalan ini
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex justify-content-between align-items-center small mt-4 mb-1">
                                    <span class="text-muted">Capaian urunan</span>
                                    <span class="fw-bold"><?= kbap_home_pct($persen_odalan) ?>%</span>
                                </div>
                                <div class="progress mb-4" style="height: 10px;" role="progressbar"
                                     aria-label="Capaian urunan odalan terakhir"
                                     aria-valuenow="<?= $persen_odalan ?>"
                                     aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar bg-success"
                                         style="width: <?= $persen_odalan ?>%"></div>
                                </div>

                                <div class="row g-3 text-center">
                                    <div class="col-6 col-lg-3">
                                        <div class="border rounded-3 py-2 h-100 bg-light">
                                            <div class="text-muted small">Tagihan</div>
                                            <div class="fw-bold"><?= kbap_home_rp($hutang_odalan['tagihan']) ?></div>
                                            <div class="text-muted" style="font-size: .72rem;">
                                                <?= kbap_home_angka($hutang_odalan['jml']) ?> tagihan
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-lg-3">
                                        <div class="border rounded-3 py-2 h-100 bg-light">
                                            <div class="text-muted small">Terbayar</div>
                                            <div class="fw-bold text-success"><?= kbap_home_rp($bayar_odalan['total']) ?></div>
                                            <div class="text-muted" style="font-size: .72rem;">
                                                <?= kbap_home_angka($bayar_odalan['trx']) ?> transaksi
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-lg-3">
                                        <div class="border rounded-3 py-2 h-100 bg-light">
                                            <div class="text-muted small">Sisa tagihan</div>
                                            <div class="fw-bold text-danger"><?= kbap_home_rp($hutang_odalan['sisa']) ?></div>
                                            <div class="text-muted" style="font-size: .72rem;">
                                                <?= kbap_home_angka($hutang_odalan['belum']) ?> belum lunas
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-lg-3">
                                        <div class="border rounded-3 py-2 h-100 bg-light">
                                            <div class="text-muted small">Dana punia</div>
                                            <div class="fw-bold text-primary"><?= kbap_home_rp($punia_odalan) ?></div>
                                            <div class="text-muted" style="font-size: .72rem;">
                                                <?= kbap_home_angka($hutang_odalan['lunas']) ?> tagihan lunas
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card border-0 shadow-sm h-100 mb-0">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title fw-bold mb-0">
                                <i class="bi bi-cash-stack me-2 text-success"></i>Kas Hari Ini
                            </h5>
                            <span class="badge bg-light text-dark border">
                                <?= kbap_home_angka($kas['trx']) ?> transaksi
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="text-muted small">Urunan masuk</div>
                                    <div class="h5 fw-bold text-success mb-0"><?= kbap_home_rp($kas['urunan']) ?></div>
                                </div>
                                <div class="col-6 text-end">
                                    <div class="text-muted small">Dana punia</div>
                                    <div class="h5 fw-bold text-primary mb-0"><?= kbap_home_rp($kas['punia']) ?></div>
                                </div>
                            </div>

                            <?php if ($kas['urunan'] > 0) : ?>
                                <div class="d-flex flex-wrap gap-1 mt-2">
                                    <span class="badge bg-success" title="Pembayaran tagihan odalan terbaru">
                                        Odalan ini <?= kbap_home_rp($kas['ini']) ?>
                                    </span>
                                    <span class="badge bg-danger" title="Melunasi tagihan odalan yang lebih lama">
                                        Tunggakan <?= kbap_home_rp($kas['tunggakan']) ?>
                                    </span>
                                    <span class="badge bg-info text-dark" title="Membayar tagihan odalan yang belum berjalan">
                                        Di muka <?= kbap_home_rp($kas['muka']) ?>
                                    </span>
                                </div>
                            <?php else : ?>
                                <div class="alert alert-light border small mt-2 mb-0 py-2">
                                    Belum ada pembayaran urunan tercatat hari ini.
                                </div>
                            <?php endif; ?>

                            <hr class="my-3">

                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">Pengeluaran hari ini</span>
                                <strong><?= kbap_home_rp($kas['belanja']) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">Piutang semeton (semua odalan)</span>
                                <strong class="text-danger"><?= kbap_home_rp($total_keuangan['sisa']) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between small">
                                <span class="text-muted">Total punia terkumpul</span>
                                <strong class="text-primary"><?= kbap_home_rp($total_keuangan['punia']) ?></strong>
                            </div>

                            <?php if ($tanpa_wilayah > 0) : ?>
                                <div class="alert alert-warning border-0 small mt-3 mb-0 py-2">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    <strong><?= kbap_home_angka($tanpa_wilayah) ?></strong> anggota aktif belum punya wilayah.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= KEUANGAN PER ODALAN + TREN ================= -->
            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <div class="card border-0 shadow-sm h-100 mb-0">
                        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h5 class="card-title fw-bold mb-0">
                                <i class="bi bi-wallet2 me-2 text-primary"></i>Keuangan per Odalan
                            </h5>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-success" title="Urunan terbayar dibanding total tagihan">
                                    Terkumpul <?= kbap_home_pct($persen_total) ?>%
                                </span>
                                <?php if ($boleh_menu('laporan_laba_rugi')) : ?>
                                    <a href="index.php?menu=laporan_laba_rugi" class="btn btn-sm btn-outline-secondary">
                                        Laporan lengkap <i class="bi bi-arrow-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light text-uppercase small">
                                        <tr>
                                            <th class="ps-3">Odalan</th>
                                            <th class="text-end">Tagihan</th>
                                            <th class="text-end">Terbayar</th>
                                            <th class="text-end">Sisa</th>
                                            <th class="text-end">Punia</th>
                                            <th class="text-end">Belanja</th>
                                            <th class="text-end pe-3">Saldo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!$keuangan) : ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-4">
                                                    Belum ada data odalan.
                                                </td>
                                            </tr>
                                        <?php else : ?>
                                            <?php foreach ($keuangan as $k) : ?>
                                                <tr>
                                                    <td class="ps-3">
                                                        <div class="fw-bold"><?= kbap_home_e($k['nama_odalan']) ?></div>
                                                        <div class="text-muted" style="font-size: .72rem;">
                                                            <?= kbap_home_e(date('d M Y', strtotime($k['tanggal']))) ?>
                                                        </div>
                                                        <div class="progress mt-1" style="height: 5px; width: 90px;"
                                                             title="Capaian urunan <?= kbap_home_pct($k['persen']) ?>%">
                                                            <div class="progress-bar bg-success" style="width: <?= $k['persen'] ?>%"></div>
                                                        </div>
                                                    </td>
                                                    <td class="text-end"><?= kbap_home_rp($k['tagihan']) ?></td>
                                                    <td class="text-end text-success">
                                                        <?= kbap_home_rp($k['bayar']) ?>
                                                        <div class="text-muted" style="font-size: .7rem;">
                                                            <?= kbap_home_pct($k['persen']) ?>% · <?= kbap_home_angka($k['trx']) ?> trx
                                                        </div>
                                                    </td>
                                                    <td class="text-end <?= $k['sisa'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                                        <?= kbap_home_rp($k['sisa']) ?>
                                                        <div class="text-muted" style="font-size: .7rem;">
                                                            <?= kbap_home_angka($k['belum']) ?> belum lunas
                                                        </div>
                                                    </td>
                                                    <td class="text-end text-primary"><?= kbap_home_rp($k['punia']) ?></td>
                                                    <td class="text-end text-danger"><?= kbap_home_rp($k['belanja']) ?></td>
                                                    <td class="text-end pe-3 fw-bold <?= $k['saldo'] < 0 ? 'text-danger' : 'text-dark' ?>">
                                                        <?= kbap_home_rp($k['saldo']) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <?php if ($keuangan) : ?>
                                        <tfoot class="table-light fw-bold">
                                            <tr>
                                                <td class="ps-3">TOTAL</td>
                                                <td class="text-end"><?= kbap_home_rp($total_keuangan['tagihan']) ?></td>
                                                <td class="text-end text-success"><?= kbap_home_rp($total_keuangan['bayar']) ?></td>
                                                <td class="text-end text-danger"><?= kbap_home_rp($total_keuangan['sisa']) ?></td>
                                                <td class="text-end text-primary"><?= kbap_home_rp($total_keuangan['punia']) ?></td>
                                                <td class="text-end text-danger"><?= kbap_home_rp($total_keuangan['belanja']) ?></td>
                                                <td class="text-end pe-3"><?= kbap_home_rp($total_keuangan['saldo']) ?></td>
                                            </tr>
                                        </tfoot>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card border-0 shadow-sm h-100 mb-0">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title fw-bold mb-0">
                                <i class="bi bi-graph-up me-2 text-success"></i>Tren 12 Bulan
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="kbap-chart">
                                <?php foreach ($tren as $b) :
                                    $t_urunan = $maks_tren > 0 ? max(2, (int) round($b['urunan'] / $maks_tren * 100)) : 2;
                                    $t_punia  = $maks_tren > 0 ? max(2, (int) round($b['punia'] / $maks_tren * 100)) : 2;
                                    ?>
                                    <div class="kbap-chart-col"
                                         title="<?= kbap_home_e($b['label']) ?>: urunan <?= kbap_home_rp($b['urunan']) ?>, punia <?= kbap_home_rp($b['punia']) ?>">
                                        <div class="kbap-chart-bars">
                                            <span class="kbap-bar bg-success" style="height: <?= $t_urunan ?>px"></span>
                                            <span class="kbap-bar bg-primary" style="height: <?= $t_punia ?>px"></span>
                                        </div>
                                        <span class="kbap-chart-label"><?= kbap_home_e($b['label']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="d-flex justify-content-center gap-3 mt-3 small text-muted">
                                <span><span class="kbap-dot bg-success"></span> Urunan</span>
                                <span><span class="kbap-dot bg-primary"></span> Punia</span>
                            </div>
                            <div class="text-muted small mt-2 text-center">
                                Puncak bulan: <strong><?= kbap_home_rp($maks_tren) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= PEMBAYARAN NGAREP VS NGEMPI ================= -->
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="card border-0 shadow-sm mb-0">
                        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h5 class="card-title fw-bold mb-0">
                                <i class="bi bi-people-fill me-2 text-info"></i>Pembayaran per Jenis Semeton
                            </h5>
                            <div class="d-flex flex-wrap gap-1">
                                <span class="badge bg-info text-dark" title="Total pembayaran dari anggota ngarep">
                                    Ngarep <?= kbap_home_rp($jenis_total['ngarep_bayar']) ?>
                                </span>
                                <span class="badge bg-secondary" title="Total keseluruhan pembayaran dari anggota ngempi">
                                    Ngempi <?= kbap_home_rp($jenis_total['ngempi_bayar']) ?>
                                </span>
                                <span class="badge bg-light text-dark border">
                                    Total <?= kbap_home_rp($jenis_grand_total) ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light text-uppercase small">
                                        <tr>
                                            <th class="ps-3">Odalan</th>
                                            <th class="text-end">Terbayar Ngarep</th>
                                            <th class="text-end">Terbayar Ngempi</th>
                                            <th class="text-end">Total Terbayar</th>
                                            <th class="text-end pe-3" style="min-width: 150px;">Porsi Ngarep</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!$keuangan) : ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-4">
                                                    Belum ada data odalan.
                                                </td>
                                            </tr>
                                        <?php else : ?>
                                            <?php foreach ($keuangan as $k) :
                                                $j = $jenis_bayar[(int) $k['id']] ?? [];
                                                $j_ngarep   = (float) ($j['ngarep_bayar'] ?? 0);
                                                $j_ngempi   = (float) ($j['ngempi_bayar'] ?? 0);
                                                $j_lain     = (float) ($j['lain_bayar'] ?? 0);
                                                $j_total    = $j_ngarep + $j_ngempi + $j_lain;
                                                $j_pct_ngarep = kbap_home_persen($j_ngarep, (float) ($j['ngarep_tagihan'] ?? 0));
                                                $j_pct_ngempi = kbap_home_persen($j_ngempi, (float) ($j['ngempi_tagihan'] ?? 0));
                                                $j_porsi_ngarep = kbap_home_persen($j_ngarep, $j_total);
                                                ?>
                                                <tr>
                                                    <td class="ps-3">
                                                        <div class="fw-bold"><?= kbap_home_e($k['nama_odalan']) ?></div>
                                                        <div class="text-muted" style="font-size: .72rem;">
                                                            <?= kbap_home_e(date('d M Y', strtotime($k['tanggal']))) ?>
                                                        </div>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="fw-bold text-success"><?= kbap_home_rp($j_ngarep) ?></div>
                                                        <div class="text-muted" style="font-size: .7rem;">
                                                            <?= kbap_home_pct($j_pct_ngarep) ?>%
                                                            dari <?= kbap_home_rp($j['ngarep_tagihan'] ?? 0) ?>
                                                            · <?= kbap_home_angka($j['ngarep_trx'] ?? 0) ?> trx
                                                        </div>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="fw-bold text-secondary"><?= kbap_home_rp($j_ngempi) ?></div>
                                                        <div class="text-muted" style="font-size: .7rem;">
                                                            <?= kbap_home_pct($j_pct_ngempi) ?>%
                                                            dari <?= kbap_home_rp($j['ngempi_tagihan'] ?? 0) ?>
                                                            · <?= kbap_home_angka($j['ngempi_trx'] ?? 0) ?> trx
                                                        </div>
                                                    </td>
                                                    <td class="text-end fw-bold"><?= kbap_home_rp($j_total) ?></td>
                                                    <td class="text-end pe-3">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <div class="progress flex-grow-1" style="height: 7px;">
                                                                <div class="progress-bar bg-info" style="width: <?= $j_porsi_ngarep ?>%"></div>
                                                            </div>
                                                            <span class="text-muted small text-nowrap">
                                                                <?= kbap_home_pct($j_porsi_ngarep) ?>%
                                                            </span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <?php if ($keuangan) : ?>
                                        <tfoot class="table-light fw-bold">
                                            <tr>
                                                <td class="ps-3">TOTAL</td>
                                                <td class="text-end text-success"><?= kbap_home_rp($jenis_total['ngarep_bayar']) ?></td>
                                                <td class="text-end text-secondary"><?= kbap_home_rp($jenis_total['ngempi_bayar']) ?></td>
                                                <td class="text-end"><?= kbap_home_rp($jenis_grand_total) ?></td>
                                                <td class="text-end pe-3">
                                                    <?= kbap_home_pct(kbap_home_persen($jenis_total['ngarep_bayar'], $jenis_grand_total)) ?>%
                                                </td>
                                            </tr>
                                        </tfoot>
                                    <?php endif; ?>
                                </table>
                            </div>
                            <?php if ($ada_jenis_lain) : ?>
                                <div class="px-3 py-2 text-muted border-top" style="font-size: .72rem;">
                                    Termasuk pembayaran dari jenis semeton lain (pemangku):
                                    <strong><?= kbap_home_rp($jenis_total['lain_bayar']) ?></strong>
                                    (<?= kbap_home_angka($jenis_total['lain_trx']) ?> transaksi).
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= PEMBAYARAN TERBARU + TUNGGAKAN WILAYAH ================= -->
            <div class="row g-3 mb-3">
                <div class="col-xl-7">
                    <div class="card border-0 shadow-sm h-100 mb-0">
                        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h5 class="card-title fw-bold mb-0">
                                <i class="bi bi-clock-history me-2 text-primary"></i>Pembayaran Terbaru
                            </h5>
                            <?php if ($boleh_menu('rekap_bayar')) : ?>
                                <a href="index.php?menu=rekap_bayar" class="btn btn-sm btn-outline-secondary">
                                    Semua transaksi <i class="bi bi-arrow-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead class="table-light text-uppercase small">
                                        <tr>
                                            <th class="ps-3">Tanggal</th>
                                            <th>Semeton</th>
                                            <th>Odalan</th>
                                            <th class="text-end">Nominal</th>
                                            <th class="text-center pe-3">Metode</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!$pembayaran_terbaru) : ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-4">
                                                    Belum ada pembayaran tercatat.
                                                </td>
                                            </tr>
                                        <?php else : ?>
                                            <?php foreach ($pembayaran_terbaru as $p) : ?>
                                                <tr>
                                                    <td class="ps-3 text-nowrap">
                                                        <?= kbap_home_e(date('d/m/y H:i', strtotime($p['tanggal_bayar']))) ?>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold text-truncate" style="max-width: 190px;"
                                                             title="<?= kbap_home_e($p['nama']) ?>">
                                                            <?= kbap_home_e($p['nama']) ?>
                                                        </div>
                                                        <div class="text-muted" style="font-size: .72rem;">
                                                            <?= kbap_home_e($p['user_id']) ?>
                                                            <?php if (!empty($p['nama_user'])) : ?>
                                                                · <?= kbap_home_e($p['nama_user']) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="small"><?= kbap_home_e($p['nama_odalan']) ?></td>
                                                    <td class="text-end fw-bold text-success text-nowrap">
                                                        <?= kbap_home_rp($p['jumlah_bayar']) ?>
                                                    </td>
                                                    <td class="text-center pe-3">
                                                        <?php
                                                        $warna_metode = [
                                                            'tunai'    => 'bg-secondary',
                                                            'transfer' => 'bg-info text-dark',
                                                            'QRIS'     => 'bg-primary',
                                                        ];
                                                        ?>
                                                        <span class="badge <?= $warna_metode[$p['metode_pembayaran']] ?? 'bg-light text-dark border' ?>">
                                                            <?= kbap_home_e($p['metode_pembayaran']) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-5">
                    <div class="card border-0 shadow-sm h-100 mb-0">
                        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h5 class="card-title fw-bold mb-0">
                                <i class="bi bi-exclamation-octagon me-2 text-danger"></i>Tunggakan per Wilayah
                            </h5>
                            <?php if ($boleh_menu('rekap_tunggakan')) : ?>
                                <a href="index.php?menu=rekap_tunggakan" class="btn btn-sm btn-outline-secondary">
                                    Detail <i class="bi bi-arrow-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (!$tunggakan_wilayah_top) : ?>
                                <div class="alert alert-success border-0 mb-0">
                                    <i class="bi bi-check-circle me-1"></i>
                                    Tidak ada tunggakan. Semua tagihan sudah lunas.
                                </div>
                            <?php else : ?>
                                <?php foreach ($tunggakan_wilayah_top as $w) :
                                    $lebar = $maks_sisa_wilayah > 0 ? max(2, round($w['sisa'] / $maks_sisa_wilayah * 100)) : 2;
                                    ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-baseline small">
                                            <span class="fw-semibold text-truncate" style="max-width: 60%;"
                                                  title="<?= kbap_home_e($w['nama_wilayah']) ?>">
                                                <?= kbap_home_e($w['nama_wilayah']) ?>
                                            </span>
                                            <span class="text-danger fw-bold text-nowrap">
                                                <?= kbap_home_rp($w['sisa']) ?>
                                            </span>
                                        </div>
                                        <div class="progress mt-1" style="height: 7px;">
                                            <div class="progress-bar bg-danger" style="width: <?= $lebar ?>%"></div>
                                        </div>
                                        <div class="text-muted" style="font-size: .72rem;">
                                            <?= kbap_home_persen_teks($w['sisa'], $total_sisa_wilayah) ?>% dari total tunggakan
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <div class="d-flex justify-content-between border-top pt-2 mt-1 small">
                                    <span class="text-muted">Total tunggakan semua wilayah</span>
                                    <strong class="text-danger"><?= kbap_home_rp($total_sisa_wilayah) ?></strong>
                                </div>
                                <?php if ($total_tanpa_wilayah_sisa > 0) : ?>
                                    <div class="text-muted mt-1" style="font-size: .72rem;">
                                        Termasuk <?= kbap_home_rp($total_tanpa_wilayah_sisa) ?> dari anggota tanpa wilayah.
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= SEBARAN PER WILAYAH ================= -->
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="card border-0 shadow-sm mb-0">
                        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h5 class="card-title fw-bold mb-0">
                                <i class="bi bi-geo-alt-fill me-2 text-warning"></i>Sebaran Semeton per Wilayah
                            </h5>
                            <div class="d-flex flex-wrap gap-1">
                                <span class="badge bg-light text-dark border">
                                    <?= kbap_home_angka(count($per_wilayah)) ?> wilayah
                                </span>
                                <span class="badge bg-light text-dark border">
                                    <?= kbap_home_angka($total_aktif_wilayah) ?> anggota aktif
                                </span>
                                <?php if ($boleh_menu('wilayah')) : ?>
                                    <a href="index.php?menu=wilayah" class="btn btn-sm btn-outline-secondary ms-1">
                                        Kelola wilayah
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light text-uppercase small">
                                        <tr>
                                            <th class="ps-3">Wilayah</th>
                                            <th class="text-center">Ngarep</th>
                                            <th class="text-center">Ngempi</th>
                                            <th class="text-center">Total</th>
                                            <th style="min-width: 160px;">Porsi anggota</th>
                                            <th class="text-end pe-3">Tunggakan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!$per_wilayah) : ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted py-4">
                                                    Belum ada data wilayah.
                                                </td>
                                            </tr>
                                        <?php else : ?>
                                            <?php foreach ($per_wilayah as $w) :
                                                $porsi = kbap_home_persen($w['total'], $total_aktif_wilayah);
                                                ?>
                                                <tr>
                                                    <td class="ps-3 fw-semibold"><?= kbap_home_e($w['nama_wilayah']) ?></td>
                                                    <td class="text-center"><?= kbap_home_angka($w['ngarep']) ?></td>
                                                    <td class="text-center"><?= kbap_home_angka($w['ngempi']) ?></td>
                                                    <td class="text-center fw-bold text-primary"><?= kbap_home_angka($w['total']) ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <div class="progress flex-grow-1" style="height: 7px;">
                                                                <div class="progress-bar bg-primary" style="width: <?= $porsi ?>%"></div>
                                                            </div>
                                                            <span class="text-muted small text-nowrap"><?= kbap_home_pct($porsi) ?>%</span>
                                                        </div>
                                                    </td>
                                                    <td class="text-end pe-3 <?= $w['sisa'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                                        <?= kbap_home_rp($w['sisa']) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <?php if ($per_wilayah) : ?>
                                        <tfoot class="table-light fw-bold">
                                            <tr>
                                                <td class="ps-3">TOTAL</td>
                                                <td class="text-center"><?= kbap_home_angka($stat_semeton['ngarep']) ?></td>
                                                <td class="text-center"><?= kbap_home_angka($stat_semeton['ngempi']) ?></td>
                                                <td class="text-center text-primary"><?= kbap_home_angka($total_aktif_wilayah) ?></td>
                                                <td></td>
                                                <td class="text-end pe-3 text-danger"><?= kbap_home_rp($total_sisa_wilayah) ?></td>
                                            </tr>
                                        </tfoot>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p class="text-muted small text-end mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Data per <?= kbap_home_e($jam_data) ?> · pembayaran yang dibatalkan tidak dihitung.
            </p>
        </div>
    </div>
</main>

<style>
    /* Gaya khusus dashboard (prefix kbap- agar tidak bentrok) */
    .kbap-chart {
        display: flex;
        align-items: flex-end;
        gap: .3rem;
        height: 132px;
    }

    .kbap-chart-col {
        flex: 1 1 0;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        height: 100%;
        min-width: 0;
    }

    .kbap-chart-bars {
        display: flex;
        align-items: flex-end;
        justify-content: center;
        gap: 2px;
        height: 100px;
    }

    .kbap-bar {
        display: block;
        width: 8px;
        border-radius: 3px 3px 0 0;
        transition: height .3s ease;
    }

    .kbap-chart-label {
        margin-top: 4px;
        font-size: .6rem;
        text-align: center;
        color: #8a94a6;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .kbap-dot {
        display: inline-block;
        width: 9px;
        height: 9px;
        border-radius: 50%;
        margin-right: 3px;
    }

    @media (max-width: 575.98px) {
        .kbap-bar {
            width: 5px;
        }

        .kbap-chart-label {
            font-size: .5rem;
        }
    }
</style>
