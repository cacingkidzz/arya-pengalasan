<?php
require_once __DIR__ . '/admin/security.php';
include 'admin/koneksi.php';

// Endpoint ini hanya untuk petugas yang login.
kbap_guard(['admin', 'bagian_data']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: search_semeton.php');
    exit;
}

/** Teks aman (array → ''). */
function kbap_upd_teks($nilai): string
{
    return is_scalar($nilai) ? trim((string) $nilai) : '';
}

/** Teks opsional: '' atau '-' → NULL, dipotong sesuai panjang kolom. */
function kbap_upd_teks_opsional($nilai, int $maks): ?string
{
    $v = kbap_upd_teks($nilai);
    if ($v === '' || $v === '-') {
        return null;
    }
    return mb_substr($v, 0, $maks);
}

/** Nomor 16 digit opsional. false = format salah. */
function kbap_upd_nomor16($nilai)
{
    $v = preg_replace('/\s+/', '', kbap_upd_teks($nilai));
    if ($v === '' || $v === '-') {
        return null;
    }
    return preg_match('/^\d{16}$/', $v) ? $v : false;
}

/** Tanggal opsional YYYY-MM-DD yang benar-benar ada. */
function kbap_upd_tanggal($nilai): ?string
{
    $v = kbap_upd_teks($nilai);
    if ($v === '' || $v === '-') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
}

/** Enum opsional (NULL bila tidak termasuk daftar). */
function kbap_upd_enum($nilai, array $boleh): ?string
{
    $v = kbap_upd_teks($nilai);
    return in_array($v, $boleh, true) ? $v : null;
}

$id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$table  = isset($_POST['table']) && is_scalar($_POST['table']) ? (string) $_POST['table'] : '';
$nama   = kbap_upd_teks($_POST['nama'] ?? '');
$no_hp  = kbap_upd_teks($_POST['no_hp'] ?? '');
$alamat = kbap_upd_teks($_POST['alamat'] ?? '');

// Whitelist tabel: mencegah SQL injection lewat nama tabel.
$tabel_valid = ['semeton', 'semeton_baru'];
if ($id <= 0 || !in_array($table, $tabel_valid, true) || $nama === '') {
    header('Location: search_semeton.php?pesan=gagal');
    exit;
}

// NIK kosong atau "-" disimpan sebagai NULL agar UNIQUE index nik tidak bentrok.
$nik = kbap_upd_nomor16($_POST['nik'] ?? '');
$kk  = kbap_upd_nomor16($_POST['no_kk'] ?? '');
if ($nik === false || $kk === false) {
    header('Location: search_semeton.php?pesan=gagal');
    exit;
}

$email = kbap_upd_teks_opsional($_POST['email'] ?? '', 100);
if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: search_semeton.php?pesan=gagal');
    exit;
}

$tanggal_input = kbap_upd_teks($_POST['tanggal_lahir'] ?? '');
$tanggal       = kbap_upd_tanggal($_POST['tanggal_lahir'] ?? '');
if ($tanggal_input !== '' && $tanggal_input !== '-' && $tanggal === null) {
    header('Location: search_semeton.php?pesan=gagal');
    exit;
}

$gol = strtoupper((string) kbap_upd_teks_opsional($_POST['golongan_darah'] ?? '', 3));
if ($gol === '') {
    $gol = null;
}
if ($gol !== null && !in_array($gol, ['A', 'B', 'AB', 'O'], true)) {
    header('Location: search_semeton.php?pesan=gagal');
    exit;
}

// NIK wajib unik antar-semeton (hanya untuk tabel semeton).
if ($table === 'semeton' && $nik !== null) {
    $cek = $koneksi->prepare('SELECT id FROM semeton WHERE nik = ? AND id <> ? LIMIT 1');
    $cek->bind_param('si', $nik, $id);
    $cek->execute();
    $nik_ada = $cek->get_result()->num_rows > 0;
    $cek->close();
    if ($nik_ada) {
        header('Location: search_semeton.php?pesan=nik_terdaftar');
        exit;
    }
}

// Kolom yang boleh diubah (whitelist) + tipe bind_param.
$kolom = [
    'nama'              => [$nama, 's'],
    'nik'               => [$nik, 's'],
    'jenis_kelamin'     => [kbap_upd_enum($_POST['jenis_kelamin'] ?? '', ['L', 'P']), 's'],
    'no_kk'             => [$kk, 's'],
    'tempat_lahir'      => [kbap_upd_teks_opsional($_POST['tempat_lahir'] ?? '', 100), 's'],
    'tanggal_lahir'     => [$tanggal, 's'],
    'agama'             => [kbap_upd_teks_opsional($_POST['agama'] ?? '', 30), 's'],
    'golongan_darah'    => [$gol, 's'],
    'status_perkawinan' => [kbap_upd_enum($_POST['status_perkawinan'] ?? '', ['belum_kawin', 'kawin', 'cerai_hidup', 'cerai_mati']), 's'],
    'pendidikan'        => [kbap_upd_teks_opsional($_POST['pendidikan'] ?? '', 50), 's'],
    'pekerjaan'         => [kbap_upd_teks_opsional($_POST['pekerjaan'] ?? '', 100), 's'],
    'nama_ayah'         => [kbap_upd_teks_opsional($_POST['nama_ayah'] ?? '', 255), 's'],
    'nama_ibu'          => [kbap_upd_teks_opsional($_POST['nama_ibu'] ?? '', 255), 's'],
    'catatan'           => [kbap_upd_teks_opsional($_POST['catatan'] ?? '', 255), 's'],
    'email'             => [$email, 's'],
    'no_hp'             => [$no_hp, 's'],
    'alamat'            => [$alamat, 's'],
];

$set = implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($kolom)));
$query = "UPDATE `$table` SET $set WHERE id = ?";
$stmt = $koneksi->prepare($query);

if (!$stmt) {
    header('Location: search_semeton.php?pesan=gagal');
    exit;
}

$tipe  = implode('', array_column($kolom, 1)) . 'i';
$nilai = array_column($kolom, 0);
$nilai[] = $id;

$stmt->bind_param($tipe, ...$nilai);

if ($stmt->execute()) {
    $stmt->close();
    header('Location: search_semeton.php?pesan=sukses');
    exit;
}

$stmt->close();
header('Location: search_semeton.php?pesan=gagal');
exit;
