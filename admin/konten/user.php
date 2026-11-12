<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('user'); // wajib login + hak akses menu (admin saja)

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// RAPIH + AMAN (2026-11-09)
// Sebelumnya: setiap baris merender satu modal edit + satu blok <script>
// di dalam <tbody> (HTML tidak valid), dan nilai `nama_user`/`username`
// di dalam atribut `value="..."` tidak di-escape (bisa merusak atribut /
// XSS). Sekarang: satu modal edit bersama + satu handler hapus, seluruh
// output di-escape, dan jumlah akun per akses ditampilkan di header.
// Password TIDAK pernah ditampilkan (kolomnya tidak dirender).
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$daftar_user = [];
if ($q_user = $koneksi->query("SELECT id_user, nama_user, username, akses FROM user ORDER BY id_user ASC")) {
    while ($u = $q_user->fetch_assoc()) {
        $daftar_user[] = $u;
    }
}
$total_user  = count($daftar_user);
$jml_admin   = 0;
$jml_bendahara = 0;
$jml_data    = 0;
foreach ($daftar_user as $u) {
    if ($u['akses'] === 'admin') {
        $jml_admin++;
    } elseif ($u['akses'] === 'bendahara') {
        $jml_bendahara++;
    } else {
        $jml_data++;
    }
}
$id_saya = (int) ($_SESSION['id_user'] ?? 0);
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-8">
                    <h3 class="mb-0 fw-bold text-dark">Daftar User</h3>
                    <p class="text-muted small mb-0">
                        Akun yang bisa masuk ke panel admin.
                        <span class="badge bg-secondary ms-1"><?= (int) $total_user ?> akun</span>
                        <span class="badge bg-danger"><?= (int) $jml_admin ?> admin</span>
                        <span class="badge bg-success"><?= (int) $jml_bendahara ?> bendahara</span>
                        <span class="badge bg-info text-dark"><?= (int) $jml_data ?> pendataan</span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="card p-4">
                <div class="row mb-3">
                    <div class="col-12">
                        <a href="#" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalTambahUser">
                            <i class="fa-solid fa-plus"></i> Tambah User
                        </a>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <table id="example1" class="table table-bordered table-striped table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID User</th>
                                    <th>Nama User</th>
                                    <th>Username</th>
                                    <th>Akses</th>
                                    <th width="190">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($daftar_user)) : ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                                            Belum ada akun pengguna.
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ($daftar_user as $row) : ?>
                                        <tr>
                                            <td><?= (int) $row['id_user'] ?></td>
                                            <td><?= $esc($row['nama_user']) ?></td>
                                            <td><?= $esc($row['username']) ?></td>
                                            <td>
                                                <?php if ($row['akses'] === 'admin') : ?>
                                                    <span class="badge bg-danger">Admin</span>
                                                <?php elseif ($row['akses'] === 'bendahara') : ?>
                                                    <span class="badge bg-success">Bendahara</span>
                                                <?php else : ?>
                                                    <span class="badge bg-info text-dark">Bagian Data</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-primary"
                                                    data-bs-toggle="modal" data-bs-target="#modalEditUser"
                                                    data-id="<?= (int) $row['id_user'] ?>"
                                                    data-nama="<?= $esc($row['nama_user']) ?>"
                                                    data-username="<?= $esc($row['username']) ?>"
                                                    data-akses="<?= $esc($row['akses']) ?>"
                                                    title="Edit user">
                                                    <i class="fas fa-pen-to-square"></i> Edit
                                                </button>
                                                <?php if ((int) $row['id_user'] === $id_saya) : ?>
                                                    <button type="button" class="btn btn-sm btn-danger" disabled
                                                        title="Tidak bisa menghapus akun yang sedang dipakai">
                                                        <i class="fas fa-lock"></i> Hapus
                                                    </button>
                                                <?php else : ?>
                                                    <a href="aksi/aksi_user.php?aksi=hapus&id=<?= (int) $row['id_user'] ?>&csrf_token=<?= $esc(kbap_csrf_token()) ?>"
                                                        class="btn btn-sm btn-danger btn-hapus-user"
                                                        data-nama="<?= $esc($row['nama_user']) ?>"
                                                        title="Hapus user">
                                                        <i class="fas fa-xmark"></i> Hapus
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Modal tambah user -->
<div class="modal fade" id="modalTambahUser" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="aksi/aksi_user.php" method="POST">
                <input type="hidden" name="aksi" value="tambah">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i>Tambah User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-nama-user">Nama User</label>
                        <input type="text" class="form-control" id="tambah-nama-user" name="nama" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-username-user">Username</label>
                        <input type="text" class="form-control" id="tambah-username-user" name="username" maxlength="50" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-password-user">Password</label>
                        <input type="password" class="form-control" id="tambah-password-user" name="password" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-akses-user">Akses</label>
                        <select class="form-select" id="tambah-akses-user" name="akses">
                            <option value="admin">admin</option>
                            <option value="bendahara">bendahara</option>
                            <option value="bagian_data">bagian_data (Pendataan)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal edit bersama (diisi dari atribut data-* tombol yang diklik) -->
<div class="modal fade" id="modalEditUser" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="aksi/aksi_user.php" method="POST">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id" id="edit-id-user" value="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-id-user-tampil">ID User</label>
                        <input type="text" class="form-control" id="edit-id-user-tampil" value="" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-nama-user">Nama User</label>
                        <input type="text" class="form-control" id="edit-nama-user" name="nama" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-username-user">Username</label>
                        <input type="text" class="form-control" id="edit-username-user" name="username" maxlength="50" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-password-user">Password</label>
                        <input type="password" class="form-control" id="edit-password-user" name="password" autocomplete="new-password" placeholder="Kosongkan jika tidak diubah">
                        <div class="form-text">Isi hanya jika ingin mengganti password.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-akses-user">Akses</label>
                        <select class="form-select" id="edit-akses-user" name="akses">
                            <option value="admin">admin</option>
                            <option value="bendahara">bendahara</option>
                            <option value="bagian_data">bagian_data (Pendataan)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Isi modal edit dari atribut data-* tombol yang diklik
    var modalEdit = document.getElementById('modalEditUser');
    if (modalEdit) {
        modalEdit.addEventListener('show.bs.modal', function (event) {
            var tombol = event.relatedTarget;
            if (!tombol) {
                return;
            }
            var id = tombol.getAttribute('data-id') || '';
            document.getElementById('edit-id-user').value = id;
            document.getElementById('edit-id-user-tampil').value = id;
            document.getElementById('edit-nama-user').value = tombol.getAttribute('data-nama') || '';
            document.getElementById('edit-username-user').value = tombol.getAttribute('data-username') || '';
            document.getElementById('edit-password-user').value = '';
            document.getElementById('edit-akses-user').value = tombol.getAttribute('data-akses') || 'bagian_data';
        });
    }

    // 2. Konfirmasi hapus (satu handler untuk semua baris)
    document.addEventListener('click', function (event) {
        var tautan = event.target.closest('a.btn-hapus-user');
        if (!tautan) {
            return;
        }
        event.preventDefault();
        var nama = tautan.getAttribute('data-nama') || '';
        var url = tautan.getAttribute('href');
        if (typeof Swal === 'undefined') {
            if (window.confirm('Hapus user "' + nama + '"?')) {
                window.location.href = url;
            }
            return;
        }
        Swal.fire({
            title: 'Peringatan!',
            text: 'Apakah kamu yakin menghapus user "' + nama + '" ?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then(function (result) {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    });
});
</script>
