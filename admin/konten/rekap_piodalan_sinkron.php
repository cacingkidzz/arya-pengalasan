<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('rekap_piodalan_sinkron'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// PAGINATION SERVER-SIDE (2026-11-08)
// Sebelumnya seluruh 607 baris dirender sekaligus (±1,1 MB HTML) dan
// total footer dijumlahkan sambil merender baris (salah bila tabel
// dipotong). Sekarang filter + paging + total dijalankan di SQL.
// Parameter: q (nama/user_id), wilayah (id), status (Ada/Lunas),
//            per (0=semua, 25, 50, 100, 250), hal (nomor halaman).
//
// PERBAIKAN AKURASI (sejak 2026-11-08): rincian per odalan dahulu memakai
// GROUP_CONCAT(DISTINCT ...) atas baris pembayaran, sehingga dua
// pembayaran dengan nominal sama pada odalan yang sama (ada 8 kasus)
// terhitung satu kali di sel per odalan, padahal Total Bayar menghitung
// keduanya. Kini agregasi dilakukan per (semeton, odalan) lebih dulu.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

// =========================
// AMBIL DATA ODALAN
// =========================
$piodalan_list = [];
if ($result_piodalan = $koneksi->query("SELECT id, nama_odalan FROM odalan ORDER BY tanggal")) {
    while ($row = $result_piodalan->fetch_assoc()) {
        $piodalan_list[(int) $row['id']] = $row['nama_odalan'];
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

// =========================
// QUERY UTAMA (agregat per semeton)
// =========================
// h : total & rincian SISA tagihan per odalan
// p : total & rincian PEMBAYARAN per odalan (yang dibatalkan tidak dihitung)
// Keduanya diagregasi per (id_semeton, id_odalan) dulu agar rincian akurat.
$sql_select = "SELECT s.id, s.user_id, s.nama, w.nama_wilayah, sw.nama_sub, s.jenis_user,
            COALESCE(h.total_hutang, 0) AS total_hutang,
            COALESCE(h.detail_hutang, '') AS detail_hutang,
            COALESCE(p.total_bayar, 0) AS total_bayar,
            COALESCE(p.detail_bayar, '') AS detail_bayar,
            CASE WHEN COALESCE(h.total_hutang, 0) > 0 THEN 'Ada' ELSE 'Lunas' END AS status
          FROM semeton s
          LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
          LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
          LEFT JOIN (
              SELECT id_semeton,
                     SUM(sisa) AS total_hutang,
                     GROUP_CONCAT(CONCAT(id_odalan, ':', sisa) SEPARATOR '|') AS detail_hutang
              FROM (
                  SELECT id_semeton, id_odalan, SUM(jumlah_hutang) AS sisa
                  FROM hutang
                  GROUP BY id_semeton, id_odalan
              ) hh
              GROUP BY id_semeton
          ) h ON h.id_semeton = s.id
          LEFT JOIN (
              SELECT id_semeton,
                     SUM(dibayar) AS total_bayar,
                     GROUP_CONCAT(CONCAT(id_piodalan, ':', dibayar) SEPARATOR '|') AS detail_bayar
              FROM (
                  SELECT id_semeton, id_piodalan, SUM(jumlah_bayar) AS dibayar
                  FROM pembayaran
                  WHERE dibatalkan_at IS NULL
                  GROUP BY id_semeton, id_piodalan
              ) pp
              GROUP BY id_semeton
          ) p ON p.id_semeton = s.id";

$sql_agregat = $sql_select . $where_sql;

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
$total_hutang_semua = 0.0;
$total_bayar_semua  = 0.0;
$hutang_per_odalan  = array_fill_keys(array_keys($piodalan_list), 0.0);
$bayar_per_odalan   = array_fill_keys(array_keys($piodalan_list), 0.0);

$sql_ids = "SELECT x.id FROM ($sql_agregat) x" . ($f_status !== '' ? " WHERE x.status = ?" : '');

// (a) total keseluruhan
$sql_total = "SELECT COALESCE(SUM(x.total_hutang), 0) AS th, COALESCE(SUM(x.total_bayar), 0) AS tb
              FROM ($sql_agregat) x" . ($f_status !== '' ? " WHERE x.status = ?" : '');
if ($stmt = $koneksi->prepare($sql_total)) {
    if ($f_status !== '') {
        $stmt->bind_param($tipe . 's', ...array_merge($nilai, [$f_status]));
    } elseif ($tipe !== '') {
        $stmt->bind_param($tipe, ...$nilai);
    }
    $stmt->execute();
    $ringkas = $stmt->get_result()->fetch_assoc();
    $total_hutang_semua = (float) ($ringkas['th'] ?? 0);
    $total_bayar_semua  = (float) ($ringkas['tb'] ?? 0);
    $stmt->close();
}

// (b) sisa tagihan per odalan
$sql_h = "SELECT h.id_odalan, SUM(h.jumlah_hutang) AS total
          FROM hutang h
          JOIN ($sql_ids) f ON f.id = h.id_semeton
          GROUP BY h.id_odalan";
if ($stmt = $koneksi->prepare($sql_h)) {
    if ($f_status !== '') {
        $stmt->bind_param($tipe . 's', ...array_merge($nilai, [$f_status]));
    } elseif ($tipe !== '') {
        $stmt->bind_param($tipe, ...$nilai);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $id_o = (int) $r['id_odalan'];
        if (array_key_exists($id_o, $hutang_per_odalan)) {
            $hutang_per_odalan[$id_o] = (float) $r['total'];
        }
    }
    $stmt->close();
}

// (c) pembayaran per odalan
$sql_p = "SELECT p.id_piodalan, SUM(p.jumlah_bayar) AS total
          FROM pembayaran p
          JOIN ($sql_ids) f ON f.id = p.id_semeton
          WHERE p.dibatalkan_at IS NULL
          GROUP BY p.id_piodalan";
if ($stmt = $koneksi->prepare($sql_p)) {
    if ($f_status !== '') {
        $stmt->bind_param($tipe . 's', ...array_merge($nilai, [$f_status]));
    } elseif ($tipe !== '') {
        $stmt->bind_param($tipe, ...$nilai);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $id_o = (int) $r['id_piodalan'];
        if (array_key_exists($id_o, $bayar_per_odalan)) {
            $bayar_per_odalan[$id_o] = (float) $r['total'];
        }
    }
    $stmt->close();
}

// --- URL bantu pagination (mempertahankan filter) ---
$param_dasar = ['menu' => 'rekap_piodalan_sinkron'];
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
$url_reset = 'index.php?menu=rekap_piodalan_sinkron';
$ada_filter = ($f_q !== '' || $f_wilayah > 0 || $f_status !== '');

// Rentang baris yang sedang tampil
$dari   = $jumlah_filter > 0 ? $offset + 1 : 0;
$sampai = $per > 0 ? min($offset + $per, $jumlah_filter) : $jumlah_filter;

// Jumlah kolom tabel (untuk colspan baris kosong)
$jumlah_kolom = 5 + (count($piodalan_list) * 3) + 2;
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-8">
                    <h3 class="mb-0">Rekap Hutang vs Pembayaran</h3>
                    <p class="text-muted small mb-0">
                        Audit &amp; validasi data: sisa tagihan dibandingkan dengan pembayaran tiap odalan.
                    </p>
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
                        <input type="hidden" name="menu" value="rekap_piodalan_sinkron">
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
                            <label class="form-label small text-muted mb-1" for="filter-status">Status tagihan</label>
                            <select id="filter-status" name="status" class="form-select">
                                <option value="">Semua status</option>
                                <option value="Ada" <?= $f_status === 'Ada' ? 'selected' : '' ?>>Masih ada sisa</option>
                                <option value="Lunas" <?= $f_status === 'Lunas' ? 'selected' : '' ?>>Tanpa sisa</option>
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
                    <table id="tabelSinkron" class="table table-bordered table-striped mb-0">
                        <thead class="table-dark text-center">
                            <tr>
                                <th>User ID</th>
                                <th>Nama</th>
                                <th>Wilayah</th>
                                <th>Sub</th>
                                <th>Jenis</th>
                                <?php foreach ($piodalan_list as $nama_o) : ?>
                                    <th>Hutang<br><?= $esc($nama_o) ?></th>
                                    <th>Bayar<br><?= $esc($nama_o) ?></th>
                                    <th>Status</th>
                                <?php endforeach; ?>
                                <th class="table-warning">Total Hutang</th>
                                <th class="table-info">Total Bayar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($jumlah_filter === 0) : ?>
                                <tr>
                                    <td colspan="<?= (int) $jumlah_kolom ?>" class="text-center text-muted py-5">
                                        <?php if ($ada_filter) : ?>
                                            Tidak ada semeton yang cocok dengan filter. <a href="<?= $esc($url_reset) ?>">Reset filter</a>.
                                        <?php else : ?>
                                            Belum ada data semeton.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($data_rows as $row) :
                                // ---- parse rincian hutang per odalan ----
                                $hutang_detail = [];
                                if (!empty($row['detail_hutang'])) {
                                    foreach (explode('|', $row['detail_hutang']) as $item) {
                                        $bagian = explode(':', $item, 2);
                                        if (count($bagian) === 2) {
                                            $hutang_detail[(int) $bagian[0]] = (float) $bagian[1];
                                        }
                                    }
                                }

                                // ---- parse rincian pembayaran per odalan ----
                                $bayar_detail = [];
                                if (!empty($row['detail_bayar'])) {
                                    foreach (explode('|', $row['detail_bayar']) as $item) {
                                        $bagian = explode(':', $item, 2);
                                        if (count($bagian) === 2) {
                                            $bayar_detail[(int) $bagian[0]] = (float) $bagian[1];
                                        }
                                    }
                                }
                            ?>
                                <tr>
                                    <td><?= $esc($row['user_id']) ?></td>
                                    <td><?= $esc($row['nama']) ?></td>
                                    <td><?= $esc($row['nama_wilayah'] ?? '-') ?></td>
                                    <td><?= $esc($row['nama_sub'] ?? '-') ?></td>
                                    <td><?= $esc(ucfirst((string) $row['jenis_user'])) ?></td>
                                    <?php foreach ($piodalan_list as $id_o => $nama_o) :
                                        $hutang = $hutang_detail[$id_o] ?? 0;
                                        $bayar  = $bayar_detail[$id_o] ?? 0;

                                        $cell_class = '';
                                        if ($hutang == 0 && $bayar == 0) {
                                            $status     = "<span class='badge bg-secondary'>Tidak Ada Data</span>";
                                            $cell_class = 'bg-secondary text-white';
                                        } elseif ($hutang == 0) {
                                            if ($bayar > 0) {
                                                $status = "<span class='badge bg-success'>OK</span>";
                                            } else {
                                                $status     = "<span class='badge bg-danger'>Tanpa Pembayaran</span>";
                                                $cell_class = 'bg-danger text-white';
                                            }
                                        } elseif ($bayar == 0) {
                                            $status     = "<span class='badge bg-danger'>Belum Bayar</span>";
                                            $cell_class = 'bg-danger text-white';
                                        } elseif ($bayar == $hutang) {
                                            $status = "<span class='badge bg-success'>Lunas</span>";
                                        } elseif ($bayar < $hutang) {
                                            $status     = "<span class='badge bg-warning text-dark'>Kurang</span>";
                                            $cell_class = 'bg-warning';
                                        } elseif ($bayar > $hutang) {
                                            $status     = "<span class='badge bg-info'>Lebih</span>";
                                            $cell_class = 'bg-info';
                                        } else {
                                            $status = "<span class='badge bg-secondary'>Error</span>";
                                        }
                                    ?>
                                        <td class="text-center"><?= $hutang > 0 ? 'Rp ' . number_format($hutang, 0, ',', '.') : '-' ?></td>
                                        <td class="text-center"><?= $bayar > 0 ? 'Rp ' . number_format($bayar, 0, ',', '.') : '-' ?></td>
                                        <td class="text-center <?= $cell_class ?>"><?= $status ?></td>
                                    <?php endforeach; ?>
                                    <td class="table-warning text-center">
                                        <?= $row['total_hutang'] > 0 ? 'Rp ' . number_format((float) $row['total_hutang'], 0, ',', '.') : '-' ?>
                                    </td>
                                    <td class="table-info text-center">
                                        <?= $row['total_bayar'] > 0 ? 'Rp ' . number_format((float) $row['total_bayar'], 0, ',', '.') : '-' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark text-center">
                            <tr>
                                <th colspan="5" title="Total sesuai filter yang aktif">TOTAL</th>
                                <?php foreach ($piodalan_list as $id_o => $nama_o) : ?>
                                    <th>Rp <?= number_format((float) $hutang_per_odalan[$id_o], 0, ',', '.') ?></th>
                                    <th>Rp <?= number_format((float) $bayar_per_odalan[$id_o], 0, ',', '.') ?></th>
                                    <th></th>
                                <?php endforeach; ?>
                                <th class="table-warning">Rp <?= number_format($total_hutang_semua, 0, ',', '.') ?></th>
                                <th class="table-info">Rp <?= number_format($total_bayar_semua, 0, ',', '.') ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Info & navigasi halaman -->
                <div class="card-footer bg-white border-top px-4 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="text-muted small">
                        Menampilkan <strong><?= (int) $dari ?></strong>&ndash;<strong><?= (int) $sampai ?></strong>
                        dari <strong><?= number_format($jumlah_filter, 0, ',', '.') ?></strong> semeton
                        <?php if ($ada_filter) : ?>
                            <span class="text-secondary">(difilter dari <?= number_format($total_semeton, 0, ',', '.') ?> total)</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($per > 0 && $total_hal > 1) : ?>
                        <nav aria-label="Navigasi halaman rekap hutang vs pembayaran">
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
