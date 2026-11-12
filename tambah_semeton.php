<?php
include 'admin/koneksi.php'; // Pastikan koneksi ke database sudah benar

// =====================================================================
// FORM PUBLIK PENDAFTARAN (usulan) — diperkeras 2026-11-11
// - Validasi: nama/no_hp/alamat wajib; NIK & No. KK harus 16 digit
//   (boleh kosong); email harus format benar; tanggal lahir harus nyata;
//   wilayah & sub wilayah harus cocok.
// - NIK kosong / "-" disimpan sebagai NULL.
// - Data pendukung baru (jenis_kelamin, no_kk, tempat/tanggal lahir, agama,
//   golongan darah, status perkawinan, pendidikan, pekerjaan, nama ayah/ibu,
//   catatan) ikut disimpan agar tidak hilang saat usulan diverifikasi.
// - Semua query memakai prepared statement.
// =====================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: form_tambah_semeton.php');
    exit;
}

/** Teks aman dari input (array → ''). */
function kbap_public_teks($nilai): string
{
    return is_scalar($nilai) ? trim((string) $nilai) : '';
}

/** Teks opsional: '' atau '-' → NULL, dipotong sesuai panjang kolom. */
function kbap_public_teks_opsional($nilai, int $maks): ?string
{
    $v = kbap_public_teks($nilai);
    if ($v === '' || $v === '-') {
        return null;
    }
    return mb_substr($v, 0, $maks);
}

/** Nomor 16 digit opsional. false = format salah. */
function kbap_public_nomor16($nilai)
{
    $v = preg_replace('/\s+/', '', kbap_public_teks($nilai));
    if ($v === '' || $v === '-') {
        return null;
    }
    return preg_match('/^\d{16}$/', $v) ? $v : false;
}

/** Tanggal opsional YYYY-MM-DD yang benar-benar ada. */
function kbap_public_tanggal($nilai): ?string
{
    $v = kbap_public_teks($nilai);
    if ($v === '' || $v === '-') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
}

/** Enum opsional (NULL bila tidak termasuk daftar). */
function kbap_public_enum($nilai, array $boleh): ?string
{
    $v = kbap_public_teks($nilai);
    return in_array($v, $boleh, true) ? $v : null;
}

$nama   = kbap_public_teks($_POST['nama'] ?? '');
$no_hp  = kbap_public_teks($_POST['no_hp'] ?? '');
$alamat = kbap_public_teks($_POST['alamat'] ?? '');

$nik = kbap_public_nomor16($_POST['nik'] ?? '');
$kk  = kbap_public_nomor16($_POST['no_kk'] ?? '');

$email = kbap_public_teks_opsional($_POST['email'] ?? '', 100);

$tanggal_input = kbap_public_teks($_POST['tanggal_lahir'] ?? '');
$tanggal       = kbap_public_tanggal($_POST['tanggal_lahir'] ?? '');

$wilayah = isset($_POST['wilayah']) && is_scalar($_POST['wilayah']) ? (int) $_POST['wilayah'] : 0;
$sub     = isset($_POST['sub_wilayah']) && is_scalar($_POST['sub_wilayah']) ? (int) $_POST['sub_wilayah'] : 0;

$gol = strtoupper((string) kbap_public_teks_opsional($_POST['golongan_darah'] ?? '', 3));
if ($gol === '') {
    $gol = null;
}

$tidak_valid = $nama === '' || $no_hp === '' || $alamat === ''
    || mb_strlen($nama) > 255 || mb_strlen($no_hp) > 15
    || $nik === false || $kk === false
    || ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL))
    || ($tanggal_input !== '' && $tanggal_input !== '-' && $tanggal === null)
    || ($gol !== null && !in_array($gol, ['A', 'B', 'AB', 'O'], true))
    || $wilayah <= 0 || $sub <= 0;

// Pastikan sub wilayah benar-benar milik wilayah yang dipilih.
if (!$tidak_valid) {
    $cek = $koneksi->prepare('SELECT id_sub FROM sub_wilayah WHERE id_sub = ? AND id_wilayah = ? LIMIT 1');
    if (!$cek) {
        $tidak_valid = true;
    } else {
        $cek->bind_param('ii', $sub, $wilayah);
        $cek->execute();
        $tidak_valid = !$cek->get_result()->fetch_assoc();
        $cek->close();
    }
}

if ($tidak_valid) {
    echo "<script>alert('Data belum lengkap atau tidak valid. Periksa NIK/No. KK (16 digit), email, tanggal lahir, serta wilayah dan sub wilayah.'); window.history.back();</script>";
    exit;
}

$jenis_kelamin     = kbap_public_enum($_POST['jenis_kelamin'] ?? '', ['L', 'P']);
$tempat_lahir      = kbap_public_teks_opsional($_POST['tempat_lahir'] ?? '', 100);
$agama             = kbap_public_teks_opsional($_POST['agama'] ?? '', 30);
$status_perkawinan = kbap_public_enum($_POST['status_perkawinan'] ?? '', ['belum_kawin', 'kawin', 'cerai_hidup', 'cerai_mati']);
$pendidikan        = kbap_public_teks_opsional($_POST['pendidikan'] ?? '', 50);
$pekerjaan         = kbap_public_teks_opsional($_POST['pekerjaan'] ?? '', 100);
$nama_ayah         = kbap_public_teks_opsional($_POST['nama_ayah'] ?? '', 255);
$nama_ibu          = kbap_public_teks_opsional($_POST['nama_ibu'] ?? '', 255);
$catatan           = kbap_public_teks_opsional($_POST['catatan'] ?? '', 255);

// Simpan ke tabel `semeton_baru` (prepared statement).
$query = 'INSERT INTO semeton_baru
            (nama, nik, jenis_kelamin, no_kk, tempat_lahir, tanggal_lahir, agama,
             golongan_darah, status_perkawinan, pendidikan, pekerjaan, nama_ayah,
             nama_ibu, catatan, email, no_hp, alamat, wilayah, sub_wilayah)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
$stmt = $koneksi->prepare($query);

if ($stmt) {
    $stmt->bind_param(
        'sssssssssssssssssii',
        $nama, $nik, $jenis_kelamin, $kk, $tempat_lahir, $tanggal, $agama,
        $gol, $status_perkawinan, $pendidikan, $pekerjaan, $nama_ayah,
        $nama_ibu, $catatan, $email, $no_hp, $alamat, $wilayah, $sub
    );

    if ($stmt->execute()) {
        $stmt->close();
        header('Location: search_semeton.php?pesan=tambah');
        exit;
    }
    $stmt->close();
}

echo "<script>alert('Gagal menambahkan data! Silakan coba lagi.'); window.history.back();</script>";
