<?php
/**
 * Endpoint dropdown sub wilayah (dipakai halaman publik lama).
 *
 * Diperkeras 2026-11-13: parameter wajib skalar + bilangan bulat, dan
 * nama sub wilayah di-escape agar tidak bisa menyisipkan HTML.
 */
require_once __DIR__ . '/admin/koneksi.php';

if (isset($_POST['id_wilayah']) && is_scalar($_POST['id_wilayah'])) {
    $id_wilayah = (int) $_POST['id_wilayah'];

    $stmt = $koneksi->prepare('SELECT id_sub, nama_sub FROM sub_wilayah WHERE id_wilayah = ? ORDER BY nama_sub');
    $stmt->bind_param('i', $id_wilayah);
    $stmt->execute();
    $result = $stmt->get_result();

    echo "<option value=''>Pilih Sub Wilayah</option>";
    while ($row = $result->fetch_assoc()) {
        echo "<option value='" . (int) $row['id_sub'] . "'>"
            . htmlspecialchars((string) $row['nama_sub'], ENT_QUOTES, 'UTF-8')
            . "</option>";
    }
    $stmt->close();
}
