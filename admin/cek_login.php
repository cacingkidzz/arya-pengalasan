<?php
/**
 * cek_login.php — verifikasi login.
 *
 * Mendukung dua format password:
 *  - hash modern (password_hash / bcrypt / argon2) → password_verify()
 *  - password lama yang masih plaintext → dibandingkan langsung (legacy)
 *
 * CATATAN: password lama sengaja TIDAK diubah otomatis ke hash di sini,
 * karena aturan proyek melarang perubahan data tanpa persetujuan.
 */

require_once __DIR__ . '/security.php';
include 'koneksi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

if (!kbap_verify_csrf()) {
    header('Location: login.php?pesan=gagal');
    exit;
}

$user = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
$pass = isset($_POST['password']) ? (string) $_POST['password'] : '';

if ($user === '' || $pass === '') {
    header('Location: login.php?pesan=gagal');
    exit;
}

$stmt = $koneksi->prepare('SELECT id_user, nama_user, username, password, akses FROM user WHERE username = ? LIMIT 1');
if (!$stmt) {
    header('Location: login.php?pesan=gagal');
    exit;
}

$stmt->bind_param('s', $user);
$stmt->execute();
$result = $stmt->get_result();
$row    = $result->fetch_assoc();
$stmt->close();

$valid = false;
if ($row) {
    $stored = (string) $row['password'];

    // Password hash modern
    if (password_verify($pass, $stored)) {
        $valid = true;
    } else {
        // Kompatibilitas password lama (plaintext)
        $info = password_get_info($stored);
        if (($info['algoName'] ?? 'unknown') === 'unknown' && hash_equals($stored, $pass)) {
            $valid = true;
        }
    }
}

if (!$valid) {
    header('Location: login.php?pesan=gagal');
    exit;
}

// Cegah session fixation
session_regenerate_id(true);

$_SESSION['id_user'] = $row['id_user'];
$_SESSION['nama']    = $row['nama_user'];
$_SESSION['user']    = $row['username'];
$_SESSION['akses']   = $row['akses'];

kbap_audit_log($koneksi, 'user', 'LOGIN', $row['id_user'], null, [
    'username' => $row['username'],
    'akses'    => $row['akses'],
], 'Login berhasil');

header('Location: index.php');
exit;
