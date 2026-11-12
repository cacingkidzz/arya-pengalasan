<?php
require_once __DIR__ . '/security.php';
kbap_guard([], false);

include __DIR__ . '/koneksi.php';

$id_semeton = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id_semeton <= 0) {
    http_response_code(400);
    exit('ID semeton tidak valid.');
}

$stmt = $koneksi->prepare(
    "SELECT s.*, w.nama_wilayah, sw.nama_sub
     FROM semeton s
     LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
     LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
     WHERE s.id = ? LIMIT 1"
);
$stmt->bind_param('i', $id_semeton);
$stmt->execute();
$semeton = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$semeton) {
    http_response_code(404);
    exit('Data tidak ditemukan!');
}

$nama_wilayah = $semeton['nama_wilayah'] ?? 'Tidak ada';
$nama_sub     = $semeton['nama_sub'] ?? 'Tidak ada';
$user_id      = $semeton['user_id'];

// Buat kanvas kartu
$width  = 600;
$height = 300;
$image  = imagecreatetruecolor($width, $height);

$background   = imagecolorallocate($image, 255, 255, 255);
$text_color   = imagecolorallocate($image, 0, 0, 0);
$border_color = imagecolorallocate($image, 30, 60, 114);

imagefilledrectangle($image, 0, 0, $width, $height, $background);
imagerectangle($image, 5, 5, $width - 5, $height - 5, $border_color);

$font = __DIR__ . '/dist/font/arial.ttf';
$font_ok = is_file($font);

if ($font_ok) {
    imagettftext($image, 18, 0, 230, 50, $text_color, $font, 'KARTU ANGGOTA SEMETON');
    imagettftext($image, 12, 0, 230, 95, $text_color, $font, 'Nama         : ' . $semeton['nama']);
    imagettftext($image, 12, 0, 230, 135, $text_color, $font, 'User ID      : ' . $user_id);
    imagettftext($image, 12, 0, 230, 175, $text_color, $font, 'Jenis        : ' . ucfirst((string) $semeton['jenis_user']));
    imagettftext($image, 12, 0, 230, 215, $text_color, $font, 'Wilayah      : ' . $nama_wilayah);
    imagettftext($image, 12, 0, 230, 255, $text_color, $font, 'Sub Wilayah  : ' . $nama_sub);
} else {
    // Fallback bila font tidak tersedia.
    imagestring($image, 5, 230, 45, 'KARTU ANGGOTA SEMETON', $text_color);
    imagestring($image, 4, 230, 90, 'Nama        : ' . $semeton['nama'], $text_color);
    imagestring($image, 4, 230, 130, 'User ID     : ' . $user_id, $text_color);
    imagestring($image, 4, 230, 170, 'Jenis       : ' . ucfirst((string) $semeton['jenis_user']), $text_color);
    imagestring($image, 4, 230, 210, 'Wilayah     : ' . $nama_wilayah, $text_color);
    imagestring($image, 4, 230, 250, 'Sub Wilayah : ' . $nama_sub, $text_color);
}

// Tempelkan QR Code (jika ada).
// basename() menormalkan baris lama yang menyimpan qr_code dengan awalan
// folder (mis. "../qr_codes/APP00607.png").
$qr_name = basename((string) ($semeton['qr_code'] ?? ''));
$qr_code_path = __DIR__ . '/qr_codes/' . $qr_name;
if ($qr_name !== '' && is_file($qr_code_path)) {
    $qr_image = imagecreatefrompng($qr_code_path);
    if ($qr_image) {
        imagecopyresampled($image, $qr_image, 25, 60, 0, 0, 180, 180, imagesx($qr_image), imagesy($qr_image));
        imagedestroy($qr_image);
    }
}

// Simpan + tampilkan
$kartu_dir = __DIR__ . '/kartu_anggota';
if (!is_dir($kartu_dir)) {
    mkdir($kartu_dir, 0777, true);
}
$output_file = $kartu_dir . '/kartu_' . $user_id . '.jpg';
imagejpeg($image, $output_file, 95);

header('Content-Type: image/jpeg');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
imagejpeg($image, null, 95);
imagedestroy($image);
