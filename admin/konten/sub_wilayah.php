<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('sub_wilayah'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// RAPIH + AMAN (2026-11-09)
// Sebelumnya: setiap baris (85) merender satu modal edit + satu blok
// <script>, dan setiap modal menjalankan `SELECT * FROM wilayah`
// sendiri-sendiri (85 x 23 option = ±2.000 option, ±808 KB HTML).
// Output nama wilayah/sub wilayah juga tidak di-escape (XSS).
// Sekarang: master wilayah diambil sekali, satu modal edit bersama yang
// diisi dari atribut data-*, satu handler hapus, output escaped.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

// 1. Master wilayah (dipakai dropdown tambah + edit)
$wilayah_list = [];
if ($q_w = $koneksi->query("SELECT id_wilayah, nama_wilayah FROM wilayah ORDER BY nama_wilayah ASC, id_wilayah ASC")) {
    while ($r = $q_w->fetch_assoc()) {
        $wilayah_list[(int) $r['id_wilayah']] = $r['nama_wilayah'];
    }
}

// 2. Data sub wilayah + jumlah anggota yang memakainya (untuk pengaman hapus)
$data_sub = [];
if ($q_s = $koneksi->query("SELECT s.id_sub, s.id_wilayah, s.nama_sub, w.nama_wilayah,
                                   (SELECT COUNT(*) FROM semeton m WHERE m.sub_wilayah = s.id_sub) AS jml_semeton,
                                   (SELECT COUNT(*) FROM semeton_baru b WHERE b.sub_wilayah = s.id_sub) AS jml_baru
                            FROM sub_wilayah s
                            JOIN wilayah w ON s.id_wilayah = w.id_wilayah
                            ORDER BY s.id_sub ASC")) {
    while ($r = $q_s->fetch_assoc()) {
        $data_sub[] = $r;
    }
}
$total_sub = count($data_sub);
$total_wilayah = count($wilayah_list);

$status = isset($_GET['status']) && is_scalar($_GET['status']) ? (string) $_GET['status'] : '';
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0 fw-bold text-dark">Sub Wilayah</h3>
                    <p class="text-muted small mb-0">
                        Daftar sub wilayah (banjar) di bawah tiap wilayah.
                        <span class="badge bg-secondary ms-1"><?= (int) $total_sub ?> sub wilayah</span>
                        <span class="badge bg-light text-dark border"><?= (int) $total_wilayah ?> wilayah</span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="card p-4">

                <?php if ($status === 'dipakai') : ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            Swal.fire({
                                title: 'Tidak Bisa Dihapus',
                                text: 'Sub wilayah ini masih dipakai oleh anggota. Pindahkan anggotanya ke sub wilayah lain terlebih dahulu.',
                                icon: 'warning',
                                confirmButtonText: 'OK'
                            });
                        });
                    </script>
                <?php endif; ?>

                <div class="row mb-3">
                    <div class="col-12">
                        <a href="#" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalTambahSub">
                            <i class="fa-solid fa-plus"></i> Tambah Data
                        </a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <table id="example1" class="table table-bordered table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Id Sub Wilayah</th>
                                    <th>Nama Wilayah</th>
                                    <th>Nama Sub Wilayah</th>
                                    <th width="190">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($data_sub)) : ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-muted">
                                            <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                                            Belum ada data sub wilayah.
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ($data_sub as $row) :
                                        $jml_pakai = (int) $row['jml_semeton'] + (int) $row['jml_baru'];
                                        ?>
                                        <tr>
                                            <td><?= (int) $row['id_sub'] ?></td>
                                            <td><?= $esc($row['nama_wilayah']) ?></td>
                                            <td><?= $esc($row['nama_sub']) ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-primary"
                                                    data-bs-toggle="modal" data-bs-target="#modalEditSub"
                                                    data-id="<?= (int) $row['id_sub'] ?>"
                                                    data-id-wilayah="<?= (int) $row['id_wilayah'] ?>"
                                                    data-nama="<?= $esc($row['nama_sub']) ?>"
                                                    title="Edit sub wilayah">
                                                    <i class="fas fa-pen-to-square"></i> Edit
                                                </button>
                                                <?php if ($jml_pakai > 0) : ?>
                                                    <button type="button" class="btn btn-sm btn-danger" disabled
                                                        title="Tidak bisa dihapus: dipakai oleh <?= (int) $jml_pakai ?> anggota">
                                                        <i class="fas fa-lock"></i> Hapus
                                                    </button>
                                                <?php else : ?>
                                                    <a href="aksi/aksi_sub.php?aksi=hapus&id=<?= (int) $row['id_sub'] ?>&csrf_token=<?= $esc(kbap_csrf_token()) ?>"
                                                        class="btn btn-sm btn-danger btn-hapus-sub"
                                                        data-nama="<?= $esc($row['nama_sub']) ?>"
                                                        title="Hapus sub wilayah">
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

<!-- Modal tambah sub wilayah -->
<div class="modal fade" id="modalTambahSub" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="aksi/aksi_sub.php" method="POST">
                <input type="hidden" name="aksi" value="tambah">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i>Tambah Sub Wilayah</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-id-wilayah">Nama Wilayah</label>
                        <select class="form-select" id="tambah-id-wilayah" name="id_wilayah" required>
                            <option value="">-- Pilih Wilayah --</option>
                            <?php foreach ($wilayah_list as $id_w => $nama_w) : ?>
                                <option value="<?= (int) $id_w ?>"><?= $esc($nama_w) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-nama-sub">Nama Sub Wilayah</label>
                        <input type="text" class="form-control" id="tambah-nama-sub" name="nama_sub" required>
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
<div class="modal fade" id="modalEditSub" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="aksi/aksi_sub.php" method="POST">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id" id="edit-id-sub" value="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit Sub Wilayah</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-id-sub-tampil">Id Sub Wilayah</label>
                        <input type="text" class="form-control" id="edit-id-sub-tampil" value="" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-id-wilayah">Nama Wilayah</label>
                        <select class="form-select" id="edit-id-wilayah" name="id_wilayah" required>
                            <?php foreach ($wilayah_list as $id_w => $nama_w) : ?>
                                <option value="<?= (int) $id_w ?>"><?= $esc($nama_w) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-nama-sub">Nama Sub Wilayah</label>
                        <input type="text" class="form-control" id="edit-nama-sub" name="nama_sub" required>
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
    var modalEdit = document.getElementById('modalEditSub');
    if (modalEdit) {
        modalEdit.addEventListener('show.bs.modal', function (event) {
            var tombol = event.relatedTarget;
            if (!tombol) {
                return;
            }
            var id = tombol.getAttribute('data-id') || '';
            document.getElementById('edit-id-sub').value = id;
            document.getElementById('edit-id-sub-tampil').value = id;
            document.getElementById('edit-nama-sub').value = tombol.getAttribute('data-nama') || '';
            var select = document.getElementById('edit-id-wilayah');
            if (select) {
                select.value = tombol.getAttribute('data-id-wilayah') || '';
            }
        });
    }

    // 2. Konfirmasi hapus (satu handler untuk semua baris)
    document.addEventListener('click', function (event) {
        var tautan = event.target.closest('a.btn-hapus-sub');
        if (!tautan) {
            return;
        }
        event.preventDefault();
        var nama = tautan.getAttribute('data-nama') || '';
        var url = tautan.getAttribute('href');
        if (typeof Swal === 'undefined') {
            if (window.confirm('Hapus sub wilayah "' + nama + '"?')) {
                window.location.href = url;
            }
            return;
        }
        Swal.fire({
            title: 'Peringatan!',
            text: 'Apakah kamu yakin menghapus sub wilayah "' + nama + '" ?',
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
