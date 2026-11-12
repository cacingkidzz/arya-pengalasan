<?php
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bendahara'], false);

require_once __DIR__ . '/../koneksi.php';

if (!isset($_GET['id'])) {
    die("ID tidak valid");
}

$id = intval($_GET['id']);
$query = "SELECT nota FROM pengeluaran WHERE id_pengeluaran = $id";
$result = mysqli_query($koneksi, $query);

if (!$result || mysqli_num_rows($result) == 0) {
    die("Nota tidak ditemukan");
}

$row = mysqli_fetch_assoc($result);
$nota = $row['nota'];
$path = "../uploads/nota/" . $nota;

if (!file_exists($path)) {
    die("File tidak ditemukan");
}

header("Content-Type: image/jpeg"); // Sesuaikan dengan format gambar
readfile($path);
exit;
