<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('dana_punia'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// RAPIH + AMAN + PAGINATION SERVER-SIDE (2026-11-10)
// Sebelumnya:
//  - `id_piodalan` dari URL dipakai mentah di 3 query  -> SQL injection.
//  - setiap baris merender satu modal edit DI DALAM <tbody> (HTML tidak valid).
//  - nama donatur / alamat / keterangan dirender mentah -> XSS.
//  - form filter mengirim `menu=rekap_punia` (menu yang salah).
// Sekarang: `id_piodalan` divalidasi terhadap daftar odalan, modal edit
// dipakai bersama, paging/pencarian dijalankan di SQL, semua output
// di-escape, dan total mengikuti filter yang aktif.
// Parameter: id_piodalan, q (donatur/alamat/keterangan), metode,
//            per (0=semua, 25, 50, 100, 250), hal.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$rupiah = static function ($n) {
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
};
$tanggal_rapi = static function ($t) {
    $ts = strtotime((string) $t);
    return $ts ? date('d/m/Y', $ts) : '-';
};

// 1. Master odalan (sekali query)
$odalan_list = [];
if ($q_odalan = $koneksi->query('SELECT id, nama_odalan, tanggal FROM odalan ORDER BY tanggal DESC, id DESC')) {
    while ($r = $q_odalan->fetch_assoc()) {
        $odalan_list[(int) $r['id']] = $r;
    }
    $q_odalan->free();
}

// 2. Odalan terpilih — divalidasi, bukan disisipkan ke SQL
$f_odalan = (isset($_GET['id_piodalan']) && is_scalar($_GET['id_piodalan'])) ? (int) $_GET['id_piodalan'] : 0;
if (!array_key_exists($f_odalan, $odalan_list)) {
    $kunci_odalan = array_keys($odalan_list);
    $f_odalan = $kunci_odalan ? (int) $kunci_odalan[0] : 0;
}
$judul_odalan = $f_odalan > 0 ? $odalan_list[$f_odalan]['nama_odalan'] : 'Piodalan Belum Dipilih';

// 3. Filter lain
$f_q      = (isset($_GET['q']) && is_string($_GET['q'])) ? trim($_GET['q']) : '';
$f_metode = (isset($_GET['metode']) && is_scalar($_GET['metode'])) ? (string) $_GET['metode'] : '';
if (!in_array($f_metode, ['tunai', 'transfer', 'QRIS'], true)) {
    $f_metode = '';
}
$per = (isset($_GET['per']) && is_scalar($_GET['per'])) ? (int) $_GET['per'] : 50;
if (!in_array($per, [0, 25, 50, 100, 250], true)) {
    $per = 50;
}
$hal = (isset($_GET['hal']) && is_scalar($_GET['hal'])) ? (int) $_GET['hal'] : 1;
if ($hal < 1) {
    $hal = 1;
}

// 4. WHERE dinamis (prepared statement)
$where = ['d.id_piodalan = ?'];
$tipe  = 'i';
$nilai = [$f_odalan];
if ($f_q !== '') {
    $where[] = '(d.nama_donatur LIKE ? OR d.alamat LIKE ? OR d.keterangan LIKE ?)';
    $like    = '%' . $f_q . '%';
    $tipe   .= 'sss';
    array_push($nilai, $like, $like, $like);
}
if ($f_metode !== '') {
    $where[]  = 'd.metode_pembayaran = ?';
    $tipe    .= 's';
    $nilai[]  = $f_metode;
}
$where_sql = ' WHERE ' . implode(' AND ', $where);

// 5. Statistik odalan terpilih (tanpa filter) — untuk header
$stat_jml   = 0;
$stat_total = 0.0;
if ($stmt = $koneksi->prepare('SELECT COUNT(*) AS jml, COALESCE(SUM(jumlah_punia), 0) AS total FROM dana_punia WHERE id_piodalan = ?')) {
    $stmt->bind_param('i', $f_odalan);
    $stmt->execute();
    if ($stat = $stmt->get_result()->fetch_assoc()) {
        $stat_jml   = (int) ($stat['jml'] ?? 0);
        $stat_total = (float) ($stat['total'] ?? 0);
    }
    $stmt->close();
}

// 6. Total sesuai filter (dipakai footer, dihitung di SQL)
$jumlah_filter = 0;
$total_filter  = 0.0;
$sql_agg = "SELECT COUNT(*) AS jml, COALESCE(SUM(d.jumlah_punia), 0) AS total FROM dana_punia d" . $where_sql;
if ($stmt = $koneksi->prepare($sql_agg)) {
    $stmt->bind_param($tipe, ...$nilai);
    $stmt->execute();
    if ($agg = $stmt->get_result()->fetch_assoc()) {
        $jumlah_filter = (int) ($agg['jml'] ?? 0);
        $total_filter  = (float) ($agg['total'] ?? 0);
    }
    $stmt->close();
}

// 7. Normalisasi halaman
$total_hal = ($per > 0) ? max(1, (int) ceil($jumlah_filter / $per)) : 1;
if ($hal > $total_hal) {
    $hal = $total_hal;
}
$offset = ($per > 0) ? ($hal - 1) * $per : 0;

// 8. Ambil satu halaman data
$data_rows = [];
$sql = "SELECT d.* FROM dana_punia d" . $where_sql . " ORDER BY d.tanggal_punia DESC, d.id_punia DESC";
if ($per > 0) {
    $sql .= ' LIMIT ? OFFSET ?';
}
if ($stmt = $koneksi->prepare($sql)) {
    $nilai_data = $nilai;
    if ($per > 0) {
        $nilai_data[] = $per;
        $nilai_data[] = $offset;
        $stmt->bind_param($tipe . 'ii', ...$nilai_data);
    } else {
        $stmt->bind_param($tipe, ...$nilai_data);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $data_rows[] = $r;
    }
    $stmt->close();
}

// 9. URL bantu (mempertahankan filter)
$param_dasar = ['menu' => 'dana_punia', 'id_piodalan' => $f_odalan];
if ($f_q !== '') {
    $param_dasar['q'] = $f_q;
}
if ($f_metode !== '') {
    $param_dasar['metode'] = $f_metode;
}
if ($per !== 50) {
    $param_dasar['per'] = $per;
}
$url_hal = static function (int $h) use ($param_dasar): string {
    return 'index.php?' . http_build_query(array_merge($param_dasar, ['hal' => $h]));
};
$url_reset = 'index.php?menu=dana_punia&id_piodalan=' . (int) $f_odalan;

$param_csv = ['jenis' => 'punia', 'id_piodalan' => $f_odalan, 'csrf_token' => kbap_csrf_token()];
if ($f_q !== '') {
    $param_csv['q'] = $f_q;
}
if ($f_metode !== '') {
    $param_csv['metode'] = $f_metode;
}
$url_csv = 'aksi/export_csv.php?' . http_build_query($param_csv);

$ada_filter = ($f_q !== '' || $f_metode !== '');
$dari   = $jumlah_filter > 0 ? $offset + 1 : 0;
$sampai = $per > 0 ? min($offset + $per, $jumlah_filter) : $jumlah_filter;
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-8">
                    <h3 class="mb-0 text-success fw-bold"><i class="bi bi-heart-fill me-2"></i> Dana Punia</h3>
                    <p class="text-muted small mb-0">
                        Kelola donasi sukarela untuk <strong><?= $esc($judul_odalan) ?></strong>
                        <span class="badge bg-success ms-1"><?= $rupiah($stat_total) ?></span>
                        <span class="badge bg-secondary"><?= number_format($stat_jml, 0, ',', '.') ?> donasi</span>
                    </p>
                </div>
                <div class="col-sm-4 text-end">
                    <a href="<?= $esc($url_csv) ?>" class="btn btn-outline-success btn-sm me-1">
                        <i class="bi bi-filetype-csv"></i> Unduh CSV
                    </a>
                    <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#modalTambahPunia">
                        <i class="fa-solid fa-plus me-1"></i> Tambah
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <!-- Filter & pencarian server-side -->
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-body py-3">
                    <form method="GET" action="index.php" class="row g-2 align-items-end">
                        <input type="hidden" name="menu" value="dana_punia">
                        <div class="col-12 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="filter-odalan">Piodalan</label>
                            <select id="filter-odalan" name="id_piodalan" class="form-select border-success">
                                <?php foreach ($odalan_list as $id_o => $o) : ?>
                                    <option value="<?= (int) $id_o ?>" <?= $f_odalan === (int) $id_o ? 'selected' : '' ?>>
                                        <?= $esc($o['nama_odalan']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="filter-q">Cari donatur</label>
                            <input type="search" id="filter-q" name="q" class="form-control" value="<?= $esc($f_q) ?>" placeholder="Nama, alamat, atau keterangan">
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="filter-metode">Metode</label>
                            <select id="filter-metode" name="metode" class="form-select">
                                <option value="">Semua metode</option>
                                <option value="tunai" <?= $f_metode === 'tunai' ? 'selected' : '' ?>>Tunai</option>
                                <option value="transfer" <?= $f_metode === 'transfer' ? 'selected' : '' ?>>Transfer</option>
                                <option value="QRIS" <?= $f_metode === 'QRIS' ? 'selected' : '' ?>>QRIS</option>
                            </select>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="filter-per">Baris/halaman</label>
                            <select id="filter-per" name="per" class="form-select">
                                <?php foreach ([25 => '25 baris', 50 => '50 baris', 100 => '100 baris', 250 => '250 baris', 0 => 'Semua'] as $nilai_per => $label_per) : ?>
                                    <option value="<?= (int) $nilai_per ?>" <?= $per === (int) $nilai_per ? 'selected' : '' ?>><?= $esc($label_per) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-lg-2 d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-fill" title="Terapkan filter">
                                <i class="fas fa-search"></i> Cari
                            </button>
                            <?php if ($ada_filter || $per !== 50) : ?>
                                <a href="<?= $esc($url_reset) ?>" class="btn btn-outline-secondary" title="Reset filter">
                                    <i class="fas fa-rotate-left"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
                <div class="table-responsive">
                    <table id="tabelPunia" class="table table-bordered table-striped table-hover align-middle mb-0">
                        <thead class="table-dark text-center">
                            <tr>
                                <th style="width: 60px;">No</th>
                                <th>Nama Donatur</th>
                                <th>Alamat</th>
                                <th class="text-end">Jumlah</th>
                                <th>Keterangan</th>
                                <th>Metode</th>
                                <th>Tanggal</th>
                                <th style="width: 110px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($jumlah_filter === 0) : ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                                        <?php if ($ada_filter) : ?>
                                            Tidak ada donasi yang cocok dengan filter.
                                            <a href="<?= $esc($url_reset) ?>">Reset filter</a>.
                                        <?php else : ?>
                                            Belum ada data dana punia untuk piodalan ini.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else : ?>
                                <?php $no = $dari; ?>
                                <?php foreach ($data_rows as $row) : ?>
                                    <tr>
                                        <td class="text-center"><?= $no++ ?></td>
                                        <td class="fw-bold"><?= $esc($row['nama_donatur']) ?></td>
                                        <td class="small"><?= $esc($row['alamat'] !== null && trim((string) $row['alamat']) !== '' ? $row['alamat'] : '-') ?></td>
                                        <td class="text-end fw-bold text-success text-nowrap"><?= $rupiah($row['jumlah_punia']) ?></td>
                                        <td class="small fst-italic"><?= $esc($row['keterangan'] !== null && trim((string) $row['keterangan']) !== '' ? $row['keterangan'] : '-') ?></td>
                                        <td class="text-center">
                                            <span class="badge rounded-pill bg-outline-primary border border-primary text-primary px-3">
                                                <?= $esc(strtoupper((string) $row['metode_pembayaran'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-center small text-nowrap"><?= $esc($tanggal_rapi($row['tanggal_punia'])) ?></td>
                                        <td class="text-center">
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-primary"
                                                    data-bs-toggle="modal" data-bs-target="#modalEditPunia"
                                                    data-id="<?= (int) $row['id_punia'] ?>"
                                                    data-id-odalan="<?= (int) $row['id_piodalan'] ?>"
                                                    data-nama="<?= $esc($row['nama_donatur']) ?>"
                                                    data-alamat="<?= $esc($row['alamat']) ?>"
                                                    data-jumlah="<?= $esc($row['jumlah_punia']) ?>"
                                                    data-keterangan="<?= $esc($row['keterangan']) ?>"
                                                    data-metode="<?= $esc($row['metode_pembayaran']) ?>"
                                                    data-tanggal="<?= $esc($row['tanggal_punia']) ?>"
                                                    title="Edit punia">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="aksi/aksi_dana_punia.php?aksi=hapus&id=<?= (int) $row['id_punia'] ?>&csrf_token=<?= $esc(kbap_csrf_token()) ?>"
                                                    class="btn btn-sm btn-danger btn-hapus-punia"
                                                    data-nama="<?= $esc($row['nama_donatur']) ?>"
                                                    data-jumlah="<?= $rupiah($row['jumlah_punia']) ?>"
                                                    title="Hapus punia">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <th colspan="3" class="text-end" title="Total sesuai filter yang aktif">Total (sesuai filter)</th>
                                <th class="text-end text-nowrap"><?= $rupiah($total_filter) ?></th>
                                <th colspan="4"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Info & navigasi halaman -->
                <div class="card-footer bg-white border-top px-4 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="text-muted small">
                        Menampilkan <strong><?= (int) $dari ?></strong>&ndash;<strong><?= (int) $sampai ?></strong>
                        dari <strong><?= number_format($jumlah_filter, 0, ',', '.') ?></strong> donasi
                        <?php if ($ada_filter) : ?>
                            <span class="text-secondary">(difilter dari <?= number_format($stat_jml, 0, ',', '.') ?> donasi piodalan ini)</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($per > 0 && $total_hal > 1) : ?>
                        <nav aria-label="Navigasi halaman dana punia">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $hal <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $hal <= 1 ? '#' : $esc($url_hal($hal - 1)) ?>" aria-label="Sebelumnya">&laquo;</a>
                                </li>
                                <?php
                                $awal_hal  = max(1, $hal - 2);
                                $akhir_hal = min($total_hal, $hal + 2);
                                ?>
                                <?php if ($awal_hal > 1) : ?>
                                    <li class="page-item <?= $hal === 1 ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= $esc($url_hal(1)) ?>">1</a>
                                    </li>
                                    <?php if ($awal_hal > 2) : ?>
                                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php for ($p = $awal_hal; $p <= $akhir_hal; $p++) : ?>
                                    <li class="page-item <?= $p === $hal ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= $esc($url_hal($p)) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($akhir_hal < $total_hal) : ?>
                                    <?php if ($akhir_hal < $total_hal - 1) : ?>
                                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                    <?php endif; ?>
                                    <li class="page-item <?= $hal === $total_hal ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= $esc($url_hal($total_hal)) ?>"><?= $total_hal ?></a>
                                    </li>
                                <?php endif; ?>
                                <li class="page-item <?= $hal >= $total_hal ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $hal >= $total_hal ? '#' : $esc($url_hal($hal + 1)) ?>" aria-label="Berikutnya">&raquo;</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Modal tambah punia -->
<div class="modal fade" id="modalTambahPunia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="aksi/aksi_dana_punia.php" method="POST">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_piodalan" value="<?= (int) $f_odalan ?>">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus me-2"></i> Tambah Dana Punia</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small">
                        <i class="bi bi-info-circle me-2"></i> Donasi ini akan dicatat untuk: <strong><?= $esc($judul_odalan) ?></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-nama-punia">Nama Donatur</label>
                        <input type="text" class="form-control" id="tambah-nama-punia" name="nama" maxlength="255" placeholder="Contoh: I Gede Suka" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-alamat-punia">Alamat</label>
                        <textarea class="form-control" id="tambah-alamat-punia" name="alamat" rows="2" placeholder="Alamat atau asal donatur"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-7 mb-3">
                            <label class="form-label fw-bold" for="tambah-jumlah-punia">Jumlah Punia (Rp)</label>
                            <input type="number" class="form-control" id="tambah-jumlah-punia" name="jumlah" min="0" step="0.01" max="99999999.99" placeholder="0" required>
                        </div>
                        <div class="col-md-5 mb-3">
                            <label class="form-label fw-bold" for="tambah-metode-punia">Metode</label>
                            <select class="form-select" id="tambah-metode-punia" name="metode">
                                <option value="tunai">Tunai</option>
                                <option value="transfer">Transfer</option>
                                <option value="QRIS">QRIS</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-ket-punia">Keterangan</label>
                        <input type="text" class="form-control" id="tambah-ket-punia" name="ket" placeholder="Contoh: Untuk konsumsi">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-tanggal-punia">Tanggal</label>
                        <input type="date" class="form-control" id="tambah-tanggal-punia" name="tanggal" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success px-4">Simpan Dana Punia</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal edit bersama (diisi dari atribut data-* tombol yang diklik) -->
<div class="modal fade" id="modalEditPunia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="aksi/aksi_dana_punia.php" method="POST">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id" id="edit-id-punia" value="">
                <input type="hidden" name="id_piodalan" id="edit-id-odalan-punia" value="<?= (int) $f_odalan ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> Edit Dana Punia</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-nama-punia">Nama Donatur</label>
                        <input type="text" class="form-control" id="edit-nama-punia" name="nama" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-alamat-punia">Alamat</label>
                        <textarea class="form-control" id="edit-alamat-punia" name="alamat" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="edit-jumlah-punia">Jumlah (Rp)</label>
                            <input type="number" class="form-control" id="edit-jumlah-punia" name="jumlah" min="0" step="0.01" max="99999999.99" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="edit-metode-punia">Metode</label>
                            <select class="form-select" id="edit-metode-punia" name="metode">
                                <option value="tunai">Tunai</option>
                                <option value="transfer">Transfer</option>
                                <option value="QRIS">QRIS</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-ket-punia">Keterangan</label>
                        <input type="text" class="form-control" id="edit-ket-punia" name="ket">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-tanggal-punia">Tanggal</label>
                        <input type="date" class="form-control" id="edit-tanggal-punia" name="tanggal" required>
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
    // 1. Isi modal edit dari data-* baris yang diklik
    var modalEdit = document.getElementById('modalEditPunia');
    if (modalEdit) {
        modalEdit.addEventListener('show.bs.modal', function (event) {
            var tombol = event.relatedTarget;
            if (!tombol) {
                return;
            }
            var d = tombol.dataset;
            document.getElementById('edit-id-punia').value = d.id || '';
            document.getElementById('edit-id-odalan-punia').value = d.idOdalan || '';
            document.getElementById('edit-nama-punia').value = d.nama || '';
            document.getElementById('edit-alamat-punia').value = d.alamat || '';
            document.getElementById('edit-jumlah-punia').value = d.jumlah || '';
            document.getElementById('edit-ket-punia').value = d.keterangan || '';
            document.getElementById('edit-tanggal-punia').value = d.tanggal || '';
            var metode = document.getElementById('edit-metode-punia');
            metode.value = d.metode || 'tunai';
        });
    }

    // 2. Konfirmasi hapus (satu handler untuk semua baris)
    document.addEventListener('click', function (event) {
        var tautan = event.target.closest('a.btn-hapus-punia');
        if (!tautan) {
            return;
        }
        event.preventDefault();
        var nama   = tautan.getAttribute('data-nama') || '';
        var jumlah = tautan.getAttribute('data-jumlah') || '';
        var url    = tautan.getAttribute('href');
        if (typeof Swal === 'undefined') {
            if (window.confirm('Hapus dana punia dari "' + nama + '" (' + jumlah + ')?')) {
                window.location.href = url;
            }
            return;
        }
        Swal.fire({
            title: 'Hapus Data?',
            text: 'Dana punia dari "' + nama + '" sebesar ' + jumlah + ' akan dihapus permanen!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
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
