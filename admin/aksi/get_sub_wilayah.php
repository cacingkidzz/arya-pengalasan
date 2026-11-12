<?php
require_once __DIR__ . '/../security.php';
kbap_guard([], false);
include("../koneksi.php");

if (isset($_POST['id_wilayah'])) {
    $id_wilayah = $_POST['id_wilayah'];

    // Debugging
    error_log("ID Wilayah diterima: " . $id_wilayah);

    $query = "SELECT * FROM sub_wilayah WHERE id_wilayah = '$id_wilayah'";
    $result = mysqli_query($koneksi, $query);

    if (!$result) {
        die("Query Error: " . mysqli_error($koneksi));
    }

    if (mysqli_num_rows($result) > 0) {
        echo '<option value="">-- Pilih Sub Wilayah --</option>';
        while ($row = mysqli_fetch_assoc($result)) {
            echo '<option value="' . $row['id_sub'] . '">' . $row['nama_sub'] . '</option>';
        }
    } else {
        echo '<option value="">Sub Wilayah Tidak Ditemukan</option>';
    }
}
