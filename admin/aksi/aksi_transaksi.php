<?php
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bendahara']);
include("../koneksi.php");

// Pastikan ada parameter aksi
$aksi = isset($_REQUEST['aksi']) ? $_REQUEST['aksi'] : '';

// 1. AKSI TAMBAH DATA
if ($aksi == 'tambah') {
    $id_piodalan = isset($_POST['id_piodalan']) ? (int) $_POST['id_piodalan'] : 0;
    $tanggal     = isset($_POST['tanggal']) ? (string) $_POST['tanggal'] : '';
    $keterangan  = isset($_POST['keterangan']) ? trim((string) $_POST['keterangan']) : '';

    // Validasi ringan: odalan harus ada, tanggal harus format Y-m-d, keterangan wajib
    $odalan_valid = false;
    if ($id_piodalan > 0 && ($stmt_o = $koneksi->prepare("SELECT id FROM odalan WHERE id = ? LIMIT 1"))) {
        $stmt_o->bind_param('i', $id_piodalan);
        $stmt_o->execute();
        $odalan_valid = (bool) $stmt_o->get_result()->fetch_assoc();
        $stmt_o->close();
    }
    $tanggal_valid = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal);

    if (!$odalan_valid || !$tanggal_valid || $keterangan === '') {
        header("Location: ../index.php?menu=pengeluaran" . ($id_piodalan > 0 ? '&id_odalan=' . $id_piodalan : ''));
        exit();
    }

    $stmt = $koneksi->prepare("INSERT INTO transaksi (id_piodalan, tanggal, keterangan) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $id_piodalan, $tanggal, $keterangan);

    if ($stmt->execute()) {
        // Ambil ID transaksi yang baru saja masuk
        $id_baru = $stmt->insert_id;
        $stmt->close();

        kbap_audit_log($koneksi, 'transaksi', 'INSERT', $id_baru, null, [
            'id_piodalan' => $id_piodalan,
            'tanggal'     => $tanggal,
            'keterangan'  => $keterangan,
        ], 'Tambah nota pengeluaran odalan ' . $id_piodalan);

        // Setelah simpan, langsung lempar ke halaman DETAIL untuk isi rincian barang/biaya
        header("Location: ../index.php?menu=detail_transaksi&id=$id_baru");
        exit();
    } else {
        $pesan = $stmt->error;
        $stmt->close();
        echo "Gagal Tambah: " . htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8');
    }
}

// 2. AKSI HAPUS DATA
elseif ($aksi == 'hapus') {
    $id         = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $id_odalan  = isset($_GET['id_odalan']) ? (int) $_GET['id_odalan'] : 0;

    if ($id <= 0) {
        header("Location: ../index.php?menu=pengeluaran");
        exit();
    }

    // detail_transaksi tidak punya ON DELETE CASCADE, jadi hapus rincian dulu.
    $koneksi->begin_transaction();
    try {
        // Simpan data nota untuk jejak audit sebelum dihapus
        $data_lama = null;
        if ($stmt_lama = $koneksi->prepare("SELECT id_piodalan, tanggal, keterangan FROM transaksi WHERE id_transaksi = ? LIMIT 1")) {
            $stmt_lama->bind_param("i", $id);
            $stmt_lama->execute();
            $data_lama = $stmt_lama->get_result()->fetch_assoc();
            $stmt_lama->close();
        }
        $jml_detail = 0;
        if ($stmt_c = $koneksi->prepare("SELECT COUNT(*) AS c FROM detail_transaksi WHERE id_transaksi = ?")) {
            $stmt_c->bind_param("i", $id);
            $stmt_c->execute();
            $jml_detail = (int) ($stmt_c->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt_c->close();
        }

        $stmt = $koneksi->prepare("DELETE FROM detail_transaksi WHERE id_transaksi = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $koneksi->prepare("DELETE FROM transaksi WHERE id_transaksi = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $terhapus = $stmt->affected_rows;
        $stmt->close();

        $koneksi->commit();

        // Catat hanya bila benar-benar ada baris yang terhapus
        if ($terhapus > 0) {
            kbap_audit_log($koneksi, 'transaksi', 'DELETE', $id, $data_lama, [
                'detail_ikut_terhapus' => $jml_detail,
            ], 'Hapus nota id ' . $id . ' (+' . $jml_detail . ' rincian)');
        }

        // Balikkan ke halaman daftar nota odalan yang sama
        header("Location: ../index.php?menu=pengeluaran" . ($id_odalan > 0 ? '&id_odalan=' . $id_odalan : ''));
        exit();
    } catch (Throwable $e) {
        $koneksi->rollback();
        echo "Gagal Hapus: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

// 3. AKSI EDIT DATA
elseif ($aksi == 'edit') {
    $id_transaksi = isset($_POST['id_transaksi']) ? (int) $_POST['id_transaksi'] : 0;
    $id_odalan    = isset($_POST['id_piodalan']) ? (int) $_POST['id_piodalan'] : 0;
    $tanggal      = isset($_POST['tanggal']) ? (string) $_POST['tanggal'] : '';
    $keterangan   = isset($_POST['keterangan']) ? trim((string) $_POST['keterangan']) : '';

    $tanggal_valid = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal);
    if ($id_transaksi <= 0 || !$tanggal_valid || $keterangan === '') {
        header("Location: ../index.php?menu=pengeluaran" . ($id_odalan > 0 ? '&id_odalan=' . $id_odalan : ''));
        exit();
    }

    // Simpan nilai lama untuk jejak audit
    $data_lama = null;
    if ($stmt_lama = $koneksi->prepare('SELECT id_piodalan, tanggal, keterangan FROM transaksi WHERE id_transaksi = ? LIMIT 1')) {
        $stmt_lama->bind_param('i', $id_transaksi);
        $stmt_lama->execute();
        $data_lama = $stmt_lama->get_result()->fetch_assoc();
        $stmt_lama->close();
    }

    // id_odalan untuk kembali ke halaman yang benar (bila POST tidak membawanya)
    if ($id_odalan <= 0 && is_array($data_lama)) {
        $id_odalan = (int) $data_lama['id_piodalan'];
    }

    $stmt = $koneksi->prepare("UPDATE transaksi SET tanggal = ?, keterangan = ? WHERE id_transaksi = ?");
    $stmt->bind_param('ssi', $tanggal, $keterangan, $id_transaksi);

    if ($stmt->execute()) {
        $stmt->close();
        kbap_audit_log($koneksi, 'transaksi', 'UPDATE', $id_transaksi, $data_lama, [
            'tanggal'    => $tanggal,
            'keterangan' => $keterangan,
        ], 'Ubah nota id ' . $id_transaksi);
        header("Location: ../index.php?menu=pengeluaran" . ($id_odalan > 0 ? '&id_odalan=' . $id_odalan : ''));
        exit();
    } else {
        $pesan = $stmt->error;
        $stmt->close();
        echo "Gagal Update: " . htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8');
    }
} else {
    // Jika akses ilegal tanpa aksi
    header("Location: ../index.php?menu=pengeluaran");
    exit();
}
