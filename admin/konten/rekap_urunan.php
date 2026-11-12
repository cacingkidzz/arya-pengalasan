<?php

require_once __DIR__ . '/../security.php';
kbap_guard_menu('rekap_urunan'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// PAGINATION SERVER-SIDE (2026-11-08)
// Sebelumnya seluruh 607 baris dirender sekaligus (±561 KB HTML) dan
// paging/pencarian memakai DataTables sisi klien (hanya menyaring baris
// yang sudah tampil). Sekarang filter + paging dijalankan di SQL.
// Parameter: q (nama/user_id), wilayah (id), status (Ada/Lunas),
//            per (0=semua, 25, 50, 100, 250), hal (nomor halaman).
// Baris TOTAL di bawah tabel ikut filter yang aktif.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

// Ambil daftar semua piodalan (menjadi kolom tabel)
$piodalan_list = [];
if ($result_piodalan = $koneksi->query("SELECT id, nama_odalan FROM odalan ORDER BY tanggal")) {
    while ($row = $result_piodalan->fetch_assoc()) {
        $piodalan_list[$row['id']] = $row['nama_odalan'];
    }
}

// Daftar wilayah untuk dropdown filter (23 baris, satu query)
$wilayah_list = [];
if ($q_wilayah = mysqli_query($koneksi, "SELECT id_wilayah, nama_wilayah FROM wilayah ORDER BY nama_wilayah ASC")) {
    while ($r = mysqli_fetch_assoc($q_wilayah)) {
        $wilayah_list[] = $r;
    }
}

$f_q       = (isset($_GET['q']) && is_string($_GET['q'])) ? trim($_GET['q']) : '';
$f_wilayah = (isset($_GET['wilayah']) && is_scalar($_GET['wilayah'])) ? (int) $_GET['wilayah'] : 0;
$f_status  = (isset($_GET['status']) && is_string($_GET['status'])) ? $_GET['status'] : '';
if (!in_array($f_status, ['Ada', 'Lunas'], true)) {
    $f_status = '';
}

$per = (isset($_GET['per']) && is_scalar($_GET['per'])) ? (int) $_GET['per'] : 50;
if (!in_array($per, [0, 25, 50, 100, 250], true)) {
    $per = 50;
}

$hal = (isset($_GET['hal']) && is_scalar($_GET['hal'])) ? (int) $_GET['hal'] : 1;
if ($hal < 1) {
    $hal = 1;
}

// --- WHERE dinamis (prepared statement) ---
$where = [];
$tipe  = '';
$nilai = [];
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
$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Query agregat: satu baris per semeton + rincian sisa per odalan
$sql_select = "SELECT s.id, s.user_id, s.nama, w.nama_wilayah, sw.nama_sub, s.jenis_user,
            COALESCE(SUM(h.jumlah_hutang), 0) AS total_hutang,
            CASE WHEN COALESCE(SUM(h.jumlah_hutang), 0) > 0 THEN 'Ada' ELSE 'Lunas' END AS status,
            GROUP_CONCAT(CONCAT(h.id_odalan, ':', h.jumlah_hutang) SEPARATOR '|') AS detail_hutang
          FROM semeton s
          LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
          LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
          LEFT JOIN hutang h ON s.id = h.id_semeton";
$sql_group = " GROUP BY s.id, s.user_id, s.nama, w.nama_wilayah, sw.nama_sub, s.jenis_user";

$sql_agregat = $sql_select . $where_sql . $sql_group;

// --- Total seluruh semeton (untuk keterangan "difilter dari N") ---
$total_semeton = 0;
if ($res_total = $koneksi->query("SELECT COUNT(*) AS c FROM semeton")) {
    $total_semeton = (int) ($res_total->fetch_assoc()['c'] ?? 0);
    $res_total->free();
}

// --- Total baris sesuai filter ---
$jumlah_filter = 0;
$sql_count = "SELECT COUNT(*) AS c FROM ($sql_agregat) x";
if ($f_status !== '') {
    $sql_count .= " WHERE x.status = ?";
}
if ($stmt = $koneksi->prepare($sql_count)) {
    if ($f_status !== '') {
        $stmt->bind_param($tipe . 's', ...array_merge($nilai, [$f_status]));
    } elseif ($tipe !== '') {
        $stmt->bind_param($tipe, ...$nilai);
    }
    $stmt->execute();
    $jumlah_filter = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
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
$sql = "SELECT * FROM ($sql_agregat) x";
if ($f_status !== '') {
    $sql .= " WHERE x.status = ?";
}
$sql .= " ORDER BY x.nama_wilayah ASC, x.nama_sub ASC, x.nama ASC, x.user_id ASC";
if ($per > 0) {
    $sql .= " LIMIT ? OFFSET ?";
}
if ($stmt = $koneksi->prepare($sql)) {
    $nilai_data = $nilai;
    if ($f_status !== '') {
        $nilai_data[] = $f_status;
    }
    if ($per > 0) {
        $nilai_data[] = $per;
        $nilai_data[] = $offset;
        $stmt->bind_param($tipe . ($f_status !== '' ? 's' : '') . 'ii', ...$nilai_data);
    } elseif ($nilai_data) {
        $stmt->bind_param($tipe . ($f_status !== '' ? 's' : ''), ...$nilai_data);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $data_rows[] = $r;
    }
    $stmt->close();
}

// --- Total footer (mengikuti filter aktif, bukan hanya halaman ini) ---
$total_per_piodalan = array_fill_keys(array_keys($piodalan_list), 0);
$total_hutang_semua = 0;
$sql_ids = "SELECT x.id FROM ($sql_agregat) x" . ($f_status !== '' ? " WHERE x.status = ?" : '');
$sql_total = "SELECT h.id_odalan, SUM(h.jumlah_hutang) AS sisa
              FROM hutang h
              JOIN ($sql_ids) f ON f.id = h.id_semeton
              GROUP BY h.id_odalan";
if ($stmt = $koneksi->prepare($sql_total)) {
    if ($f_status !== '') {
        $stmt->bind_param($tipe . 's', ...array_merge($nilai, [$f_status]));
    } elseif ($tipe !== '') {
        $stmt->bind_param($tipe, ...$nilai);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $id_o = (int) $r['id_odalan'];
        if (array_key_exists($id_o, $total_per_piodalan)) {
            $total_per_piodalan[$id_o] = (float) $r['sisa'];
        }
        $total_hutang_semua += (float) $r['sisa'];
    }
    $stmt->close();
}

// --- URL bantu pagination (mempertahankan filter) ---
$param_dasar = ['menu' => 'rekap_urunan'];
if ($f_q !== '') {
    $param_dasar['q'] = $f_q;
}
if ($f_wilayah > 0) {
    $param_dasar['wilayah'] = $f_wilayah;
}
if ($f_status !== '') {
    $param_dasar['status'] = $f_status;
}
if ($per !== 50) {
    $param_dasar['per'] = $per;
}
$url_hal = static function (int $h) use ($param_dasar): string {
    return 'index.php?' . http_build_query(array_merge($param_dasar, ['hal' => $h]));
};
$url_reset = 'index.php?menu=rekap_urunan';

// CSV ikut filter aktif
$param_csv = ['jenis' => 'urunan', 'csrf_token' => kbap_csrf_token()];
if ($f_q !== '') {
    $param_csv['q'] = $f_q;
}
if ($f_wilayah > 0) {
    $param_csv['wilayah'] = $f_wilayah;
}
if ($f_status !== '') {
    $param_csv['status'] = $f_status;
}
$url_csv = 'aksi/export_csv.php?' . http_build_query($param_csv);

// Rentang baris yang sedang tampil
$dari   = $jumlah_filter > 0 ? $offset + 1 : 0;
$sampai = $per > 0 ? min($offset + $per, $jumlah_filter) : $jumlah_filter;
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <h3 class="mb-0" id="judul-tabel">Rekap Urunan</h3>
                    <p class="text-muted small mb-0">Sisa tagihan urunan per semeton untuk setiap odalan.</p>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="<?= $esc($url_csv) ?>" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-filetype-csv"></i> Unduh CSV
                    </a>
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
                        <input type="hidden" name="menu" value="rekap_urunan">
                        <div class="col-12 col-lg-4">
                            <label class="form-label small text-muted mb-1" for="filter-q">Cari semeton</label>
                            <input type="search" id="filter-q" name="q" class="form-control" value="<?= $esc($f_q) ?>" placeholder="Nama atau ID anggota (APPxxxxx)">
                        </div>
                        <div class="col-6 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="filter-wilayah">Wilayah</label>
                            <select id="filter-wilayah" name="wilayah" class="form-select">
                                <option value="">Semua wilayah</option>
                                <?php foreach ($wilayah_list as $w) : ?>
                                    <option value="<?= (int) $w['id_wilayah'] ?>" <?= $f_wilayah === (int) $w['id_wilayah'] ? 'selected' : '' ?>><?= $esc($w['nama_wilayah']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="filter-status">Status</label>
                            <select id="filter-status" name="status" class="form-select">
                                <option value="">Semua status</option>
                                <option value="Ada" <?= $f_status === 'Ada' ? 'selected' : '' ?>>Ada sisa tagihan</option>
                                <option value="Lunas" <?= $f_status === 'Lunas' ? 'selected' : '' ?>>Lunas (tanpa sisa)</option>
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
                        <div class="col-12 col-lg-1 d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-fill" title="Terapkan filter">
                                <i class="fas fa-search"></i>
                            </button>
                            <?php if ($f_q !== '' || $f_wilayah > 0 || $f_status !== '' || $per !== 50) : ?>
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
                    <table id="tabelUrunan" class="table table-bordered table-striped mb-0">
                        <thead class="table-dark text-center">
                            <tr>
                                <th class="sticky-col" style="background: red; min-width: 100px;">User ID</th>
                                <th class="sticky-col" style="background: red; min-width: 150px;">Nama</th>
                                <th>Wilayah</th>
                                <th>Sub Wilayah</th>
                                <th>Jenis User</th>
                                <?php foreach ($piodalan_list as $piodalan): ?>
                                    <th><?= $esc($piodalan) ?></th>
                                <?php endforeach; ?>
                                <th class="table-warning">Total Hutang</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($jumlah_filter === 0) : ?>
                                <tr>
                                    <td colspan="<?= 6 + count($piodalan_list) ?>" class="text-center text-muted py-5">
                                        <?php if ($f_q !== '' || $f_wilayah > 0 || $f_status !== '') : ?>
                                            Tidak ada semeton yang cocok dengan filter. <a href="<?= $esc($url_reset) ?>">Reset filter</a>.
                                        <?php else : ?>
                                            Belum ada data semeton.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($data_rows as $row) :
                                $hutang_detail = [];
                                if (!empty($row['detail_hutang'])) {
                                    foreach (explode('|', $row['detail_hutang']) as $hutang) {
                                        $bagian = explode(':', $hutang, 2);
                                        if (count($bagian) === 2) {
                                            $hutang_detail[(int) $bagian[0]] = $bagian[1];
                                        }
                                    }
                                }
                            ?>
                                <tr>
                                    <td class="sticky-col" style="background: white;"><?= $esc($row['user_id']) ?></td>
                                    <td class="sticky-col" style="background: white;"><?= $esc($row['nama']) ?></td>
                                    <td><?= $esc($row['nama_wilayah']) ?></td>
                                    <td><?= $esc($row['nama_sub']) ?></td>
                                    <td><?= $esc(ucfirst((string) $row['jenis_user'])) ?></td>
                                    <?php foreach ($piodalan_list as $piodalan_id => $piodalan) :
                                        $jumlah_hutang = $hutang_detail[$piodalan_id] ?? null;
                                    ?>
                                        <td class="text-center"><?= $jumlah_hutang !== null ? 'Rp ' . number_format((float) $jumlah_hutang, 0, ',', '.') : '-' ?></td>
                                    <?php endforeach; ?>
                                    <td class="table-warning text-center">
                                        <?= $row['total_hutang'] > 0 ? 'Rp ' . number_format((float) $row['total_hutang'], 0, ',', '.') : '-' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th class="text-center" title="Total sesuai filter yang aktif">Total</th>
                                <?php foreach ($piodalan_list as $piodalan_id => $piodalan): ?>
                                    <th class="text-center">Rp <?= number_format((float) $total_per_piodalan[$piodalan_id], 0, ',', '.') ?></th>
                                <?php endforeach; ?>
                                <th class="table-warning text-center">Rp <?= number_format((float) $total_hutang_semua, 0, ',', '.') ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Info & navigasi halaman -->
                <div class="card-footer bg-white border-top px-4 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="text-muted small">
                        Menampilkan <strong><?= (int) $dari ?></strong>&ndash;<strong><?= (int) $sampai ?></strong>
                        dari <strong><?= number_format($jumlah_filter, 0, ',', '.') ?></strong> semeton
                        <?php if ($f_q !== '' || $f_wilayah > 0 || $f_status !== '') : ?>
                            <span class="text-secondary">(difilter dari <?= number_format($total_semeton, 0, ',', '.') ?> total)</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($per > 0 && $total_hal > 1) : ?>
                        <nav aria-label="Navigasi halaman rekap urunan">
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
