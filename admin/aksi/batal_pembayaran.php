<?php
require_once __DIR__ . '/../security.php';
include '../koneksi.php';

kbap_guard(['admin', 'bendahara']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php?menu=pembayaran');
    exit;
}

$id_pembayaran = isset($_POST['id_pembayaran']) ? (int) $_POST['id_pembayaran'] : 0;
$id_user       = (int) $_SESSION['id_user'];
$alasan_batal  = trim((string) ($_POST['alasan_batal'] ?? ''));
if (mb_strlen($alasan_batal) > 255) {
    $alasan_batal = mb_substr($alasan_batal, 0, 255);
}

if ($id_pembayaran <= 0) {
    echo "<script>alert('Data pembayaran tidak valid!'); window.history.back();</script>";
    exit;
}

$koneksi->begin_transaction();

try {
    // 1. Ambil data pembayaran (sumber kebenaran, bukan input POST)
    $stmt = $koneksi->prepare(
        "SELECT p.id_pembayaran, p.id_semeton, p.id_piodalan, p.jumlah_bayar,
                p.metode_pembayaran, p.tanggal_bayar, s.user_id, s.jenis_user
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

    $id_semeton = (int) $bayar['id_semeton'];
    $id_piodalan = (int) $bayar['id_piodalan'];
    $user_id_semeton = $bayar['user_id'];

    // 2. Kunci baris tagihan lebih dulu. Urutan kunci sengaja dibuat sama dengan
    //    proses_pembayaran.php (hutang -> pembayaran) supaya pembatalan dan
    //    pembayaran tidak berjalan bersamaan pada tagihan yang sama dan tidak
    //    saling menimbulkan deadlock.
    $stmt = $koneksi->prepare(
        'SELECT id FROM hutang WHERE id_semeton = ? AND id_odalan = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('ii', $id_semeton, $id_piodalan);
    $stmt->execute();
    $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 3. Tandai batal (soft-cancel) — baris TIDAK dihapus agar jejak audit tetap ada.
    $stmt = $koneksi->prepare(
        'UPDATE pembayaran
         SET dibatalkan_at = NOW(), dibatalkan_oleh = ?, alasan_batal = ?
         WHERE id_pembayaran = ? AND dibatalkan_at IS NULL'
    );
    $stmt->bind_param('isi', $id_user, $alasan_batal, $id_pembayaran);
    $stmt->execute();
    if ($stmt->affected_rows < 1) {
        $stmt->close();
        throw new RuntimeException('Pembayaran sudah dibatalkan sebelumnya.');
    }
    $stmt->close();

    // 4. Jika tidak ada lagi pembayaran sah untuk tagihan ini, pulihkan hutang.
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
        'alasan_batal'    => $alasan_batal,
    ], 'Pembatalan pembayaran');

    $koneksi->commit();

    echo "<script>
            alert('Pembayaran berhasil dibatalkan dan tagihan dikembalikan.');
            window.location.href='../index.php?menu=pembayaran_detail&id=" . urlencode((string) $user_id_semeton) . "';
          </script>";
} catch (Throwable $e) {
    $koneksi->rollback();
    $pesan = addslashes($e->getMessage());
    echo "<script>
            alert('Gagal membatalkan pembayaran: $pesan');
            window.history.back();
          </script>";
}
