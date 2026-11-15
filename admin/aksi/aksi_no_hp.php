<?php
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// INPUT NO. HP LANGSUNG DARI HALAMAN PEMBAYARAN (2026-11-15)
// - Petugas kasir bisa mengisi / mengubah No. HP semeton tanpa harus
//   membuka menu Semeton (permintaan user).
// - Hak akses disamakan dengan menu `pembayaran`: admin + bendahara.
// - Hanya kolom `semeton.no_hp` yang ditulis (tidak ada perubahan skema).
// - Nomor dinormalisasi menjadi digit saja (spasi, titik, strip, kurung,
//   dan awalan "+" dibuang). Panjang wajib 8–15 digit (kolom varchar(15)).
// - Kosong = hapus nomor (disimpan sebagai string kosong, bukan NULL,
//   karena kolom `no_hp` NOT NULL).
// - Setiap perubahan dicatat ke `audit_log` (UPDATE semeton, hanya no_hp).
// =====================================================================

kbap_guard(['admin', 'bendahara']);

$aksi_post = isset($_POST['aksi']) && is_scalar($_POST['aksi']) ? (string) $_POST['aksi'] : '';

$teks = static function ($nilai): string {
    return is_scalar($nilai) ? trim((string) $nilai) : '';
};

/** Normalisasi nomor HP: sisakan digit saja. */
$normalisasi = static function (string $v): string {
    return preg_replace('/\D+/', '', $v) ?? '';
};

// --- Parameter filter halaman asal (dipakai untuk redirect) ---
$sumber = $teks($_POST['sumber'] ?? '') === 'detail' ? 'detail' : 'pembayaran';

$f_q = $teks($_POST['q'] ?? '');
if (mb_strlen($f_q) > 100) {
    $f_q = mb_substr($f_q, 0, 100);
}

$f_wilayah = isset($_POST['wilayah']) && is_scalar($_POST['wilayah']) ? (int) $_POST['wilayah'] : 0;
if ($f_wilayah < 0) {
    $f_wilayah = 0;
}

$f_status = $teks($_POST['status'] ?? '');
if (!in_array($f_status, ['Lunas', 'Belum Lunas'], true)) {
    $f_status = '';
}

$f_per = isset($_POST['per']) && is_scalar($_POST['per']) ? (int) $_POST['per'] : 50;
if (!in_array($f_per, [0, 25, 50, 100, 250], true)) {
    $f_per = 50;
}

$f_hal = isset($_POST['hal']) && is_scalar($_POST['hal']) ? (int) $_POST['hal'] : 1;
if ($f_hal < 1) {
    $f_hal = 1;
}

/**
 * Redirect kembali ke halaman asal.
 * - sumber=detail  -> kembali ke layar kasir semeton tersebut.
 * - sumber=pembayaran -> kembali ke daftar pembayaran dengan filter aktif.
 */
$kembali = static function (string $pesan, string $user_id) use ($sumber, $f_q, $f_wilayah, $f_status, $f_per, $f_hal): void {
    // PENTING: halaman pembayaran memakai `status` untuk FILTER
    // (Lunas / Belum Lunas), jadi notifikasi dikirim lewat `pesan`
    // agar filter tidak tertimpa.
    if ($sumber === 'detail') {
        header('Location: ../index.php?menu=pembayaran_detail&id=' . rawurlencode($user_id) . '&pesan=' . rawurlencode($pesan));
        exit;
    }

    $param = ['menu' => 'pembayaran'];
    if ($f_q !== '') {
        $param['q'] = $f_q;
    }
    if ($f_wilayah > 0) {
        $param['wilayah'] = $f_wilayah;
    }
    if ($f_status !== '') {
        $param['status'] = $f_status;
    }
    if ($f_per !== 50) {
        $param['per'] = $f_per;
    }
    if ($f_hal > 1) {
        $param['hal'] = $f_hal;
    }
    $param['pesan'] = $pesan;

    header('Location: ../index.php?' . http_build_query($param));
    exit;
};

// ---------------------------------------------------------------- SIMPAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'simpan_no_hp') {
    $user_id = $teks($_POST['user_id'] ?? '');
    if ($user_id === '' || mb_strlen($user_id) > 20) {
        $kembali('no_hp_gagal', '');
    }

    $no_hp_mentah = $teks($_POST['no_hp'] ?? '');
    $no_hp        = $normalisasi($no_hp_mentah);

    if ($no_hp_mentah === '' || $no_hp_mentah === '-') {
        // Kosong = hapus nomor (kolom NOT NULL, jadi disimpan '').
        $no_hp = '';
    } elseif ($no_hp === '') {
        // Ada isi tetapi tidak ada satu pun digit (mis. "abc") → tolak,
        // jangan diam-diam menghapus nomor.
        $kembali('no_hp_gagal', $user_id);
    }

    // Selain kosong wajib 8–15 digit (kolom varchar(15)).
    if ($no_hp !== '' && (strlen($no_hp) < 8 || strlen($no_hp) > 15)) {
        $kembali('no_hp_gagal', $user_id);
    }
    if (mb_strlen($no_hp_mentah) > 40) { // jaga-jaga input aneh
        $kembali('no_hp_gagal', $user_id);
    }

    // Ambil baris semeton (prepared statement).
    $lama = null;
    if ($stmt = $koneksi->prepare('SELECT id, user_id, nama, no_hp FROM semeton WHERE user_id = ? LIMIT 1')) {
        $stmt->bind_param('s', $user_id);
        $stmt->execute();
        $lama = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }

    if (!$lama) {
        $kembali('no_hp_gagal', $user_id);
    }

    $no_hp_lama = (string) ($lama['no_hp'] ?? '');

    if ($no_hp_lama === $no_hp) {
        // Tidak ada perubahan — tetap dianggap berhasil.
        $kembali('no_hp', $user_id);
    }

    try {
        $stmt = $koneksi->prepare('UPDATE semeton SET no_hp = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
        if (!$stmt) {
            $kembali('no_hp_gagal', $user_id);
        }
        $id_semeton = (int) $lama['id'];
        $stmt->bind_param('si', $no_hp, $id_semeton);
        $stmt->execute();
        $berubah = $stmt->affected_rows;
        $stmt->close();

        if ($berubah < 1) {
            $kembali('no_hp_gagal', $user_id);
        }

        kbap_audit_log(
            $koneksi,
            'semeton',
            'UPDATE',
            $id_semeton,
            ['no_hp' => $no_hp_lama],
            ['no_hp' => $no_hp],
            'Ubah No. HP dari halaman pembayaran (' . (string) $lama['user_id'] . ')'
        );
    } catch (Throwable $e) {
        error_log('aksi_no_hp: ' . $e->getMessage());
        $kembali('no_hp_gagal', $user_id);
    }

    $kembali('no_hp', $user_id);
}

// Tanpa aksi yang dikenal: kembali ke daftar pembayaran.
$kembali('no_hp_gagal', '');
