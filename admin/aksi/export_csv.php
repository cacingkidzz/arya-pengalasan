<?php
/**
 * Ekspor CSV sederhana (tanpa library).
 *
 * Pemakaian:
 *   aksi/export_csv.php?jenis=semeton&csrf_token=...
 *   aksi/export_csv.php?jenis=tunggakan
 *   aksi/export_csv.php?jenis=urunan
 *   aksi/export_csv.php?jenis=punia&id_piodalan=3
 *   aksi/export_csv.php?jenis=rekap_hari&id_odalan=3&dari=2026-04-29&sampai=2026-04-29
 *   aksi/export_csv.php?jenis=laba_rugi
 *
 * File CSV memakai pemisah titik-koma (;) + BOM UTF-8 agar rapi saat
 * dibuka di Excel versi Indonesia. Hanya membaca data.
 */
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bendahara', 'bagian_data'], false);

require_once __DIR__ . '/../koneksi.php';

$jenis = isset($_GET['jenis']) ? (string) $_GET['jenis'] : '';
$role  = kbap_role();

// Laporan keuangan hanya untuk admin & bendahara.
if ($jenis !== 'semeton' && !in_array($role, ['admin', 'bendahara'], true)) {
    kbap_deny(403, 'Role Anda tidak memiliki izin mengunduh laporan ini.');
}

$nama_file = 'kbap_' . preg_replace('/[^a-z_]/', '', $jenis) . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nama_file . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'wb');
if (!$out) {
    exit;
}
// BOM agar Excel membaca UTF-8 dengan benar
fwrite($out, "\xEF\xBB\xBF");

$tulis = static function (array $kolom) use ($out): void {
    fputcsv($out, $kolom, ';', '"', '\\');
};

switch ($jenis) {

    // ---------------------------------------------------------------
    case 'semeton':
        $tulis([
            'ID', 'User ID', 'Nama', 'NIK', 'Jenis Kelamin', 'No. KK', 'Tempat Lahir',
            'Tanggal Lahir', 'Agama', 'Golongan Darah', 'Status Perkawinan', 'Pendidikan',
            'Pekerjaan', 'Nama Ayah', 'Nama Ibu', 'Catatan', 'Email', 'No HP', 'Alamat',
            'Wilayah', 'Sub Wilayah', 'Jenis User', 'Jabatan', 'Status',
        ]);

        // Filter opsional (dipakai tombol Unduh CSV di halaman semeton)
        $where = [];
        $tipe  = '';
        $nilai = [];
        $q = (isset($_GET['q']) && is_string($_GET['q'])) ? trim($_GET['q']) : '';
        $f_wilayah = (isset($_GET['wilayah']) && is_scalar($_GET['wilayah'])) ? (int) $_GET['wilayah'] : 0;
        $f_status = (isset($_GET['status']) && is_string($_GET['status'])) ? $_GET['status'] : '';
        if (!in_array($f_status, ['aktif', 'tidak_aktif'], true)) {
            $f_status = '';
        }
        $f_jk = (isset($_GET['jk']) && is_string($_GET['jk'])) ? $_GET['jk'] : '';
        if (!in_array($f_jk, ['L', 'P'], true)) {
            $f_jk = '';
        }
        if ($q !== '') {
            $where[] = '(s.nama LIKE ? OR s.user_id LIKE ? OR s.nik LIKE ? OR s.no_hp LIKE ? OR s.no_kk LIKE ?)';
            $like    = '%' . $q . '%';
            $tipe   .= 'sssss';
            array_push($nilai, $like, $like, $like, $like, $like);
        }
        if ($f_wilayah > 0) {
            $where[]  = 's.wilayah = ?';
            $tipe    .= 'i';
            $nilai[]  = $f_wilayah;
        }
        if ($f_status !== '') {
            $where[]  = 's.status = ?';
            $tipe    .= 's';
            $nilai[]  = $f_status;
        }
        if ($f_jk !== '') {
            $where[]  = 's.jenis_kelamin = ?';
            $tipe    .= 's';
            $nilai[]  = $f_jk;
        }
        $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT s.id, s.user_id, s.nama, s.nik, s.jenis_kelamin, s.no_kk,
                       s.tempat_lahir, s.tanggal_lahir, s.agama, s.golongan_darah,
                       s.status_perkawinan, s.pendidikan, s.pekerjaan, s.nama_ayah,
                       s.nama_ibu, s.catatan, s.email, s.no_hp, s.alamat,
                       w.nama_wilayah, sw.nama_sub, s.jenis_user, s.jabatan, s.status
                FROM semeton s
                LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
                LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
                $where_sql
                ORDER BY s.user_id ASC";

        if ($stmt = $koneksi->prepare($sql)) {
            if ($tipe !== '') {
                $stmt->bind_param($tipe, ...$nilai);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $tulis([
                    $r['id'], $r['user_id'], $r['nama'], $r['nik'], $r['jenis_kelamin'], $r['no_kk'],
                    $r['tempat_lahir'], $r['tanggal_lahir'], $r['agama'], $r['golongan_darah'],
                    $r['status_perkawinan'], $r['pendidikan'], $r['pekerjaan'], $r['nama_ayah'],
                    $r['nama_ibu'], $r['catatan'], $r['email'], $r['no_hp'], $r['alamat'],
                    $r['nama_wilayah'], $r['nama_sub'], $r['jenis_user'], $r['jabatan'], $r['status'],
                ]);
            }
            $stmt->close();
        }
        break;

    // ---------------------------------------------------------------
    case 'tunggakan':
        $tulis([
            'Nama', 'User ID', 'Wilayah', 'Sub Wilayah', 'Odalan Dibayar', 'Tanggal Odalan',
            'Tanggal Bayar', 'Telat (hari)', 'Nominal', 'Metode', 'Odalan Aktif Saat Bayar',
        ]);
        $sql = "SELECT * FROM (
                    SELECT s.user_id, s.nama, w.nama_wilayah, sw.nama_sub,
                           o.nama_odalan, o.tanggal AS tanggal_odalan,
                           p.id_piodalan, p.tanggal_bayar, p.jumlah_bayar, p.metode_pembayaran,
                           (SELECT o3.nama_odalan FROM odalan o3
                             WHERE o3.tanggal <= DATE(p.tanggal_bayar)
                             ORDER BY o3.tanggal DESC, o3.id DESC LIMIT 1) AS nama_aktif,
                           (SELECT o2.id FROM odalan o2
                             WHERE o2.tanggal <= DATE(p.tanggal_bayar)
                             ORDER BY o2.tanggal DESC, o2.id DESC LIMIT 1) AS id_aktif
                    FROM pembayaran p
                    JOIN odalan o  ON o.id = p.id_piodalan
                    JOIN semeton s ON s.id = p.id_semeton
                    LEFT JOIN wilayah w      ON s.wilayah = w.id_wilayah
                    LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
                    WHERE p.dibatalkan_at IS NULL
                ) x
                WHERE x.id_aktif IS NOT NULL AND x.id_aktif > x.id_piodalan
                ORDER BY x.tanggal_bayar DESC, x.nama ASC";
        if ($res = $koneksi->query($sql)) {
            while ($r = $res->fetch_assoc()) {
                $telat = (int) ((strtotime((string) $r['tanggal_bayar']) - strtotime((string) $r['tanggal_odalan'])) / 86400);
                $tulis([
                    $r['nama'], $r['user_id'], $r['nama_wilayah'], $r['nama_sub'],
                    $r['nama_odalan'], $r['tanggal_odalan'], $r['tanggal_bayar'],
                    $telat, (float) $r['jumlah_bayar'], $r['metode_pembayaran'], $r['nama_aktif'],
                ]);
            }
            $res->free();
        }
        break;

    // ---------------------------------------------------------------
    case 'urunan':
        $list_odalan = [];
        if ($res = $koneksi->query("SELECT id, nama_odalan FROM odalan ORDER BY tanggal ASC, id ASC")) {
            while ($r = $res->fetch_assoc()) {
                $list_odalan[(int) $r['id']] = $r['nama_odalan'];
            }
            $res->free();
        }

        $tulis(array_merge(
            ['User ID', 'Nama', 'Wilayah', 'Sub Wilayah', 'Jenis User'],
            array_values($list_odalan),
            ['Total Sisa Tagihan']
        ));

        // Filter opsional (dipakai tombol Unduh CSV di halaman rekap_urunan)
        $where = [];
        $tipe  = '';
        $nilai = [];
        $q = (isset($_GET['q']) && is_string($_GET['q'])) ? trim($_GET['q']) : '';
        $f_wilayah = (isset($_GET['wilayah']) && is_scalar($_GET['wilayah'])) ? (int) $_GET['wilayah'] : 0;
        $f_status = (isset($_GET['status']) && is_string($_GET['status'])) ? $_GET['status'] : '';
        if (!in_array($f_status, ['Ada', 'Lunas'], true)) {
            $f_status = '';
        }
        if ($q !== '') {
            $where[] = '(s.nama LIKE ? OR s.user_id LIKE ?)';
            $like    = '%' . $q . '%';
            $tipe   .= 'ss';
            array_push($nilai, $like, $like);
        }
        if ($f_wilayah > 0) {
            $where[]  = 's.wilayah = ?';
            $tipe    .= 'i';
            $nilai[]  = $f_wilayah;
        }
        $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT * FROM (
                    SELECT s.id, s.user_id, s.nama, w.nama_wilayah, sw.nama_sub, s.jenis_user,
                           COALESCE(SUM(h.jumlah_hutang), 0) AS total_sisa,
                           CASE WHEN COALESCE(SUM(h.jumlah_hutang), 0) > 0 THEN 'Ada' ELSE 'Lunas' END AS status,
                           GROUP_CONCAT(CONCAT(h.id_odalan, ':', h.jumlah_hutang) SEPARATOR '|') AS detail
                    FROM semeton s
                    LEFT JOIN wilayah w      ON s.wilayah = w.id_wilayah
                    LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
                    LEFT JOIN hutang h       ON s.id = h.id_semeton
                    $where_sql
                    GROUP BY s.id, s.user_id, s.nama, w.nama_wilayah, sw.nama_sub, s.jenis_user
                ) x
                " . ($f_status !== '' ? "WHERE x.status = ?" : '') . "
                ORDER BY x.nama_wilayah ASC, x.nama_sub ASC, x.nama ASC, x.user_id ASC";

        if ($stmt = $koneksi->prepare($sql)) {
            if ($f_status !== '') {
                $stmt->bind_param($tipe . 's', ...array_merge($nilai, [$f_status]));
            } elseif ($tipe !== '') {
                $stmt->bind_param($tipe, ...$nilai);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $detail = [];
                if (!empty($r['detail'])) {
                    foreach (explode('|', $r['detail']) as $pasang) {
                        $bagian = explode(':', $pasang, 2);
                        if (count($bagian) === 2) {
                            $detail[(int) $bagian[0]] = (float) $bagian[1];
                        }
                    }
                }
                $kolom = [$r['user_id'], $r['nama'], $r['nama_wilayah'], $r['nama_sub'], $r['jenis_user']];
                foreach ($list_odalan as $id_o => $nama_o) {
                    $kolom[] = isset($detail[$id_o]) ? $detail[$id_o] : '';
                }
                $kolom[] = (float) $r['total_sisa'];
                $tulis($kolom);
            }
            $stmt->close();
        }
        break;

    // ---------------------------------------------------------------
    case 'punia':
        $tulis([
            'ID', 'Piodalan', 'Nama Donatur', 'Alamat', 'Jumlah Punia',
            'Keterangan', 'Metode', 'Tanggal',
        ]);

        // Filter opsional (dipakai tombol Unduh CSV di halaman dana_punia)
        $where = [];
        $tipe  = '';
        $nilai = [];
        $f_odalan = (isset($_GET['id_piodalan']) && is_scalar($_GET['id_piodalan'])) ? (int) $_GET['id_piodalan'] : 0;
        if ($f_odalan > 0) {
            $where[]  = 'd.id_piodalan = ?';
            $tipe    .= 'i';
            $nilai[]  = $f_odalan;
        }
        $q = (isset($_GET['q']) && is_string($_GET['q'])) ? trim($_GET['q']) : '';
        if ($q !== '') {
            $where[] = '(d.nama_donatur LIKE ? OR d.alamat LIKE ? OR d.keterangan LIKE ?)';
            $like    = '%' . $q . '%';
            $tipe   .= 'sss';
            array_push($nilai, $like, $like, $like);
        }
        $f_metode = (isset($_GET['metode']) && is_scalar($_GET['metode'])) ? (string) $_GET['metode'] : '';
        if (in_array($f_metode, ['tunai', 'transfer', 'QRIS'], true)) {
            $where[]  = 'd.metode_pembayaran = ?';
            $tipe    .= 's';
            $nilai[]  = $f_metode;
        }

        $sql = "SELECT d.*, o.nama_odalan
                FROM dana_punia d
                LEFT JOIN odalan o ON o.id = d.id_piodalan
                " . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
                ORDER BY d.tanggal_punia ASC, d.id_punia ASC";
        if ($stmt = $koneksi->prepare($sql)) {
            if ($tipe !== '') {
                $stmt->bind_param($tipe, ...$nilai);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $tulis([
                    (int) $r['id_punia'],
                    $r['nama_odalan'],
                    $r['nama_donatur'],
                    $r['alamat'],
                    (float) $r['jumlah_punia'],
                    $r['keterangan'],
                    $r['metode_pembayaran'],
                    $r['tanggal_punia'],
                ]);
            }
            $stmt->close();
        }
        break;

    // ---------------------------------------------------------------
    case 'rekap_hari':
        $tulis([
            'ID Pembayaran', 'Tanggal Bayar', 'User ID', 'Nama', 'Wilayah', 'Sub Wilayah',
            'Odalan Dibayar', 'Jenis', 'Jumlah Bayar', 'Metode', 'Petugas', 'Status',
        ]);

        // Odalan acara: default odalan terbaru; hanya id yang benar-benar ada.
        $acara = (isset($_GET['id_odalan']) && is_scalar($_GET['id_odalan'])) ? (int) $_GET['id_odalan'] : 0;
        $odalan_ada = [];
        if ($res = $koneksi->query('SELECT id FROM odalan ORDER BY tanggal DESC, id DESC')) {
            while ($r = $res->fetch_assoc()) {
                $odalan_ada[] = (int) $r['id'];
            }
            $res->free();
        }
        if ($acara <= 0 || !in_array($acara, $odalan_ada, true)) {
            $acara = $odalan_ada ? $odalan_ada[0] : 0;
        }

        // Periode kas (default hari ini; tanggal tidak valid -> hari ini).
        $hari_ini = date('Y-m-d');
        $baca_tgl = static function ($v) use ($hari_ini): string {
            if (!is_scalar($v)) {
                return $hari_ini;
            }
            $t = trim((string) $v);
            $d = DateTime::createFromFormat('Y-m-d', $t);
            return ($d instanceof DateTime && $d->format('Y-m-d') === $t) ? $t : $hari_ini;
        };
        $dari   = $baca_tgl($_GET['dari'] ?? $hari_ini);
        $sampai = $baca_tgl($_GET['sampai'] ?? $hari_ini);
        if ($dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        $f_petugas = (isset($_GET['petugas']) && is_scalar($_GET['petugas'])) ? (int) $_GET['petugas'] : 0;

        $where = ['DATE(p.tanggal_bayar) BETWEEN ? AND ?', 'p.dibatalkan_at IS NULL'];
        $tipe  = 'ss';
        $nilai = [$dari, $sampai];
        if ($f_petugas > 0) {
            $where[] = 'p.id_user = ?';
            $tipe   .= 'i';
            $nilai[] = $f_petugas;
        }

        $sql = "SELECT p.id_pembayaran, p.id_piodalan, p.tanggal_bayar, p.jumlah_bayar,
                       p.metode_pembayaran, p.status,
                       s.user_id, s.nama, w.nama_wilayah, sw.nama_sub,
                       o.nama_odalan, u.username
                FROM pembayaran p
                JOIN semeton s ON s.id = p.id_semeton
                JOIN odalan o  ON o.id = p.id_piodalan
                LEFT JOIN wilayah w      ON s.wilayah = w.id_wilayah
                LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
                LEFT JOIN user u         ON u.id_user = p.id_user
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.tanggal_bayar ASC, p.id_pembayaran ASC";

        if ($stmt = $koneksi->prepare($sql)) {
            $stmt->bind_param($tipe, ...$nilai);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $id_o = (int) $r['id_piodalan'];
                if ($acara > 0 && $id_o === $acara) {
                    $jenis_baris = 'Odalan ini';
                } elseif ($acara > 0 && $id_o < $acara) {
                    $jenis_baris = 'Tunggakan';
                } elseif ($acara > 0 && $id_o > $acara) {
                    $jenis_baris = 'Bayar di muka';
                } else {
                    $jenis_baris = '-';
                }
                $tulis([
                    (int) $r['id_pembayaran'],
                    $r['tanggal_bayar'],
                    $r['user_id'],
                    $r['nama'],
                    $r['nama_wilayah'],
                    $r['nama_sub'],
                    $r['nama_odalan'],
                    $jenis_baris,
                    (float) $r['jumlah_bayar'],
                    $r['metode_pembayaran'],
                    $r['username'],
                    $r['status'],
                ]);
            }
            $stmt->close();
        }
        break;

    // ---------------------------------------------------------------
    case 'laba_rugi':
        $tulis([
            'Odalan', 'Tanggal', 'Punia', 'Urunan', 'Total Pemasukan', 'Pengeluaran',
            'Saldo', 'Margin (%)', 'Tagihan Urunan', 'Sisa Tagihan', 'Tagihan Lunas', 'Jumlah Tagihan',
        ]);
        $sql = "SELECT o.id, o.nama_odalan, o.tanggal,
                       (SELECT COALESCE(SUM(d.jumlah_punia), 0) FROM dana_punia d
                         WHERE d.id_piodalan = o.id) AS punia,
                       (SELECT COALESCE(SUM(p.jumlah_bayar), 0) FROM pembayaran p
                         WHERE p.id_piodalan = o.id AND p.dibatalkan_at IS NULL) AS urunan,
                       (SELECT COALESCE(SUM(h.jumlah_tagihan_asli), 0) FROM hutang h
                         WHERE h.id_odalan = o.id) AS tagihan,
                       (SELECT COALESCE(SUM(h.jumlah_hutang), 0) FROM hutang h
                         WHERE h.id_odalan = o.id) AS sisa,
                       (SELECT COUNT(*) FROM hutang h
                         WHERE h.id_odalan = o.id AND h.status = 'lunas') AS jml_lunas,
                       (SELECT COUNT(*) FROM hutang h WHERE h.id_odalan = o.id) AS jml_tagihan,
                       (SELECT COALESCE(SUM(pg.jumlah), 0) FROM pengeluaran pg
                         WHERE pg.id_piodalan = o.id) AS belanja_lama,
                       (SELECT COALESCE(SUM(dt.total), 0) FROM detail_transaksi dt
                          JOIN transaksi t ON t.id_transaksi = dt.id_transaksi
                         WHERE t.id_piodalan = o.id) AS belanja_baru
                FROM odalan o
                ORDER BY o.tanggal ASC, o.id ASC";
        if ($res = $koneksi->query($sql)) {
            while ($r = $res->fetch_assoc()) {
                $masuk   = (float) $r['punia'] + (float) $r['urunan'];
                $belanja = (float) $r['belanja_lama'] + (float) $r['belanja_baru'];
                $saldo   = $masuk - $belanja;
                $tulis([
                    $r['nama_odalan'], $r['tanggal'], (float) $r['punia'], (float) $r['urunan'],
                    $masuk, $belanja, $saldo,
                    $masuk > 0 ? number_format($saldo / $masuk * 100, 2, '.', '') : '0',
                    (float) $r['tagihan'], (float) $r['sisa'],
                    (int) $r['jml_lunas'], (int) $r['jml_tagihan'],
                ]);
            }
            $res->free();
        }
        break;

    // ---------------------------------------------------------------
    default:
        kbap_deny(400, 'Jenis ekspor tidak dikenal.');
}

fclose($out);
exit;
