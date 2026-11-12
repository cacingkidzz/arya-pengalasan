<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../koneksi.php';

// Hak akses tidak diubah: kategori pengeluaran = admin + bagian_data.
kbap_guard(['admin', 'bagian_data']);

// =====================================================================
// DIPERKERAS (2026-11-10)
// - `aksi` dibaca terpisah untuk POST/GET dan dicek `is_scalar`.
// - Validasi: nama kategori wajib & <= 100 karakter (varchar(100)),
//   `id` di-cast (int); id yang tidak ada tidak menulis apa pun.
// - Hapus DITOLAK bila kategori masih dipakai `pengeluaran` (FK
//   `pengeluaran_ibfk_2` = RESTRICT, dulu error SQL mentah).
// - Audit DELETE hanya dicatat bila benar-benar ada baris terhapus.
// - Semua cabang memakai exit() setelah redirect.
// =====================================================================
$aksi_post = isset($_POST['aksi']) && is_scalar($_POST['aksi']) ? (string) $_POST['aksi'] : '';
$aksi_get  = isset($_GET['aksi']) && is_scalar($_GET['aksi']) ? (string) $_GET['aksi'] : '';

$teks = static function ($nilai): string {
    return is_scalar($nilai) ? trim((string) $nilai) : '';
};

$kembali = static function (string $status): void {
    header('Location: ../index.php?menu=kategori_pengeluaran&status=' . $status);
    exit;
};

$ambil_kategori = static function ($koneksi, int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    if (!$stmt = $koneksi->prepare('SELECT id_kategori, nama_kategori FROM kategori_pengeluaran WHERE id_kategori = ? LIMIT 1')) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
};

// ---------------------------------------------------------------- TAMBAH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'tambah') {
    $nama_kategori = $teks($_POST['nama_kategori'] ?? '');

    if ($nama_kategori === '' || mb_strlen($nama_kategori) > 100) {
        $kembali('gagal');
    }

    try {
        $stmt = $koneksi->prepare('INSERT INTO kategori_pengeluaran (nama_kategori) VALUES (?)');
        $stmt->bind_param('s', $nama_kategori);
        $stmt->execute();
        $id_baru = (int) $stmt->insert_id;
        $stmt->close();
    } catch (Throwable $e) {
        error_log('aksi_kategori_pengeluaran tambah: ' . $e->getMessage());
        $kembali('gagal');
    }

    kbap_audit_log($koneksi, 'kategori_pengeluaran', 'INSERT', $id_baru, null, [
        'nama_kategori' => $nama_kategori,
    ], 'Tambah kategori pengeluaran ' . $nama_kategori);

    $kembali('sukses');
}

// ------------------------------------------------------------------ EDIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'edit') {
    $id            = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $nama_kategori = $teks($_POST['nama_kategori'] ?? '');

    if ($nama_kategori === '' || mb_strlen($nama_kategori) > 100) {
        $kembali('gagal');
    }

    $data_lama = $ambil_kategori($koneksi, $id);
    if (!$data_lama) {
        $kembali('gagal');
    }

    try {
        $stmt = $koneksi->prepare('UPDATE kategori_pengeluaran SET nama_kategori = ? WHERE id_kategori = ?');
        $stmt->bind_param('si', $nama_kategori, $id);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('aksi_kategori_pengeluaran edit: ' . $e->getMessage());
        $kembali('gagal');
    }

    kbap_audit_log($koneksi, 'kategori_pengeluaran', 'UPDATE', $id, $data_lama, [
        'nama_kategori' => $nama_kategori,
    ], 'Ubah kategori pengeluaran id ' . $id);

    $kembali('edit');
}

// ----------------------------------------------------------------- HAPUS
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $aksi_get === 'hapus') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    $data_lama = $ambil_kategori($koneksi, $id);
    if (!$data_lama) {
        $kembali('gagal');
    }

    // Tolak hapus bila masih dipakai (FK RESTRICT).
    $dipakai = 0;
    if ($stmt = $koneksi->prepare('SELECT COUNT(*) AS c FROM pengeluaran WHERE id_kategori = ?')) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $dipakai = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }
    if ($dipakai > 0) {
        error_log('aksi_kategori_pengeluaran hapus ditolak id ' . $id . ': dipakai ' . $dipakai . ' pengeluaran');
        $kembali('dipakai');
    }

    try {
        $stmt = $koneksi->prepare('DELETE FROM kategori_pengeluaran WHERE id_kategori = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $terhapus = (int) $stmt->affected_rows;
        $stmt->close();
    } catch (Throwable $e) {
        error_log('aksi_kategori_pengeluaran hapus: ' . $e->getMessage());
        $kembali('gagal');
    }

    if ($terhapus === 0) {
        $kembali('gagal');
    }

    kbap_audit_log($koneksi, 'kategori_pengeluaran', 'DELETE', $id, $data_lama, null,
        'Hapus kategori pengeluaran id ' . $id . ' (' . $data_lama['nama_kategori'] . ')');

    $kembali('hapus');
}

// Tanpa aksi yang dikenal: kembali ke daftar.
header('Location: ../index.php?menu=kategori_pengeluaran');
exit;
