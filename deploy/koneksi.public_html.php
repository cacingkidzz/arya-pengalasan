<?php
// =====================================================================
// TEMPLATE koneksi database untuk hosting (`public_html`).
//
// CARA PAKAI
//   A. Otomatis (disarankan)
//      Jalankan `deploy/buat-paket.ps1` dengan parameter kredensial:
//        .\deploy\buat-paket.ps1 -DbUser "namauser_dbuser" `
//            -DbPass "password_db" -DbName "namauser_kbap"
//      Skrip akan menaruh hasilnya sebagai `admin/koneksi.php` di paket ZIP.
//
//   B. Manual
//      1. Salin file ini ke `admin/koneksi.php` di server.
//      2. Ganti __DB_HOST__, __DB_USER__, __DB_PASS__, __DB_NAME__.
//
// PENTING
//   - Jangan pernah meng-commit `admin/koneksi.php` (sudah di .gitignore).
//   - Jangan menaruh kredensial di folder yang bisa diunduh publik.
//   - Setelah diubah, file ini TIDAK boleh diakses langsung lewat browser
//     (sudah diblokir oleh .htaccess di public_html).
//
// Urutan prioritas kredensial:
//   1. Environment variable `KBAP_DB_USER` (+ KBAP_DB_PASS, KBAP_DB_HOST,
//      KBAP_DB_NAME) -> dipakai untuk uji/CLI.
//   2. File `admin/koneksi.local.php` -> HANYA dibaca di localhost/CLI,
//      sehingga aman walau tidak sengaja ikut terunggah.
//   3. Nilai default di bawah -> dipakai di produksi.
// =====================================================================

// Tolak akses langsung (file ini hanya untuk di-include).
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit('Not found');
}

$host     = "__DB_HOST__";
$username = "__DB_USER__";
$password = "__DB_PASS__";
$database = "__DB_NAME__";

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
    // "@" meredam PHP Warning saat placeholder __DB_HOST__ belum diisi
    // (mis. paket baru diunggah). Kegagalan tetap ditangani catch di bawah.
    $koneksi = @new mysqli($host, $username, $password, $database);
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
