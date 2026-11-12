<?php
/**
 * SHIM REDIRECT (2026-11-12)
 *
 * Halaman cetak lama (`cetak_laporan.php`) hanya membaca tabel `pengeluaran`
 * (model 2025) dan punya beberapa bug: `getElementById(" tanggal")` (ada spasi)
 * sehingga memicu TypeError, tanggal tanda tangan hardcoded "9 Maret 2025",
 * serta mencetak otomatis saat dibuka.
 *
 * Penggantinya: `admin/aksi/cetak_pdf.php`, yang mencetak **kedua model data**
 * (pengeluaran lama + transaksi/detail_transaksi baru) dengan filter odalan
 * yang sama. File ini dipertahankan sebagai shim agar tautan/bookmark lama
 * tetap berfungsi, bukan dihapus.
 */
require_once __DIR__ . '/../security.php';
kbap_guard([], false); // cukup login; hak akses dicek oleh halaman tujuan

$id_piodalan = (isset($_GET['id_odalan']) && is_scalar($_GET['id_odalan'])) ? (int) $_GET['id_odalan'] : 0;

$tujuan = '../aksi/cetak_pdf.php';
if ($id_piodalan > 0) {
    $tujuan .= '?id_odalan=' . $id_piodalan;
}

header('Location: ' . $tujuan, true, 302);
exit;
