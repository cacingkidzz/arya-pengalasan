<?php
require_once __DIR__ . '/admin/security.php';
include 'admin/koneksi.php';

kbap_guard(['admin', 'bagian_data']);

$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$sumber = isset($_GET['sumber']) && is_scalar($_GET['sumber']) ? (string) $_GET['sumber'] : '';

if ($id <= 0 || !in_array($sumber, ['semeton', 'semeton_baru'], true)) {
    http_response_code(400);
    exit('Parameter edit tidak valid.');
}

$table = $sumber; // sudah divalidasi terhadap whitelist

$stmt = $koneksi->prepare("SELECT * FROM `$table` WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) {
    http_response_code(404);
    exit('Data semeton tidak ditemukan.');
}

$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$nilai = static function (array $data, string $kolom) use ($esc) {
    return $esc($data[$kolom] ?? '');
};
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Semeton</title>
    <link href="admin/dist/vendor/bootstrap/5.3.3/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container my-4">
        <h3 class="mb-3">Edit Semeton</h3>
        <a href="search_semeton.php" class="btn btn-secondary mb-3">Kembali</a>
        <div class="card shadow-sm">
            <div class="card-body">
                <form action="update_semeton.php" method="post">
                    <?= kbap_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $data['id'] ?>">
                    <input type="hidden" name="table" value="<?= $esc($table) ?>">

                    <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: .75rem;">Identitas</h6>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Nama <span class="text-danger">*</span></label>
                            <input type="text" name="nama" value="<?= $nilai($data, 'nama') ?>" class="form-control" maxlength="255" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Jenis Kelamin</label>
                            <select name="jenis_kelamin" class="form-select">
                                <option value="">- Tidak diisi -</option>
                                <option value="L" <?= ($data['jenis_kelamin'] ?? '') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                                <option value="P" <?= ($data['jenis_kelamin'] ?? '') === 'P' ? 'selected' : '' ?>>Perempuan</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">NIK</label>
                            <input type="text" name="nik" value="<?= $nilai($data, 'nik') ?>" class="form-control" maxlength="16" inputmode="numeric" placeholder="16 digit (opsional)">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">No. KK</label>
                            <input type="text" name="no_kk" value="<?= $nilai($data, 'no_kk') ?>" class="form-control" maxlength="16" inputmode="numeric" placeholder="16 digit (opsional)">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tempat Lahir</label>
                            <input type="text" name="tempat_lahir" value="<?= $nilai($data, 'tempat_lahir') ?>" class="form-control" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tanggal Lahir</label>
                            <input type="date" name="tanggal_lahir" value="<?= $nilai($data, 'tanggal_lahir') ?>" class="form-control">
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: .75rem;">Data Pendukung</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Agama</label>
                            <input type="text" name="agama" value="<?= $nilai($data, 'agama') ?>" class="form-control" maxlength="30" list="daftarAgama">
                            <datalist id="daftarAgama">
                                <option value="Hindu"></option>
                                <option value="Islam"></option>
                                <option value="Kristen"></option>
                                <option value="Katolik"></option>
                                <option value="Buddha"></option>
                                <option value="Konghucu"></option>
                            </datalist>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Golongan Darah</label>
                            <select name="golongan_darah" class="form-select">
                                <option value="">- Tidak diisi -</option>
                                <?php foreach (['A', 'B', 'AB', 'O'] as $gol) : ?>
                                    <option value="<?= $gol ?>" <?= ($data['golongan_darah'] ?? '') === $gol ? 'selected' : '' ?>><?= $gol ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Status Perkawinan</label>
                            <select name="status_perkawinan" class="form-select">
                                <option value="">- Tidak diisi -</option>
                                <?php foreach (['belum_kawin' => 'Belum Kawin', 'kawin' => 'Kawin', 'cerai_hidup' => 'Cerai Hidup', 'cerai_mati' => 'Cerai Mati'] as $kunci => $label) : ?>
                                    <option value="<?= $kunci ?>" <?= ($data['status_perkawinan'] ?? '') === $kunci ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Pendidikan</label>
                            <input type="text" name="pendidikan" value="<?= $nilai($data, 'pendidikan') ?>" class="form-control" maxlength="50">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Pekerjaan</label>
                            <input type="text" name="pekerjaan" value="<?= $nilai($data, 'pekerjaan') ?>" class="form-control" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nama Ayah</label>
                            <input type="text" name="nama_ayah" value="<?= $nilai($data, 'nama_ayah') ?>" class="form-control" maxlength="255">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nama Ibu</label>
                            <input type="text" name="nama_ibu" value="<?= $nilai($data, 'nama_ibu') ?>" class="form-control" maxlength="255">
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: .75rem;">Kontak</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" value="<?= $nilai($data, 'email') ?>" class="form-control" maxlength="100" placeholder="Kosongkan kalau tidak punya !">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">No HP</label>
                            <input type="text" name="no_hp" value="<?= $nilai($data, 'no_hp') ?>" class="form-control" maxlength="15">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Alamat</label>
                        <textarea name="alamat" class="form-control" rows="3"><?= $nilai($data, 'alamat') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <input type="text" name="catatan" value="<?= $nilai($data, 'catatan') ?>" class="form-control" maxlength="255">
                    </div>

                    <button type="submit" class="btn btn-success">Update</button>
                </form>
            </div>
        </div>
    </div>
</body>

</html>
