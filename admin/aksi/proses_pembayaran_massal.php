<?php
/**
 * proses_pembayaran_massal.php
 *
 * Pembayaran urunan untuk BEBERAPA semeton sekaligus, biasanya satu wilayah
 * (mis. penagihan kolektif di banjar). Setiap semeton tetap dicatat sebagai
 * satu baris `pembayaran` tersendiri (nomor nota masing-masing) dan tagihan
 * `hutang`-nya ditutup satu per satu.
 *
 * Aturan sama dengan proses_pembayaran.php:
 *   - SELECT ... FOR UPDATE (serialisasi; menahan klik ganda / retry jaringan)
 *   - tolak bila sudah ada pembayaran aktif untuk tagihan yang sama
 *   - nominal selalu diambil dari hutang.jumlah_hutang (bukan dari klien)
 *   - UNIQUE `uq_pembayaran_aktif` sebagai jaring pengaman terakhir
 *
 * Satu transaksi untuk seluruh batch:
 *   - baris yang sudah lunas / tidak punya tagihan DILEWATI (tidak
 *     menggagalkan baris lain),
 *   - kegagalan tak terduga -> seluruh batch dibatalkan (rollback).
 */

require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../koneksi.php';

kbap_guard(['admin', 'bendahara']);

/**
 * Kembali ke halaman kasir massal dengan pesan gagal.
 * Filter odalan/wilayah/sub wilayah dipertahankan agar petugas tidak
 * kehilangan konteks.
 */
$gagal = static function (string $pesan): void {
    $params = ['menu' => 'pembayaran_massal', 'pesan' => 'bayar_massal_gagal'];
    if (isset($_POST['id_piodalan']) && is_scalar($_POST['id_piodalan'])) {
        $params['id_odalan'] = (int) $_POST['id_piodalan'];
    }
    if (isset($_POST['wilayah']) && is_scalar($_POST['wilayah'])) {
        $params['wilayah'] = (int) $_POST['wilayah'];
    }
    if (isset($_POST['sub']) && is_scalar($_POST['sub'])) {
        $params['sub'] = (int) $_POST['sub'];
    }
    $params['info'] = mb_substr(trim($pesan), 0, 300);
    header('Location: ../index.php?' . http_build_query($params));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php?menu=pembayaran_massal');
    exit;
}

$id_piodalan = (isset($_POST['id_piodalan']) && is_scalar($_POST['id_piodalan']))
    ? (int) $_POST['id_piodalan']
    : 0;
$f_wilayah   = (isset($_POST['wilayah']) && is_scalar($_POST['wilayah']))
    ? (int) $_POST['wilayah']
    : 0;
$f_sub       = (isset($_POST['sub']) && is_scalar($_POST['sub']))
    ? (int) $_POST['sub']
    : 0;
$tgl_bayar   = (isset($_POST['tgl_bayar']) && is_scalar($_POST['tgl_bayar']))
    ? trim((string) $_POST['tgl_bayar'])
    : '';
$metode      = (isset($_POST['metode_pembayaran']) && is_scalar($_POST['metode_pembayaran']))
    ? (string) $_POST['metode_pembayaran']
    : '';
$id_user     = (int) $_SESSION['id_user'];

$metode_valid = ['tunai', 'transfer', 'QRIS'];
if ($id_piodalan <= 0 || !in_array($metode, $metode_valid, true)) {
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

// Kumpulkan id semeton (angka, unik, maksimal 300 per proses).
$ids = [];
if (isset($_POST['id_semeton']) && is_array($_POST['id_semeton'])) {
    foreach ($_POST['id_semeton'] as $nilai) {
        if (!is_scalar($nilai)) {
            continue;
        }
        $n = (int) $nilai;
        if ($n > 0) {
            $ids[$n] = $n;
        }
    }
}
$ids = array_values($ids);

if (empty($ids)) {
    $gagal('Pilih minimal satu semeton yang akan dibayar.');
}
if (count($ids) > 300) {
    $gagal('Terlalu banyak data dalam satu kali proses (maksimal 300 semeton).');
}

// Pastikan odalan benar-benar ada.
$stmt = $koneksi->prepare('SELECT id, nama_odalan FROM odalan WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id_piodalan);
$stmt->execute();
$odalan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$odalan) {
    $gagal('Odalan tidak ditemukan.');
}

// Sub wilayah hanya dipakai untuk konteks & jejak audit: pastikan benar-benar
// milik wilayah yang dipilih. Nilai tidak cocok diabaikan (daftar id semeton
// tetap yang menentukan pembayaran, bukan filter ini).
if ($f_sub > 0) {
    if ($f_wilayah <= 0) {
        $f_sub = 0;
    } else {
        $stmt = $koneksi->prepare('SELECT id_sub FROM sub_wilayah WHERE id_sub = ? AND id_wilayah = ? LIMIT 1');
        $stmt->bind_param('ii', $f_sub, $f_wilayah);
        $stmt->execute();
        $sub_ada = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$sub_ada) {
            $f_sub = 0;
        }
    }
}

$berhasil        = [];
$dilewati        = [];
$total_nominal   = 0.0;
$dalam_transaksi = false;

try {
    $koneksi->begin_transaction();
    $dalam_transaksi = true;

    foreach ($ids as $id_semeton) {
        // 1. Kunci baris tagihan (serialisasi per tagihan).
        $stmt = $koneksi->prepare(
            'SELECT h.id, h.jumlah_hutang, h.status, s.user_id
             FROM hutang h
             JOIN semeton s ON s.id = h.id_semeton
             WHERE h.id_semeton = ? AND h.id_odalan = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->bind_param('ii', $id_semeton, $id_piodalan);
        $stmt->execute();
        $hutang = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$hutang) {
            $dilewati[] = 'semeton #' . $id_semeton . ' (tidak punya tagihan)';
            continue;
        }

        $label = (string) ($hutang['user_id'] ?? ('#' . $id_semeton));

        if ($hutang['status'] === 'lunas' || (float) $hutang['jumlah_hutang'] <= 0) {
            $dilewati[] = $label . ' (sudah lunas)';
            continue;
        }

        // 2. Tolak bila sudah ada pembayaran sah (data tidak konsisten).
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
            $dilewati[] = $label . ' (sudah ada pembayaran #' . (int) $sudah_bayar['id_pembayaran'] . ')';
            continue;
        }

        $jumlah_bayar = (float) $hutang['jumlah_hutang'];

        // 3. Simpan pembayaran.
        try {
            $stmt = $koneksi->prepare(
                "INSERT INTO pembayaran
                    (id_semeton, id_piodalan, jumlah_bayar, metode_pembayaran, tanggal_bayar, status, id_user)
                 VALUES (?, ?, ?, ?, ?, 'lunas', ?)"
            );
            $stmt->bind_param('iidssi', $id_semeton, $id_piodalan, $jumlah_bayar, $metode, $tgl_bayar, $id_user);
            $stmt->execute();
            $id_pembayaran_baru = (int) $koneksi->insert_id;
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            // Jaring pengaman UNIQUE `uq_pembayaran_aktif` (error 1062).
            if ((int) $e->getCode() === 1062) {
                $dilewati[] = $label . ' (sudah tercatat)';
                continue;
            }
            throw $e;
        }

        // 4. Tutup tagihan (nominal tagihan asli dipertahankan).
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

        if ($terpengaruh !== 1) {
            throw new RuntimeException('Gagal menutup tagihan ' . $label . '. Seluruh proses dibatalkan.');
        }

        kbap_audit_log($koneksi, 'pembayaran', 'INSERT', $id_pembayaran_baru, null, [
            'id_semeton'    => $id_semeton,
            'id_piodalan'   => $id_piodalan,
            'jumlah_bayar'  => $jumlah_bayar,
            'metode'        => $metode,
            'tanggal_bayar' => $tgl_bayar,
            'id_wilayah'    => $f_wilayah > 0 ? $f_wilayah : null,
            'id_sub'        => $f_sub > 0 ? $f_sub : null,
        ], 'Pembayaran urunan (massal)');

        $berhasil[]     = $id_semeton;
        $total_nominal += $jumlah_bayar;
    }

    if (empty($berhasil)) {
        $koneksi->rollback();
        $dalam_transaksi = false;
        $pesan = 'Tidak ada pembayaran yang tercatat.';
        if (!empty($dilewati)) {
            $ringkas = array_slice($dilewati, 0, 5);
            $pesan .= ' ' . count($dilewati) . ' tagihan dilewati: ' . implode(', ', $ringkas)
                . (count($dilewati) > 5 ? ', ...' : '');
        }
        $gagal($pesan);
    }

    $koneksi->commit();
    $dalam_transaksi = false;
} catch (Throwable $e) {
    if ($dalam_transaksi) {
        try {
            $koneksi->rollback();
        } catch (Throwable $abaikan) {
            // Transaksi sudah tidak aktif; tidak perlu ditangani lagi.
        }
    }
    $gagal('Pembayaran massal gagal: ' . $e->getMessage());
}

$params = [
    'menu'    => 'pembayaran_massal',
    'pesan'   => 'bayar_massal',
    'ok'      => count($berhasil),
    'lew'     => count($dilewati),
    'nominal' => $total_nominal,
];
if ($id_piodalan > 0) {
    $params['id_odalan'] = $id_piodalan;
}
if ($f_wilayah > 0) {
    $params['wilayah'] = $f_wilayah;
}
if ($f_sub > 0) {
    $params['sub'] = $f_sub;
}

header('Location: ../index.php?' . http_build_query($params));
exit;
