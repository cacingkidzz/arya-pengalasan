<?php
// =====================================================================
// CONTOH konfigurasi koneksi database.
//
// Cara pakai:
//   1. Salin file ini menjadi `admin/koneksi.php`.
//   2. Ganti $username / $password / $database sesuai server Anda.
//   3. Jangan pernah meng-commit `admin/koneksi.php` (sudah di .gitignore).
//
// Urutan prioritas kredensial yang dipakai `koneksi.php`:
//   1. Environment variable `KBAP_DB_USER` (+ opsional KBAP_DB_PASS,
//      KBAP_DB_HOST, KBAP_DB_NAME) → dipakai untuk uji/CLI.
//   2. File `admin/koneksi.local.php` (opsional) → HANYA dibaca bila
//      diakses dari localhost/127.0.0.1 atau lewat CLI.
//   3. Nilai default di bawah → dipakai di server produksi.
// =====================================================================

$host     = "localhost";
$username = "nama_user_database";
$password = "password_database";
$database = "nama_database";

/** Ambil nilai environment dari getenv() atau $_SERVER (Apache SetEnv). */
if (!function_exists('kbap_env')) {
    function kbap_env(string $nama)
    {
        $nilai = getenv($nama);
        if ($nilai !== false && $nilai !== '') {
            return $nilai;
        }
        foreach ([$_SERVER, $_ENV] as $sumber) {
            if (isset($sumber[$nama]) && $sumber[$nama] !== '') {
                return (string) $sumber[$nama];
            }
        }
        return false;
    }
}

/** True bila aplikasi sedang diakses dari localhost atau lewat CLI. */
if (!function_exists('kbap_lokal_aktif')) {
    function kbap_lokal_aktif(?string $nama_server = null, ?string $sapi = null): bool
    {
        $nama_server = $nama_server ?? ($_SERVER['SERVER_NAME'] ?? '');
        $sapi        = $sapi ?? PHP_SAPI;

        if (in_array($nama_server, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return true;
        }
        return in_array($sapi, ['cli', 'cli-server'], true);
    }
}

$env_user = kbap_env('KBAP_DB_USER');
if ($env_user !== false) {
    // Mode environment (uji lokal / CLI).
    $host     = kbap_env('KBAP_DB_HOST') ?: 'localhost';
    $username = $env_user;
    $env_pass = kbap_env('KBAP_DB_PASS');
    $password = ($env_pass === false) ? '' : $env_pass;
    $database = kbap_env('KBAP_DB_NAME') ?: 'kbap';
} elseif (kbap_lokal_aktif() && is_file(__DIR__ . '/koneksi.local.php')) {
    // Override lokal: hanya di localhost/CLI, tidak pernah di produksi.
    include __DIR__ . '/koneksi.local.php';
}

// Membuat koneksi. Kegagalan ditangani agar kredensial/stack trace
// tidak pernah tampil ke pengguna.
try {
    $koneksi = new mysqli($host, $username, $password, $database);
    if ($koneksi->connect_error) {
        throw new mysqli_sql_exception($koneksi->connect_error);
    }
    $koneksi->set_charset('utf8mb4');
} catch (Throwable $e) {
    error_log('KBAP koneksi database gagal: ' . $e->getMessage());
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    exit('Koneksi database gagal. Periksa konfigurasi koneksi atau hubungi administrator.');
}
