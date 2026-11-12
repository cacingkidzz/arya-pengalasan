<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('piodalan'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// RAPIH + AMAN (2026-11-10)
// Sebelumnya: setiap baris merender satu modal edit + dua blok <script>
// di dalam <tbody> (HTML tidak valid), seluruh output mentah (XSS), dan
// tombol Hapus aktif walau odalan sudah punya pembayaran/punia/nota.
//
// PENTING: FK `pembayaran_ibfk_2` ber-ON DELETE CASCADE dan
// `dana_punia`/`pengeluaran` ber-RESTRICT. Menghapus odalan yang punya
// pembayaran akan MENGHAPUS RIWAYAT PEMBAYARAN tanpa peringatan, dan
// menghapus odalan yang punya punia akan gagal SETELAH hutangnya
// terlanjur dihapus (data jadi tidak konsisten). Karena itu tombol hapus
// dinonaktifkan bila odalan masih punya data keuangan, dan endpoint
// menolaknya juga di sisi server.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$role          = kbap_role();
// Hak akses mengikuti endpoint (tidak diubah): kelola odalan = admin + bagian_data,
// generate tagihan = admin + bendahara.
$boleh_kelola  = in_array($role, ['admin', 'bagian_data'], true);
$boleh_generate = in_array($role, ['admin', 'bendahara'], true);

$daftar_odalan = [];
$sql = "SELECT o.id, o.nama_odalan, o.deskripsi, o.tanggal, o.iuran_ngarep, o.iuran_ngempi,
               (SELECT COUNT(*) FROM hutang h WHERE h.id_odalan = o.id) AS jml_hutang,
               (SELECT COUNT(*) FROM hutang h WHERE h.id_odalan = o.id AND h.status = 'belum_lunas') AS jml_belum,
               (SELECT COUNT(*) FROM pembayaran p WHERE p.id_piodalan = o.id AND p.dibatalkan_at IS NULL) AS jml_bayar,
               (SELECT COALESCE(SUM(p.jumlah_bayar), 0) FROM pembayaran p WHERE p.id_piodalan = o.id AND p.dibatalkan_at IS NULL) AS total_bayar,
               (SELECT COUNT(*) FROM dana_punia d WHERE d.id_piodalan = o.id) AS jml_punia,
               (SELECT COUNT(*) FROM pengeluaran g WHERE g.id_piodalan = o.id) AS jml_pengeluaran,
               (SELECT COUNT(*) FROM transaksi t WHERE t.id_piodalan = o.id) AS jml_transaksi
        FROM odalan o
        ORDER BY o.tanggal DESC, o.id DESC";
if ($q = $koneksi->query($sql)) {
    while ($r = $q->fetch_assoc()) {
        $daftar_odalan[] = $r;
    }
}

$total_odalan   = count($daftar_odalan);
$total_tagihan  = 0;
$total_terbayar = 0.0;
foreach ($daftar_odalan as $r) {
    $total_tagihan += (int) $r['jml_hutang'];
    $total_terbayar += (float) $r['total_bayar'];
}

$rupiah = static function ($n) {
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
};
$tanggal_rapi = static function ($t) {
    $ts = strtotime((string) $t);
    return $ts ? date('d-m-Y', $ts) : '-';
};
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-8">
                    <h3 class="mb-0 fw-bold text-dark">Daftar Odalan</h3>
                    <p class="text-muted small mb-0">
                        Kegiatan odalan beserta tarif iuran per jenis anggota.
                        <span class="badge bg-secondary ms-1"><?= (int) $total_odalan ?> odalan</span>
                        <span class="badge bg-primary"><?= number_format($total_tagihan, 0, ',', '.') ?> tagihan</span>
                        <span class="badge bg-success">terbayar <?= $rupiah($total_terbayar) ?></span>
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
                            <a href="#" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalTambahOdalan">
                                <i class="fa-solid fa-plus"></i> Tambah Odalan
                            </a>
                        <?php else : ?>
                            <span class="text-muted small">
                                <i class="fa-solid fa-lock me-1"></i>
                                Hanya admin dan petugas pendataan yang bisa menambah/mengubah odalan.
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <table id="example1" class="table table-bordered table-striped table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Nama Odalan</th>
                                    <th>Deskripsi</th>
                                    <th>Tanggal</th>
                                    <th>Iuran Ngarep</th>
                                    <th>Iuran Ngempi</th>
                                    <th>Tagihan</th>
                                    <th>Terbayar</th>
                                    <th width="230">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($daftar_odalan)) : ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">
                                            <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                                            Belum ada data odalan.
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ($daftar_odalan as $row) : ?>
                                        <?php
                                        $id_o        = (int) $row['id'];
                                        $jml_bayar   = (int) $row['jml_bayar'];
                                        $jml_punia   = (int) $row['jml_punia'];
                                        $jml_belanja = (int) $row['jml_pengeluaran'] + (int) $row['jml_transaksi'];
                                        $punya_keuangan = ($jml_bayar + $jml_punia + $jml_belanja) > 0;

                                        $alasan_kunci = [];
                                        if ($jml_bayar > 0) {
                                            $alasan_kunci[] = $jml_bayar . ' pembayaran';
                                        }
                                        if ($jml_punia > 0) {
                                            $alasan_kunci[] = $jml_punia . ' punia';
                                        }
                                        if ((int) $row['jml_pengeluaran'] > 0) {
                                            $alasan_kunci[] = (int) $row['jml_pengeluaran'] . ' pengeluaran';
                                        }
                                        if ((int) $row['jml_transaksi'] > 0) {
                                            $alasan_kunci[] = (int) $row['jml_transaksi'] . ' nota';
                                        }
                                        ?>
                                        <tr>
                                            <td><?= $id_o ?></td>
                                            <td class="fw-semibold"><?= $esc($row['nama_odalan']) ?></td>
                                            <td class="text-muted small"><?= $esc($row['deskripsi'] !== null && trim((string) $row['deskripsi']) !== '' ? $row['deskripsi'] : '-') ?></td>
                                            <td class="text-nowrap"><?= $esc($tanggal_rapi($row['tanggal'])) ?></td>
                                            <td class="text-nowrap"><?= $rupiah($row['iuran_ngarep']) ?></td>
                                            <td class="text-nowrap"><?= $rupiah($row['iuran_ngempi']) ?></td>
                                            <td class="text-nowrap">
                                                <?php if ((int) $row['jml_hutang'] === 0) : ?>
                                                    <span class="badge bg-light text-dark">belum dibuat</span>
                                                <?php else : ?>
                                                    <span class="badge bg-primary"><?= (int) $row['jml_hutang'] ?></span>
                                                    <?php if ((int) $row['jml_belum'] > 0) : ?>
                                                        <span class="badge bg-danger"><?= (int) $row['jml_belum'] ?> belum lunas</span>
                                                    <?php else : ?>
                                                        <span class="badge bg-success">lunas semua</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-nowrap">
                                                <?= $rupiah($row['total_bayar']) ?>
                                                <span class="text-muted small d-block"><?= $jml_bayar ?> transaksi</span>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <?php if ($boleh_kelola) : ?>
                                                        <button type="button" class="btn btn-sm btn-primary"
                                                            data-bs-toggle="modal" data-bs-target="#modalEditOdalan"
                                                            data-id="<?= $id_o ?>"
                                                            data-nama="<?= $esc($row['nama_odalan']) ?>"
                                                            data-deskripsi="<?= $esc($row['deskripsi']) ?>"
                                                            data-tanggal="<?= $esc($row['tanggal']) ?>"
                                                            data-ngarep="<?= $esc($row['iuran_ngarep']) ?>"
                                                            data-ngempi="<?= $esc($row['iuran_ngempi']) ?>"
                                                            title="Edit odalan">
                                                            <i class="fas fa-pen-to-square"></i> Edit
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($boleh_generate) : ?>
                                                        <button type="button" class="btn btn-sm btn-warning btn-generate-odalan"
                                                            data-id="<?= $id_o ?>"
                                                            data-nama="<?= $esc($row['nama_odalan']) ?>"
                                                            title="Buat tagihan untuk semeton baru">
                                                            <i class="fas fa-gear"></i> Generate
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if (!$boleh_kelola) : ?>
                                                        <?php /* tombol hapus disembunyikan untuk role yang tidak berhak */ ?>
                                                    <?php elseif ($punya_keuangan) : ?>
                                                        <button type="button" class="btn btn-sm btn-danger" disabled
                                                            title="Tidak bisa dihapus: masih punya <?= $esc(implode(', ', $alasan_kunci)) ?>">
                                                            <i class="fas fa-lock"></i> Hapus
                                                        </button>
                                                    <?php else : ?>
                                                        <a href="aksi/aksi_odalan.php?aksi=hapus&id=<?= $id_o ?>&csrf_token=<?= $esc(kbap_csrf_token()) ?>"
                                                            class="btn btn-sm btn-danger btn-hapus-odalan"
                                                            data-nama="<?= $esc($row['nama_odalan']) ?>"
                                                            title="Hapus odalan">
                                                            <i class="fas fa-xmark"></i> Hapus
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($punya_keuangan) : ?>
                                                    <div class="small text-muted mt-1">
                                                        <i class="fas fa-lock me-1"></i>terkunci: <?= $esc(implode(', ', $alasan_kunci)) ?>
                                                    </div>
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

<?php if ($boleh_kelola) : ?>
    <!-- Modal tambah odalan -->
    <div class="modal fade" id="modalTambahOdalan" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="aksi/aksi_odalan.php" method="POST">
                    <input type="hidden" name="aksi" value="tambah">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i>Tambah Odalan</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="tambah-nama-odalan">Nama Odalan</label>
                            <input type="text" class="form-control" id="tambah-nama-odalan" name="nama_odalan" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="tambah-deskripsi-odalan">Deskripsi</label>
                            <textarea class="form-control" id="tambah-deskripsi-odalan" name="deskripsi" rows="2" placeholder="Opsional"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="tambah-tanggal-odalan">Tanggal</label>
                            <input type="date" class="form-control" id="tambah-tanggal-odalan" name="tanggal" required>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold" for="tambah-ngarep-odalan">Iuran Ngarep</label>
                                <input type="number" class="form-control" id="tambah-ngarep-odalan" name="iuran_ngarep" min="0" step="0.01" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold" for="tambah-ngempi-odalan">Iuran Ngempi</label>
                                <input type="number" class="form-control" id="tambah-ngempi-odalan" name="iuran_ngempi" min="0" step="0.01" required>
                            </div>
                        </div>
                        <div class="alert alert-info py-2 mb-0 small">
                            <i class="fa-solid fa-circle-info me-1"></i>
                            Saat disimpan, tagihan otomatis dibuat untuk semua anggota aktif
                            (kecuali <em>pemangku</em>) sesuai tarifnya.
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
    <div class="modal fade" id="modalEditOdalan" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="aksi/aksi_odalan.php" method="POST">
                    <input type="hidden" name="aksi" value="edit">
                    <input type="hidden" name="id" id="edit-id-odalan" value="">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fw-bold"><i class="fas fa-pen-to-square me-2"></i>Edit Odalan</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="edit-id-odalan-tampil">ID Odalan</label>
                            <input type="text" class="form-control" id="edit-id-odalan-tampil" value="" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="edit-nama-odalan">Nama Odalan</label>
                            <input type="text" class="form-control" id="edit-nama-odalan" name="nama_odalan" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="edit-deskripsi-odalan">Deskripsi</label>
                            <textarea class="form-control" id="edit-deskripsi-odalan" name="deskripsi" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" for="edit-tanggal-odalan">Tanggal</label>
                            <input type="date" class="form-control" id="edit-tanggal-odalan" name="tanggal" required>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold" for="edit-ngarep-odalan">Iuran Ngarep</label>
                                <input type="number" class="form-control" id="edit-ngarep-odalan" name="iuran_ngarep" min="0" step="0.01" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold" for="edit-ngempi-odalan">Iuran Ngempi</label>
                                <input type="number" class="form-control" id="edit-ngempi-odalan" name="iuran_ngempi" min="0" step="0.01" required>
                            </div>
                        </div>
                        <div class="alert alert-warning py-2 mb-0 small">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            Mengubah tarif <strong>tidak</strong> mengubah tagihan yang sudah dibuat.
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
    // 1. Isi modal edit dari atribut data-* tombol yang diklik
    var modalEdit = document.getElementById('modalEditOdalan');
    if (modalEdit) {
        modalEdit.addEventListener('show.bs.modal', function (event) {
            var tombol = event.relatedTarget;
            if (!tombol) {
                return;
            }
            var d = tombol.dataset;
            document.getElementById('edit-id-odalan').value = d.id || '';
            document.getElementById('edit-id-odalan-tampil').value = d.id || '';
            document.getElementById('edit-nama-odalan').value = d.nama || '';
            document.getElementById('edit-deskripsi-odalan').value = d.deskripsi || '';
            document.getElementById('edit-tanggal-odalan').value = d.tanggal || '';
            document.getElementById('edit-ngarep-odalan').value = d.ngarep || '';
            document.getElementById('edit-ngempi-odalan').value = d.ngempi || '';
        });
    }

    // 2. Konfirmasi hapus (satu handler untuk semua baris)
    document.addEventListener('click', function (event) {
        var tautan = event.target.closest('a.btn-hapus-odalan');
        if (!tautan) {
            return;
        }
        event.preventDefault();
        var nama = tautan.getAttribute('data-nama') || '';
        var url = tautan.getAttribute('href');
        if (typeof Swal === 'undefined') {
            if (window.confirm('Hapus odalan "' + nama + '" beserta tagihannya?')) {
                window.location.href = url;
            }
            return;
        }
        Swal.fire({
            title: 'Peringatan!',
            text: 'Apakah kamu yakin menghapus odalan "' + nama + '" beserta seluruh tagihannya?',
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

    // 3. Generate tagihan untuk semeton baru (satu handler)
    document.addEventListener('click', function (event) {
        var tombol = event.target.closest('.btn-generate-odalan');
        if (!tombol) {
            return;
        }
        var id = tombol.getAttribute('data-id');
        var nama = tombol.getAttribute('data-nama') || '';
        var jalankan = function () {
            var url = 'aksi/aksi_generate_hutang.php?id_odalan=' + encodeURIComponent(id)
                + '&csrf_token=' + encodeURIComponent(window.KBAP_CSRF || '');
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (response) {
                    return response.text().then(function (teks) {
                        var data = null;
                        try {
                            data = JSON.parse(teks);
                        } catch (e) {
                            data = null;
                        }
                        if (!response.ok || !data) {
                            throw new Error('Respons tidak valid (' + response.status + ')');
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    var ok = data.status === 'success';
                    Swal.fire(ok ? 'Berhasil!' : 'Gagal!', data.message || '-', ok ? 'success' : 'error');
                })
                .catch(function (error) {
                    Swal.fire('Error!', 'Terjadi kesalahan dalam proses generate: ' + error.message, 'error');
                });
        };

        if (typeof Swal === 'undefined') {
            if (window.confirm('Buat tagihan untuk semeton baru pada odalan "' + nama + '"?')) {
                jalankan();
            }
            return;
        }
        Swal.fire({
            title: 'Konfirmasi',
            text: 'Apakah kamu ingin menambahkan hutang bagi semeton baru pada odalan "' + nama + '"?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Generate!',
            cancelButtonText: 'Batal'
        }).then(function (result) {
            if (result.isConfirmed) {
                jalankan();
            }
        });
    });
});
</script>
