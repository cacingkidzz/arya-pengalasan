<?php
require_once __DIR__ . '/../security.php';
kbap_guard([], false);
include("../koneksi.php");

$id_piodalan = $_GET['id_piodalan'];

$query = "SELECT p.*, k.nama_kategori, DATE(p.tanggal_pengeluaran) AS tanggal
          FROM pengeluaran p
          LEFT JOIN kategori_pengeluaran k ON p.id_kategori = k.id_kategori
          WHERE p.id_piodalan = '$id_piodalan'
          ORDER BY p.tanggal_pengeluaran ASC";

$result = mysqli_query($koneksi, $query);
$data = [];
$rekap_per_tanggal = [];
$total_pengeluaran = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $tanggal = $row['tanggal'];
    $jumlah = floatval($row['jumlah']);

    if (!isset($rekap_per_tanggal[$tanggal])) {
        $rekap_per_tanggal[$tanggal] = [
            'items' => [],
            'total' => 0
        ];
    }

    $rekap_per_tanggal[$tanggal]['items'][] = $row;
    $rekap_per_tanggal[$tanggal]['total'] += $jumlah;

    $total_pengeluaran += $jumlah;
}

// Jika tidak ada data, kirim array kosong
if (empty($rekap_per_tanggal)) {
    echo json_encode(['rekap_per_tanggal' => [], 'total_pengeluaran' => 0]);
    exit;
}

echo json_encode([
    'rekap_per_tanggal' => $rekap_per_tanggal,
    'total_pengeluaran' => $total_pengeluaran
]);
