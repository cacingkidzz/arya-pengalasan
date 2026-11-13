<?php
require_once __DIR__ . '/../security.php';
kbap_guard([], false);
include("../koneksi.php");

$id_piodalan = $_GET['id_piodalan'];
$query = "SELECT p.*, k.nama_kategori 
          FROM pengeluaran p
          JOIN kategori_pengeluaran k ON p.id_kategori = k.id_kategori
          WHERE p.id_piodalan = '$id_piodalan'";

$result = mysqli_query($koneksi, $query);
$data = [];

while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
}

echo json_encode($data);
