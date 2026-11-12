<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../koneksi.php';

// Hak akses tidak diubah: dana punia = admin + bendahara.
kbap_guard(['admin', 'bendahara']);

// =====================================================================
// DIPERKERAS (2026-11-10)
// - Semua query memakai prepared statement (dulu INSERT/UPDATE disusun
//   dari real_escape_string; `id_piodalan` & `id` disisipkan mentah).
// - Validasi: id_piodalan harus ada di tabel odalan, nama donatur wajib &
//   <= 255 karakter, jumlah 0 … 99.999.999,99, metode harus salah satu
//   enum, tanggal wajib valid (checkdate).
// - Redirect selalu membawa id_piodalan agar filter halaman tidak reset,
//   dan menuju `menu=dana_punia` (dulu error redirect ke `rekap_punia`).
// - Audit DELETE hanya dicatat bila benar-benar ada baris terhapus.
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

/** Redirect ke halaman dana punia dengan filter odalan tetap. */
$kembali = static function (int $id_piodalan, string $status): void {
    header('Location: ../index.php?menu=dana_punia&id_piodalan=' . $id_piodalan . '&status=' . $status);
    exit;
};

/** Ambil satu baris punia (null bila tidak ada). */
$ambil_punia = static function ($koneksi, int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    if (!$stmt = $koneksi->prepare('SELECT id_punia, id_piodalan, nama_donatur, alamat, jumlah_punia, keterangan, metode_pembayaran, tanggal_punia FROM dana_punia WHERE id_punia = ? LIMIT 1')) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
};

$odalan_ada = static function ($koneksi, int $id): bool {
    if ($id <= 0) {
        return false;
    }
    if (!$stmt = $koneksi->prepare('SELECT 1 FROM odalan WHERE id = ? LIMIT 1')) {
        return false;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $ada = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $ada;
};

// ---------------------------------------------------------------- TAMBAH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'tambah') {
    $id_piodalan = isset($_POST['id_piodalan']) ? (int) $_POST['id_piodalan'] : 0;
    $nama        = $teks($_POST['nama'] ?? '');
    $alamat      = $teks($_POST['alamat'] ?? '');
    $jumlah      = $nominal_valid($_POST['jumlah'] ?? null);
    $keterangan  = $teks($_POST['ket'] ?? '');
    $metode      = $teks($_POST['metode'] ?? '');
    $tanggal     = $teks($_POST['tanggal'] ?? '');

    if (!$odalan_ada($koneksi, $id_piodalan)
        || $nama === '' || mb_strlen($nama) > 255
        || $jumlah === null
        || !in_array($metode, ['tunai', 'transfer', 'QRIS'], true)
        || !$tanggal_valid($tanggal)) {
        $kembali($id_piodalan, 'gagal');
    }

    try {
        $stmt = $koneksi->prepare('INSERT INTO dana_punia (id_piodalan, nama_donatur, alamat, jumlah_punia, keterangan, metode_pembayaran, tanggal_punia) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issdsss', $id_piodalan, $nama, $alamat, $jumlah, $keterangan, $metode, $tanggal);
        $stmt->execute();
        $id_baru = (int) $stmt->insert_id;
        $stmt->close();
    } catch (Throwable $e) {
        error_log('aksi_dana_punia tambah: ' . $e->getMessage());
        $kembali($id_piodalan, 'gagal');
    }

    kbap_audit_log($koneksi, 'dana_punia', 'INSERT', $id_baru, null, [
        'id_piodalan'   => $id_piodalan,
        'nama_donatur'  => $nama,
        'jumlah_punia'  => $jumlah,
        'tanggal_punia' => $tanggal,
        'metode'        => $metode,
    ], 'Tambah punia dari ' . $nama);

    $kembali($id_piodalan, 'sukses');
}

// ------------------------------------------------------------------ EDIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'edit') {
    $id          = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $id_piodalan = isset($_POST['id_piodalan']) ? (int) $_POST['id_piodalan'] : 0;
    $nama        = $teks($_POST['nama'] ?? '');
    $alamat      = $teks($_POST['alamat'] ?? '');
    $jumlah      = $nominal_valid($_POST['jumlah'] ?? null);
    $keterangan  = $teks($_POST['ket'] ?? '');
    $metode      = $teks($_POST['metode'] ?? '');
    $tanggal     = $teks($_POST['tanggal'] ?? '');

    $data_lama = $ambil_punia($koneksi, $id);
    if (!$data_lama) {
        $kembali($id_piodalan, 'gagal');
    }
    // Bila POST tidak membawa id_piodalan yang sah, pakai nilai lama.
    if (!$odalan_ada($koneksi, $id_piodalan)) {
        $id_piodalan = (int) $data_lama['id_piodalan'];
    }

    if ($nama === '' || mb_strlen($nama) > 255
        || $jumlah === null
        || !in_array($metode, ['tunai', 'transfer', 'QRIS'], true)
        || !$tanggal_valid($tanggal)) {
        $kembali($id_piodalan, 'gagal');
    }

    try {
        $stmt = $koneksi->prepare('UPDATE dana_punia SET id_piodalan = ?, nama_donatur = ?, alamat = ?, jumlah_punia = ?, keterangan = ?, metode_pembayaran = ?, tanggal_punia = ? WHERE id_punia = ?');
        $stmt->bind_param('issdsssi', $id_piodalan, $nama, $alamat, $jumlah, $keterangan, $metode, $tanggal, $id);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('aksi_dana_punia edit: ' . $e->getMessage());
        $kembali($id_piodalan, 'gagal');
    }

    kbap_audit_log($koneksi, 'dana_punia', 'UPDATE', $id, $data_lama, [
        'id_piodalan'   => $id_piodalan,
        'nama_donatur'  => $nama,
        'jumlah_punia'  => $jumlah,
        'tanggal_punia' => $tanggal,
        'metode'        => $metode,
    ], 'Ubah punia id ' . $id);

    $kembali($id_piodalan, 'edit');
}

// ----------------------------------------------------------------- HAPUS
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $aksi_get === 'hapus') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    $data_lama = $ambil_punia($koneksi, $id);
    if (!$data_lama) {
        $kembali(0, 'gagal');
    }
    $id_piodalan = (int) $data_lama['id_piodalan'];

    try {
        $stmt = $koneksi->prepare('DELETE FROM dana_punia WHERE id_punia = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $terhapus = (int) $stmt->affected_rows;
        $stmt->close();
    } catch (Throwable $e) {
        error_log('aksi_dana_punia hapus: ' . $e->getMessage());
        $kembali($id_piodalan, 'gagal');
    }

    if ($terhapus === 0) {
        $kembali($id_piodalan, 'gagal');
    }

    kbap_audit_log($koneksi, 'dana_punia', 'DELETE', $id, $data_lama, null,
        'Hapus punia id ' . $id . ' (' . $data_lama['nama_donatur'] . ')');

    $kembali($id_piodalan, 'hapus');
}

// Tanpa aksi yang dikenal: kembali ke daftar.
header('Location: ../index.php?menu=dana_punia');
exit;
