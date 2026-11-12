<?php
require_once __DIR__ . '/../security.php';
kbap_guard([], false);
include("../koneksi.php");

$id_piodalan = $_GET['id_piodalan'];

// Query mengambil data dari tabel transaksi berdasarkan id_piodalan
$query = "SELECT * FROM transaksi WHERE id_piodalan = '$id_piodalan' ORDER BY tanggal_transaksi DESC";
$result = mysqli_query($koneksi, $query);

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
}

header('Content-Type: application/json');
echo json_encode($data);
