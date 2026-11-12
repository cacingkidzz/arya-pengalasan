<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('rekap_bayar'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// PAGINATION SERVER-SIDE + PERBAIKAN KEAMANAN (2026-11-08)
// Sebelumnya: semua transaksi satu odalan dirender sekaligus (±751 KB),
// filter petugas & total dihitung di sisi klien (hanya baris yang tampil),
// dan `id_piodalan` dari URL disisipkan langsung ke SQL (SQL injection).
// Sekarang: filter (petugas/wilayah/cari) + paging + total dijalankan di
// SQL dengan prepared statement.
// Parameter: id_piodalan, q, petugas, wilayah, per, hal.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

// 1. Daftar odalan (id terbesar = terbaru, dipakai sebagai default)
$odalan_list = [];
if ($result_list_odalan = $koneksi->query("SELECT id, nama_odalan FROM odalan ORDER BY id DESC")) {
    while ($list = $result_list_odalan->fetch_assoc()) {
        $odalan_list[(int) $list['id']] = $list['nama_odalan'];
    }
}

// 2. Pilihan odalan divalidasi terhadap daftar (bukan string mentah)
$f_odalan = (isset($_GET['id_piodalan']) && is_scalar($_GET['id_piodalan'])) ? (int) $_GET['id_piodalan'] : 0;
if (!isset($odalan_list[$f_odalan])) {
    $f_odalan = $odalan_list ? (int) array_key_first($odalan_list) : 0;
}
$judul_odalan = $odalan_list[$f_odalan] ?? 'Piodalan Belum Dipilih';

// 3. Filter lain
$f_q       = (isset($_GET['q']) && is_string($_GET['q'])) ? trim($_GET['q']) : '';
$f_wilayah = (isset($_GET['wilayah']) && is_scalar($_GET['wilayah'])) ? (int) $_GET['wilayah'] : 0;
$f_petugas = (isset($_GET['petugas']) && is_scalar($_GET['petugas'])) ? (int) $_GET['petugas'] : 0;

$per = (isset($_GET['per']) && is_scalar($_GET['per'])) ? (int) $_GET['per'] : 50;
if (!in_array($per, [0, 25, 50, 100, 250], true)) {
    $per = 50;
}

$hal = (isset($_GET['hal']) && is_scalar($_GET['hal'])) ? (int) $_GET['hal'] : 1;
if ($hal < 1) {
    $hal = 1;
}

// 4. Daftar wilayah untuk dropdown filter (23 baris, satu query)
$wilayah_list = [];
if ($q_wilayah = mysqli_query($koneksi, "SELECT id_wilayah, nama_wilayah FROM wilayah ORDER BY nama_wilayah ASC")) {
    while ($r = mysqli_fetch_assoc($q_wilayah)) {
        $wilayah_list[] = $r;
    }
}

// 5. Daftar petugas yang benar-benar mencatat pembayaran pada odalan ini
$petugas_list = [];
if ($stmt = $koneksi->prepare("SELECT u.id_user, u.username
                               FROM pembayaran p
                               JOIN user u ON u.id_user = p.id_user
                               WHERE p.id_piodalan = ? AND p.dibatalkan_at IS NULL
                               GROUP BY u.id_user, u.username
                               ORDER BY u.username ASC")) {
    $stmt->bind_param('i', $f_odalan);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $petugas_list[] = $r;
    }
    $stmt->close();
}

// --- WHERE dinamis (prepared statement) ---
$where = ['p.id_piodalan = ?', 'p.dibatalkan_at IS NULL'];
$tipe  = 'i';
$nilai = [$f_odalan];
if ($f_q !== '') {
    $where[] = '(s.nama LIKE ? OR s.user_id LIKE ?)';
    $like    = '%' . $f_q . '%';
    $tipe   .= 'ss';
    array_push($nilai, $like, $like);
}
if ($f_wilayah > 0) {
    $where[]  = 's.wilayah = ?';
    $tipe    .= 'i';
    $nilai[]  = $f_wilayah;
}
if ($f_petugas > 0) {
    $where[]  = 'p.id_user = ?';
    $tipe    .= 'i';
    $nilai[]  = $f_petugas;
}
$where_sql = ' WHERE ' . implode(' AND ', $where);

$sql_from = "FROM pembayaran p
             JOIN semeton s ON p.id_semeton = s.id
             JOIN wilayah w ON s.wilayah = w.id_wilayah
             JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
             LEFT JOIN user u ON p.id_user = u.id_user
             $where_sql";

// --- Total transaksi & nominal (sesuai filter) ---
$jumlah_filter  = 0;
$total_bayar    = 0.0;
if ($stmt = $koneksi->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(p.jumlah_bayar), 0) AS total $sql_from")) {
    $stmt->bind_param($tipe, ...$nilai);
    $stmt->execute();
    $ringkas       = $stmt->get_result()->fetch_assoc();
    $jumlah_filter = (int) ($ringkas['c'] ?? 0);
    $total_bayar   = (float) ($ringkas['total'] ?? 0);
    $stmt->close();
}

// --- Normalisasi halaman ---
$total_hal = ($per > 0) ? max(1, (int) ceil($jumlah_filter / $per)) : 1;
if ($hal > $total_hal) {
    $hal = $total_hal;
}
$offset = ($per > 0) ? ($hal - 1) * $per : 0;

// --- Ambil satu halaman data ---
$data_rows = [];
$sql = "SELECT s.user_id, s.nama, w.nama_wilayah, sw.nama_sub, s.jenis_user,
               p.jumlah_bayar, p.status, p.tanggal_bayar,
               u.id_user, u.username AS nama_petugas
        $sql_from
        ORDER BY p.tanggal_bayar DESC, p.id_pembayaran DESC";
if ($per > 0) {
    $sql .= " LIMIT ? OFFSET ?";
}
if ($stmt = $koneksi->prepare($sql)) {
    if ($per > 0) {
        $nilai_data = array_merge($nilai, [$per, $offset]);
        $stmt->bind_param($tipe . 'ii', ...$nilai_data);
    } else {
        $stmt->bind_param($tipe, ...$nilai);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $data_rows[] = $r;
    }
    $stmt->close();
}

// --- URL bantu pagination (mempertahankan filter) ---
$param_dasar = ['menu' => 'rekap_bayar', 'id_piodalan' => $f_odalan];
if ($f_q !== '') {
    $param_dasar['q'] = $f_q;
}
if ($f_wilayah > 0) {
    $param_dasar['wilayah'] = $f_wilayah;
}
if ($f_petugas > 0) {
    $param_dasar['petugas'] = $f_petugas;
}
if ($per !== 50) {
    $param_dasar['per'] = $per;
}
$url_hal = static function (int $h) use ($param_dasar): string {
    return 'index.php?' . http_build_query(array_merge($param_dasar, ['hal' => $h]));
};
$url_reset = 'index.php?menu=rekap_bayar&id_piodalan=' . $f_odalan;
$ada_filter = ($f_q !== '' || $f_wilayah > 0 || $f_petugas > 0 || $per !== 50);
// Label kartu total hanya berubah bila ada filter DATA (q/wilayah/petugas),
// bukan karena jumlah baris per halaman saja.
$ada_filter_data = ($f_q !== '' || $f_wilayah > 0 || $f_petugas > 0);

// Rentang baris yang sedang tampil
$dari   = $jumlah_filter > 0 ? $offset + 1 : 0;
$sampai = $per > 0 ? min($offset + $per, $jumlah_filter) : $jumlah_filter;
?>

<main class="app-main">
    <div class="app-content-header py-3">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-8">
                    <h3 class="mb-0 text-primary fw-bold">
                        <i class="bi bi-calendar2-event"></i> Rekap: <?= $esc($judul_odalan) ?>
                    </h3>
                    <p class="text-muted small mb-0">Daftar transaksi pembayaran urunan untuk odalan ini.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="row mb-3 g-3">
                <div class="col-lg-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <form method="GET" action="index.php">
                                <input type="hidden" name="menu" value="rekap_bayar">
                                <label class="form-label small fw-bold text-muted" for="pilih-odalan">Pilih Piodalan:</label>
                                <select id="pilih-odalan" name="id_piodalan" class="form-select border-primary" onchange="this.form.submit()">
                                    <?php foreach ($odalan_list as $id_o => $nama_o) : ?>
                                        <option value="<?= (int) $id_o ?>" <?= $f_odalan === (int) $id_o ? 'selected' : '' ?>>
                                            <?= $esc($nama_o) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card bg-primary text-white shadow border-0 h-100">
                        <div class="card-body py-2 text-center d-flex flex-column justify-content-center">
                            <small class="text-uppercase opacity-75 small">Total <?= $ada_filter_data ? '(sesuai filter)' : 'Keseluruhan' ?></small>
                            <h2 class="fw-bold mb-0">Rp <?= number_format($total_bayar, 0, ',', '.') ?></h2>
                            <small class="opacity-75"><?= number_format($jumlah_filter, 0, ',', '.') ?> transaksi</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter & pencarian server-side -->
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-body py-3">
                    <form method="GET" action="index.php" class="row g-2 align-items-end">
                        <input type="hidden" name="menu" value="rekap_bayar">
                        <input type="hidden" name="id_piodalan" value="<?= (int) $f_odalan ?>">
                        <div class="col-12 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="filter-q">Cari semeton</label>
                            <input type="search" id="filter-q" name="q" class="form-control" value="<?= $esc($f_q) ?>" placeholder="Nama atau ID anggota">
                        </div>
                        <div class="col-6 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="filter-petugas">Petugas</label>
                            <select id="filter-petugas" name="petugas" class="form-select">
                                <option value="">Semua petugas</option>
                                <?php foreach ($petugas_list as $pt) : ?>
                                    <option value="<?= (int) $pt['id_user'] ?>" <?= $f_petugas === (int) $pt['id_user'] ? 'selected' : '' ?>><?= $esc($pt['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="filter-wilayah">Wilayah</label>
                            <select id="filter-wilayah" name="wilayah" class="form-select">
                                <option value="">Semua wilayah</option>
                                <?php foreach ($wilayah_list as $w) : ?>
                                    <option value="<?= (int) $w['id_wilayah'] ?>" <?= $f_wilayah === (int) $w['id_wilayah'] ? 'selected' : '' ?>><?= $esc($w['nama_wilayah']) ?></option>
                                <?php endforeach; ?>
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
                                <i class="fas fa-search"></i>
                            </button>
                            <?php if ($ada_filter) : ?>
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
                    <table id="tabelBayar" class="table table-striped table-bordered align-middle mb-0">
                        <thead class="table-dark text-center">
                            <tr>
                                <th>User ID</th>
                                <th>Nama</th>
                                <th>Wilayah</th>
                                <th>Sub Wilayah</th>
                                <th>Jenis User</th>
                                <th>Nominal Bayar</th>
                                <th>Tanggal Bayar</th>
                                <th>Petugas</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($jumlah_filter === 0) : ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-5">
                                        <?php if ($ada_filter) : ?>
                                            Tidak ada transaksi yang cocok dengan filter. <a href="<?= $esc($url_reset) ?>">Reset filter</a>.
                                        <?php else : ?>
                                            Belum ada transaksi pembayaran pada odalan ini.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($data_rows as $row) : ?>
                                <tr>
                                    <td class="text-center"><?= $esc($row['user_id']) ?></td>
                                    <td class="fw-bold"><?= $esc($row['nama']) ?></td>
                                    <td><?= $esc($row['nama_wilayah']) ?></td>
                                    <td><?= $esc($row['nama_sub']) ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary text-white"><?= $esc(ucfirst((string) $row['jenis_user'])) ?></span>
                                    </td>
                                    <td class="text-end fw-bold">Rp <?= number_format((float) $row['jumlah_bayar'], 0, ',', '.') ?></td>
                                    <td class="small text-center"><?= $esc(date('d-m-Y H:i', strtotime((string) $row['tanggal_bayar']))) ?></td>
                                    <td class="text-center">
                                        <?php if (!empty($row['id_user'])) : ?>
                                            <a href="<?= $esc('index.php?' . http_build_query(['menu' => 'rekap_bayar', 'id_piodalan' => $f_odalan, 'petugas' => (int) $row['id_user']])) ?>"
                                                class="btn btn-sm btn-outline-info rounded-pill px-3">
                                                <?= $esc($row['nama_petugas'] ?? 'System') ?>
                                            </a>
                                        <?php else : ?>
                                            <span class="text-muted small">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $row['status'] == 'lunas' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                            <?= $esc(ucfirst((string) $row['status'])) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Info & navigasi halaman -->
                <div class="card-footer bg-white border-top px-4 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="text-muted small">
                        Menampilkan <strong><?= (int) $dari ?></strong>&ndash;<strong><?= (int) $sampai ?></strong>
                        dari <strong><?= number_format($jumlah_filter, 0, ',', '.') ?></strong> transaksi
                    </div>

                    <?php if ($per > 0 && $total_hal > 1) : ?>
                        <nav aria-label="Navigasi halaman rekap pembayaran">
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
