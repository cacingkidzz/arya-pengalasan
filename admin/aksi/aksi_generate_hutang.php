<?php
require_once __DIR__ . '/../security.php';
include '../koneksi.php';

kbap_guard(['admin', 'bendahara'], false, true); // wajib login + role
kbap_require_csrf(true); // endpoint GET yang menulis data: token wajib selalu

$id_odalan = isset($_GET['id_odalan']) ? (int) $_GET['id_odalan'] : (int) ($_POST['id_odalan'] ?? 0);
$id_user   = (int) $_SESSION['id_user'];

if ($id_odalan <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ID odalan tidak ditemukan.']);
    exit;
}

// Ambil tarif odalan
$stmt = $koneksi->prepare('SELECT iuran_ngarep, iuran_ngempi FROM odalan WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id_odalan);
$stmt->execute();
$odalan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$odalan) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Odalan tidak ditemukan.']);
    exit;
}

// Semeton aktif (ngarep/ngempi) yang BELUM punya baris hutang di odalan ini.
$stmt = $koneksi->prepare(
    "SELECT s.id, s.jenis_user
     FROM semeton s
     WHERE s.jenis_user IN ('ngarep','ngempi')
       AND s.status = 'aktif'
       AND NOT EXISTS (
           SELECT 1 FROM hutang h WHERE h.id_semeton = s.id AND h.id_odalan = ?
       )"
);
$stmt->bind_param('i', $id_odalan);
$stmt->execute();
$result = $stmt->get_result();

$insert = $koneksi->prepare(
    "INSERT INTO hutang (id_semeton, id_odalan, jumlah_hutang, jumlah_tagihan_asli, status, id_user)
     VALUES (?, ?, ?, ?, 'belum_lunas', ?)"
);

$total = 0;
$koneksi->begin_transaction();
try {
    while ($row = $result->fetch_assoc()) {
        $jumlah = ($row['jenis_user'] === 'ngarep')
            ? (float) $odalan['iuran_ngarep']
            : (float) $odalan['iuran_ngempi'];

        $id_semeton = (int) $row['id'];
        $insert->bind_param('iiddi', $id_semeton, $id_odalan, $jumlah, $jumlah, $id_user);
        if ($insert->execute()) {
            $total++;
        }
    }
    $koneksi->commit();
} catch (Throwable $e) {
    $koneksi->rollback();
    $insert->close();
    $stmt->close();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan tagihan: ' . $e->getMessage()]);
    exit;
}

$insert->close();
$stmt->close();

if ($total > 0) {
    kbap_audit_log($koneksi, 'hutang', 'INSERT', null, null, [
        'id_odalan'    => $id_odalan,
        'jumlah_baris' => $total,
    ], 'Generate tagihan odalan #' . $id_odalan);

    echo json_encode(['status' => 'success', 'message' => $total . ' tagihan berhasil ditambahkan.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Tidak ada semeton baru yang perlu ditambahkan tagihan.']);
}
