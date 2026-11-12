<?php
require_once __DIR__ . '/../security.php';
include '../koneksi.php';

kbap_guard(['admin', 'bendahara']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php?menu=pembayaran');
    exit;
}

$id_pembayaran = (int) ($_POST['id_pembayaran'] ?? 0);
$tgl_bayar     = trim((string) ($_POST['tgl_bayar'] ?? ''));
$metode        = (string) ($_POST['metode_pembayaran'] ?? '');
$metode_valid  = ['tunai', 'transfer', 'QRIS'];

if ($id_pembayaran <= 0 || !in_array($metode, $metode_valid, true)) {
    echo "<script>alert('Data edit pembayaran tidak valid!'); window.history.back();</script>";
    exit;
}

$tgl_bayar = str_replace('T', ' ', $tgl_bayar);
if ($tgl_bayar === '') {
    $tgl_bayar = date('Y-m-d H:i:s');
} elseif (strlen($tgl_bayar) === 16) {
    $tgl_bayar .= ':00';
}
if (!strtotime($tgl_bayar)) {
    echo "<script>alert('Tanggal pembayaran tidak valid!'); window.history.back();</script>";
    exit;
}

// Pastikan pembayaran ada, sekaligus ambil user_id untuk redirect + nilai lama untuk audit.
$stmt = $koneksi->prepare(
    "SELECT s.user_id, p.id_piodalan, p.jumlah_bayar, p.tanggal_bayar, p.metode_pembayaran
     FROM pembayaran p
     JOIN semeton s ON s.id = p.id_semeton
     WHERE p.id_pembayaran = ? AND p.dibatalkan_at IS NULL LIMIT 1"
);
$stmt->bind_param('i', $id_pembayaran);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo "<script>alert('Pembayaran tidak ditemukan!'); window.history.back();</script>";
    exit;
}

$stmt = $koneksi->prepare(
    'UPDATE pembayaran SET tanggal_bayar = ?, metode_pembayaran = ? WHERE id_pembayaran = ?'
);
$stmt->bind_param('ssi', $tgl_bayar, $metode, $id_pembayaran);

if ($stmt->execute()) {
    $stmt->close();
    kbap_audit_log($koneksi, 'pembayaran', 'UPDATE', $id_pembayaran, [
        'tanggal_bayar'     => $row['tanggal_bayar'],
        'metode_pembayaran' => $row['metode_pembayaran'],
    ], [
        'tanggal_bayar'     => $tgl_bayar,
        'metode_pembayaran' => $metode,
    ], 'Ubah tanggal/metode pembayaran id ' . $id_pembayaran);
    header('Location: ../index.php?menu=pembayaran_detail&id=' . urlencode((string) $row['user_id']) . '&status=edit');
    exit;
}

$stmt->close();
echo "<script>alert('Gagal menyimpan perubahan pembayaran!'); window.history.back();</script>";
