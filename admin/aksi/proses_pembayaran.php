<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../koneksi.php';

kbap_guard(['admin', 'bendahara']);

/**
 * Tampilkan pesan gagal lalu kembali ke halaman form.
 * Memakai json_encode agar teks aman di dalam <script> (bebas kutip/tag).
 */
$gagal = static function (string $pesan): void {
    echo '<script>alert(' . json_encode(
        $pesan,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) . '); window.history.back();</script>';
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php?menu=pembayaran');
    exit;
}

$id_semeton_input = isset($_POST['id_semeton']) && is_scalar($_POST['id_semeton'])
    ? trim((string) $_POST['id_semeton'])
    : '';
$id_piodalan = isset($_POST['id_piodalan']) && is_scalar($_POST['id_piodalan'])
    ? (int) $_POST['id_piodalan']
    : 0;
$tgl_bayar = isset($_POST['tgl_bayar']) && is_scalar($_POST['tgl_bayar'])
    ? trim((string) $_POST['tgl_bayar'])
    : '';
$metode = isset($_POST['metode_pembayaran']) && is_scalar($_POST['metode_pembayaran'])
    ? (string) $_POST['metode_pembayaran']
    : '';
$id_user = (int) $_SESSION['id_user'];

$metode_valid = ['tunai', 'transfer', 'QRIS'];
if ($id_semeton_input === '' || $id_piodalan <= 0 || !in_array($metode, $metode_valid, true)) {
    $gagal('Data pembayaran tidak lengkap!');
}

// Normalisasi tanggal dari input datetime-local.
$tgl_bayar = str_replace('T', ' ', $tgl_bayar);
if ($tgl_bayar === '') {
    $tgl_bayar = date('Y-m-d H:i:s');
} elseif (strlen($tgl_bayar) === 16) {
    $tgl_bayar .= ':00';
}
if (!strtotime($tgl_bayar)) {
    $gagal('Tanggal pembayaran tidak valid!');
}

$koneksi->begin_transaction();

try {
    // 1. Cari semeton berdasarkan user_id (string).
    $stmt = $koneksi->prepare('SELECT id, jenis_user FROM semeton WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('s', $id_semeton_input);
    $stmt->execute();
    $semeton = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$semeton) {
        throw new RuntimeException('Semeton tidak ditemukan.');
    }

    $id_semeton = (int) $semeton['id'];

    // 2. Kunci baris tagihan (SELECT ... FOR UPDATE).
    //    Ini titik serialisasi: bila dua request datang bersamaan (klik ganda /
    //    retry jaringan lambat), request kedua MENUNGGU sampai request pertama
    //    commit, lalu membaca status terbaru (lunas) dan ditolak.
    //    Tanpa lock, keduanya membaca "belum_lunas" dan sama-sama menyisipkan.
    $stmt = $koneksi->prepare(
        'SELECT id, jumlah_hutang, status FROM hutang
         WHERE id_semeton = ? AND id_odalan = ? LIMIT 1
         FOR UPDATE'
    );
    $stmt->bind_param('ii', $id_semeton, $id_piodalan);
    $stmt->execute();
    $hutang = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$hutang) {
        throw new RuntimeException('Tagihan untuk odalan ini tidak ditemukan.');
    }
    if ($hutang['status'] === 'lunas' || (float) $hutang['jumlah_hutang'] <= 0) {
        throw new RuntimeException('Tagihan ini sudah lunas. Tidak bisa dibayar dua kali.');
    }

    // 3. Pengaman tambahan: tolak bila sudah ada pembayaran sah untuk tagihan ini.
    //    Melindungi dari data yang tidak konsisten (mis. tagihan belum ditutup
    //    padahal pembayaran sudah tercatat).
    $stmt = $koneksi->prepare(
        'SELECT id_pembayaran FROM pembayaran
         WHERE id_semeton = ? AND id_piodalan = ? AND dibatalkan_at IS NULL
         LIMIT 1'
    );
    $stmt->bind_param('ii', $id_semeton, $id_piodalan);
    $stmt->execute();
    $sudah_bayar = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($sudah_bayar) {
        throw new RuntimeException(
            'Tagihan ini sudah memiliki pembayaran tercatat (#' . (int) $sudah_bayar['id_pembayaran']
            . '). Tidak bisa dibayar dua kali.'
        );
    }

    $jumlah_bayar = (float) $hutang['jumlah_hutang'];

    // 4. Simpan pembayaran.
    $stmt = $koneksi->prepare(
        "INSERT INTO pembayaran (id_semeton, id_piodalan, jumlah_bayar, metode_pembayaran, tanggal_bayar, status, id_user)
         VALUES (?, ?, ?, ?, ?, 'lunas', ?)"
    );
    $stmt->bind_param('iidssi', $id_semeton, $id_piodalan, $jumlah_bayar, $metode, $tgl_bayar, $id_user);
    $stmt->execute();
    $id_pembayaran_baru = (int) $koneksi->insert_id;
    $stmt->close();

    // 5. Tutup tagihan. Nominal tagihan asli disimpan permanen agar riwayat tidak hilang.
    $stmt = $koneksi->prepare(
        "UPDATE hutang
         SET jumlah_hutang = 0,
             jumlah_tagihan_asli = CASE WHEN jumlah_tagihan_asli > 0 THEN jumlah_tagihan_asli ELSE ? END,
             status = 'lunas',
             id_user = ?
         WHERE id_semeton = ? AND id_odalan = ?"
    );
    $stmt->bind_param('diii', $jumlah_bayar, $id_user, $id_semeton, $id_piodalan);
    $stmt->execute();
    $terpengaruh = $stmt->affected_rows;
    $stmt->close();

    // 6. Bila tagihan tidak ikut tertutup, batalkan semuanya (jangan tinggalkan
    //    pembayaran tanpa penutupan tagihan).
    if ($terpengaruh !== 1) {
        throw new RuntimeException('Gagal menutup tagihan (data tagihan berubah). Pembayaran dibatalkan.');
    }

    kbap_audit_log($koneksi, 'pembayaran', 'INSERT', $id_pembayaran_baru, null, [
        'id_semeton'    => $id_semeton,
        'id_piodalan'   => $id_piodalan,
        'jumlah_bayar'  => $jumlah_bayar,
        'metode'        => $metode,
        'tanggal_bayar' => $tgl_bayar,
    ], 'Pembayaran urunan');

    $koneksi->commit();

    header('Location: ../index.php?menu=pembayaran_detail&id=' . urlencode($id_semeton_input) . '&status=pembayaran');
    exit;
} catch (Throwable $e) {
    $koneksi->rollback();

    // 7. Jaring pengaman terakhir: UNIQUE index `uq_pembayaran_aktif`
    //    (kolom generated `kunci_aktif`) menolak pembayaran kedua untuk tagihan
    //    yang sama, walaupun kedua request lolos pemeriksaan di atas.
    //    Error 1062 = duplicate entry. Pesannya dibuat ramah, bukan pesan SQL.
    if ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062) {
        $gagal('Pembayaran ini sudah tercatat. Tidak bisa dibayar dua kali.');
    }

    $gagal('Pembayaran gagal: ' . $e->getMessage());
}
