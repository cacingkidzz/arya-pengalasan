<?php
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bagian_data']);
require_once __DIR__ . '/../koneksi.php';
date_default_timezone_set('Asia/Makassar');

// =====================================================================
// DIPERKERAS (2026-11-09)
// - Semua query tulis memakai prepared statement (dulu INSERT/UPDATE
//   menyusun SQL dari real_escape_string).
// - Validasi: nama_wilayah wajib tidak kosong, id di-cast (int).
// - Hapus ditolak bila wilayah masih punya sub wilayah / anggota.
//   FK `fk_wilayah` ber-ON DELETE CASCADE (sub wilayah ikut terhapus)
//   dan FK semeton ber-ON DELETE SET NULL (wilayah/sub wilayah anggota
//   akan dikosongkan) — jadi hapus tanpa pengaman = kehilangan data.
// =====================================================================
$aksi_post = isset($_POST['aksi']) && is_scalar($_POST['aksi']) ? (string) $_POST['aksi'] : '';
$aksi_get  = isset($_GET['aksi']) && is_scalar($_GET['aksi']) ? (string) $_GET['aksi'] : '';

// 1. TAMBAH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'tambah') {
    $nama_wilayah = isset($_POST['nama_wilayah']) ? trim((string) $_POST['nama_wilayah']) : '';

    if ($nama_wilayah === '') {
        header('location:../index.php?menu=wilayah&status=gagal');
        exit();
    }

    $stmt = $koneksi->prepare('INSERT INTO wilayah (nama_wilayah) VALUES (?)');
    $stmt->bind_param('s', $nama_wilayah);

    if ($stmt->execute()) {
        $id_baru = $stmt->insert_id;
        $stmt->close();
        kbap_audit_log($koneksi, 'wilayah', 'INSERT', $id_baru, null, [
            'nama_wilayah' => $nama_wilayah,
        ], 'Tambah wilayah ' . $nama_wilayah);
        header('location:../index.php?menu=wilayah&status=sukses');
        exit();
    }

    $stmt->close();
    header('location:../index.php?menu=wilayah&status=gagal');
    exit();
}

// 2. EDIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'edit') {
    $id_wilayah   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $nama_wilayah = isset($_POST['nama_wilayah']) ? trim((string) $_POST['nama_wilayah']) : '';

    if ($id_wilayah <= 0 || $nama_wilayah === '') {
        header('location:../index.php?menu=wilayah&status=gagal');
        exit();
    }

    // Simpan nilai lama untuk jejak audit
    $data_lama = null;
    if ($stmt_lama = $koneksi->prepare('SELECT nama_wilayah FROM wilayah WHERE id_wilayah = ? LIMIT 1')) {
        $stmt_lama->bind_param('i', $id_wilayah);
        $stmt_lama->execute();
        $data_lama = $stmt_lama->get_result()->fetch_assoc();
        $stmt_lama->close();
    }
    if (!$data_lama) {
        header('location:../index.php?menu=wilayah&status=gagal');
        exit();
    }

    $stmt = $koneksi->prepare('UPDATE wilayah SET nama_wilayah = ? WHERE id_wilayah = ?');
    $stmt->bind_param('si', $nama_wilayah, $id_wilayah);

    if ($stmt->execute()) {
        $stmt->close();
        kbap_audit_log($koneksi, 'wilayah', 'UPDATE', $id_wilayah, $data_lama, [
            'nama_wilayah' => $nama_wilayah,
        ], 'Ubah wilayah id ' . $id_wilayah);
        header('location:../index.php?menu=wilayah&status=edit');
        exit();
    }

    $stmt->close();
    header('location:../index.php?menu=wilayah&status=gagal');
    exit();
}

// 3. HAPUS (GET + token CSRF, dijaga kbap_guard)
if ($aksi_get === 'hapus') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        header('location:../index.php?menu=wilayah&status=gagal');
        exit();
    }

    // Pengaman: FK fk_wilayah = ON DELETE CASCADE, FK semeton = ON DELETE SET NULL.
    $jml_pakai = 0;
    if ($stmt_cek = $koneksi->prepare('SELECT
            (SELECT COUNT(*) FROM sub_wilayah WHERE id_wilayah = ?) +
            (SELECT COUNT(*) FROM semeton WHERE wilayah = ?) +
            (SELECT COUNT(*) FROM semeton_baru WHERE wilayah = ?) AS jml')) {
        $stmt_cek->bind_param('iii', $id, $id, $id);
        $stmt_cek->execute();
        $jml_pakai = (int) ($stmt_cek->get_result()->fetch_assoc()['jml'] ?? 0);
        $stmt_cek->close();
    }
    if ($jml_pakai > 0) {
        header('location:../index.php?menu=wilayah&status=dipakai');
        exit();
    }

    // Simpan nilai lama untuk jejak audit
    $data_lama = null;
    if ($stmt_lama = $koneksi->prepare('SELECT nama_wilayah FROM wilayah WHERE id_wilayah = ? LIMIT 1')) {
        $stmt_lama->bind_param('i', $id);
        $stmt_lama->execute();
        $data_lama = $stmt_lama->get_result()->fetch_assoc();
        $stmt_lama->close();
    }

    $terhapus = 0;
    try {
        $stmt = $koneksi->prepare('DELETE FROM wilayah WHERE id_wilayah = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $terhapus = $stmt->affected_rows;
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        error_log('kbap: gagal hapus wilayah id ' . $id . ': ' . $e->getMessage());
        header('location:../index.php?menu=wilayah&status=gagal');
        exit();
    }

    if ($terhapus > 0) {
        kbap_audit_log($koneksi, 'wilayah', 'DELETE', $id, $data_lama, null, 'Hapus wilayah id ' . $id);
    }
    header('location:../index.php?menu=wilayah&status=hapus');
    exit();
}
