<?php
/**
 * security.php
 * Helper terpusat untuk autentikasi, otorisasi role, dan proteksi CSRF.
 *
 * Aturan pakai:
 *   require_once __DIR__ . '/security.php';
 *   kbap_guard(['admin', 'bendahara']);            // untuk endpoint tulis
 *   kbap_guard(['admin', 'bagian_data'], false);   // untuk endpoint baca saja
 */

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
    session_start();
}

/** Apakah user sudah login? */
function kbap_is_logged_in(): bool
{
    return isset($_SESSION['id_user']) && $_SESSION['id_user'] !== '';
}

/** Role user yang sedang login. */
function kbap_role(): string
{
    return $_SESSION['akses'] ?? '';
}

/** Token CSRF untuk sesi ini (dibuat otomatis bila belum ada). */
function kbap_csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input CSRF siap pakai untuk form. */
function kbap_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(kbap_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Ambil token dari POST, GET, atau header X-CSRF-Token. */
function kbap_request_csrf_token(): string
{
    if (!empty($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        return $_POST['csrf_token'];
    }
    if (!empty($_GET['csrf_token']) && is_string($_GET['csrf_token'])) {
        return $_GET['csrf_token'];
    }
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    return '';
}

/** Validasi token CSRF. */
function kbap_verify_csrf(): bool
{
    $token = kbap_request_csrf_token();
    return $token !== '' && hash_equals(kbap_csrf_token(), $token);
}

/** Kirim respons penolakan (JSON atau HTML) lalu berhenti. */
function kbap_deny(int $status, string $message, bool $json = false): void
{
    http_response_code($status);

    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($status === 401) {
        $script    = $_SERVER['SCRIPT_NAME'] ?? '';
        $pos       = strpos($script, '/admin/');
        $adminBase = $pos !== false ? substr($script, 0, $pos + 7) : '/admin/';
        header('Location: ' . $adminBase . 'login.php?pesan=belum_login');
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Akses Ditolak</title>'
        . '<link href="dist/vendor/bootstrap/5.3.3/bootstrap.min.css" rel="stylesheet"></head>'
        . '<body class="p-5"><div class="alert alert-danger"><strong>Akses ditolak.</strong><br>'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<a href="javascript:history.back()" class="btn btn-secondary">Kembali</a></body></html>';
    exit;
}

/**
 * Penjaga utama endpoint.
 *
 * @param string[] $roles  Role yang boleh mengakses (kosong = semua yang login).
 * @param bool     $csrf   Wajibkan token pada POST dan pada GET ber-parameter aksi.
 * @param bool     $json   Kirim error sebagai JSON (untuk endpoint AJAX).
 */
function kbap_guard(array $roles = [], bool $csrf = true, bool $json = false): void
{
    if (!kbap_is_logged_in()) {
        kbap_deny(401, 'Silakan login terlebih dahulu.', $json);
    }

    if (!empty($roles) && !in_array(kbap_role(), $roles, true)) {
        kbap_deny(403, 'Role Anda tidak memiliki izin untuk aksi ini.', $json);
    }

    if ($csrf) {
        $method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $butuhToken = ($method === 'POST') || isset($_GET['aksi']);
        if ($butuhToken && !kbap_verify_csrf()) {
            kbap_deny(419, 'Token keamanan (CSRF) tidak valid atau kedaluwarsa. Muat ulang halaman lalu coba lagi.', $json);
        }
    }
}

/** Paksa validasi CSRF untuk endpoint GET non-standar (mis. generate hutang). */
function kbap_require_csrf(bool $json = false): void
{
    if (!kbap_verify_csrf()) {
        kbap_deny(419, 'Token keamanan (CSRF) tidak valid atau kedaluwarsa.', $json);
    }
}

/**
 * Penjaga untuk file tampilan di admin/konten yang bisa dibuka langsung
 * (tanpa lewat router konten.php). Memastikan login + hak akses menu.
 */
function kbap_guard_menu(string $menu): void
{
    kbap_guard([], false);
    if (!in_array($menu, kbap_allowed_menus(kbap_role()), true)) {
        kbap_deny(403, 'Role Anda tidak memiliki izin membuka halaman ini.');
    }
}

/** Daftar menu yang boleh diakses tiap role. */
function kbap_allowed_menus(string $role): array
{
    $semua = [
        'home', 'user', 'wilayah', 'sub_wilayah', 'piodalan', 'semeton', 'pembayaran',
        'pembayaran_detail', 'rekap_urunan', 'rekap_filter', 'rekap_hari', 'rekap_bayar',
        'semeton_baru', 'rekap_bayar_piodalan', 'dana_punia', 'rekap_punia',
        'rekap_punia_hari_ini', 'rekap_punia_filter', 'kategori_pengeluaran', 'pengeluaran',
        'laporan_pengeluaran', 'detail_transaksi', 'rekap_piodalan_sinkron', 'double',
        'rekap_tunggakan', 'laporan_rekap_semeton', 'laporan_laba_rugi', 'audit_log',
        'laporan_rekap_odalan',
    ];

    switch ($role) {
        case 'admin':
            return $semua;
        case 'bendahara':
            return [
                'home', 'pembayaran', 'pembayaran_detail', 'rekap_urunan', 'rekap_filter',
                'rekap_hari', 'rekap_bayar', 'rekap_bayar_piodalan', 'dana_punia',
                'rekap_punia', 'rekap_punia_hari_ini', 'rekap_punia_filter',
                'pengeluaran', 'laporan_pengeluaran', 'detail_transaksi',
                'rekap_tunggakan', 'laporan_rekap_semeton', 'laporan_laba_rugi',
                'laporan_rekap_odalan',
            ];
        case 'bagian_data':
            return [
                'home', 'wilayah', 'sub_wilayah', 'piodalan', 'kategori_pengeluaran',
                'semeton_baru', 'semeton', 'rekap_urunan', 'rekap_filter', 'rekap_hari',
                'rekap_bayar', 'rekap_bayar_piodalan', 'laporan_pengeluaran',
            ];
        default:
            return ['home'];
    }
}

/**
 * Catat jejak audit ke tabel `audit_log`.
 *
 * Selalu "best effort": kegagalan mencatat TIDAK boleh menggagalkan
 * transaksi utama (cukup ditulis ke error_log).
 *
 * @param mysqli     $koneksi
 * @param string     $nama_tabel  Nama tabel yang diubah.
 * @param string     $aksi        INSERT|UPDATE|DELETE|CANCEL|LOGIN.
 * @param mixed      $id_record   Primary key baris terkait (opsional).
 * @param mixed      $data_lama   Data sebelum perubahan (array/objek/null).
 * @param mixed      $data_baru   Data sesudah perubahan (array/objek/null).
 * @param string     $keterangan  Ringkasan singkat.
 */
function kbap_audit_log(
    $koneksi,
    string $nama_tabel,
    string $aksi,
    $id_record = null,
    $data_lama = null,
    $data_baru = null,
    string $keterangan = ''
): void {
    try {
        if (!($koneksi instanceof mysqli)) {
            return;
        }

        $id_user  = isset($_SESSION['id_user']) ? (int) $_SESSION['id_user'] : null;
        $username = (string) ($_SESSION['user'] ?? $_SESSION['username'] ?? ($_SESSION['nama'] ?? ''));
        if ($username === '') {
            $username = null;
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            $ip = null;
        }

        $flag   = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $lama   = $data_lama === null ? null : json_encode($data_lama, $flag);
        $baru   = $data_baru === null ? null : json_encode($data_baru, $flag);
        $record = $id_record === null ? null : (string) $id_record;
        $ket    = mb_substr(trim($keterangan), 0, 255);

        $stmt = $koneksi->prepare(
            'INSERT INTO audit_log
               (nama_tabel, aksi, id_record, id_user, username, data_lama, data_baru, keterangan, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param(
            'sssisssss',
            $nama_tabel,
            $aksi,
            $record,
            $id_user,
            $username,
            $lama,
            $baru,
            $ket,
            $ip
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('kbap_audit_log gagal: ' . $e->getMessage());
    }
}
