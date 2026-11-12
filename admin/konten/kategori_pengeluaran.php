<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('kategori_pengeluaran'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// RAPIH + AMAN (2026-11-10)
// Sebelumnya: setiap baris merender satu modal edit + satu blok <script>
// DI DALAM <tbody> (HTML tidak valid), nama kategori dirender mentah (XSS),
// dan tombol hapus aktif walau kategori masih dipakai `pengeluaran`
// (FK `pengeluaran_ibfk_2` = RESTRICT, jadi hapus akan error SQL).
// Sekarang: satu modal edit bersama, satu handler hapus, badge pemakaian,
// tombol hapus terkunci bila masih dipakai, semua output di-escape.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$daftar = [];
$sql = "SELECT k.id_kategori, k.nama_kategori,
               (SELECT COUNT(*) FROM pengeluaran p WHERE p.id_kategori = k.id_kategori) AS dipakai
        FROM kategori_pengeluaran k
        ORDER BY k.nama_kategori ASC, k.id_kategori ASC";
if ($q = $koneksi->query($sql)) {
    while ($r = $q->fetch_assoc()) {
        $daftar[] = $r;
    }
    $q->free();
}

$total_kategori = count($daftar);
$total_dipakai  = 0;
$total_pengeluaran = 0;
foreach ($daftar as $r) {
    $jumlah = (int) $r['dipakai'];
    $total_pengeluaran += $jumlah;
    if ($jumlah > 0) {
        $total_dipakai++;
    }
}

$boleh_kelola = in_array(kbap_role(), ['admin', 'bagian_data'], true);
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-8">
                    <h3 class="mb-0 fw-bold text-dark">Data Kategori Pengeluaran</h3>
                    <p class="text-muted small mb-0">
                        Master kategori untuk pengeluaran model lama.
                        <span class="badge bg-secondary ms-1"><?= (int) $total_kategori ?> kategori</span>
                        <span class="badge bg-primary"><?= (int) $total_dipakai ?> dipakai</span>
                        <span class="badge bg-success"><?= number_format($total_pengeluaran, 0, ',', '.') ?> pengeluaran</span>
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
                        <?php if ($boleh_kelola) : ?>
                            <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalTambahKategori">
                                <i class="fa-solid fa-plus"></i> Tambah Kategori
                            </button>
                        <?php else : ?>
                            <span class="text-muted small">
                                <i class="fa-solid fa-lock me-1"></i>
                                Hanya admin dan petugas pendataan yang bisa mengubah kategori.
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <table id="example1" class="table table-bordered table-striped table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th style="width: 70px;">No</th>
                                    <th>Nama Kategori</th>
                                    <th style="width: 200px;">Dipakai</th>
                                    <th style="width: 150px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($daftar)) : ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-muted">
                                            <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                                            Belum ada data kategori pengeluaran.
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <?php $no = 1; ?>
                                    <?php foreach ($daftar as $row) : ?>
                                        <?php $dipakai = (int) $row['dipakai']; ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td class="fw-semibold"><?= $esc($row['nama_kategori']) ?></td>
                                            <td>
                                                <?php if ($dipakai > 0) : ?>
                                                    <span class="badge bg-primary"><?= number_format($dipakai, 0, ',', '.') ?> pengeluaran</span>
                                                <?php else : ?>
                                                    <span class="badge bg-light text-dark">belum dipakai</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <?php if ($boleh_kelola) : ?>
                                                        <button type="button" class="btn btn-sm btn-primary"
                                                            data-bs-toggle="modal" data-bs-target="#modalEditKategori"
                                                            data-id="<?= (int) $row['id_kategori'] ?>"
                                                            data-nama="<?= $esc($row['nama_kategori']) ?>"
                                                            title="Edit kategori">
                                                            <i class="fas fa-pen-to-square"></i> Edit
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if (!$boleh_kelola) : ?>
                                                        <?php /* tombol hapus disembunyikan untuk role yang tidak berhak */ ?>
                                                    <?php elseif ($dipakai > 0) : ?>
                                                        <button type="button" class="btn btn-sm btn-danger" disabled
                                                            title="Tidak bisa dihapus: masih dipakai <?= number_format($dipakai, 0, ',', '.') ?> pengeluaran">
                                                            <i class="fas fa-lock"></i> Hapus
                                                        </button>
                                                    <?php else : ?>
                                                        <a href="aksi/aksi_kategori_pengeluaran.php?aksi=hapus&id=<?= (int) $row['id_kategori'] ?>&csrf_token=<?= $esc(kbap_csrf_token()) ?>"
                                                            class="btn btn-sm btn-danger btn-hapus-kategori"
                                                            data-nama="<?= $esc($row['nama_kategori']) ?>"
                                                            title="Hapus kategori">
                                                            <i class="fas fa-xmark"></i> Hapus
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
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

<?php if ($boleh_kelola) : ?>
    <!-- Modal tambah kategori -->
    <div class="modal fade" id="modalTambahKategori" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="aksi/aksi_kategori_pengeluaran.php" method="POST">
                    <input type="hidden" name="aksi" value="tambah">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i>Tambah Kategori</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label fw-bold" for="tambah-nama-kategori">Nama Kategori</label>
                            <input type="text" class="form-control" id="tambah-nama-kategori" name="nama_kategori" maxlength="100" required>
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

    <!-- Modal edit bersama -->
    <div class="modal fade" id="modalEditKategori" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="aksi/aksi_kategori_pengeluaran.php" method="POST">
                    <input type="hidden" name="aksi" value="edit">
                    <input type="hidden" name="id" id="edit-id-kategori" value="">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fw-bold"><i class="fas fa-pen-to-square me-2"></i>Edit Kategori</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label fw-bold" for="edit-nama-kategori">Nama Kategori</label>
                            <input type="text" class="form-control" id="edit-nama-kategori" name="nama_kategori" maxlength="100" required>
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
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Isi modal edit dari data-* tombol yang diklik
    var modalEdit = document.getElementById('modalEditKategori');
    if (modalEdit) {
        modalEdit.addEventListener('show.bs.modal', function (event) {
            var tombol = event.relatedTarget;
            if (!tombol) {
                return;
            }
            document.getElementById('edit-id-kategori').value = tombol.dataset.id || '';
            document.getElementById('edit-nama-kategori').value = tombol.dataset.nama || '';
        });
    }

    // 2. Konfirmasi hapus (satu handler untuk semua baris)
    document.addEventListener('click', function (event) {
        var tautan = event.target.closest('a.btn-hapus-kategori');
        if (!tautan) {
            return;
        }
        event.preventDefault();
        var nama = tautan.getAttribute('data-nama') || '';
        var url  = tautan.getAttribute('href');
        if (typeof Swal === 'undefined') {
            if (window.confirm('Hapus kategori "' + nama + '"?')) {
                window.location.href = url;
            }
            return;
        }
        Swal.fire({
            title: 'Peringatan!',
            text: 'Apakah kamu yakin menghapus kategori "' + nama + '"?',
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
