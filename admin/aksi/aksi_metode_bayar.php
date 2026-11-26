<?php
/**
 * aksi_metode_bayar.php  (baru 2026-11-26)
 *
 * Ubah METODE PEMBAYARAN secara massal dari halaman
 * "Rekap Bayar & Tunggakan" (rekap_hari).
 *
 * Tujuan: bila petugas salah memilih metode (tunai/transfer/QRIS),
 * tidak perlu membuka menu Pembayaran > detail semeton satu per satu.
 *
 * Yang diubah HANYA kolom metode_pembayaran (tanggal_bayar tidak disentuh).
 * Pembayaran yang sudah dibatalkan (dibatalkan_at terisi) tidak bisa diubah.
 */

require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../koneksi.php';

kbap_guard(['admin', 'bendahara']); // sama dengan edit_pembayaran.php (POST + CSRF wajib)

// ---------------------------------------------------------------------
// 1. Baca filter aktif agar redirect kembali ke tampilan yang sama
// ---------------------------------------------------------------------
$teks = static function (string $nama, string $default = ''): string {
    if (!isset($_POST[$nama]) || !is_scalar($_POST[$nama])) {
        return $default;
    }
    return trim((string) $_POST[$nama]);
};

$f_odalan  = (int) $teks('id_odalan', '0');
$f_dari    = $teks('dari');
$f_sampai  = $teks('sampai');
$f_petugas = (int) $teks('petugas', '0');

$kembali = static function (string $pesan, int $diubah = 0) use ($f_odalan, $f_dari, $f_sampai, $f_petugas): void {
    $param = ['menu' => 'rekap_hari', 'pesan' => $pesan];
    if ($diubah > 0) {
        $param['ubah'] = $diubah;
    }
    if ($f_odalan > 0) {
        $param['id_odalan'] = $f_odalan;
    }
    if ($f_dari !== '') {
        $param['dari'] = $f_dari;
    }
    if ($f_sampai !== '') {
        $param['sampai'] = $f_sampai;
    }
    if ($f_petugas > 0) {
        $param['petugas'] = $f_petugas;
    }
    header('Location: ../index.php?' . http_build_query($param));
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $kembali('metode_gagal');
}

// ---------------------------------------------------------------------
// 2. Validasi metode baru + daftar id pembayaran
// ---------------------------------------------------------------------
$metode_baru  = $teks('metode_baru');
$metode_valid = ['tunai', 'transfer', 'QRIS']; // mengikuti enum pembayaran.metode_pembayaran
if (!in_array($metode_baru, $metode_valid, true)) {
    $kembali('metode_gagal');
}

$ids = [];
if (isset($_POST['id_pembayaran']) && is_array($_POST['id_pembayaran'])) {
    foreach ($_POST['id_pembayaran'] as $v) {
        if (!is_scalar($v)) {
            continue;
        }
        $n = (int) $v;
        if ($n > 0) {
            $ids[$n] = $n;
        }
    }
}
$ids = array_values($ids);

// Batas wajar: satu periode kas tidak lebih dari 300 transaksi yang diubah sekaligus.
if (!$ids || count($ids) > 300) {
    $kembali('metode_gagal');
}

// ---------------------------------------------------------------------
// 3. Ambil nilai lama (hanya pembayaran aktif)
//    Id sudah di-cast (int) sehingga aman disisipkan ke IN (...).
// ---------------------------------------------------------------------
$lama = [];
$sql  = 'SELECT id_pembayaran, metode_pembayaran
         FROM pembayaran
         WHERE id_pembayaran IN (' . implode(',', $ids) . ')
           AND dibatalkan_at IS NULL';
if ($res = $koneksi->query($sql)) {
    while ($r = $res->fetch_assoc()) {
        $lama[(int) $r['id_pembayaran']] = (string) $r['metode_pembayaran'];
    }
}
if (!$lama) {
    $kembali('metode_gagal');
}

// ---------------------------------------------------------------------
// 4. Update + audit (satu transaksi)
// ---------------------------------------------------------------------
$diubah = 0;
$koneksi->begin_transaction();
try {
    $stmt = $koneksi->prepare(
        'UPDATE pembayaran SET metode_pembayaran = ?
         WHERE id_pembayaran = ? AND dibatalkan_at IS NULL'
    );
    if (!$stmt) {
        throw new RuntimeException('prepare UPDATE pembayaran gagal');
    }

    foreach ($lama as $id => $metode_lama) {
        if ($metode_lama === $metode_baru) {
            continue; // sudah sesuai, tidak perlu ditulis/di-audit
        }

        $stmt->bind_param('si', $metode_baru, $id);
        if ($stmt->execute() && $stmt->affected_rows === 1) {
            $diubah++;
            kbap_audit_log(
                $koneksi,
                'pembayaran',
                'UPDATE',
                $id,
                ['metode_pembayaran' => $metode_lama],
                ['metode_pembayaran' => $metode_baru],
                'Ubah metode pembayaran dari halaman rekap hari (#' . $id . ')'
            );
        }
    }
    $stmt->close();

    if ($diubah === 0) {
        $koneksi->rollback();
        $kembali('metode_gagal');
    }

    $koneksi->commit();
} catch (Throwable $e) {
    $koneksi->rollback();
    error_log('KBAP ubah metode pembayaran gagal: ' . $e->getMessage());
    $kembali('metode_gagal');
}

$kembali('metode', $diubah);
