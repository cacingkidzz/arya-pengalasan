<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('wilayah'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// RAPIH + AMAN (2026-11-09)
// Sebelumnya: setiap baris merender satu modal edit + satu blok <script>,
// output tidak di-escape, dan tombol hapus tidak tahu bahwa FK
// `fk_wilayah` ber-ON DELETE CASCADE (menghapus wilayah ikut menghapus
// semua sub wilayahnya, lalu mengosongkan sub wilayah anggotanya).
// Sekarang: satu modal edit bersama, satu handler hapus, output escaped,
// dan hapus diblokir selama wilayah masih punya sub wilayah/anggota.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$data_wilayah = [];
if ($q_w = $koneksi->query("SELECT w.id_wilayah, w.nama_wilayah,
                                   (SELECT COUNT(*) FROM sub_wilayah s WHERE s.id_wilayah = w.id_wilayah) AS jml_sub,
                                   (SELECT COUNT(*) FROM semeton m WHERE m.wilayah = w.id_wilayah) AS jml_semeton,
                                   (SELECT COUNT(*) FROM semeton_baru b WHERE b.wilayah = w.id_wilayah) AS jml_baru
                            FROM wilayah w
                            ORDER BY w.id_wilayah ASC")) {
    while ($r = $q_w->fetch_assoc()) {
        $data_wilayah[] = $r;
    }
}
$total_wilayah = count($data_wilayah);
$total_sub     = 0;
$total_anggota = 0;
foreach ($data_wilayah as $w) {
    $total_sub     += (int) $w['jml_sub'];
    $total_anggota += (int) $w['jml_semeton'];
}

$status = isset($_GET['status']) && is_scalar($_GET['status']) ? (string) $_GET['status'] : '';
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0 fw-bold text-dark">Wilayah</h3>
                    <p class="text-muted small mb-0">
                        Daftar wilayah (banjar) beserta jumlah sub wilayah dan anggotanya.
                        <span class="badge bg-secondary ms-1"><?= (int) $total_wilayah ?> wilayah</span>
                        <span class="badge bg-light text-dark border"><?= (int) $total_sub ?> sub wilayah</span>
                        <span class="badge bg-light text-dark border"><?= (int) $total_anggota ?> anggota</span>
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
                                text: 'Wilayah ini masih memiliki sub wilayah atau anggota. Hapus/pindahkan sub wilayah dan anggotanya terlebih dahulu.',
                                icon: 'warning',
                                confirmButtonText: 'OK'
                            });
                        });
                    </script>
                <?php endif; ?>

                <div class="row mb-3">
                    <div class="col-12">
                        <a href="#" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalTambahWilayah">
                            <i class="fa-solid fa-plus"></i> Tambah Data
                        </a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <table id="example1" class="table table-bordered table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Id Wilayah</th>
                                    <th>Nama Wilayah</th>
                                    <th width="130" class="text-center">Sub Wilayah</th>
                                    <th width="120" class="text-center">Anggota</th>
                                    <th width="190">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($data_wilayah)) : ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                                            Belum ada data wilayah.
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ($data_wilayah as $row) :
                                        $jml_sub     = (int) $row['jml_sub'];
                                        $jml_semeton = (int) $row['jml_semeton'];
                                        $jml_baru    = (int) $row['jml_baru'];
                                        $jml_pakai   = $jml_sub + $jml_semeton + $jml_baru;
                                        ?>
                                        <tr>
                                            <td><?= (int) $row['id_wilayah'] ?></td>
                                            <td><?= $esc($row['nama_wilayah']) ?></td>
                                            <td class="text-center">
                                                <span class="badge <?= $jml_sub > 0 ? 'bg-info text-dark' : 'bg-light text-dark border' ?>"><?= $jml_sub ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge <?= $jml_semeton > 0 ? 'bg-primary' : 'bg-light text-dark border' ?>"><?= $jml_semeton ?></span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-primary"
                                                    data-bs-toggle="modal" data-bs-target="#modalEditWilayah"
                                                    data-id="<?= (int) $row['id_wilayah'] ?>"
                                                    data-nama="<?= $esc($row['nama_wilayah']) ?>"
                                                    title="Edit wilayah">
                                                    <i class="fas fa-pen-to-square"></i> Edit
                                                </button>
                                                <?php if ($jml_pakai > 0) : ?>
                                                    <button type="button" class="btn btn-sm btn-danger" disabled
                                                        title="Tidak bisa dihapus: <?= $jml_sub ?> sub wilayah, <?= $jml_semeton + $jml_baru ?> anggota">
                                                        <i class="fas fa-lock"></i> Hapus
                                                    </button>
                                                <?php else : ?>
                                                    <a href="aksi/aksi_wilayah.php?aksi=hapus&id=<?= (int) $row['id_wilayah'] ?>&csrf_token=<?= $esc(kbap_csrf_token()) ?>"
                                                        class="btn btn-sm btn-danger btn-hapus-wilayah"
                                                        data-nama="<?= $esc($row['nama_wilayah']) ?>"
                                                        title="Hapus wilayah">
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

<!-- Modal tambah wilayah -->
<div class="modal fade" id="modalTambahWilayah" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="aksi/aksi_wilayah.php" method="POST">
                <input type="hidden" name="aksi" value="tambah">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i>Tambah Wilayah</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-nama-wilayah">Nama Wilayah</label>
                        <input type="text" class="form-control" id="tambah-nama-wilayah" name="nama_wilayah" required>
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
<div class="modal fade" id="modalEditWilayah" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="aksi/aksi_wilayah.php" method="POST">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id" id="edit-id-wilayah" value="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit Wilayah</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-id-wilayah-tampil">Id Wilayah</label>
                        <input type="text" class="form-control" id="edit-id-wilayah-tampil" value="" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-nama-wilayah">Nama Wilayah</label>
                        <input type="text" class="form-control" id="edit-nama-wilayah" name="nama_wilayah" required>
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
    var modalEdit = document.getElementById('modalEditWilayah');
    if (modalEdit) {
        modalEdit.addEventListener('show.bs.modal', function (event) {
            var tombol = event.relatedTarget;
            if (!tombol) {
                return;
            }
            var id = tombol.getAttribute('data-id') || '';
            document.getElementById('edit-id-wilayah').value = id;
            document.getElementById('edit-id-wilayah-tampil').value = id;
            document.getElementById('edit-nama-wilayah').value = tombol.getAttribute('data-nama') || '';
        });
    }

    // 2. Konfirmasi hapus (satu handler untuk semua baris)
    document.addEventListener('click', function (event) {
        var tautan = event.target.closest('a.btn-hapus-wilayah');
        if (!tautan) {
            return;
        }
        event.preventDefault();
        var nama = tautan.getAttribute('data-nama') || '';
        var url = tautan.getAttribute('href');
        if (typeof Swal === 'undefined') {
            if (window.confirm('Hapus wilayah "' + nama + '"?')) {
                window.location.href = url;
            }
            return;
        }
        Swal.fire({
            title: 'Peringatan!',
            text: 'Apakah kamu yakin menghapus wilayah "' + nama + '" ?',
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
