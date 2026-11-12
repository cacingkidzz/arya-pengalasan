<?php
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bendahara']);
require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// DIPERKERAS (2026-11-09)
// - Semua query tulis memakai prepared statement (dulu INSERT/UPDATE
//   menyusun SQL dari nilai mentah: `$satuan`, `$harga`, `$jumlah`,
//   `$total`, dan `$id_detail` disisipkan langsung → SQL injection).
// - Validasi: id_transaksi harus ada di tabel `transaksi`, nama barang
//   wajib, harga >= 0, jumlah > 0, total dihitung di server
//   (round(harga * jumlah, 2)), satuan dipotong 15 karakter (varchar(15)).
// - Hapus/ubah memakai id_transaksi dari database (bukan dari URL/POST)
//   agar redirect selalu ke nota yang benar.
// - Audit hanya dicatat bila baris benar-benar berubah/terhapus.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$ke_detail = static function (int $id_transaksi) {
    if ($id_transaksi > 0) {
        header('Location: ../index.php?menu=detail_transaksi&id=' . $id_transaksi);
    } else {
        header('Location: ../index.php?menu=pengeluaran');
    }
    exit();
};
// Potong teks agar tidak melebihi lebar kolom (satuan varchar(15)).
$potong = static function ($teks, int $maks): string {
    $teks = (string) $teks;
    return function_exists('mb_substr') ? mb_substr($teks, 0, $maks, 'UTF-8') : substr($teks, 0, $maks);
};

$aksi_post = isset($_POST['aksi']) && is_scalar($_POST['aksi']) ? (string) $_POST['aksi'] : '';
$aksi_get  = isset($_GET['aksi']) && is_scalar($_GET['aksi']) ? (string) $_GET['aksi'] : '';

// 1. TAMBAH BARANG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'tambah') {
    $id_transaksi = isset($_POST['id_transaksi']) ? (int) $_POST['id_transaksi'] : 0;
    $nama_barang  = isset($_POST['nama_barang']) ? trim((string) $_POST['nama_barang']) : '';
    $satuan       = isset($_POST['satuan']) ? trim((string) $_POST['satuan']) : '';
    $harga_input  = isset($_POST['harga']) && is_scalar($_POST['harga']) ? (string) $_POST['harga'] : '';
    $jumlah_input = isset($_POST['jumlah']) && is_scalar($_POST['jumlah']) ? (string) $_POST['jumlah'] : '';

    // Nota tujuan harus benar-benar ada
    $nota_ada = false;
    if ($id_transaksi > 0 && ($stmt_o = $koneksi->prepare('SELECT id_transaksi FROM transaksi WHERE id_transaksi = ? LIMIT 1'))) {
        $stmt_o->bind_param('i', $id_transaksi);
        $stmt_o->execute();
        $nota_ada = (bool) $stmt_o->get_result()->fetch_assoc();
        $stmt_o->close();
    }

    $harga_valid  = is_numeric($harga_input) && (float) $harga_input >= 0;
    $jumlah_valid = is_numeric($jumlah_input) && (float) $jumlah_input > 0;

    if (!$nota_ada || $nama_barang === '' || !$harga_valid || !$jumlah_valid) {
        $ke_detail($id_transaksi);
    }

    $harga  = round((float) $harga_input, 2);
    $jumlah = round((float) $jumlah_input, 2);
    $total  = round($harga * $jumlah, 2);
    $satuan = $potong($satuan, 15);

    $stmt = $koneksi->prepare('INSERT INTO detail_transaksi (id_transaksi, nama_barang, satuan, harga, jumlah, total) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('issddd', $id_transaksi, $nama_barang, $satuan, $harga, $jumlah, $total);

    if ($stmt->execute()) {
        $id_baru = $stmt->insert_id;
        $stmt->close();

        kbap_audit_log($koneksi, 'detail_transaksi', 'INSERT', $id_baru, null, [
            'id_transaksi' => $id_transaksi,
            'nama_barang'  => $nama_barang,
            'satuan'       => $satuan,
            'harga'        => $harga,
            'jumlah'       => $jumlah,
            'total'        => $total,
        ], 'Tambah rincian nota ' . $id_transaksi . ': ' . $nama_barang);

        $ke_detail($id_transaksi);
    }

    $pesan = $stmt->error;
    $stmt->close();
    echo 'Gagal Tambah: ' . $esc($pesan);
    exit();
}

// 2. EDIT BARANG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'edit') {
    $id_detail    = isset($_POST['id_detail']) ? (int) $_POST['id_detail'] : 0;
    $id_transaksi = isset($_POST['id_transaksi']) ? (int) $_POST['id_transaksi'] : 0;
    $nama_barang  = isset($_POST['nama_barang']) ? trim((string) $_POST['nama_barang']) : '';
    $satuan       = isset($_POST['satuan']) ? trim((string) $_POST['satuan']) : '';
    $harga_input  = isset($_POST['harga']) && is_scalar($_POST['harga']) ? (string) $_POST['harga'] : '';
    $jumlah_input = isset($_POST['jumlah']) && is_scalar($_POST['jumlah']) ? (string) $_POST['jumlah'] : '';

    if ($id_detail <= 0) {
        $ke_detail($id_transaksi);
    }

    // Simpan nilai lama untuk jejak audit + sumber id_transaksi yang benar
    $data_lama = null;
    if ($stmt_lama = $koneksi->prepare('SELECT id_transaksi, nama_barang, satuan, harga, jumlah, total FROM detail_transaksi WHERE id_detail = ? LIMIT 1')) {
        $stmt_lama->bind_param('i', $id_detail);
        $stmt_lama->execute();
        $data_lama = $stmt_lama->get_result()->fetch_assoc();
        $stmt_lama->close();
    }
    if (!$data_lama) {
        // Baris tidak ada: jangan menulis apa pun
        $ke_detail($id_transaksi);
    }
    if ($id_transaksi <= 0) {
        $id_transaksi = (int) $data_lama['id_transaksi'];
    }

    $harga_valid  = is_numeric($harga_input) && (float) $harga_input >= 0;
    $jumlah_valid = is_numeric($jumlah_input) && (float) $jumlah_input > 0;

    if ($nama_barang === '' || !$harga_valid || !$jumlah_valid) {
        $ke_detail($id_transaksi);
    }

    $harga  = round((float) $harga_input, 2);
    $jumlah = round((float) $jumlah_input, 2);
    $total  = round($harga * $jumlah, 2);
    $satuan = $potong($satuan, 15);

    $stmt = $koneksi->prepare('UPDATE detail_transaksi SET nama_barang = ?, satuan = ?, harga = ?, jumlah = ?, total = ? WHERE id_detail = ?');
    $stmt->bind_param('ssdddi', $nama_barang, $satuan, $harga, $jumlah, $total, $id_detail);

    if ($stmt->execute()) {
        $stmt->close();

        kbap_audit_log($koneksi, 'detail_transaksi', 'UPDATE', $id_detail, $data_lama, [
            'nama_barang' => $nama_barang,
            'satuan'      => $satuan,
            'harga'       => $harga,
            'jumlah'      => $jumlah,
            'total'       => $total,
        ], 'Ubah rincian id ' . $id_detail . ' (' . $nama_barang . ')');

        $ke_detail($id_transaksi);
    }

    $pesan = $stmt->error;
    $stmt->close();
    echo 'Gagal Update: ' . $esc($pesan);
    exit();
}

// 3. HAPUS BARANG (GET + token CSRF, dijaga kbap_guard)
if ($aksi_get === 'hapus') {
    $id_detail    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $id_transaksi = isset($_GET['id_t']) ? (int) $_GET['id_t'] : 0;

    if ($id_detail <= 0) {
        $ke_detail($id_transaksi);
    }

    // Simpan nilai lama untuk jejak audit + sumber id_transaksi yang benar
    $data_lama = null;
    if ($stmt_lama = $koneksi->prepare('SELECT id_transaksi, nama_barang, satuan, harga, jumlah, total FROM detail_transaksi WHERE id_detail = ? LIMIT 1')) {
        $stmt_lama->bind_param('i', $id_detail);
        $stmt_lama->execute();
        $data_lama = $stmt_lama->get_result()->fetch_assoc();
        $stmt_lama->close();
    }
    if (is_array($data_lama)) {
        $id_transaksi = (int) $data_lama['id_transaksi'];
    }

    $terhapus = 0;
    $stmt = $koneksi->prepare('DELETE FROM detail_transaksi WHERE id_detail = ?');
    $stmt->bind_param('i', $id_detail);
    $stmt->execute();
    $terhapus = $stmt->affected_rows;
    $stmt->close();

    if ($terhapus > 0) {
        kbap_audit_log($koneksi, 'detail_transaksi', 'DELETE', $id_detail, $data_lama, null,
            'Hapus rincian id ' . $id_detail . ($data_lama ? ' (' . $data_lama['nama_barang'] . ')' : ''));
    }

    $ke_detail($id_transaksi);
}

// Tanpa aksi yang dikenal: kembali ke daftar transaksi
header('Location: ../index.php?menu=pengeluaran');
exit();
