<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../koneksi.php';

// Hak akses tidak diubah: kelola odalan = admin + bagian_data.
kbap_guard(['admin', 'bagian_data']);

// =====================================================================
// DIPERKERAS (2026-11-10)
// - Semua query memakai prepared statement (dulu INSERT/UPDATE disusun
//   dari real_escape_string; `id` disisipkan mentah).
// - Tambah odalan: odalan + tagihan dibuat dalam SATU transaksi, dan
//   tagihan dibuat dengan satu `INSERT ... SELECT` (dulu 604 INSERT
//   terpisah tanpa transaksi → bisa tersisa odalan tanpa tagihan).
// - Edit: validasi tanggal & nominal, baris lama dibaca dulu.
// - Hapus: DITOLAK bila odalan masih punya pembayaran / punia /
//   pengeluaran / nota. Ini krusial karena FK `pembayaran_ibfk_2`
//   ber-ON DELETE CASCADE (menghapus odalan akan MENGHAPUS RIWAYAT
//   PEMBAYARAN), sementara `dana_punia`/`pengeluaran` ber-RESTRICT
//   (delete gagal setelah hutang terlanjur terhapus).
// =====================================================================
$aksi_post = isset($_POST['aksi']) && is_scalar($_POST['aksi']) ? (string) $_POST['aksi'] : '';
$aksi_get  = isset($_GET['aksi']) && is_scalar($_GET['aksi']) ? (string) $_GET['aksi'] : '';

$teks = static function ($nilai): string {
    return is_scalar($nilai) ? trim((string) $nilai) : '';
};

$tanggal_valid = static function (string $t): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
};

$nominal_valid = static function ($nilai): ?float {
    if (!is_scalar($nilai) || !is_numeric($nilai)) {
        return null;
    }
    $n = (float) $nilai;
    if ($n < 0 || $n > 99999999.99) { // decimal(10,2)
        return null;
    }
    return round($n, 2);
};

/** Ambil satu baris odalan (null bila tidak ada). */
$ambil_odalan = static function ($koneksi, int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    if (!$stmt = $koneksi->prepare('SELECT id, nama_odalan, deskripsi, tanggal, iuran_ngarep, iuran_ngempi FROM odalan WHERE id = ? LIMIT 1')) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
};

// ---------------------------------------------------------------- TAMBAH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'tambah') {
    $nama_odalan   = $teks($_POST['nama_odalan'] ?? '');
    $deskripsi     = $teks($_POST['deskripsi'] ?? '');
    $tanggal       = $teks($_POST['tanggal'] ?? '');
    $iuran_ngarep  = $nominal_valid($_POST['iuran_ngarep'] ?? null);
    $iuran_ngempi  = $nominal_valid($_POST['iuran_ngempi'] ?? null);

    if ($nama_odalan === '' || mb_strlen($nama_odalan) > 100
        || !$tanggal_valid($tanggal)
        || $iuran_ngarep === null || $iuran_ngempi === null) {
        header('Location: ../index.php?menu=piodalan&status=gagal');
        exit;
    }

    try {
        $koneksi->begin_transaction();

        $stmt = $koneksi->prepare('INSERT INTO odalan (nama_odalan, deskripsi, tanggal, iuran_ngarep, iuran_ngempi) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssdd', $nama_odalan, $deskripsi, $tanggal, $iuran_ngarep, $iuran_ngempi);
        $stmt->execute();
        $odalan_id = (int) $stmt->insert_id;
        $stmt->close();

        // Tagihan untuk semua anggota aktif non-pemangku, dalam satu query.
        $id_user_login = (int) ($_SESSION['id_user'] ?? 0);
        $stmt_h = $koneksi->prepare(
            "INSERT INTO hutang (id_semeton, id_odalan, jumlah_hutang, jumlah_tagihan_asli, status, id_user)
             SELECT s.id, ?, IF(s.jenis_user = 'ngarep', ?, ?), IF(s.jenis_user = 'ngarep', ?, ?), 'belum_lunas', ?
             FROM semeton s
             WHERE s.jenis_user <> 'pemangku' AND s.status = 'aktif'"
        );
        $stmt_h->bind_param('iddddi', $odalan_id, $iuran_ngarep, $iuran_ngempi, $iuran_ngarep, $iuran_ngempi, $id_user_login);
        $stmt_h->execute();
        $jml_tagihan = (int) $stmt_h->affected_rows;
        $stmt_h->close();

        $koneksi->commit();
    } catch (Throwable $e) {
        $koneksi->rollback();
        error_log('aksi_odalan tambah: ' . $e->getMessage());
        header('Location: ../index.php?menu=piodalan&status=gagal');
        exit;
    }

    kbap_audit_log($koneksi, 'odalan', 'INSERT', $odalan_id, null, [
        'nama_odalan'    => $nama_odalan,
        'tanggal'        => $tanggal,
        'iuran_ngarep'   => $iuran_ngarep,
        'iuran_ngempi'   => $iuran_ngempi,
        'tagihan_dibuat' => $jml_tagihan,
    ], 'Tambah odalan + ' . $jml_tagihan . ' tagihan');

    header('Location: ../index.php?menu=piodalan&status=sukses');
    exit;
}

// ------------------------------------------------------------------ EDIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'edit') {
    $id            = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $nama_odalan   = $teks($_POST['nama_odalan'] ?? '');
    $deskripsi     = $teks($_POST['deskripsi'] ?? '');
    $tanggal       = $teks($_POST['tanggal'] ?? '');
    $iuran_ngarep  = $nominal_valid($_POST['iuran_ngarep'] ?? null);
    $iuran_ngempi  = $nominal_valid($_POST['iuran_ngempi'] ?? null);

    if ($id <= 0 || $nama_odalan === '' || mb_strlen($nama_odalan) > 100
        || !$tanggal_valid($tanggal)
        || $iuran_ngarep === null || $iuran_ngempi === null) {
        header('Location: ../index.php?menu=piodalan&status=gagal');
        exit;
    }

    $data_lama = $ambil_odalan($koneksi, $id);
    if (!$data_lama) {
        header('Location: ../index.php?menu=piodalan&status=gagal');
        exit;
    }

    try {
        $stmt = $koneksi->prepare('UPDATE odalan SET nama_odalan = ?, deskripsi = ?, tanggal = ?, iuran_ngarep = ?, iuran_ngempi = ? WHERE id = ?');
        $stmt->bind_param('sssddi', $nama_odalan, $deskripsi, $tanggal, $iuran_ngarep, $iuran_ngempi, $id);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('aksi_odalan edit: ' . $e->getMessage());
        header('Location: ../index.php?menu=piodalan&status=gagal');
        exit;
    }

    kbap_audit_log($koneksi, 'odalan', 'UPDATE', $id, $data_lama, [
        'nama_odalan'  => $nama_odalan,
        'tanggal'      => $tanggal,
        'iuran_ngarep' => $iuran_ngarep,
        'iuran_ngempi' => $iuran_ngempi,
    ], 'Ubah odalan id ' . $id);

    header('Location: ../index.php?menu=piodalan&status=edit');
    exit;
}

// ----------------------------------------------------------------- HAPUS
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $aksi_get === 'hapus') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        header('Location: ../index.php?menu=piodalan&status=gagal');
        exit;
    }

    $data_lama = $ambil_odalan($koneksi, $id);
    if (!$data_lama) {
        header('Location: ../index.php?menu=piodalan&status=gagal');
        exit;
    }

    // Hitung data yang bergantung pada odalan ini. Bila ada data keuangan,
    // penghapusan DITOLAK (lihat catatan di atas).
    $terpakai = [];
    $cek = [
        'pembayaran' => 'SELECT COUNT(*) AS c FROM pembayaran WHERE id_piodalan = ?',
        'dana_punia' => 'SELECT COUNT(*) AS c FROM dana_punia WHERE id_piodalan = ?',
        'pengeluaran' => 'SELECT COUNT(*) AS c FROM pengeluaran WHERE id_piodalan = ?',
        'transaksi'  => 'SELECT COUNT(*) AS c FROM transaksi WHERE id_piodalan = ?',
    ];
    foreach ($cek as $label => $sql_cek) {
        if ($stmt_c = $koneksi->prepare($sql_cek)) {
            $stmt_c->bind_param('i', $id);
            $stmt_c->execute();
            $jml = (int) ($stmt_c->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt_c->close();
            if ($jml > 0) {
                $terpakai[] = $label . ' (' . $jml . ')';
            }
        }
    }
    if (!empty($terpakai)) {
        error_log('aksi_odalan hapus ditolak id ' . $id . ': ' . implode(', ', $terpakai));
        header('Location: ../index.php?menu=piodalan&status=dipakai');
        exit;
    }

    $jml_hutang = 0;
    if ($stmt_h = $koneksi->prepare('SELECT COUNT(*) AS c FROM hutang WHERE id_odalan = ?')) {
        $stmt_h->bind_param('i', $id);
        $stmt_h->execute();
        $jml_hutang = (int) ($stmt_h->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt_h->close();
    }

    try {
        $koneksi->begin_transaction();

        $stmt = $koneksi->prepare('DELETE FROM hutang WHERE id_odalan = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $koneksi->prepare('DELETE FROM odalan WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $terhapus = (int) $stmt->affected_rows;
        $stmt->close();

        if ($terhapus === 0) {
            $koneksi->rollback();
            header('Location: ../index.php?menu=piodalan&status=gagal');
            exit;
        }

        $koneksi->commit();
    } catch (Throwable $e) {
        $koneksi->rollback();
        error_log('aksi_odalan hapus: ' . $e->getMessage());
        header('Location: ../index.php?menu=piodalan&status=gagal');
        exit;
    }

    kbap_audit_log($koneksi, 'odalan', 'DELETE', $id, $data_lama, [
        'tagihan_ikut_terhapus' => $jml_hutang,
    ], 'Hapus odalan id ' . $id . ' (+' . $jml_hutang . ' tagihan)');

    header('Location: ../index.php?menu=piodalan&status=hapus');
    exit;
}

header('Location: ../index.php?menu=piodalan');
exit;
