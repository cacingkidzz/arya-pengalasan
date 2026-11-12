<?php
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bagian_data']);
require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// DIPERKERAS + DATA PENDUKUNG (2026-11-11)
// - Semua query memakai prepared statement.
// - Validasi: nama/no_hp/alamat wajib, panjang mengikuti kolom, NIK & No. KK
//   harus 16 digit (boleh kosong), email harus format benar, tanggal lahir
//   harus tanggal nyata, enum diwhitelist, wilayah & sub wilayah harus ada
//   dan sub wilayah harus milik wilayah yang dipilih.
// - NIK kosong / "-" disimpan sebagai NULL (konsisten dengan semeton, 10.14).
// - Kolom baru (lihat migrasi 2026-11-11): jenis_kelamin, no_kk,
//   tempat_lahir, tanggal_lahir, agama, golongan_darah, status_perkawinan,
//   pendidikan, pekerjaan, nama_ayah, nama_ibu, catatan.
// - Edit: baris lama dibaca dulu (audit + tolak id tak ada).
// - Hapus: audit hanya bila ada baris terhapus; semua cabang exit().
// =====================================================================
$aksi_post = isset($_POST['aksi']) && is_scalar($_POST['aksi']) ? (string) $_POST['aksi'] : '';
$aksi_get  = isset($_GET['aksi']) && is_scalar($_GET['aksi']) ? (string) $_GET['aksi'] : '';

$teks = static function ($nilai): string {
    return is_scalar($nilai) ? trim((string) $nilai) : '';
};

/** Teks opsional: '' atau '-' dianggap NULL, dipotong sesuai panjang kolom. */
$teks_opsional = static function ($nilai, int $maks): ?string {
    $v = is_scalar($nilai) ? trim((string) $nilai) : '';
    if ($v === '' || $v === '-') {
        return null;
    }
    return mb_substr($v, 0, $maks);
};

/** Nomor 16 digit opsional (NIK / No. KK). false = format salah. */
$nomor16 = static function ($nilai) {
    $v = preg_replace('/\s+/', '', is_scalar($nilai) ? trim((string) $nilai) : '');
    if ($v === '' || $v === '-') {
        return null;
    }
    return preg_match('/^\d{16}$/', $v) ? $v : false;
};

/** Tanggal opsional YYYY-MM-DD yang benar-benar ada. */
$tanggal_opsional = static function ($nilai): ?string {
    $v = is_scalar($nilai) ? trim((string) $nilai) : '';
    if ($v === '' || $v === '-') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
};

/** Nilai enum opsional (NULL bila tidak termasuk daftar). */
$enum_opsional = static function ($nilai, array $boleh): ?string {
    $v = is_scalar($nilai) ? trim((string) $nilai) : '';
    return in_array($v, $boleh, true) ? $v : null;
};

/**
 * Kumpulkan & validasi isian form. Mengembalikan array data siap simpan,
 * atau null bila ada isian tidak valid.
 */
$kumpulkan = static function ($koneksi, $validasi_wilayah) use ($teks, $teks_opsional, $nomor16, $tanggal_opsional, $enum_opsional): ?array {
    $nama   = $teks($_POST['nama'] ?? '');
    $no_hp  = $teks($_POST['no_hp'] ?? '');
    $alamat = $teks($_POST['alamat'] ?? '');

    if ($nama === '' || $no_hp === '' || $alamat === ''
        || mb_strlen($nama) > 255 || mb_strlen($no_hp) > 15) {
        return null;
    }

    $email = $teks_opsional($_POST['email'] ?? '', 100);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $nik = $nomor16($_POST['nik'] ?? '');
    $kk  = $nomor16($_POST['no_kk'] ?? '');
    if ($nik === false || $kk === false) {
        return null;
    }

    $tanggal_input = $teks($_POST['tanggal_lahir'] ?? '');
    $tanggal       = $tanggal_opsional($_POST['tanggal_lahir'] ?? '');
    if ($tanggal_input !== '' && $tanggal_input !== '-' && $tanggal === null) {
        return null; // tanggal diisi tetapi tidak valid
    }

    $gol = $enum_opsional(strtoupper($teks($_POST['golongan_darah'] ?? '')), ['A', 'B', 'AB', 'O']);

    $id_wilayah = isset($_POST['id_wilayah']) && is_scalar($_POST['id_wilayah']) ? (int) $_POST['id_wilayah'] : 0;
    $sub        = isset($_POST['sub_wilayah']) && is_scalar($_POST['sub_wilayah']) ? (int) $_POST['sub_wilayah'] : 0;
    if (!($wilayah_sub = $validasi_wilayah($koneksi, $id_wilayah, $sub))) {
        return null;
    }

    return [
        'nama'              => $nama,
        'nik'               => $nik,
        'jenis_kelamin'     => $enum_opsional($_POST['jenis_kelamin'] ?? '', ['L', 'P']),
        'no_kk'             => $kk,
        'tempat_lahir'      => $teks_opsional($_POST['tempat_lahir'] ?? '', 100),
        'tanggal_lahir'     => $tanggal,
        'agama'             => $teks_opsional($_POST['agama'] ?? '', 30),
        'golongan_darah'    => $gol,
        'status_perkawinan' => $enum_opsional($_POST['status_perkawinan'] ?? '', ['belum_kawin', 'kawin', 'cerai_hidup', 'cerai_mati']),
        'pendidikan'        => $teks_opsional($_POST['pendidikan'] ?? '', 50),
        'pekerjaan'         => $teks_opsional($_POST['pekerjaan'] ?? '', 100),
        'nama_ayah'         => $teks_opsional($_POST['nama_ayah'] ?? '', 255),
        'nama_ibu'          => $teks_opsional($_POST['nama_ibu'] ?? '', 255),
        'catatan'           => $teks_opsional($_POST['catatan'] ?? '', 255),
        'email'             => $email,
        'no_hp'             => $no_hp,
        'alamat'            => $alamat,
        'wilayah'           => $wilayah_sub[0],
        'sub_wilayah'       => $wilayah_sub[1],
    ];
};

/**
 * Validasi wilayah + sub wilayah. Mengembalikan array [wilayah, sub] bila valid,
 * atau null bila tidak (sub wilayah harus benar-benar milik wilayah tersebut).
 */
$validasi_wilayah = static function ($koneksi, int $id_wilayah, int $id_sub): ?array {
    if ($id_wilayah <= 0 || $id_sub <= 0) {
        return null;
    }
    if (!$stmt = $koneksi->prepare('SELECT id_sub FROM sub_wilayah WHERE id_sub = ? AND id_wilayah = ? LIMIT 1')) {
        return null;
    }
    $stmt->bind_param('ii', $id_sub, $id_wilayah);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ok ? [$id_wilayah, $id_sub] : null;
};

/** Ambil data usulan berdasarkan id (null bila tidak ada). */
$ambil_usulan = static function ($koneksi, int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    if (!$stmt = $koneksi->prepare('SELECT * FROM semeton_baru WHERE id = ? LIMIT 1')) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
};

/** NIK sudah dipakai usulan lain? (NULL boleh berulang) */
$nik_dipakai = static function ($koneksi, ?string $nik, int $kecuali_id = 0): bool {
    if ($nik === null) {
        return false;
    }
    if (!$stmt = $koneksi->prepare('SELECT id FROM semeton_baru WHERE nik = ? AND id <> ? LIMIT 1')) {
        return true;
    }
    $stmt->bind_param('si', $nik, $kecuali_id);
    $stmt->execute();
    $ada = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ada;
};

// Daftar kolom data pendukung yang disimpan (dipakai INSERT & UPDATE).
$kolom_pendukung = [
    'jenis_kelamin', 'no_kk', 'tempat_lahir', 'tanggal_lahir', 'agama',
    'golongan_darah', 'status_perkawinan', 'pendidikan', 'pekerjaan',
    'nama_ayah', 'nama_ibu', 'catatan',
];

// 1. TAMBAH USULAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'tambah') {
    $data = $kumpulkan($koneksi, $validasi_wilayah);
    if ($data === null) {
        header('Location: ../index.php?menu=semeton_baru&status=data_invalid');
        exit;
    }
    if ($nik_dipakai($koneksi, $data['nik'])) {
        header('Location: ../index.php?menu=semeton_baru&status=nik_terdaftar');
        exit;
    }

    $kolom = ['nama', 'nik', 'email', 'no_hp', 'alamat', 'wilayah', 'sub_wilayah'];
    $tipe  = 'sssssii';
    $nilai = [
        $data['nama'], $data['nik'], $data['email'], $data['no_hp'],
        $data['alamat'], $data['wilayah'], $data['sub_wilayah'],
    ];
    foreach ($kolom_pendukung as $k) {
        $kolom[] = $k;
        $tipe   .= 's';
        $nilai[] = $data[$k];
    }

    $sql = 'INSERT INTO semeton_baru (' . implode(', ', $kolom) . ') VALUES ('
        . implode(', ', array_fill(0, count($kolom), '?')) . ')';
    $stmt = $koneksi->prepare($sql);

    if ($stmt) {
        $stmt->bind_param($tipe, ...$nilai);
        if ($stmt->execute()) {
            $id_baru = (int) $stmt->insert_id;
            $stmt->close();
            kbap_audit_log($koneksi, 'semeton_baru', 'INSERT', $id_baru, null, [
                'nama'          => $data['nama'],
                'nik'           => $data['nik'],
                'jenis_kelamin' => $data['jenis_kelamin'],
                'no_kk'         => $data['no_kk'],
                'wilayah'       => $data['wilayah'],
                'sub_wilayah'   => $data['sub_wilayah'],
            ], 'Tambah usulan anggota ' . $data['nama']);
            header('Location: ../index.php?menu=semeton_baru&status=sukses');
            exit;
        }
        $stmt->close();
    }
    header('Location: ../index.php?menu=semeton_baru&status=gagal');
    exit;
}

// 2. EDIT USULAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksi_post === 'edit') {
    $id = isset($_POST['id']) && is_scalar($_POST['id']) ? (int) $_POST['id'] : 0;

    $data_lama = $ambil_usulan($koneksi, $id);
    if (!$data_lama) {
        header('Location: ../index.php?menu=semeton_baru&status=gagal');
        exit;
    }

    $data = $kumpulkan($koneksi, $validasi_wilayah);
    if ($data === null) {
        header('Location: ../index.php?menu=semeton_baru&status=data_invalid');
        exit;
    }
    if ($nik_dipakai($koneksi, $data['nik'], $id)) {
        header('Location: ../index.php?menu=semeton_baru&status=nik_terdaftar');
        exit;
    }

    $kolom = ['nama', 'nik', 'email', 'no_hp', 'alamat', 'wilayah', 'sub_wilayah'];
    $tipe  = 'sssssii';
    $nilai = [
        $data['nama'], $data['nik'], $data['email'], $data['no_hp'],
        $data['alamat'], $data['wilayah'], $data['sub_wilayah'],
    ];
    foreach ($kolom_pendukung as $k) {
        $kolom[] = $k;
        $tipe   .= 's';
        $nilai[] = $data[$k];
    }

    $set = implode(', ', array_map(static fn($k) => "$k = ?", $kolom));
    $stmt = $koneksi->prepare("UPDATE semeton_baru SET $set WHERE id = ?");

    if ($stmt) {
        $tipe   .= 'i';
        $nilai[] = $id;
        $stmt->bind_param($tipe, ...$nilai);
        if ($stmt->execute()) {
            $stmt->close();
            kbap_audit_log($koneksi, 'semeton_baru', 'UPDATE', $id, $data_lama, [
                'nama'          => $data['nama'],
                'nik'           => $data['nik'],
                'jenis_kelamin' => $data['jenis_kelamin'],
                'no_kk'         => $data['no_kk'],
                'wilayah'       => $data['wilayah'],
                'sub_wilayah'   => $data['sub_wilayah'],
            ], 'Ubah usulan anggota id ' . $id);
            header('Location: ../index.php?menu=semeton_baru&status=edit');
            exit;
        }
        $stmt->close();
    }
    header('Location: ../index.php?menu=semeton_baru&status=gagal');
    exit;
}

// 3. HAPUS USULAN (GET + token CSRF, dijaga kbap_guard)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $aksi_get === 'hapus') {
    $id = isset($_GET['id']) && is_scalar($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        header('Location: ../index.php?menu=semeton_baru&status=gagal');
        exit;
    }

    $data_lama = $ambil_usulan($koneksi, $id);

    $stmt = $koneksi->prepare('DELETE FROM semeton_baru WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $terhapus = $stmt->affected_rows;
    $stmt->close();

    if ($terhapus > 0) {
        kbap_audit_log($koneksi, 'semeton_baru', 'DELETE', $id, $data_lama, null,
            'Hapus usulan anggota id ' . $id . ($data_lama ? ' (' . $data_lama['nama'] . ')' : ''));
        header('Location: ../index.php?menu=semeton_baru&status=hapus');
        exit;
    }

    header('Location: ../index.php?menu=semeton_baru&status=gagal');
    exit;
}

header('Location: ../index.php?menu=semeton_baru');
exit;
