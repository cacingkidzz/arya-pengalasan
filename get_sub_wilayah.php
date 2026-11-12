<?php
include 'admin/koneksi.php';

if (isset($_POST['id_wilayah'])) {
    $id_wilayah = $_POST['id_wilayah'];
    $query = "SELECT id_sub, nama_sub FROM sub_wilayah WHERE id_wilayah = ?";
    $stmt = $koneksi->prepare($query);
    $stmt->bind_param("i", $id_wilayah);
    $stmt->execute();
    $result = $stmt->get_result();

    echo "<option value=''>Pilih Sub Wilayah</option>";
    while ($row = $result->fetch_assoc()) {
        echo "<option value='" . $row['id_sub'] . "'>" . $row['nama_sub'] . "</option>";
    }
    $stmt->close();
}
