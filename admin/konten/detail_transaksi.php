<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('detail_transaksi'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// RAPIH + AMAN (2026-11-09)
// Sebelumnya: setiap baris merender satu modal edit di dalam <tbody>
// (HTML tidak valid), dan beberapa nilai tidak di-escape menyeluruh.
// Sekarang: satu modal edit bersama yang diisi dari atribut data-*,
// output di-escape, dan ringkasan jumlah barang ditampilkan di header.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$id_transaksi = isset($_GET['id']) && is_scalar($_GET['id']) ? (int) $_GET['id'] : 0;

// Ambil data header (untuk info di atas tabel)
$header = null;
if ($id_transaksi > 0) {
    if ($stmt = $koneksi->prepare("SELECT * FROM transaksi WHERE id_transaksi = ? LIMIT 1")) {
        $stmt->bind_param('i', $id_transaksi);
        $stmt->execute();
        $header = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$header) {
    echo '<main class="app-main"><div class="app-content p-4">'
        . '<div class="alert alert-warning">Transaksi tidak ditemukan. '
        . '<a href="index.php?menu=pengeluaran" class="alert-link">Kembali ke daftar transaksi</a>.</div>'
        . '</div></main>';
    return;
}

$id_transaksi = (int) $header['id_transaksi'];
$id_piodalan  = (int) $header['id_piodalan'];

// Hitung Grand Total
$grand_total = 0.0;
if ($stmt = $koneksi->prepare("SELECT COALESCE(SUM(total), 0) AS grand_total FROM detail_transaksi WHERE id_transaksi = ?")) {
    $stmt->bind_param('i', $id_transaksi);
    $stmt->execute();
    $grand_total = (float) ($stmt->get_result()->fetch_assoc()['grand_total'] ?? 0);
    $stmt->close();
}

// Ambil seluruh rincian sekali (dipakai untuk tabel + modal bersama)
$detail_rows = [];
if ($q = $koneksi->prepare("SELECT * FROM detail_transaksi WHERE id_transaksi = ? ORDER BY id_detail")) {
    $q->bind_param('i', $id_transaksi);
    $q->execute();
    $res = $q->get_result();
    while ($row = $res->fetch_assoc()) {
        $detail_rows[] = $row;
    }
    $q->close();
}
$jumlah_barang = count($detail_rows);
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <h3 class="mb-0 fw-bold text-dark">Rincian Belanja</h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="index.php?menu=pengeluaran&id_odalan=<?= (int) $id_piodalan ?>" class="btn btn-secondary btn-sm">
                        <i class="fa-solid fa-chevron-left"></i> Kembali
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="card mb-4 border-0 shadow-sm bg-primary text-white">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <small class="d-block opacity-75">Keterangan Transaksi:</small>
                        <h5 class="mb-0 fw-bold"><?= $esc($header['keterangan'] ?: 'Tanpa Keterangan') ?></h5>
                        <small class="d-block opacity-75 mt-1">
                            <?= $esc(date('d/m/Y', strtotime((string) $header['tanggal']))) ?>
                            &middot; <span class="badge bg-light text-dark"><?= (int) $jumlah_barang ?> barang</span>
                        </small>
                    </div>
                    <div class="text-end">
                        <small class="d-block opacity-75">Total Biaya:</small>
                        <h2 class="mb-0 fw-bold">Rp <?= number_format($grand_total, 0, ',', '.') ?></h2>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-bold">Daftar Barang</h5>
                    <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#modalTambah">
                        <i class="fa-solid fa-plus"></i> Tambah Barang
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" width="60">No</th>
                                    <th>Nama Barang</th>
                                    <th class="text-end">Harga</th>
                                    <th class="text-center">Jumlah</th>
                                    <th>Satuan</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-center" width="120">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($detail_rows)) : ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5 text-muted">
                                            <i class="fa-solid fa-box-open fa-2x mb-2 d-block"></i>
                                            Belum ada barang pada nota ini. Klik "Tambah Barang" untuk mengisi rincian.
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <?php $no = 1; foreach ($detail_rows as $row) : ?>
                                        <tr>
                                            <td class="text-center"><?= $no++ ?></td>
                                            <td class="fw-bold"><?= $esc($row['nama_barang']) ?></td>
                                            <td class="text-end">Rp <?= number_format((float) $row['harga'], 0, ',', '.') ?></td>
                                            <td class="text-center"><?= $esc(rtrim(rtrim(number_format((float) $row['jumlah'], 2, ',', '.'), '0'), ',')) ?></td>
                                            <td><span class="badge bg-secondary"><?= $esc($row['satuan']) ?></span></td>
                                            <td class="text-end fw-bold text-primary">Rp <?= number_format((float) $row['total'], 0, ',', '.') ?></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-warning btn-sm"
                                                    data-bs-toggle="modal" data-bs-target="#modalEdit"
                                                    data-id="<?= (int) $row['id_detail'] ?>"
                                                    data-nama="<?= $esc($row['nama_barang']) ?>"
                                                    data-harga="<?= $esc((float) $row['harga']) ?>"
                                                    data-jumlah="<?= $esc((float) $row['jumlah']) ?>"
                                                    data-satuan="<?= $esc($row['satuan']) ?>"
                                                    title="Edit barang">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                <a href="aksi/aksi_detail.php?aksi=hapus&id=<?= (int) $row['id_detail'] ?>&id_t=<?= (int) $id_transaksi ?>&csrf_token=<?= $esc(kbap_csrf_token()) ?>"
                                                    class="btn btn-danger btn-sm" onclick="return confirm('Hapus barang ini?')" title="Hapus barang">
                                                    <i class="fa-solid fa-trash"></i>
                                                </a>
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

<!-- Modal tambah barang -->
<div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="aksi/aksi_detail.php" method="POST">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_transaksi" value="<?= (int) $id_transaksi ?>">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i>Tambah Barang/Jasa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold" for="tambah-nama-barang">Nama Barang</label>
                        <input type="text" name="nama_barang" id="tambah-nama-barang" class="form-control" placeholder="Contoh: Dupa, Kelapa, dll" maxlength="255" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label small fw-bold" for="tambah-harga">Harga Satuan</label>
                            <input type="number" name="harga" id="tambah-harga" class="form-control" placeholder="0" min="0" step="0.01" required>
                        </div>
                        <div class="col-3 mb-3">
                            <label class="form-label small fw-bold" for="tambah-jumlah">Jumlah</label>
                            <input type="number" name="jumlah" id="tambah-jumlah" class="form-control" value="1" min="0.01" step="0.01" required>
                        </div>
                        <div class="col-3 mb-3">
                            <label class="form-label small fw-bold" for="tambah-satuan">Satuan</label>
                            <input type="text" name="satuan" id="tambah-satuan" class="form-control" placeholder="Pcs" value="Pcs" maxlength="15">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-dark px-4">Simpan ke Daftar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal edit bersama (diisi dari atribut data-* tombol yang diklik) -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="aksi/aksi_detail.php" method="POST">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id_detail" id="edit-id-detail" value="">
                <input type="hidden" name="id_transaksi" value="<?= (int) $id_transaksi ?>">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit Barang</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold" for="edit-nama-barang">Nama Barang</label>
                        <input type="text" name="nama_barang" id="edit-nama-barang" class="form-control" maxlength="255" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label small fw-bold" for="edit-harga">Harga</label>
                            <input type="number" name="harga" id="edit-harga" class="form-control" min="0" step="0.01" required>
                        </div>
                        <div class="col-3 mb-3">
                            <label class="form-label small fw-bold" for="edit-jumlah">Qty</label>
                            <input type="number" name="jumlah" id="edit-jumlah" class="form-control" min="0.01" step="0.01" required>
                        </div>
                        <div class="col-3 mb-3">
                            <label class="form-label small fw-bold" for="edit-satuan">Satuan</label>
                            <input type="text" name="satuan" id="edit-satuan" class="form-control" placeholder="Pcs/Kg" maxlength="15">
                        </div>
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
    var modalEdit = document.getElementById('modalEdit');
    if (!modalEdit) {
        return;
    }
    modalEdit.addEventListener('show.bs.modal', function (event) {
        var tombol = event.relatedTarget;
        if (!tombol) {
            return;
        }
        document.getElementById('edit-id-detail').value = tombol.getAttribute('data-id') || '';
        document.getElementById('edit-nama-barang').value = tombol.getAttribute('data-nama') || '';
        document.getElementById('edit-harga').value = tombol.getAttribute('data-harga') || '';
        document.getElementById('edit-jumlah').value = tombol.getAttribute('data-jumlah') || '';
        document.getElementById('edit-satuan').value = tombol.getAttribute('data-satuan') || '';
    });
});
</script>
