<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('rekap_punia_filter'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// DIPERKERAS (2026-11-10)
// Sebelumnya:
//  - `id_piodalan` dari URL disisipkan MENTAH ke 2 query -> SQL injection
//    (uji: `id_piodalan=1' OR '1'='1` menampilkan 397 baris, bukan 235).
//  - input array memicu "Array to string conversion".
//  - nama donatur/alamat/keterangan/nama odalan dirender mentah (XSS).
//  - nama odalan masuk ke nama file JS tanpa escape.
//  - tombol "Download Excel" memuat library XLSX dari internet (gagal offline).
// Sekarang: id divalidasi terhadap daftar odalan, total dihitung di SQL,
// seluruh output di-escape, dan ekspor memakai CSV resmi aplikasi.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$rupiah = static function ($n) {
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
};
$tanggal_rapi = static function ($t, string $format = 'd M Y') {
    if ($t === null || $t === '') {
        return '-';
    }
    $ts = strtotime((string) $t);
    return $ts ? date($format, $ts) : '-';
};

// 1. Master odalan (sekali query)
$odalan_list = [];
if ($q = $koneksi->query('SELECT id, nama_odalan FROM odalan ORDER BY tanggal DESC, id DESC')) {
    while ($r = $q->fetch_assoc()) {
        $odalan_list[(int) $r['id']] = $r['nama_odalan'];
    }
    $q->free();
}

// 2. Odalan terpilih — divalidasi
$f_odalan = (isset($_GET['id_piodalan']) && is_scalar($_GET['id_piodalan'])) ? (int) $_GET['id_piodalan'] : 0;
if (!array_key_exists($f_odalan, $odalan_list)) {
    $kunci = array_keys($odalan_list);
    $f_odalan = $kunci ? (int) $kunci[0] : 0;
}
$judul_odalan = $f_odalan > 0 ? $odalan_list[$f_odalan] : 'Piodalan Tidak Ditemukan';

// 3. Data + total di SQL
$data_rows = [];
$total_akhir = 0.0;
if ($f_odalan > 0) {
    if ($stmt = $koneksi->prepare('SELECT id_punia, nama_donatur, alamat, jumlah_punia, keterangan, metode_pembayaran, tanggal_punia
                                   FROM dana_punia WHERE id_piodalan = ?
                                   ORDER BY tanggal_punia DESC, id_punia DESC')) {
        $stmt->bind_param('i', $f_odalan);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $data_rows[] = $r;
            $total_akhir += (float) $r['jumlah_punia'];
        }
        $stmt->close();
    }
}

// 3b. Total per metode pembayaran (2026-11-28) — satu query agregat,
// dihitung di SQL agar angkanya konsisten dengan total keseluruhan.
$metode_rows   = [];
$total_donasi  = count($data_rows);
if ($f_odalan > 0) {
    if ($stmt = $koneksi->prepare('SELECT metode_pembayaran, COUNT(*) AS jml, SUM(jumlah_punia) AS total
                                   FROM dana_punia WHERE id_piodalan = ?
                                   GROUP BY metode_pembayaran
                                   ORDER BY total DESC')) {
        $stmt->bind_param('i', $f_odalan);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $metode_rows[] = $r;
        }
        $stmt->close();
    }
}
// Warna badge metode (sama dengan badge di tabel rincian).
$warna_metode = static function (string $m): string {
    $m = strtolower($m);
    return $m === 'tunai' ? 'warning' : ($m === 'qris' ? 'primary' : 'info text-white');
};

$boleh_ekspor = in_array(kbap_role(), ['admin', 'bendahara'], true);
$url_csv = 'aksi/export_csv.php?' . http_build_query([
    'jenis'       => 'punia',
    'id_piodalan' => $f_odalan,
    'csrf_token'  => kbap_csrf_token(),
]);
?>

<main class="app-main">
    <div class="app-content-header py-4 bg-white border-bottom mb-4">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-7">
                    <h3 class="mb-0 text-success fw-bold">
                        <i class="bi bi-journal-check me-2"></i> Rekap Dana Punia
                    </h3>
                    <p class="text-muted small mb-0">
                        Laporan kontribusi sukarela semeton pada <strong><?= $esc($judul_odalan) ?></strong>
                        <span class="badge bg-success ms-1"><?= $rupiah($total_akhir) ?></span>
                        <span class="badge bg-secondary"><?= number_format(count($data_rows), 0, ',', '.') ?> donasi</span>
                    </p>
                </div>
                <div class="col-md-5 text-md-end mt-3 mt-md-0">
                    <div class="btn-group">
                        <a href="cetak_punia.php?id=<?= (int) $f_odalan ?>" target="_blank" rel="noopener" class="btn btn-outline-danger">
                            <i class="bi bi-printer-fill me-2"></i> Cetak Laporan
                        </a>
                        <?php if ($boleh_ekspor) : ?>
                            <a href="<?= $esc($url_csv) ?>" class="btn btn-success ms-2">
                                <i class="bi bi-filetype-csv me-2"></i> Unduh CSV
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                        <div class="card-body">
                            <label class="form-label fw-bold text-success mb-2" for="filter-odalan">Ganti Periode Piodalan:</label>
                            <form method="GET">
                                <input type="hidden" name="menu" value="rekap_punia_filter">
                                <select id="filter-odalan" name="id_piodalan" class="form-select shadow-none border-success" onchange="this.form.submit()">
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

                <div class="col-md-8">
                    <div class="card bg-success text-white shadow h-100">
                        <div class="card-body d-flex align-items-center justify-content-between px-4">
                            <div>
                                <p class="mb-0 text-uppercase small opacity-75">Total Dana Terkumpul</p>
                                <h1 class="display-5 fw-bold mb-0"><?= $rupiah($total_akhir) ?></h1>
                            </div>
                            <div class="display-1 opacity-25"><i class="bi bi-cash-stack"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($metode_rows)) : ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-3 pb-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h6 class="mb-0 fw-bold text-dark">
                            <i class="bi bi-wallet2 me-1 text-success"></i> Total per Metode Pembayaran
                        </h6>
                        <span class="badge bg-light text-dark border">
                            <?= number_format(count($metode_rows), 0, ',', '.') ?> metode &middot;
                            <?= number_format($total_donasi, 0, ',', '.') ?> donasi
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table id="tabelMetodePunia" class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4" style="width: 160px;">Metode</th>
                                    <th class="text-center" style="width: 140px;">Jumlah Donasi</th>
                                    <th class="text-end" style="width: 200px;">Nominal</th>
                                    <th class="pe-4">Porsi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($metode_rows as $mr) : ?>
                                    <?php
                                    $m_nom   = (float) $mr['total'];
                                    $m_pct   = $total_akhir > 0 ? ($m_nom / $total_akhir * 100) : 0;
                                    $m_label = (string) $mr['metode_pembayaran'];
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="badge bg-<?= $warna_metode($m_label) ?> rounded-pill text-uppercase" style="font-size: 0.7rem;">
                                                <?= $esc($m_label) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="fw-semibold"><?= number_format((int) $mr['jml'], 0, ',', '.') ?></span>
                                        </td>
                                        <td class="text-end fw-bold text-success"><?= $rupiah($m_nom) ?></td>
                                        <td class="pe-4">
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1" style="height: 8px;">
                                                    <div class="progress-bar bg-success" role="progressbar"
                                                        style="width: <?= number_format($m_pct, 1, '.', '') ?>%;"
                                                        aria-valuenow="<?= number_format($m_pct, 1, '.', '') ?>"
                                                        aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <span class="small text-muted" style="min-width: 52px; text-align: right;">
                                                    <?= number_format($m_pct, 1, ',', '.') ?>%
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td class="ps-4">TOTAL</td>
                                    <td class="text-center"><?= number_format($total_donasi, 0, ',', '.') ?></td>
                                    <td class="text-end"><?= $rupiah($total_akhir) ?></td>
                                    <td class="pe-4">100%</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table id="tabelPunia" class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="ps-4" style="width: 60px;">No</th>
                                <th>Identitas Donatur</th>
                                <th>Keterangan Donasi</th>
                                <th class="text-center" style="width: 130px;">Metode</th>
                                <th class="text-end pe-4">Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($data_rows)) : ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-5">
                                        <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                                        Belum ada data dana punia untuk piodalan ini.
                                    </td>
                                </tr>
                            <?php else : ?>
                                <?php $i = 1; ?>
                                <?php foreach ($data_rows as $row) : ?>
                                    <tr>
                                        <td class="ps-4 text-muted"><?= $i++ ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= $esc($row['nama_donatur']) ?></div>
                                            <div class="small text-muted">
                                                <i class="bi bi-geo-alt me-1"></i><?= $esc($row['alamat'] !== null && trim((string) $row['alamat']) !== '' ? $row['alamat'] : '-') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold text-primary"><?= $esc($tanggal_rapi($row['tanggal_punia'])) ?></div>
                                            <div class="small text-muted fst-italic"><?= $esc($row['keterangan'] !== null && trim((string) $row['keterangan']) !== '' ? $row['keterangan'] : '-') ?></div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $warna_metode((string) $row['metode_pembayaran']) ?> rounded-pill text-uppercase" style="font-size: 0.65rem;">
                                                <?= $esc($row['metode_pembayaran']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <span class="fw-bold text-success fs-5"><?= $rupiah($row['jumlah_punia']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <th colspan="4" class="text-end">Total</th>
                                <th class="text-end pe-4"><?= $rupiah($total_akhir) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>
