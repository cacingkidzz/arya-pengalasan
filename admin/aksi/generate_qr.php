<?php
/**
 * Membuat QR Code untuk semeton yang belum punya file QR.
 *
 * Diperbarui 2026-11-12: sebelumnya hanya memproses baris dengan
 * `qr_code` kosong/NULL. Bila kolom sudah berisi nama file tetapi filenya
 * hilang (terhapus / gagal dibuat), QR tidak pernah dibuat ulang sehingga
 * kartu anggota untuk anggota itu tidak bisa dicetak.
 */
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bagian_data'], false);
kbap_require_csrf();

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../libs/phpqrcode/qrlib.php';

$qr_folder = __DIR__ . '/../qr_codes';
if (!is_dir($qr_folder)) {
    @mkdir($qr_folder, 0777, true);
}

$query  = 'SELECT id, user_id, qr_code FROM semeton ORDER BY id';
$result = mysqli_query($koneksi, $query);

$generated = 0;
$gagal     = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $id      = (int) $row['id'];
    $user_id = (string) $row['user_id'];

    // Normalkan nama file (sebagian baris lama menyimpan "../qr_codes/...").
    $qr_name = basename((string) $row['qr_code']);
    if ($qr_name === '' || $qr_name === '.') {
        $qr_name = $user_id . '.png';
    }
    $qr_file = $qr_folder . '/' . $qr_name;

    // Lewati bila file QR sudah ada.
    if (is_file($qr_file)) {
        continue;
    }

    QRcode::png('ID: ' . $user_id, $qr_file, QR_ECLEVEL_L, 10);

    if (!is_file($qr_file)) {
        $gagal++;
        continue;
    }

    // Simpan nama file ke database bila belum sesuai.
    if ((string) $row['qr_code'] !== $qr_name) {
        $stmt = $koneksi->prepare('UPDATE semeton SET qr_code = ? WHERE id = ?');
        $stmt->bind_param('si', $qr_name, $id);
        $stmt->execute();
        $stmt->close();
    }

    $generated++;
}

header('Location: ../index.php?menu=semeton&status=success&generated=' . $generated
    . ($gagal > 0 ? '&gagal=' . $gagal : ''));
exit;
