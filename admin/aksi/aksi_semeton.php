<?php
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bagian_data']);
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../libs/phpqrcode/qrlib.php';

// =====================================================================
// DIPERKERAS + DATA PENDUKUNG (2026-11-11)
// - Semua query tulis memakai prepared statement (dulu INSERT/UPDATE
//   disusun dari real_escape_string, `id`/`id_wilayah` disisipkan mentah).
// - Kolom baru `semeton` (lihat migrasi 2026-11-11): jenis_kelamin,
//   no_kk, tempat_lahir, tanggal_lahir, agama, golongan_darah,
//   status_perkawinan, pendidikan, pekerjaan, nama_ayah, nama_ibu,
//   catatan, updated_at.
// - Validasi: NIK & No. KK harus 16 digit (boleh kosong), tanggal lahir
//   harus tanggal nyata, wilayah/sub wilayah harus cocok, enum diwhitelist.
// - Perilaku hapus TIDAK diubah: hutang anggota dihapus lebih dulu, lalu
//   baris semeton (pembayaran mengikuti ON DELETE CASCADE yang sudah ada).
// =====================================================================

/** Ambil teks dari input (aman untuk array). */
function kbap_input($nilai): string
{
    return is_scalar($nilai) ? trim((string) $nilai) : '';
}

/** Teks opsional: '' atau '-' dianggap NULL, dipotong sesuai panjang kolom. */
function kbap_teks_opsional($nilai, int $maks): ?string
{
    $v = kbap_input($nilai);
    if ($v === '' || $v === '-') {
        return null;
    }
    return mb_substr($v, 0, $maks);
}

/** Tanggal opsional: hanya menerima format YYYY-MM-DD yang benar-benar ada. */
function kbap_tanggal_opsional($nilai): ?string
{
    $v = kbap_input($nilai);
    if ($v === '' || $v === '-') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
}

/** Nilai enum: kembalikan NULL bila tidak termasuk daftar yang diizinkan. */
function kbap_enum_opsional($nilai, array $boleh): ?string
{
    $v = kbap_input($nilai);
    return in_array($v, $boleh, true) ? $v : null;
}

/**
 * Nomor 16 digit opsional (NIK / No. KK).
 * Mengembalikan null (kosong), string 16 digit, atau false (format salah).
 * SENGAJA tanpa return type: `?string` akan meng-coerce false menjadi ''.
 */
function kbap_nomor16($nilai)
{
    $v = preg_replace('/\s+/', '', kbap_input($nilai));
    if ($v === '' || $v === '-') {
        return null;
    }
    return preg_match('/^\d{16}$/', $v) ? $v : false;
}

/** Buat user_id otomatis (APPxxxxx) berdasarkan nomor terbesar. */
function generateUserId($koneksi): string
{
    $result = mysqli_query($koneksi, "SELECT MAX(CAST(SUBSTRING(user_id, 4) AS UNSIGNED)) AS max_id FROM semeton");
    $fetch  = $result ? mysqli_fetch_assoc($result) : ['max_id' => 0];
    $number = (int) ($fetch['max_id'] ?? 0) + 1;
    return 'APP' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
}

/**
 * Buat QR Code anggota.
 *
 * Kembalikan **nama file saja** (mis. `APP00007.png`) agar konsisten dengan
 * generator kartu (`generate_satu_kartu.php` / `generate_all_kartu.php`) yang
 * membaca `basename(qr_code)`. Sebelumnya nilai ini berupa `../qr_codes/...`
 * sehingga pemeriksaan file di halaman lain bisa gagal.
 */
function generateQRCode(string $user_id): string
{
    $qr_dir = __DIR__ . '/../qr_codes/';
    if (!file_exists($qr_dir)) {
        mkdir($qr_dir, 0777, true);
    }
    $file_path = $qr_dir . $user_id . '.png';
    QRcode::png('ID: ' . $user_id, $file_path, QR_ECLEVEL_L, 10);
    return $user_id . '.png';
}

/** Baca satu baris semeton (untuk audit & validasi edit). */
function kbap_ambil_semeton($koneksi, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $koneksi->prepare('SELECT * FROM semeton WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Validasi pasangan wilayah + sub wilayah (sub harus milik wilayah tsb). */
function kbap_validasi_wilayah($koneksi, int $id_wilayah, int $id_sub): bool
{
    if ($id_wilayah <= 0 || $id_sub <= 0) {
        return false;
    }
    $stmt = $koneksi->prepare('SELECT id_sub FROM sub_wilayah WHERE id_sub = ? AND id_wilayah = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $id_sub, $id_wilayah);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ok;
}

/**
 * Kumpulkan & validasi seluruh isian form.
 * Mengembalikan array data siap simpan, atau null bila ada isian tidak valid.
 */
function kbap_kumpulkan_data($koneksi): ?array
{
    $nama    = kbap_input($_POST['nama'] ?? '');
    $jenis   = kbap_enum_opsional($_POST['jenis'] ?? '', ['ngarep', 'ngempi', 'pemangku']);
    $jabatan = kbap_input($_POST['jabatan'] ?? 'Anggota');

    if ($nama === '' || mb_strlen($nama) > 255 || $jenis === null
        || $jabatan === '' || mb_strlen($jabatan) > 50) {
        return null;
    }

    $nik = kbap_nomor16($_POST['nik'] ?? '');
    $kk  = kbap_nomor16($_POST['no_kk'] ?? '');
    if ($nik === false || $kk === false) {
        return null; // format NIK / No. KK salah (harus 16 digit)
    }

    $email = kbap_teks_opsional($_POST['email'] ?? '', 255);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $no_hp  = kbap_teks_opsional($_POST['no_hp'] ?? '', 15);
    $alamat = kbap_input($_POST['alamat'] ?? '');
    if (mb_strlen($alamat) > 65535) {
        return null;
    }

    $id_wilayah = isset($_POST['id_wilayah']) && is_scalar($_POST['id_wilayah']) ? (int) $_POST['id_wilayah'] : 0;
    $sub        = isset($_POST['sub_wilayah']) && is_scalar($_POST['sub_wilayah']) ? (int) $_POST['sub_wilayah'] : 0;
    if (!kbap_validasi_wilayah($koneksi, $id_wilayah, $sub)) {
        return null;
    }

    $gol_darah = strtoupper((string) kbap_teks_opsional($_POST['golongan_darah'] ?? '', 3));
    if ($gol_darah === '' || $gol_darah === '-') {
        $gol_darah = null;
    }
    if ($gol_darah !== null && !in_array($gol_darah, ['A', 'B', 'AB', 'O'], true)) {
        return null;
    }

    // Tanggal lahir: kosong boleh, tetapi kalau diisi harus tanggal nyata.
    $tanggal_input = kbap_input($_POST['tanggal_lahir'] ?? '');
    $tanggal       = kbap_tanggal_opsional($_POST['tanggal_lahir'] ?? '');
    if ($tanggal_input !== '' && $tanggal_input !== '-' && $tanggal === null) {
        return null;
    }

    return [
        'nama'              => $nama,
        'nik'               => $nik,
        'jenis_kelamin'     => kbap_enum_opsional($_POST['jenis_kelamin'] ?? '', ['L', 'P']),
        'no_kk'             => $kk,
        'tempat_lahir'      => kbap_teks_opsional($_POST['tempat_lahir'] ?? '', 100),
        'tanggal_lahir'     => $tanggal,
        'agama'             => kbap_teks_opsional($_POST['agama'] ?? '', 30),
        'golongan_darah'    => $gol_darah,
        'status_perkawinan' => kbap_enum_opsional($_POST['status_perkawinan'] ?? '', ['belum_kawin', 'kawin', 'cerai_hidup', 'cerai_mati']),
        'pendidikan'        => kbap_teks_opsional($_POST['pendidikan'] ?? '', 50),
        'pekerjaan'         => kbap_teks_opsional($_POST['pekerjaan'] ?? '', 100),
        'nama_ayah'         => kbap_teks_opsional($_POST['nama_ayah'] ?? '', 255),
        'nama_ibu'          => kbap_teks_opsional($_POST['nama_ibu'] ?? '', 255),
        'catatan'           => kbap_teks_opsional($_POST['catatan'] ?? '', 255),
        'email'             => $email,
        'no_hp'             => $no_hp,
        'alamat'            => $alamat,
        'wilayah'           => $id_wilayah,
        'sub_wilayah'       => $sub,
        'jenis_user'        => $jenis,
        'jabatan'           => $jabatan,
    ];
}

/** NIK wajib unik antar-semeton (NULL boleh berulang). */
function kbap_nik_dipakai($koneksi, ?string $nik, int $kecuali_id = 0): bool
{
    if ($nik === null) {
        return false;
    }
    $stmt = $koneksi->prepare('SELECT id FROM semeton WHERE nik = ? AND id <> ? LIMIT 1');
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('si', $nik, $kecuali_id);
    $stmt->execute();
    $ada = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ada;
}

$aksi_post = isset($_POST['aksi']) && is_scalar($_POST['aksi']) ? (string) $_POST['aksi'] : '';
$aksi_get  = isset($_GET['aksi']) && is_scalar($_GET['aksi']) ? (string) $_GET['aksi'] : '';

// =========================================================================
// AKSI POST: TAMBAH
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'tambah') {
    $data = kbap_kumpulkan_data($koneksi);
    if ($data === null) {
        header('Location: ../index.php?menu=semeton&status=data_invalid');
        exit;
    }
    if (kbap_nik_dipakai($koneksi, $data['nik'])) {
        header('Location: ../index.php?menu=semeton&status=nik_terdaftar');
        exit;
    }

    $user_id = generateUserId($koneksi);
    $qr_path = generateQRCode($user_id);
    $status  = 'aktif';

    $kolom = [
        'user_id' => [$user_id, 's'],
        'nama' => [$data['nama'], 's'],
        'nik' => [$data['nik'], 's'],
        'jenis_kelamin' => [$data['jenis_kelamin'], 's'],
        'no_kk' => [$data['no_kk'], 's'],
        'tempat_lahir' => [$data['tempat_lahir'], 's'],
        'tanggal_lahir' => [$data['tanggal_lahir'], 's'],
        'agama' => [$data['agama'], 's'],
        'golongan_darah' => [$data['golongan_darah'], 's'],
        'status_perkawinan' => [$data['status_perkawinan'], 's'],
        'pendidikan' => [$data['pendidikan'], 's'],
        'pekerjaan' => [$data['pekerjaan'], 's'],
        'nama_ayah' => [$data['nama_ayah'], 's'],
        'nama_ibu' => [$data['nama_ibu'], 's'],
        'catatan' => [$data['catatan'], 's'],
        'email' => [$data['email'], 's'],
        'no_hp' => [$data['no_hp'], 's'],
        'alamat' => [$data['alamat'], 's'],
        'wilayah' => [$data['wilayah'], 'i'],
        'sub_wilayah' => [$data['sub_wilayah'], 'i'],
        'jenis_user' => [$data['jenis_user'], 's'],
        'jabatan' => [$data['jabatan'], 's'],
        'qr_code' => [$qr_path, 's'],
        'status' => [$status, 's'],
    ];

    $sql = 'INSERT INTO semeton (' . implode(', ', array_keys($kolom)) . ') VALUES ('
        . implode(', ', array_fill(0, count($kolom), '?')) . ')';
    $stmt = $koneksi->prepare($sql);

    if ($stmt) {
        $tipe  = implode('', array_column($kolom, 1));
        $nilai = array_column($kolom, 0);
        $stmt->bind_param($tipe, ...$nilai);
        if ($stmt->execute()) {
            $id_baru = (int) $stmt->insert_id;
            $stmt->close();
            kbap_audit_log($koneksi, 'semeton', 'INSERT', $id_baru, null, [
                'user_id'        => $user_id,
                'nama'           => $data['nama'],
                'nik'            => $data['nik'],
                'jenis_kelamin'  => $data['jenis_kelamin'],
                'no_kk'          => $data['no_kk'],
                'jenis_user'     => $data['jenis_user'],
                'status'         => $status,
            ], 'Tambah semeton ' . $user_id);
            header('Location: ../index.php?menu=semeton&status=sukses');
            exit;
        }
        $stmt->close();
    }

    // Gagal simpan: hapus QR yang sudah dibuat agar tidak ada file yatim.
    if (file_exists($qr_path)) {
        unlink($qr_path);
    }
    header('Location: ../index.php?menu=semeton&status=gagal');
    exit;
}

// =========================================================================
// AKSI POST: EDIT
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'edit') {
    $id = isset($_POST['id']) && is_scalar($_POST['id']) ? (int) $_POST['id'] : 0;

    $data_lama = kbap_ambil_semeton($koneksi, $id);
    if (!$data_lama) {
        header('Location: ../index.php?menu=semeton&status=gagal');
        exit;
    }

    $data = kbap_kumpulkan_data($koneksi);
    if ($data === null) {
        header('Location: ../index.php?menu=semeton&status=data_invalid');
        exit;
    }
    if (kbap_nik_dipakai($koneksi, $data['nik'], $id)) {
        header('Location: ../index.php?menu=semeton&status=nik_terdaftar');
        exit;
    }

    $status = kbap_enum_opsional($_POST['status'] ?? '', ['aktif', 'tidak_aktif']) ?? 'aktif';

    $kolom = [
        'nama' => [$data['nama'], 's'],
        'nik' => [$data['nik'], 's'],
        'jenis_kelamin' => [$data['jenis_kelamin'], 's'],
        'no_kk' => [$data['no_kk'], 's'],
        'tempat_lahir' => [$data['tempat_lahir'], 's'],
        'tanggal_lahir' => [$data['tanggal_lahir'], 's'],
        'agama' => [$data['agama'], 's'],
        'golongan_darah' => [$data['golongan_darah'], 's'],
        'status_perkawinan' => [$data['status_perkawinan'], 's'],
        'pendidikan' => [$data['pendidikan'], 's'],
        'pekerjaan' => [$data['pekerjaan'], 's'],
        'nama_ayah' => [$data['nama_ayah'], 's'],
        'nama_ibu' => [$data['nama_ibu'], 's'],
        'catatan' => [$data['catatan'], 's'],
        'email' => [$data['email'], 's'],
        'no_hp' => [$data['no_hp'], 's'],
        'alamat' => [$data['alamat'], 's'],
        'wilayah' => [$data['wilayah'], 'i'],
        'sub_wilayah' => [$data['sub_wilayah'], 'i'],
        'jenis_user' => [$data['jenis_user'], 's'],
        'jabatan' => [$data['jabatan'], 's'],
        'status' => [$status, 's'],
    ];

    $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($kolom)));
    $stmt = $koneksi->prepare("UPDATE semeton SET $set WHERE id = ?");

    if ($stmt) {
        $tipe  = implode('', array_column($kolom, 1)) . 'i';
        $nilai = array_column($kolom, 0);
        $nilai[] = $id;
        $stmt->bind_param($tipe, ...$nilai);
        if ($stmt->execute()) {
            $stmt->close();
            kbap_audit_log($koneksi, 'semeton', 'UPDATE', $id, $data_lama, [
                'nama'           => $data['nama'],
                'nik'            => $data['nik'],
                'jenis_kelamin'  => $data['jenis_kelamin'],
                'no_kk'          => $data['no_kk'],
                'tanggal_lahir'  => $data['tanggal_lahir'],
                'jenis_user'     => $data['jenis_user'],
                'jabatan'        => $data['jabatan'],
                'status'         => $status,
            ], 'Ubah semeton id ' . $id);
            header('Location: ../index.php?menu=semeton&status=edit');
            exit;
        }
        $stmt->close();
    }

    header('Location: ../index.php?menu=semeton&status=gagal');
    exit;
}

// =========================================================================
// AKSI GET: HAPUS
// Perilaku lama dipertahankan: hutang dihapus dulu, lalu baris semeton
// (pembayaran mengikuti CASCADE `pembayaran_ibfk_1` yang sudah ada).
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $aksi_get === 'hapus') {
    $id = isset($_GET['id']) && is_scalar($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        header('Location: ../index.php?menu=semeton&status=gagal');
        exit;
    }

    $data = kbap_ambil_semeton($koneksi, $id);
    if (!$data) {
        header('Location: ../index.php?menu=semeton&status=gagal');
        exit;
    }

    $koneksi->begin_transaction();
    try {
        $stmt_h = $koneksi->prepare('DELETE FROM hutang WHERE id_semeton = ?');
        $stmt_h->bind_param('i', $id);
        $stmt_h->execute();
        $stmt_h->close();

        $stmt_s = $koneksi->prepare('DELETE FROM semeton WHERE id = ?');
        $stmt_s->bind_param('i', $id);
        $stmt_s->execute();
        $terhapus = $stmt_s->affected_rows;
        $stmt_s->close();

        if ($terhapus < 1) {
            throw new RuntimeException('Baris semeton tidak terhapus.');
        }

        $koneksi->commit();
    } catch (Throwable $e) {
        $koneksi->rollback();
        error_log('KBAP hapus semeton gagal: ' . $e->getMessage());
        header('Location: ../index.php?menu=semeton&status=gagal');
        exit;
    }

    // Hapus file QR & kartu setelah DB sukses (di luar transaksi).
    $user_id = (string) $data['user_id'];
    foreach ([
        (string) $data['qr_code'],
        __DIR__ . '/../qr_codes/' . $user_id . '.png',
        __DIR__ . '/../kartu_anggota/kartu_' . $user_id . '.jpg',
    ] as $file) {
        if ($file !== '' && file_exists($file)) {
            @unlink($file);
        }
    }

    kbap_audit_log($koneksi, 'semeton', 'DELETE', $id, [
        'user_id' => $data['user_id'],
        'nama'    => $data['nama'],
        'nik'     => $data['nik'],
    ], null, 'Hapus semeton ' . $data['user_id']);

    header('Location: ../index.php?menu=semeton&status=hapus');
    exit;
}

header('Location: ../index.php?menu=semeton');
exit;
