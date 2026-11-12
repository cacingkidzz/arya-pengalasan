<?php
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bagian_data']);
require_once __DIR__ . '/../koneksi.php';
date_default_timezone_set('Asia/Makassar');

// =====================================================================
// DIPERKERAS (2026-11-09)
// - Semua query tulis memakai prepared statement (dulu INSERT/UPDATE
//   menyusun SQL dari real_escape_string).
// - Validasi: id_wilayah harus ada di tabel wilayah, nama_sub wajib.
// - Hapus ditolak bila sub wilayah masih dipakai anggota (FK semeton
//   ON DELETE SET NULL akan mengosongkan sub wilayah anggota tsb).
// =====================================================================
$aksi_post = isset($_POST['aksi']) && is_scalar($_POST['aksi']) ? (string) $_POST['aksi'] : '';
$aksi_get  = isset($_GET['aksi']) && is_scalar($_GET['aksi']) ? (string) $_GET['aksi'] : '';

/** Cek apakah id_wilayah benar-benar ada. */
$wilayah_valid = static function (mysqli $koneksi, int $id_wilayah): bool {
    if ($id_wilayah <= 0) {
        return false;
    }
    $stmt = $koneksi->prepare('SELECT id_wilayah FROM wilayah WHERE id_wilayah = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $id_wilayah);
    $stmt->execute();
    $ada = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ada;
};

// 1. TAMBAH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'tambah') {
    $id_wilayah = isset($_POST['id_wilayah']) ? (int) $_POST['id_wilayah'] : 0;
    $nama_sub   = isset($_POST['nama_sub']) ? trim((string) $_POST['nama_sub']) : '';

    if (!$wilayah_valid($koneksi, $id_wilayah) || $nama_sub === '') {
        header('location:../index.php?menu=sub_wilayah&status=gagal');
        exit();
    }

    $stmt = $koneksi->prepare('INSERT INTO sub_wilayah (id_wilayah, nama_sub) VALUES (?, ?)');
    $stmt->bind_param('is', $id_wilayah, $nama_sub);

    if ($stmt->execute()) {
        $id_baru = $stmt->insert_id;
        $stmt->close();
        kbap_audit_log($koneksi, 'sub_wilayah', 'INSERT', $id_baru, null, [
            'id_wilayah' => $id_wilayah,
            'nama_sub'   => $nama_sub,
        ], 'Tambah sub wilayah ' . $nama_sub);
        header('location:../index.php?menu=sub_wilayah&status=sukses');
        exit();
    }

    $stmt->close();
    header('location:../index.php?menu=sub_wilayah&status=gagal');
    exit();
}

// 2. EDIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'edit') {
    $id_sub     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $id_wilayah = isset($_POST['id_wilayah']) ? (int) $_POST['id_wilayah'] : 0;
    $nama_sub   = isset($_POST['nama_sub']) ? trim((string) $_POST['nama_sub']) : '';

    if ($id_sub <= 0 || !$wilayah_valid($koneksi, $id_wilayah) || $nama_sub === '') {
        header('location:../index.php?menu=sub_wilayah&status=gagal');
        exit();
    }

    // Simpan nilai lama untuk jejak audit
    $data_lama = null;
    if ($stmt_lama = $koneksi->prepare('SELECT id_wilayah, nama_sub FROM sub_wilayah WHERE id_sub = ? LIMIT 1')) {
        $stmt_lama->bind_param('i', $id_sub);
        $stmt_lama->execute();
        $data_lama = $stmt_lama->get_result()->fetch_assoc();
        $stmt_lama->close();
    }
    if (!$data_lama) {
        header('location:../index.php?menu=sub_wilayah&status=gagal');
        exit();
    }

    $stmt = $koneksi->prepare('UPDATE sub_wilayah SET id_wilayah = ?, nama_sub = ? WHERE id_sub = ?');
    $stmt->bind_param('isi', $id_wilayah, $nama_sub, $id_sub);

    if ($stmt->execute()) {
        $stmt->close();
        kbap_audit_log($koneksi, 'sub_wilayah', 'UPDATE', $id_sub, $data_lama, [
            'id_wilayah' => $id_wilayah,
            'nama_sub'   => $nama_sub,
        ], 'Ubah sub wilayah id ' . $id_sub);
        header('location:../index.php?menu=sub_wilayah&status=edit');
        exit();
    }

    $stmt->close();
    header('location:../index.php?menu=sub_wilayah&status=gagal');
    exit();
}

// 3. HAPUS (GET + token CSRF, dijaga kbap_guard)
if ($aksi_get === 'hapus') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        header('location:../index.php?menu=sub_wilayah&status=gagal');
        exit();
    }

    // Pengaman: FK semeton/semeton_baru memakai ON DELETE SET NULL, jadi
    // menghapus sub wilayah yang masih dipakai akan mengosongkan data anggota.
    $jml_pakai = 0;
    if ($stmt_cek = $koneksi->prepare('SELECT
            (SELECT COUNT(*) FROM semeton WHERE sub_wilayah = ?) +
            (SELECT COUNT(*) FROM semeton_baru WHERE sub_wilayah = ?) AS jml')) {
        $stmt_cek->bind_param('ii', $id, $id);
        $stmt_cek->execute();
        $jml_pakai = (int) ($stmt_cek->get_result()->fetch_assoc()['jml'] ?? 0);
        $stmt_cek->close();
    }
    if ($jml_pakai > 0) {
        header('location:../index.php?menu=sub_wilayah&status=dipakai');
        exit();
    }

    // Simpan nilai lama untuk jejak audit
    $data_lama = null;
    if ($stmt_lama = $koneksi->prepare('SELECT id_wilayah, nama_sub FROM sub_wilayah WHERE id_sub = ? LIMIT 1')) {
        $stmt_lama->bind_param('i', $id);
        $stmt_lama->execute();
        $data_lama = $stmt_lama->get_result()->fetch_assoc();
        $stmt_lama->close();
    }

    $terhapus = 0;
    try {
        $stmt = $koneksi->prepare('DELETE FROM sub_wilayah WHERE id_sub = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $terhapus = $stmt->affected_rows;
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        error_log('kbap: gagal hapus sub_wilayah id ' . $id . ': ' . $e->getMessage());
        header('location:../index.php?menu=sub_wilayah&status=gagal');
        exit();
    }

    if ($terhapus > 0) {
        kbap_audit_log($koneksi, 'sub_wilayah', 'DELETE', $id, $data_lama, null, 'Hapus sub wilayah id ' . $id);
    }
    header('location:../index.php?menu=sub_wilayah&status=hapus');
    exit();
}
