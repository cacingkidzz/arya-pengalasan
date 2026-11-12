<?php
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bendahara']);
include("../koneksi.php");

function compressImage($source, $destination, $quality)
{
    $info = getimagesize($source);
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
        imagejpeg($image, $destination, $quality);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
        imagejpeg($image, $destination, $quality);
    }
    return $destination;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($_POST['aksi'] == "tambah") {
        $id_piodalan = $koneksi->real_escape_string($_POST['id_piodalan']);
        $id_kategori = $koneksi->real_escape_string($_POST['id_kategori']);
        $nama_pengeluaran = $koneksi->real_escape_string($_POST['nama_pengeluaran']);
        $keterangan = $koneksi->real_escape_string($_POST['keterangan']);
        $jumlah = $koneksi->real_escape_string($_POST['jumlah']);
        $tanggal_pengeluaran = $koneksi->real_escape_string($_POST['tanggal_pengeluaran']);

        $nota_path = "";
        if (!empty($_FILES['nota']['name'])) {
            $nota_name = time() . '_' . $_FILES['nota']['name'];
            $nota_tmp = $_FILES['nota']['tmp_name'];
            $nota_dest = "../uploads/nota/" . $nota_name;
            compressImage($nota_tmp, $nota_dest, 75);
            $nota_path = $nota_name;
        }

        $sql = "INSERT INTO pengeluaran (id_piodalan, id_kategori, nama_pengeluaran, keterangan, jumlah, nota, tanggal_pengeluaran) 
                VALUES ('$id_piodalan', '$id_kategori', '$nama_pengeluaran', '$keterangan', '$jumlah', '$nota_path', '$tanggal_pengeluaran')";

        if ($koneksi->query($sql) === TRUE) {
            kbap_audit_log($koneksi, 'pengeluaran', 'INSERT', $koneksi->insert_id, null, [
                'id_piodalan'         => (int) $_POST['id_piodalan'],
                'id_kategori'         => (int) $_POST['id_kategori'],
                'nama_pengeluaran'    => $_POST['nama_pengeluaran'],
                'jumlah'              => (float) $_POST['jumlah'],
                'tanggal_pengeluaran' => $_POST['tanggal_pengeluaran'],
                'nota'                => $nota_path,
            ], 'Tambah pengeluaran ' . $_POST['nama_pengeluaran']);
            header("location:../index.php?menu=pengeluaran&status=sukses");
            exit();
        } else {
            header("location:../index.php?menu=pengeluaran&status=gagal");
            exit();
        }
    }

    if ($_POST['aksi'] == "edit") {
        $id_pengeluaran = (int) $_POST['id_pengeluaran'];
        $nama_pengeluaran = $koneksi->real_escape_string($_POST['nama_pengeluaran']);
        $id_kategori = (int) $_POST['id_kategori'];
        $jumlah = (float) $_POST['jumlah'];
        $keterangan = $koneksi->real_escape_string($_POST['keterangan']);
        $tanggal_pengeluaran = $koneksi->real_escape_string($_POST['tanggal_pengeluaran']);

        if (!$id_pengeluaran) {
            die("❌ ID Pengeluaran tidak ditemukan!");
        }

        // Simpan nilai lama untuk jejak audit
        $data_lama = null;
        if ($stmt_lama = $koneksi->prepare('SELECT id_piodalan, id_kategori, nama_pengeluaran, jumlah, tanggal_pengeluaran, nota FROM pengeluaran WHERE id_pengeluaran = ? LIMIT 1')) {
            $stmt_lama->bind_param('i', $id_pengeluaran);
            $stmt_lama->execute();
            $data_lama = $stmt_lama->get_result()->fetch_assoc();
            $stmt_lama->close();
        }

        if (!empty($_FILES['nota']['name']) && $_FILES['nota']['size'] > 0) {
            $nota = time() . '_' . $_FILES['nota']['name'];
            $target_file = "../uploads/nota/" . basename($nota);

            if (move_uploaded_file($_FILES["nota"]["tmp_name"], $target_file)) {
                $query_debug = "UPDATE pengeluaran SET 
                nama_pengeluaran = '$nama_pengeluaran', 
                id_kategori = $id_kategori, 
                jumlah = $jumlah, 
                keterangan = '$keterangan', 
                tanggal_pengeluaran = '$tanggal_pengeluaran',
                nota = '$nota' 
            WHERE id_pengeluaran = $id_pengeluaran";

                if (!mysqli_query($koneksi, $query_debug)) {
                    die("❌ Error saat update: " . mysqli_error($koneksi));
                }
            } else {
                die("❌ Gagal mengupload file nota.");
            }
        } else {
            $query_debug = "UPDATE pengeluaran SET 
            nama_pengeluaran = '$nama_pengeluaran', 
            id_kategori = $id_kategori, 
            jumlah = $jumlah, 
            keterangan = '$keterangan', 
            tanggal_pengeluaran = '$tanggal_pengeluaran' 
        WHERE id_pengeluaran = $id_pengeluaran";

            if (!mysqli_query($koneksi, $query_debug)) {
                die("❌ Error saat update: " . mysqli_error($koneksi));
            }
        }

        kbap_audit_log($koneksi, 'pengeluaran', 'UPDATE', $id_pengeluaran, $data_lama, [
            'id_piodalan'         => isset($_POST['id_piodalan']) ? (int) $_POST['id_piodalan'] : ($data_lama['id_piodalan'] ?? null),
            'id_kategori'         => $id_kategori,
            'nama_pengeluaran'    => $_POST['nama_pengeluaran'],
            'jumlah'              => $jumlah,
            'tanggal_pengeluaran' => $_POST['tanggal_pengeluaran'],
        ], 'Ubah pengeluaran id ' . $id_pengeluaran);

        header("location:../index.php?menu=pengeluaran&status=edit");
        exit;
    }
}




if ($_SERVER["REQUEST_METHOD"] === "GET") {
    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];

        // Pastikan ID tidak kosong atau nol
        if ($id == 0) {
            die("❌ ID pengeluaran tidak valid!");
        }

        // Ambil data nota sebelum menghapus
        $query = "SELECT nota, id_piodalan, nama_pengeluaran, jumlah, tanggal_pengeluaran FROM pengeluaran WHERE id_pengeluaran = '$id'";
        $result = mysqli_query($koneksi, $query);

        if (!$result) {
            die("❌ Error mengambil data nota: " . mysqli_error($koneksi));
        }

        $row = mysqli_fetch_assoc($result);

        if ($row && !empty($row['nota'])) {
            $nota_path = "../uploads/nota/" . $row['nota'];

            // Pastikan file ada sebelum menghapus
            if (file_exists($nota_path)) {
                unlink($nota_path);
            }
        }

        // Hapus data dari database
        $delete_query = "DELETE FROM pengeluaran WHERE id_pengeluaran = '$id'";

        if (mysqli_query($koneksi, $delete_query)) {
            if (mysqli_affected_rows($koneksi) > 0) {
                kbap_audit_log($koneksi, 'pengeluaran', 'DELETE', $id, $row ?: null, null,
                    'Hapus pengeluaran id ' . $id . ($row ? ' (' . $row['nama_pengeluaran'] . ')' : ''));
            }
            header("Location: ../index.php?menu=pengeluaran&status=hapus");
            exit();
        } else {
            die("❌ Error menghapus data: " . mysqli_error($koneksi));
        }
    } else {
        die("❌ Parameter ID tidak ditemukan!");
    }
}
