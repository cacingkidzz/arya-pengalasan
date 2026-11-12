<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../koneksi.php';

// Hanya admin yang boleh mengelola akun.
kbap_guard(['admin']);

// =====================================================================
// DIPERKERAS (2026-11-09)
// - include koneksi absolut (__DIR__), `aksi` dibaca dengan is_scalar.
// - Validasi panjang nama/username agar tidak melebihi kolom.
// - Hapus: audit hanya dicatat bila ada baris terhapus, tidak boleh
//   menghapus akun sendiri, dan tidak boleh menghapus admin terakhir
//   (mencegah lockout). Password tidak pernah dicatat di audit.
// =====================================================================
$valid_akses = ['admin', 'bendahara', 'bagian_data'];

$aksi_post = isset($_POST['aksi']) && is_scalar($_POST['aksi']) ? (string) $_POST['aksi'] : '';
$aksi_get  = isset($_GET['aksi']) && is_scalar($_GET['aksi']) ? (string) $_GET['aksi'] : '';

$teks = static function ($nilai): string {
    return is_scalar($nilai) ? trim((string) $nilai) : '';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'tambah') {
    $nama     = $teks($_POST['nama'] ?? '');
    $username = $teks($_POST['username'] ?? '');
    $password = is_scalar($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
    $akses    = $teks($_POST['akses'] ?? '');

    if ($nama === '' || $username === '' || $password === '' || !in_array($akses, $valid_akses, true)
        || mb_strlen($nama) > 255 || mb_strlen($username) > 100) {
        header('Location: ../index.php?menu=user&status=gagal');
        exit;
    }

    // Cek username unik
    $cek = $koneksi->prepare('SELECT id_user FROM user WHERE username = ? LIMIT 1');
    $cek->bind_param('s', $username);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        $cek->close();
        header('Location: ../index.php?menu=user&status=gagal');
        exit;
    }
    $cek->close();

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $koneksi->prepare('INSERT INTO user (nama_user, username, password, akses) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $nama, $username, $hash, $akses);

    if ($stmt->execute()) {
        $id_baru = (int) $stmt->insert_id;
        $stmt->close();
        kbap_audit_log($koneksi, 'user', 'INSERT', $id_baru, null, [
            'nama_user' => $nama,
            'username'  => $username,
            'akses'     => $akses,
        ], 'Tambah akun pengguna');
        header('Location: ../index.php?menu=user&status=sukses');
        exit;
    }
    $stmt->close();
    header('Location: ../index.php?menu=user&status=gagal');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'edit') {
    $id       = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $nama     = $teks($_POST['nama'] ?? '');
    $username = $teks($_POST['username'] ?? '');
    $password = is_scalar($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
    $akses    = $teks($_POST['akses'] ?? '');

    if ($id <= 0 || $nama === '' || $username === '' || !in_array($akses, $valid_akses, true)
        || mb_strlen($nama) > 255 || mb_strlen($username) > 100) {
        header('Location: ../index.php?menu=user&status=gagal');
        exit;
    }

    // Username harus unik (kecuali milik sendiri)
    $cek = $koneksi->prepare('SELECT id_user FROM user WHERE username = ? AND id_user <> ? LIMIT 1');
    $cek->bind_param('si', $username, $id);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        $cek->close();
        header('Location: ../index.php?menu=user&status=gagal');
        exit;
    }
    $cek->close();

    // Data lama untuk audit (password tidak pernah dibaca/dicatat)
    $data_lama = null;
    if ($stmt_lama = $koneksi->prepare('SELECT nama_user, username, akses FROM user WHERE id_user = ? LIMIT 1')) {
        $stmt_lama->bind_param('i', $id);
        $stmt_lama->execute();
        $data_lama = $stmt_lama->get_result()->fetch_assoc();
        $stmt_lama->close();
    }
    if (!$data_lama) {
        header('Location: ../index.php?menu=user&status=gagal');
        exit;
    }

    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $koneksi->prepare('UPDATE user SET nama_user = ?, username = ?, password = ?, akses = ? WHERE id_user = ?');
        $stmt->bind_param('ssssi', $nama, $username, $hash, $akses, $id);
    } else {
        // Password dikosongkan = jangan ubah password lama.
        $stmt = $koneksi->prepare('UPDATE user SET nama_user = ?, username = ?, akses = ? WHERE id_user = ?');
        $stmt->bind_param('sssi', $nama, $username, $akses, $id);
    }

    if ($stmt->execute()) {
        $stmt->close();
        kbap_audit_log($koneksi, 'user', 'UPDATE', $id, $data_lama, [
            'nama_user'       => $nama,
            'username'        => $username,
            'akses'           => $akses,
            'password_diubah' => ($password !== ''),
        ], 'Ubah akun pengguna');
        header('Location: ../index.php?menu=user&status=edit');
        exit;
    }
    $stmt->close();
    header('Location: ../index.php?menu=user&status=gagal');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $aksi_get === 'hapus') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($id <= 0) {
        header('Location: ../index.php?menu=user&status=gagal');
        exit;
    }

    // Jangan izinkan admin menghapus akunnya sendiri.
    if ($id === (int) ($_SESSION['id_user'] ?? 0)) {
        header('Location: ../index.php?menu=user&status=gagal');
        exit;
    }

    $data_lama = null;
    if ($lama = $koneksi->prepare('SELECT nama_user, username, akses FROM user WHERE id_user = ? LIMIT 1')) {
        $lama->bind_param('i', $id);
        $lama->execute();
        $data_lama = $lama->get_result()->fetch_assoc();
        $lama->close();
    }

    // Pengaman: jangan sampai admin terakhir terhapus (akun terkunci).
    if (is_array($data_lama) && $data_lama['akses'] === 'admin') {
        $jml_admin = 0;
        if ($stmt_a = $koneksi->prepare("SELECT COUNT(*) AS c FROM user WHERE akses = 'admin'")) {
            $stmt_a->execute();
            $jml_admin = (int) ($stmt_a->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt_a->close();
        }
        if ($jml_admin <= 1) {
            header('Location: ../index.php?menu=user&status=gagal');
            exit;
        }
    }

    $stmt = $koneksi->prepare('DELETE FROM user WHERE id_user = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $terhapus = $stmt->affected_rows;
    $stmt->close();

    // Audit hanya bila benar-benar ada baris terhapus.
    if ($terhapus > 0) {
        kbap_audit_log($koneksi, 'user', 'DELETE', $id, $data_lama ?: null, null, 'Hapus akun pengguna');
        header('Location: ../index.php?menu=user&status=hapus');
        exit;
    }

    header('Location: ../index.php?menu=user&status=gagal');
    exit;
}

header('Location: ../index.php?menu=user');
exit;
