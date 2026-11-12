<?php
include 'admin/koneksi.php'; // Koneksi ke database

// Ambil data wilayah
$wilayahQuery = "SELECT id_wilayah, nama_wilayah FROM wilayah ORDER BY nama_wilayah ASC";
$wilayahResult = $koneksi->query($wilayahQuery);

// Ambil data sub_wilayah
$subWilayahQuery = "SELECT id_sub, nama_sub, id_wilayah FROM sub_wilayah ORDER BY nama_sub ASC";
$subWilayahResult = $koneksi->query($subWilayahQuery);

$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Semeton Baru</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body class="bg-light">
    <div class="container my-4">
        <h2 class="mb-3">Tambah Semeton Baru</h2>
        <a href="search_semeton.php" class="btn btn-secondary mb-3">Kembali</a>
        <div class="card shadow-sm">
            <div class="card-body">
                <p class="text-muted small">
                    Data ini akan masuk sebagai <strong>usulan</strong> dan diverifikasi oleh petugas.
                    Kolom bertanda <span class="text-danger">*</span> wajib diisi.
                </p>
                <form action="tambah_semeton.php" method="POST">

                    <h6 class="text-uppercase text-muted fw-bold mt-2 mb-3" style="font-size: .75rem;">Identitas</h6>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label for="nama" class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nama" name="nama" maxlength="255" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="jenis_kelamin" class="form-label">Jenis Kelamin</label>
                            <select class="form-select" id="jenis_kelamin" name="jenis_kelamin">
                                <option value="">- Tidak diisi -</option>
                                <option value="L">Laki-laki</option>
                                <option value="P">Perempuan</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="nik" class="form-label">NIK</label>
                            <input type="text" class="form-control" id="nik" name="nik" maxlength="16" inputmode="numeric" placeholder="16 digit (opsional)">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="no_kk" class="form-label">No. KK</label>
                            <input type="text" class="form-control" id="no_kk" name="no_kk" maxlength="16" inputmode="numeric" placeholder="16 digit (opsional)">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="tempat_lahir" class="form-label">Tempat Lahir</label>
                            <input type="text" class="form-control" id="tempat_lahir" name="tempat_lahir" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="tanggal_lahir" class="form-label">Tanggal Lahir</label>
                            <input type="date" class="form-control" id="tanggal_lahir" name="tanggal_lahir">
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted fw-bold mt-2 mb-3" style="font-size: .75rem;">Data Pendukung</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="agama" class="form-label">Agama</label>
                            <input type="text" class="form-control" id="agama" name="agama" maxlength="30" list="daftarAgama">
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
                            <label for="golongan_darah" class="form-label">Golongan Darah</label>
                            <select class="form-select" id="golongan_darah" name="golongan_darah">
                                <option value="">- Tidak diisi -</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="AB">AB</option>
                                <option value="O">O</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="status_perkawinan" class="form-label">Status Perkawinan</label>
                            <select class="form-select" id="status_perkawinan" name="status_perkawinan">
                                <option value="">- Tidak diisi -</option>
                                <option value="belum_kawin">Belum Kawin</option>
                                <option value="kawin">Kawin</option>
                                <option value="cerai_hidup">Cerai Hidup</option>
                                <option value="cerai_mati">Cerai Mati</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="pendidikan" class="form-label">Pendidikan</label>
                            <input type="text" class="form-control" id="pendidikan" name="pendidikan" maxlength="50">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="pekerjaan" class="form-label">Pekerjaan</label>
                            <input type="text" class="form-control" id="pekerjaan" name="pekerjaan" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="nama_ayah" class="form-label">Nama Ayah</label>
                            <input type="text" class="form-control" id="nama_ayah" name="nama_ayah" maxlength="255">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="nama_ibu" class="form-label">Nama Ibu</label>
                            <input type="text" class="form-control" id="nama_ibu" name="nama_ibu" maxlength="255">
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted fw-bold mt-2 mb-3" style="font-size: .75rem;">Kontak &amp; Wilayah</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="no_hp" class="form-label">No. HP <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="no_hp" name="no_hp" maxlength="15" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="alamat" class="form-label">Alamat <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="alamat" name="alamat" rows="3" required></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="wilayah" class="form-label">Wilayah <span class="text-danger">*</span></label>
                            <select class="form-select" id="wilayah" name="wilayah" required>
                                <option value="">Pilih Wilayah</option>
                                <?php while ($row = $wilayahResult->fetch_assoc()) { ?>
                                    <option value="<?= (int) $row['id_wilayah'] ?>"><?= $esc($row['nama_wilayah']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="sub_wilayah" class="form-label">Sub Wilayah <span class="text-danger">*</span></label>
                            <select class="form-select" id="sub_wilayah" name="sub_wilayah" required>
                                <option value="">Pilih Sub Wilayah</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="catatan" class="form-label">Catatan</label>
                        <input type="text" class="form-control" id="catatan" name="catatan" maxlength="255" placeholder="Catatan tambahan (opsional)">
                    </div>

                    <button type="submit" class="btn btn-primary">Simpan</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            $('#wilayah').change(function() {
                var id_wilayah = $(this).val();
                $.ajax({
                    url: 'get_sub_wilayah.php',
                    method: 'POST',
                    data: {
                        id_wilayah: id_wilayah
                    },
                    success: function(data) {
                        $('#sub_wilayah').html(data);
                    }
                });
            });
        });
    </script>
</body>

</html>
