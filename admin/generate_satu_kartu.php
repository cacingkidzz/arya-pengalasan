<?php
/**
 * Generate kartu anggota untuk satu semeton.
 *
 * Dipanggil dari tombol "Buat Kartu" / "Refresh Kartu" di `konten/semeton.php`.
 * Mengembalikan JSON agar UI bisa menampilkan pesan gagal yang sebenarnya
 * (sebelumnya selalu dianggap berhasil walau server gagal).
 */
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/koneksi.php';

kbap_guard(['admin', 'bagian_data'], false);
kbap_require_csrf();

// Endpoint ini mengembalikan JSON; warning/notice apa pun akan merusaknya.
ini_set('display_errors', '0');

/** Kirim hasil sebagai JSON lalu berhenti. */
$kartu_json = static function (string $status, string $message, array $extra = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(['status' => $status, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
};

if (!isset($_GET['user_id']) || !is_scalar($_GET['user_id'])) {
    $kartu_json('gagal', 'User ID tidak diberikan.');
}

$user_id = trim((string) $_GET['user_id']);
if ($user_id === '') {
    $kartu_json('gagal', 'User ID tidak diberikan.');
}

// GD wajib ada untuk membuat gambar.
if (!function_exists('imagecreatetruecolor')) {
    $kartu_json('gagal', 'Ekstensi PHP GD tidak aktif. Hubungi administrator.');
}

// Data terbaru dari semeton.
$stmt = $koneksi->prepare(
    'SELECT s.*, w.nama_wilayah, sw.nama_sub
     FROM semeton s
     LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
     LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
     WHERE s.user_id = ? LIMIT 1'
);
$stmt->bind_param('s', $user_id);
$stmt->execute();
$semeton = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$semeton) {
    $kartu_json('gagal', 'Data semeton tidak ditemukan.');
}

// --- QR Code ---------------------------------------------------------------
// Sebagian baris lama menyimpan qr_code dengan awalan folder (mis.
// "../qr_codes/APP00607.png"). basename() menormalkan semuanya.
$qr_name = basename((string) ($semeton['qr_code'] ?? ''));
if ($qr_name === '' || $qr_name === '.') {
    $qr_name = $user_id . '.png';
}
$qr_dir  = __DIR__ . '/qr_codes';
$qr_path = $qr_dir . '/' . $qr_name;

// Bila file QR hilang (pernah terhapus / tidak pernah dibuat), buat ulang
// otomatis. Isinya sama dengan yang dihasilkan menu "QR Semua"
// (`ID: APPxxxxx`) sehingga scanner di halaman pembayaran tetap bekerja.
if (!is_file($qr_path)) {
    if (!is_dir($qr_dir)) {
        @mkdir($qr_dir, 0777, true);
    }
    $qrlib = __DIR__ . '/libs/phpqrcode/qrlib.php';
    if (!is_file($qrlib)) {
        $kartu_json('gagal', 'Library QR Code tidak ditemukan (libs/phpqrcode).');
    }
    if (!class_exists('QRcode')) {
        // Library phpqrcode memicu E_DEPRECATED di PHP 8.2; jangan sampai
        // mengotori respons JSON.
        $level_lama = error_reporting();
        error_reporting($level_lama & ~E_DEPRECATED);
        require_once $qrlib;
        error_reporting($level_lama);
    }
    if (!class_exists('QRcode')) {
        $kartu_json('gagal', 'Library QR Code gagal dimuat.');
    }
    QRcode::png('ID: ' . $user_id, $qr_path, QR_ECLEVEL_L, 10);
    if (!is_file($qr_path)) {
        $kartu_json('gagal', 'QR Code gagal dibuat.');
    }
}

// --- Aset gambar -----------------------------------------------------------
$bg_path   = __DIR__ . '/dist/assets/img/kartu.png';
$font_path = __DIR__ . '/dist/font/arial.ttf';
if (!is_file($bg_path)) {
    $kartu_json('gagal', 'Background kartu tidak ditemukan (dist/assets/img/kartu.png).');
}
if (!is_file($font_path)) {
    $kartu_json('gagal', 'Font kartu tidak ditemukan (dist/font/arial.ttf).');
}

$background = @imagecreatefrompng($bg_path);
if (!$background) {
    $kartu_json('gagal', 'Background kartu tidak bisa dibaca.');
}

$qr_image = @imagecreatefrompng($qr_path);
if (!$qr_image) {
    imagedestroy($background);
    $kartu_json('gagal', 'File QR Code tidak bisa dibaca.');
}

$width  = imagesx($background);
$height = imagesy($background);

$image = imagecreatetruecolor($width, $height);
imagealphablending($image, false);
imagesavealpha($image, true);
imagecopyresampled($image, $background, 0, 0, 0, 0, $width, $height, $width, $height);

$text_color = imagecolorallocate($image, 0, 0, 0);
$nama       = trim((string) ($semeton['nama'] ?? '')) ?: '-';
$wilayah    = trim((string) ($semeton['nama_wilayah'] ?? '')) ?: '-';
$sub        = trim((string) ($semeton['nama_sub'] ?? '')) ?: '-';

imagettftext($image, 23, 0, 380, 330, $text_color, $font_path, 'KARTU ANGGOTA SEMETON');
imagettftext($image, 18, 0, 380, 380, $text_color, $font_path, 'Nama           : ' . $nama);
imagettftext($image, 18, 0, 380, 410, $text_color, $font_path, 'User ID        : ' . $user_id);
imagettftext($image, 18, 0, 380, 440, $text_color, $font_path, 'Jenis            : ' . ucfirst((string) $semeton['jenis_user']));
imagettftext($image, 18, 0, 380, 470, $text_color, $font_path, 'Wilayah        : ' . $wilayah);
imagettftext($image, 18, 0, 380, 500, $text_color, $font_path, 'Sub Wilayah : ' . $sub);

imagecopyresampled($image, $qr_image, 30, 260, 0, 0, 300, 300, imagesx($qr_image), imagesy($qr_image));

// --- Simpan ----------------------------------------------------------------
$output_file = __DIR__ . '/kartu_anggota/kartu_' . $user_id . '.jpg';
if (!is_dir(dirname($output_file))) {
    @mkdir(dirname($output_file), 0777, true);
}

// Tulis ke file sementara lalu ganti, agar kartu lama tidak rusak bila gagal.
$tmp_file = $output_file . '.tmp';
$tersimpan = imagejpeg($image, $tmp_file, 100);

imagedestroy($image);
imagedestroy($background);
imagedestroy($qr_image);

if (!$tersimpan || !is_file($tmp_file)) {
    @unlink($tmp_file);
    $kartu_json('gagal', 'Kartu gagal disimpan (periksa izin tulis folder kartu_anggota).');
}

if (is_file($output_file)) {
    @unlink($output_file);
}
if (!@rename($tmp_file, $output_file)) {
    @unlink($tmp_file);
    $kartu_json('gagal', 'Kartu gagal dipindahkan ke folder kartu_anggota.');
}

$kartu_json('sukses', 'Kartu semeton berhasil dibuat.', [
    'user_id' => $user_id,
    'file'    => 'kartu_anggota/kartu_' . $user_id . '.jpg',
    'url'     => 'kartu_anggota/kartu_' . rawurlencode($user_id) . '.jpg?t=' . time(),
]);
