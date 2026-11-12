<?php
require_once __DIR__ . '/../security.php';
include '../koneksi.php';

kbap_guard(['admin', 'bendahara'], true, true); // JSON + CSRF

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'gagal', 'message' => 'Permintaan tidak valid.']);
    exit;
}

$id_pembayaran = (int) $_POST['id'];
$id_user       = (int) $_SESSION['id_user'];

if ($id_pembayaran <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'gagal', 'message' => 'ID pembayaran tidak valid.']);
    exit;
}

$koneksi->begin_transaction();

try {
    $stmt = $koneksi->prepare(
        "SELECT p.id_semeton, p.id_piodalan, p.jumlah_bayar, p.metode_pembayaran,
                p.tanggal_bayar, s.jenis_user
         FROM pembayaran p
         JOIN semeton s ON s.id = p.id_semeton
         WHERE p.id_pembayaran = ? AND p.dibatalkan_at IS NULL LIMIT 1"
    );
    $stmt->bind_param('i', $id_pembayaran);
    $stmt->execute();
    $bayar = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bayar) {
        throw new RuntimeException('Pembayaran tidak ditemukan atau sudah dibatalkan.');
    }

    $id_semeton  = (int) $bayar['id_semeton'];
    $id_piodalan = (int) $bayar['id_piodalan'];

    // Kunci baris tagihan lebih dulu (urutan kunci sama dengan
    // proses_pembayaran.php: hutang -> pembayaran) agar pembatalan dan
    // pembayaran tidak berjalan bersamaan pada tagihan yang sama.
    $stmt = $koneksi->prepare(
        'SELECT id FROM hutang WHERE id_semeton = ? AND id_odalan = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('ii', $id_semeton, $id_piodalan);
    $stmt->execute();
    $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Tandai batal (soft-cancel) — baris TIDAK dihapus agar jejak audit tetap ada.
    $alasan = 'Dihapus dari halaman data double pembayaran';
    $stmt = $koneksi->prepare(
        'UPDATE pembayaran
         SET dibatalkan_at = NOW(), dibatalkan_oleh = ?, alasan_batal = ?
         WHERE id_pembayaran = ? AND dibatalkan_at IS NULL'
    );
    $stmt->bind_param('isi', $id_user, $alasan, $id_pembayaran);
    $stmt->execute();
    if ($stmt->affected_rows < 1) {
        $stmt->close();
        throw new RuntimeException('Pembayaran sudah dibatalkan sebelumnya.');
    }
    $stmt->close();

    // Pulihkan tagihan hanya jika tidak ada pembayaran sah lain untuk tagihan yang sama.
    $stmt = $koneksi->prepare(
        'SELECT COUNT(*) AS sisa FROM pembayaran
         WHERE id_semeton = ? AND id_piodalan = ? AND dibatalkan_at IS NULL'
    );
    $stmt->bind_param('ii', $id_semeton, $id_piodalan);
    $stmt->execute();
    $sisa = (int) $stmt->get_result()->fetch_assoc()['sisa'];
    $stmt->close();

    if ($sisa === 0) {
        // Nominal tagihan asli kini tersimpan di kolom hutang.jumlah_tagihan_asli.
        // Fallback (baris lama yang belum punya nilai) memakai tarif odalan.
        $jumlah = 0.0;

        $stmt = $koneksi->prepare('SELECT iuran_ngarep, iuran_ngempi FROM odalan WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id_piodalan);
        $stmt->execute();
        $odalan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($odalan) {
            $jumlah = ($bayar['jenis_user'] === 'ngarep')
                ? (float) $odalan['iuran_ngarep']
                : (float) $odalan['iuran_ngempi'];
        }

        $stmt = $koneksi->prepare(
            "UPDATE hutang
             SET jumlah_hutang = CASE WHEN jumlah_tagihan_asli > 0 THEN jumlah_tagihan_asli ELSE ? END,
                 status = 'belum_lunas',
                 id_user = ?
             WHERE id_semeton = ? AND id_odalan = ?"
        );
        $stmt->bind_param('diii', $jumlah, $id_user, $id_semeton, $id_piodalan);
        $stmt->execute();
        $stmt->close();
    }

    kbap_audit_log($koneksi, 'pembayaran', 'CANCEL', $id_pembayaran, [
        'id_semeton'    => $id_semeton,
        'id_piodalan'   => $id_piodalan,
        'jumlah_bayar'  => (float) $bayar['jumlah_bayar'],
        'metode'        => $bayar['metode_pembayaran'],
        'tanggal_bayar' => $bayar['tanggal_bayar'],
    ], [
        'dibatalkan_oleh' => $id_user,
        'alasan_batal'    => $alasan,
    ], 'Penghapusan pembayaran (soft-cancel)');

    $koneksi->commit();
    echo json_encode(['status' => 'sukses']);
} catch (Throwable $e) {
    $koneksi->rollback();
    http_response_code(500);
    echo json_encode(['status' => 'gagal', 'message' => $e->getMessage()]);
}
