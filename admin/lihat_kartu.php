<?php
require_once __DIR__ . '/security.php';
kbap_guard(['admin', 'bagian_data'], false); // data anggota: wajib login

include 'koneksi.php'; // Sesuaikan dengan file koneksi database Anda

$user_id = isset($_GET['user_id']) ? trim((string) $_GET['user_id']) : '';

if ($user_id === '') {
    http_response_code(400);
    exit('Parameter tidak valid!');
}

$stmt = $koneksi->prepare("SELECT nama FROM semeton WHERE user_id = ? LIMIT 1");
$stmt->bind_param('s', $user_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) {
    http_response_code(404);
    exit('Data tidak ditemukan!');
}

$nama = (string) $data['nama'];

// Hanya izinkan karakter aman pada nama file.
$safe_id   = preg_replace('/[^A-Za-z0-9_-]/', '', $user_id);
$file_name = 'kartu_' . $safe_id . '.jpg';
$file_path = __DIR__ . '/kartu_anggota/' . $file_name;
$file_url  = 'kartu_anggota/' . rawurlencode($file_name);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kartu Anggota - <?= htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="dist/vendor/bootstrap/5.3.0/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-4 text-center">
        <h3>Kartu Anggota - <?= htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') ?></h3>

        <?php if ($safe_id !== '' && is_file($file_path)) { ?>
            <img src="<?= htmlspecialchars($file_url, ENT_QUOTES, 'UTF-8') ?>" class="img-fluid mt-3"
                alt="Kartu <?= htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') ?>">
            <div class="mt-3">
                <a href="<?= htmlspecialchars($file_url, ENT_QUOTES, 'UTF-8') ?>" download class="btn btn-success">Download Kartu</a>
                <a href="index.php?menu=semeton" class="btn btn-secondary">Kembali</a>
            </div>
        <?php } else { ?>
            <p class="text-danger mt-3">Kartu tidak ditemukan!</p>
            <a href="index.php?menu=semeton" class="btn btn-secondary">Kembali</a>
        <?php } ?>
    </div>

    <script src="dist/vendor/bootstrap/5.3.0/bootstrap.bundle.min.js"></script>
</body>

</html>
