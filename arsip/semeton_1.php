<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('semeton'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0" id="judul-tabel">Data Semeton Pokok</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="card p-4">
                <div class="row">
                    <div class="col-12">
                        <div class="btn-group" role="group" aria-label="Basic mixed styles example">
                            <a href="#" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#tambah_semeton">
                                <i class="fa-solid fa-plus"></i> Tambah Semeton Pokok
                            </a>
                            <a href="aksi/generate_qr.php?csrf_token=<?= htmlspecialchars(kbap_csrf_token(), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-warning">
                                <i class="fa-solid fa-qrcode"></i> Generate QR untuk Semua
                            </a>

                            <form action="generate_all_kartu.php" method="POST">
                                <button type="submit" class="btn btn-success"><i class="fas fa-graduation-cap"></i> Generate Semua Kartu Semeton</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <table id="example" class="table table-bordered table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Status</th>
                                    <th>User ID</th>
                                    <th>Nama</th>
                                    <th>NIK</th>
                                    <th>No HP</th>
                                    <th>Jenis User</th>
                                    <th>Wilayah</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // 1️⃣ Ambil semua kartu yang sudah ada (mengurangi pemanggilan file_exists)
                                $kartu_list = array_map('basename', glob("kartu_anggota/kartu_*.jpg"));

                                $query = "SELECT s.*, w.nama_wilayah, sw.nama_sub FROM semeton s
                                          JOIN wilayah w ON s.wilayah = w.id_wilayah
                                          JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub ORDER BY s.user_id ASC";
                                $result = mysqli_query($koneksi, $query);

                                while ($row = mysqli_fetch_assoc($result)) :
                                    $user_id = $row['user_id'];

                                ?>
                                    <tr>
                                        <td>
                                            <?php
                                            if ($row['status'] == 'aktif') { ?>
                                                <div class="badge bg-success">Aktif</div>
                                            <?php } else { ?>
                                                <div class="badge bg-danger">Tidak Aktif</div>
                                            <?php } ?>
                                        </td>
                                        <td><?= $user_id ?></td>
                                        <td><?= $row['nama'] ?></td>
                                        <td><?= $row['nik'] ?></td>
                                        <td><?= $row['no_hp'] ?></td>
                                        <td>
                                            <?php
                                            if ($row['jenis_user'] == 'ngarep') { ?>
                                                <div class="badge bg-success">Ngarep</div>
                                            <?php } elseif ($row['jenis_user'] == 'ngempi') { ?>
                                                <div class="badge bg-danger">Ngempi</div>
                                            <?php } else { ?>
                                                <div class="badge bg-primary">Pemangku</div>
                                            <?php } ?>
                                        </td>
                                        <td><?= $row['nama_wilayah'] ?> - <?= $row['nama_sub'] ?></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <!-- Tombol Lihat QR -->
                                                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#qr<?= $row['id'] ?>">
                                                    <i class="fa-solid fa-search"></i>
                                                </button>

                                                <?php
                                                $kartu_path = "kartu_anggota/kartu_" . $user_id . ".jpg";
                                                if (file_exists($kartu_path)) :
                                                ?>
                                                    <!-- Jika kartu sudah ada -->
                                                    <a href="<?= $kartu_path ?>?t=<?= time() ?>" target="_blank" class="btn btn-warning btn-sm">
                                                        <i class="fa-solid fa-id-card"></i>
                                                    </a>
                                                    <button onclick="updateKartu('<?= $user_id ?>')" class="btn btn-info btn-sm">
                                                        <i class="fas fa-sync-alt"></i>
                                                    </button>
                                                <?php else : ?>
                                                    <!-- Jika kartu belum ada -->
                                                    <button onclick="generateKartu('<?= $user_id ?>')" class="btn btn-success btn-sm">
                                                        <i class="fas fa-graduation-cap"></i> Generate Kartu
                                                    </button>
                                                <?php endif; ?>

                                                <!-- Tombol Edit -->
                                                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#edit_semeton<?= $row['id'] ?>">
                                                    <i class="fas fa-pen-to-square"></i>
                                                </button>

                                                <!-- Tombol Hapus -->
                                                <button id="deleteButton<?= $row['id'] ?>" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-xmark"></i>
                                                </button>
                                            </div>
                                        </td>

                                    </tr>

                                    <script>
                                        document.getElementById('deleteButton<?= $row['id'] ?>').addEventListener('click', function() {
                                            Swal.fire({
                                                title: "Peringatan!",
                                                text: "Apakah kamu yakin menghapus data ini?",
                                                icon: "warning",
                                                showCancelButton: true,
                                                confirmButtonColor: "#3085d6",
                                                cancelButtonColor: "#d33",
                                                confirmButtonText: "Ya, Hapus!"
                                            }).then((result) => {
                                                if (result.isConfirmed) {
                                                    window.location.href = kbapUrl("aksi/aksi_semeton.php?aksi=hapus&id=<?= $row['id'] ?>");
                                                }
                                            });
                                        });
                                    </script>

                                    <div class="modal fade" id="qr<?= $row['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Detail</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <h3 class="text-center"><?= $row['nama'] ?></h3>
                                                    <p class="text-center m-0"><?= $row['nik'] ?></p>
                                                    <p class="text-center m-0"><?= $row['email'] ?></p>
                                                    <p class="text-center m-0"><?= $row['alamat'] ?></p>
                                                    <p class="text-center m-0"><?= $row['jenis_user'] ?></p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                    <!-- Modal Edit Semeton -->
                                    <div class="modal fade" id="edit_semeton<?= $row['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Edit Semeton</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <form action="aksi/aksi_semeton.php" method="POST">
                                                        <input type="hidden" name="aksi" value="edit">
                                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">

                                                        <div class="mb-2">
                                                            <label>Nama</label>
                                                            <input type="text" class="form-control" name="nama" value="<?= $row['nama'] ?>" required>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label>NIK</label>
                                                            <input type="text" class="form-control" name="nik" value="<?= $row['nik'] ?>">
                                                        </div>
                                                        <div class="mb-2">
                                                            <label>Email</label>
                                                            <input type="email" class="form-control" name="email" value="<?= $row['email'] ?>">
                                                        </div>
                                                        <div class="mb-2">
                                                            <label>No HP</label>
                                                            <input type="text" class="form-control" name="no_hp" value="<?= $row['no_hp'] ?>">
                                                        </div>
                                                        <div class="mb-2">
                                                            <label class="form-label fs-6 fw-bold">Alamat</label>
                                                            <textarea class="form-control" name="alamat"><?= $row['alamat'] ?></textarea>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label for="nama_wilayah" class="form-label fs-6 fw-bold">Nama Wilayah</label>
                                                            <select class="form-control" id="nama_wilayah" name="id_wilayah" required onchange="subWilayah(<?= $row['id'] ?>,this.value)">
                                                                <option value="">-- Pilih Wilayah --</option>
                                                                <?php
                                                                $wilayah = "SELECT * FROM wilayah";
                                                                $hasil = mysqli_query($koneksi, $wilayah);
                                                                while ($r = mysqli_fetch_array($hasil)): ?>
                                                                    <option value="<?= $r['id_wilayah'] ?>"><?= $r['nama_wilayah'] ?></option>
                                                                <?php endwhile ?>
                                                            </select>
                                                        </div>

                                                        <div class="mb-2">
                                                            <label for="sub_wilayah" class="form-label fs-6 fw-bold">Sub Wilayah</label>
                                                            <select class="form-control" id="sub_wilayah1<?= $row['id'] ?>" name="sub_wilayah" required>
                                                                <option value="">-- Pilih Sub Wilayah --</option>
                                                            </select>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label for="jenis" class="form-label fs-6 fw-bold">Urunan</label>
                                                            <select class="form-control" id="jenis" name="jenis" required>
                                                                <option value="<?= $row['jenis_user'] ?>"><?= $row['jenis_user'] ?></option>
                                                                <option value="ngarep">Ngarep</option>
                                                                <option value="ngempi">Ngempi</option>
                                                            </select>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label for="jabatan" class="form-label fs-6 fw-bold">Jabatan</label>
                                                            <select class="form-control" id="jabatan" name="jabatan" required>
                                                                <option value="<?= $row['jabatan'] ?>"><?= $row['jabatan'] ?></option>
                                                                <option value="Ketua Umum">Ketua Umum</option>
                                                                <option value="Wakil Ketua Umum">Wakil Ketua Umum</option>
                                                                <option value="Ketua">Ketua</option>
                                                                <option value="Sekretaris Umum">Sekretaris Umum</option>
                                                                <option value="Sekretaris">Sekretaris</option>
                                                                <option value="Bendahara Umum">Bendahara Umum</option>
                                                                <option value="Bendahara">Bendahara</option>
                                                                <option value="Korwil">Korwil</option>
                                                                <option value="Anggota">Anggota</option>
                                                            </select>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label for="status" class="form-label fs-6 fw-bold">Status</label>
                                                            <select class="form-control" id="status" name="status" required>
                                                                <option value="<?= $row['status'] ?>"><?= $row['status'] ?></option>
                                                                <option value="aktif">Aktif</option>
                                                                <option value="tidak_aktif">Tidak Aktif</option>
                                                            </select>
                                                        </div>

                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                <?php endwhile ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Modal Tambah Semeton -->
<div class="modal fade" id="tambah_semeton" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Semeton</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form action="aksi/aksi_semeton.php" method="POST">
                    <input type="hidden" name="aksi" value="tambah">

                    <div class="mb-2">
                        <label class="form-label fs-6 fw-bold">Nama</label>
                        <input type="text" class="form-control" name="nama" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fs-6 fw-bold">NIK</label>
                        <input type="text" class="form-control" name="nik" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fs-6 fw-bold">Email</label>
                        <input type="email" class="form-control" name="email">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fs-6 fw-bold">No HP</label>
                        <input type="text" class="form-control" name="no_hp" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fs-6 fw-bold">Alamat</label>
                        <textarea class="form-control" name="alamat" required></textarea>
                    </div>
                    <div class="mb-2">
                        <label for="nama_wilayah" class="form-label fs-6 fw-bold">Nama Wilayah</label>
                        <select class="form-control" id="nama_wilayah" name="id_wilayah" required onchange="getSubWilayah(this.value)">
                            <option value="">-- Pilih Wilayah --</option>
                            <?php
                            $wilayah = "SELECT * FROM wilayah";
                            $hasil = mysqli_query($koneksi, $wilayah);
                            while ($r = mysqli_fetch_array($hasil)): ?>
                                <option value="<?= $r['id_wilayah'] ?>"><?= $r['nama_wilayah'] ?></option>
                            <?php endwhile ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label for="sub_wilayah" class="form-label fs-6 fw-bold">Sub Wilayah</label>
                        <select class="form-control" id="sub_wilayah" name="sub_wilayah" required>
                            <option value="">-- Pilih Sub Wilayah --</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label for="jenis" class="form-label fs-6 fw-bold">Urunan</label>
                        <select class="form-control" id="jenis" name="jenis" required>
                            <option value="ngarep">Ngarep</option>
                            <option value="ngempi">Ngempi</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label for="jabatan" class="form-label fs-6 fw-bold">Jabatan</label>
                        <select class="form-control" id="jabatan" name="jabatan" required>
                            <option value="Ketua Umum">Ketua Umum</option>
                            <option value="Wakil Ketua Umum">Wakil Ketua Umum</option>
                            <option value="Ketua">Ketua</option>
                            <option value="Sekretaris Umum">Sekretaris Umum</option>
                            <option value="Sekretaris">Sekretaris</option>
                            <option value="Bendahara Umum">Bendahara Umum</option>
                            <option value="Bendahara">Bendahara</option>
                            <option value="Korwil">Korwil</option>
                            <option value="Anggota">Anggota</option>
                        </select>
                    </div>



                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- 3️⃣ JavaScript untuk AJAX Generate Kartu -->
<script>
    function generateKartu(userId) {
        fetch('generate_satu_kartu.php?user_id=' + encodeURIComponent(userId) + '&csrf_token=' + encodeURIComponent(window.KBAP_CSRF), {
                method: 'GET'
            })
            .then(response => response.text())
            .then(result => {
                alert('Kartu berhasil digenerate!');
                location.reload();
            });
    }
</script>
<script>
    function updateKartu(user_id) {
        if (confirm("Apakah Anda yakin ingin memperbarui kartu anggota ini?")) {
            window.location.href = "generate_satu_kartu.php?user_id=" + encodeURIComponent(user_id) + "&csrf_token=" + encodeURIComponent(window.KBAP_CSRF);
        }
    }
</script>