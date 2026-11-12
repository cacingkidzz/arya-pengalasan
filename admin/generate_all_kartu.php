<?php
require_once __DIR__ . '/security.php';
kbap_guard(['admin', 'bagian_data'], false);
kbap_require_csrf();

// Jangan menampilkan warning/notice: endpoint ini diakhiri header(Location),
// sehingga keluaran apa pun akan menggagalkan redirect.
ini_set('display_errors', '0');

include("koneksi.php");

// Folder penyimpan hash data kartu (bukan data database).
$hash_dir = __DIR__ . '/kartu_anggota/.hash';
if (!is_dir($hash_dir)) {
    mkdir($hash_dir, 0777, true);
}

// Ambil semua semeton
$query = "SELECT s.*, w.nama_wilayah, sw.nama_sub 
          FROM semeton s
          LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
          LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub";
$result = $koneksi->query($query);

// Counter
$updated_count = 0;
$created_count = 0;
$gagal_count   = 0;
$gagal_nama    = [];

while ($semeton = $result->fetch_assoc()) {
    $user_id = $semeton['user_id'];
    $output_file = "kartu_anggota/kartu_" . $user_id . ".jpg";

    // Path QR Code. Sebagian baris lama menyimpan qr_code dengan awalan
    // folder (mis. "../qr_codes/APP00607.png"); basename() menormalkannya.
    $qr_name = basename((string) ($semeton['qr_code'] ?? ''));
    if ($qr_name === '' || $qr_name === '.') {
        $qr_name = $user_id . '.png';
    }
    $qr_code_path = "qr_codes/" . $qr_name;

    // Bila file QR hilang, buat ulang otomatis (isi sama dengan menu "QR Semua").
    if (!is_file($qr_code_path)) {
        if (!is_dir('qr_codes')) {
            @mkdir('qr_codes', 0777, true);
        }
        $qrlib = __DIR__ . '/libs/phpqrcode/qrlib.php';
        if (is_file($qrlib) && !class_exists('QRcode')) {
            // Library phpqrcode memicu E_DEPRECATED di PHP 8.2; jangan sampai
            // mengotori output (endpoint ini berakhir dengan header Location).
            $level_lama = error_reporting();
            error_reporting($level_lama & ~E_DEPRECATED);
            require_once $qrlib;
            error_reporting($level_lama);
        }
        if (class_exists('QRcode')) {
            QRcode::png('ID: ' . $user_id, $qr_code_path, QR_ECLEVEL_L, 10);
        }
    }

    if (!is_file($qr_code_path)) {
        $gagal_count++;
        $gagal_nama[] = $user_id;
        continue; // QR tetap tidak bisa dibuat -> lewati, tetapi dilaporkan
    }

    // Data yang digunakan untuk mendeteksi perubahan
    $data_string = json_encode([
        'nama' => $semeton['nama'],
        'user_id' => $user_id,
        'jenis_user' => $semeton['jenis_user'],
        'wilayah' => $semeton['nama_wilayah'],
        'sub_wilayah' => $semeton['nama_sub'],
    ]);
    $new_data_hash = md5($data_string);

    // Cek apakah kartu sudah ada
    $hash_file = $hash_dir . '/' . $user_id . '.md5';
    $is_new = !file_exists($output_file);
    $is_updated = false;

    // Bandingkan hash data tersimpan (sidecar), bukan md5 gambar.
    if (!$is_new) {
        $old_data_hash = is_file($hash_file) ? trim((string) file_get_contents($hash_file)) : '';
        if ($old_data_hash !== $new_data_hash) {
            $is_updated = true;
        }
    }

    // Jika kartu baru atau perlu diperbarui
    if ($is_new || $is_updated) {
        // Load gambar background
        $background = @imagecreatefrompng("dist/assets/img/kartu.png");
        $qr_image   = @imagecreatefrompng($qr_code_path);
        $font       = realpath("dist/font/arial.ttf");

        if (!$background || !$qr_image || $font === false) {
            if ($background) { imagedestroy($background); }
            if ($qr_image)   { imagedestroy($qr_image); }
            $gagal_count++;
            $gagal_nama[] = $user_id;
            continue; // aset/QR rusak -> lewati, tetapi dilaporkan
        }

        // Ambil ukuran asli background
        $width = imagesx($background);
        $height = imagesy($background);

        // Buat gambar baru dengan ukuran yang sama
        $image = imagecreatetruecolor($width, $height);

        // Aktifkan alpha blending & save alpha untuk transparansi (jika PNG ada transparansi)
        imagealphablending($image, false);
        imagesavealpha($image, true);

        // Salin background dengan ukuran yang sama
        imagecopyresampled($image, $background, 0, 0, 0, 0, $width, $height, $width, $height);

        // Warna teks
        $text_color = imagecolorallocate($image, 0, 0, 0); // Hitam

        // Tambahkan teks
        imagettftext($image, 23, 0, 380, 330, $text_color, $font, "KARTU ANGGOTA SEMETON");
        imagettftext($image, 18, 0, 380, 380, $text_color, $font, "Nama           : " . $semeton['nama']);
        imagettftext($image, 18, 0, 380, 410, $text_color, $font, "User ID        : " . $user_id);
        imagettftext($image, 18, 0, 380, 440, $text_color, $font, "Jenis            : " . ucfirst($semeton['jenis_user']));
        imagettftext($image, 18, 0, 380, 470, $text_color, $font, "Wilayah        : " . $semeton['nama_wilayah']);
        imagettftext($image, 18, 0, 380, 500, $text_color, $font, "Sub Wilayah : " . $semeton['nama_sub']);

        // Tambahkan QR Code
        imagecopyresampled($image, $qr_image, 30, 260, 0, 0, 300, 300, imagesx($qr_image), imagesy($qr_image));

        // Simpan sebagai JPG
        $tersimpan = imagejpeg($image, $output_file, 100);
        imagedestroy($image);
        imagedestroy($background);
        imagedestroy($qr_image);

        if (!$tersimpan || !is_file($output_file)) {
            $gagal_count++;
            $gagal_nama[] = $user_id;
            continue;
        }

        // Simpan hash data agar pengecekan berikutnya akurat.
        file_put_contents($hash_file, $new_data_hash);

        // Update counter
        if ($is_new) {
            $created_count++;
        } elseif ($is_updated) {
            $updated_count++;
        }
    }
}

// Redirect kembali dengan notifikasi jumlah kartu dibuat, diperbarui, dan gagal.
$gagal_param = $gagal_count > 0
    ? '&gagal=' . $gagal_count . '&gagal_id=' . urlencode(implode(',', array_slice($gagal_nama, 0, 10)))
    : '';
header("Location: index.php?menu=semeton&status=kartu&created=$created_count&updated=$updated_count" . $gagal_param);
exit;
