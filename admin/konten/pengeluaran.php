<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('pengeluaran'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// RAPIH + AMAN (2026-11-09)
// Sebelumnya: `id_odalan` dari URL disisipkan langsung ke 3 query
// (SQL injection), 37 modal edit dirender di dalam <tbody> (HTML tidak
// valid, ±228 KB), dan output tidak di-escape menyeluruh.
// Sekarang: id divalidasi terhadap daftar odalan, semua query prepared,
// satu modal edit bersama yang diisi dari atribut data-*, output escaped.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

// 1. Daftar odalan (terbaru lebih dulu) + odalan default
$odalan_list = [];
if ($q_odalan = $koneksi->query("SELECT id, nama_odalan FROM odalan ORDER BY tanggal DESC, id DESC")) {
    while ($row = $q_odalan->fetch_assoc()) {
        $odalan_list[(int) $row['id']] = $row['nama_odalan'];
    }
}
$id_default = $odalan_list ? (int) array_key_first($odalan_list) : 0;

// 2. Odalan terpilih: divalidasi terhadap daftar (bukan string mentah)
$selected_id_odalan = (isset($_GET['id_odalan']) && is_scalar($_GET['id_odalan'])) ? (int) $_GET['id_odalan'] : 0;
if ($selected_id_odalan === 0 && !isset($_GET['id_odalan'])) {
    $selected_id_odalan = $id_default; // tanpa parameter → odalan terbaru
}
if ($selected_id_odalan > 0 && !isset($odalan_list[$selected_id_odalan])) {
    $selected_id_odalan = 0; // id tidak dikenal → tampilkan keadaan kosong
}

// 3. Ringkasan: total belanja & jumlah nota untuk odalan terpilih
$total_seluruh_belanja = 0.0;
$jml_nota              = 0;
$data_nota             = [];

if ($selected_id_odalan > 0) {
    if ($stmt = $koneksi->prepare("SELECT COALESCE(SUM(dt.total), 0) AS grand_total
                                   FROM detail_transaksi dt
                                   JOIN transaksi t ON dt.id_transaksi = t.id_transaksi
                                   WHERE t.id_piodalan = ?")) {
        $stmt->bind_param('i', $selected_id_odalan);
        $stmt->execute();
        $total_seluruh_belanja = (float) ($stmt->get_result()->fetch_assoc()['grand_total'] ?? 0);
        $stmt->close();
    }

    if ($stmt = $koneksi->prepare("SELECT COUNT(*) AS c FROM transaksi WHERE id_piodalan = ?")) {
        $stmt->bind_param('i', $selected_id_odalan);
        $stmt->execute();
        $jml_nota = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }

    if ($stmt = $koneksi->prepare("SELECT t.id_transaksi, t.tanggal, t.keterangan,
                                          (SELECT COALESCE(SUM(total), 0) FROM detail_transaksi
                                            WHERE id_transaksi = t.id_transaksi) AS total_nota
                                   FROM transaksi t
                                   WHERE t.id_piodalan = ?
                                   ORDER BY t.tanggal DESC, t.id_transaksi DESC")) {
        $stmt->bind_param('i', $selected_id_odalan);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $data_nota[] = $r;
        }
        $stmt->close();
    }
}
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <h3 class="mb-0 fw-bold text-dark">Manajemen Transaksi</h3>
                    <p class="text-muted small mb-0">Kelola pengeluaran dana setiap kegiatan odalan.</p>
                </div>
                <div class="col-sm-6 text-end">
                    <?php if ($selected_id_odalan > 0): ?>
                        <a href="aksi/cetak_pdf.php?id_odalan=<?= (int) $selected_id_odalan ?>" target="_blank" class="btn btn-danger shadow-sm">
                            <i class="fa-solid fa-file-pdf me-1"></i> Cetak Laporan PDF
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <?php if ($selected_id_odalan > 0): ?>
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card shadow-sm border-0 border-start border-primary border-5">
                            <div class="card-body">
                                <h6 class="text-muted small text-uppercase fw-bold">Total Pengeluaran Kegiatan</h6>
                                <h3 class="fw-bold text-primary mb-0">Rp <?= number_format($total_seluruh_belanja, 0, ',', '.') ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card shadow-sm border-0 border-start border-success border-5">
                            <div class="card-body">
                                <h6 class="text-muted small text-uppercase fw-bold">Total Nota</h6>
                                <h3 class="fw-bold text-success mb-0"><?= number_format($jml_nota, 0, ',', '.') ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 p-3 mb-4 bg-light">
                <form method="GET" action="index.php">
                    <input type="hidden" name="menu" value="pengeluaran">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6">
                            <label class="small fw-bold text-secondary" for="pilih-odalan">Pilih Kegiatan Odalan:</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-calendar-days text-primary"></i></span>
                                <select id="pilih-odalan" name="id_odalan" class="form-select border-start-0" onchange="this.form.submit()">
                                    <option value="">-- Pilih Odalan --</option>
                                    <?php foreach ($odalan_list as $id_o => $nama_o) : ?>
                                        <option value="<?= (int) $id_o ?>" <?= $selected_id_odalan === (int) $id_o ? 'selected' : '' ?>>
                                            <?= $esc($nama_o) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100 shadow-sm">
                                <i class="fa-solid fa-filter me-1"></i> Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-bold"><i class="fa-solid fa-table me-2 text-secondary"></i>Rincian Nota Transaksi</h5>
                    <?php if ($selected_id_odalan > 0): ?>
                        <button class="btn btn-dark btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTambah">
                            <i class="fa fa-plus me-1"></i> Tambah Nota
                        </button>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="tabelNota" class="table table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th width="50" class="text-center">No</th>
                                    <th>Tanggal Nota</th>
                                    <th>Keterangan / Nama Nota</th>
                                    <th class="text-end">Total Biaya</th>
                                    <th width="200" class="text-center">Opsi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($selected_id_odalan <= 0) : ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted">
                                            <i class="fa-solid fa-inbox fa-3x mb-3 d-block"></i>
                                            Silakan pilih kegiatan odalan terlebih dahulu.
                                        </td>
                                    </tr>
                                <?php elseif (empty($data_nota)) : ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted">
                                            <i class="fa-solid fa-inbox fa-3x mb-3 d-block"></i>
                                            Belum ada data transaksi untuk piodalan ini.
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <?php $no = 1; foreach ($data_nota as $item) : ?>
                                        <tr>
                                            <td class="text-center text-muted"><?= $no++ ?></td>
                                            <td>
                                                <span class="badge bg-light text-dark border">
                                                    <i class="fa-regular fa-calendar me-1"></i>
                                                    <?= $esc(date('d/m/Y', strtotime((string) $item['tanggal']))) ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold"><?= $esc($item['keterangan'] !== '' && $item['keterangan'] !== null ? $item['keterangan'] : '-') ?></td>
                                            <td class="text-end fw-bold text-danger">
                                                Rp <?= number_format((float) ($item['total_nota'] ?? 0), 0, ',', '.') ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group">
                                                    <a href="index.php?menu=detail_transaksi&id=<?= (int) $item['id_transaksi'] ?>" class="btn btn-outline-info btn-sm" title="Lihat Detail Barang">
                                                        <i class="fa-solid fa-eye"></i> Detail
                                                    </a>
                                                    <button type="button" class="btn btn-outline-warning btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#modalEdit"
                                                        data-id="<?= (int) $item['id_transaksi'] ?>"
                                                        data-tanggal="<?= $esc($item['tanggal']) ?>"
                                                        data-keterangan="<?= $esc($item['keterangan']) ?>"
                                                        title="Edit informasi nota">
                                                        <i class="fa-solid fa-pen"></i>
                                                    </button>
                                                    <a href="aksi/aksi_transaksi.php?aksi=hapus&id=<?= (int) $item['id_transaksi'] ?>&id_odalan=<?= (int) $selected_id_odalan ?>&csrf_token=<?= $esc(kbap_csrf_token()) ?>"
                                                        class="btn btn-outline-danger btn-sm"
                                                        onclick="return confirm('Hapus nota ini? Semua detail barang di dalamnya juga akan terhapus.')"
                                                        title="Hapus nota">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </a>
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

<!-- Modal edit bersama (diisi dari atribut data-* tombol yang diklik) -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="aksi/aksi_transaksi.php" method="POST">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id_transaksi" id="edit-id-transaksi" value="">
                <input type="hidden" name="id_piodalan" value="<?= (int) $selected_id_odalan ?>">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit Informasi Nota</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-tanggal">Tanggal Nota</label>
                        <input type="date" class="form-control" id="edit-tanggal" name="tanggal" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-keterangan">Keterangan Nota</label>
                        <textarea class="form-control" id="edit-keterangan" name="keterangan" rows="3" required></textarea>
                        <small class="text-muted">Contoh: Nota Toko Bangunan A, Konsumsi Pagi, dll.</small>
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

<!-- Modal tambah nota -->
<div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="aksi/aksi_transaksi.php" method="POST">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_piodalan" value="<?= (int) $selected_id_odalan ?>">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus-circle me-2"></i>Tambah Nota Baru</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-tanggal">Tanggal Transaksi</label>
                        <input type="date" class="form-control" id="tambah-tanggal" name="tanggal" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-keterangan">Keterangan / Nama Nota</label>
                        <textarea class="form-control" id="tambah-keterangan" name="keterangan" rows="3" placeholder="Masukkan nama toko atau jenis pengeluaran..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-dark px-4">Simpan Nota</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEdit = document.getElementById('modalEdit');
    if (!modalEdit) {
        return;
    }
    modalEdit.addEventListener('show.bs.modal', function (event) {
        var tombol = event.relatedTarget;
        if (!tombol) {
            return;
        }
        document.getElementById('edit-id-transaksi').value = tombol.getAttribute('data-id') || '';
        document.getElementById('edit-tanggal').value = tombol.getAttribute('data-tanggal') || '';
        document.getElementById('edit-keterangan').value = tombol.getAttribute('data-keterangan') || '';
    });
});
</script>
