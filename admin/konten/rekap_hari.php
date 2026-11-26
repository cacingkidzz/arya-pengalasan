<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('rekap_hari'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// DIPERBARUI (2026-11-11): PEMISAHAN PEMBAYARAN ODALAN vs TUNGGAKAN
//
// Masalah: saat odalan baru berjalan, sebagian warga membayar urunan
// odalan baru, sebagian lain sekaligus melunasi tunggakan odalan lama.
// Sebelumnya kartu "Total Masuk" menjumlahkan SEMUA pembayaran pada
// periode itu tanpa membedakan keduanya.
//
// Dasar pemisahan (tanpa perubahan skema):
//   pembayaran.id_piodalan = odalan yang TAGIHANNYA dilunasi.
//   Dibandingkan dengan "odalan acara" yang dipilih:
//     id_piodalan = acara  -> "Odalan ini"   (pembayaran baru)
//     id_piodalan < acara  -> "Tunggakan"    (odalan sebelumnya)
//     id_piodalan > acara  -> "Bayar di muka"
//
// Catatan: klasifikasi memakai tanggal_bayar (periode kas), bukan
// selisih hari. Halaman audit historis tetap di rekap_tunggakan.php.
//
// Perbaikan lama yang dipertahankan: filter petugas + total di SQL,
// output di-escape, tidak ada modal/JS mati.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$rupiah = static function ($n) {
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
};

// ---------------------------------------------------------------------
// 1. Daftar odalan untuk pemilih "odalan acara" (terbaru di atas)
// ---------------------------------------------------------------------
$odalan_list = [];
if ($res = $koneksi->query('SELECT id, nama_odalan, tanggal FROM odalan ORDER BY tanggal DESC, id DESC')) {
    while ($r = $res->fetch_assoc()) {
        $odalan_list[(int) $r['id']] = $r;
    }
}

$f_odalan = (isset($_GET['id_odalan']) && is_scalar($_GET['id_odalan'])) ? (int) $_GET['id_odalan'] : 0;
if ($f_odalan <= 0 || !isset($odalan_list[$f_odalan])) {
    // Default: odalan terbaru (tanggal terbesar)
    $f_odalan = $odalan_list ? array_key_first($odalan_list) : 0;
}

$nama_acara = $f_odalan > 0 ? (string) $odalan_list[$f_odalan]['nama_odalan'] : '—';

// ---------------------------------------------------------------------
// 2. Periode kas (default: hari ini). Bisa dipakai untuk acara
//    yang pembayarannya berlangsung beberapa hari.
// ---------------------------------------------------------------------
$tanggal_hari_ini = date('Y-m-d');
$baca_tanggal = static function ($nilai) use ($tanggal_hari_ini): string {
    if (!is_scalar($nilai)) {
        return $tanggal_hari_ini;
    }
    $teks = trim((string) $nilai);
    $dt   = DateTime::createFromFormat('Y-m-d', $teks);
    return ($dt instanceof DateTime && $dt->format('Y-m-d') === $teks) ? $teks : $tanggal_hari_ini;
};
$f_dari   = $baca_tanggal($_GET['dari'] ?? $tanggal_hari_ini);
$f_sampai = $baca_tanggal($_GET['sampai'] ?? $tanggal_hari_ini);
if ($f_dari > $f_sampai) {
    [$f_dari, $f_sampai] = [$f_sampai, $f_dari];
}

// ---------------------------------------------------------------------
// 3. Filter petugas (id_user) — divalidasi terhadap data periode
// ---------------------------------------------------------------------
$f_petugas = (isset($_GET['petugas']) && is_scalar($_GET['petugas'])) ? (int) $_GET['petugas'] : 0;

$petugas_list = [];
if ($stmt = $koneksi->prepare('SELECT DISTINCT p.id_user, u.username
                               FROM pembayaran p
                               LEFT JOIN user u ON u.id_user = p.id_user
                               WHERE DATE(p.tanggal_bayar) BETWEEN ? AND ? AND p.dibatalkan_at IS NULL
                               ORDER BY u.username ASC')) {
    $stmt->bind_param('ss', $f_dari, $f_sampai);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $petugas_list[(int) $r['id_user']] = $r['username'] !== null ? $r['username'] : ('User #' . (int) $r['id_user']);
    }
    $stmt->close();
}
if ($f_petugas > 0 && !array_key_exists($f_petugas, $petugas_list)) {
    $f_petugas = 0;
}

// ---------------------------------------------------------------------
// 4. WHERE dinamis (prepared)
// ---------------------------------------------------------------------
$where = ['DATE(p.tanggal_bayar) BETWEEN ? AND ?', 'p.dibatalkan_at IS NULL'];
$tipe  = 'ss';
$nilai = [$f_dari, $f_sampai];
if ($f_petugas > 0) {
    $where[] = 'p.id_user = ?';
    $tipe   .= 'i';
    $nilai[] = $f_petugas;
}
$where_sql = ' WHERE ' . implode(' AND ', $where);

/**
 * Hitung jumlah transaksi + nominal untuk satu kondisi tambahan.
 * @return array{0:int,1:float}
 */
$hitung = static function (string $kondisi, array $param, string $tipe_tambahan) use ($koneksi, $where_sql, $tipe, $nilai): array {
    $sql = 'SELECT COUNT(*) AS jml, COALESCE(SUM(p.jumlah_bayar), 0) AS total
            FROM pembayaran p' . $where_sql;
    if ($kondisi !== '') {
        $sql .= ' AND ' . $kondisi;
    }
    $tipe_gabung  = $tipe . $tipe_tambahan;
    $nilai_gabung = array_merge($nilai, $param);

    if ($stmt = $koneksi->prepare($sql)) {
        $stmt->bind_param($tipe_gabung, ...$nilai_gabung);
        $stmt->execute();
        $baris = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($baris) {
            return [(int) $baris['jml'], (float) $baris['total']];
        }
    }
    return [0, 0.0];
};

[$jml_masuk, $total_masuk] = $hitung('', [], '');

$jml_ini = $jml_tunggakan = $jml_muka = 0;
$total_ini = $total_tunggakan = $total_muka = 0.0;
if ($f_odalan > 0) {
    [$jml_ini, $total_ini]               = $hitung('p.id_piodalan = ?', [$f_odalan], 'i');
    [$jml_tunggakan, $total_tunggakan]   = $hitung('p.id_piodalan < ?', [$f_odalan], 'i');
    [$jml_muka, $total_muka]             = $hitung('p.id_piodalan > ?', [$f_odalan], 'i');
}

// ---------------------------------------------------------------------
// 5. Rincian tunggakan per odalan lama
// ---------------------------------------------------------------------
$rincian_tunggakan = [];
if ($f_odalan > 0) {
    $sql = 'SELECT p.id_piodalan, o.nama_odalan, o.tanggal,
                   COUNT(*) AS jml, COALESCE(SUM(p.jumlah_bayar), 0) AS total
            FROM pembayaran p
            JOIN odalan o ON o.id = p.id_piodalan'
            . $where_sql . ' AND p.id_piodalan < ?
            GROUP BY p.id_piodalan, o.nama_odalan, o.tanggal
            ORDER BY o.tanggal ASC, p.id_piodalan ASC';
    if ($stmt = $koneksi->prepare($sql)) {
        $tipe_gabung  = $tipe . 'i';
        $nilai_gabung = array_merge($nilai, [$f_odalan]);
        $stmt->bind_param($tipe_gabung, ...$nilai_gabung);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rincian_tunggakan[] = $r;
        }
        $stmt->close();
    }
}

// ---------------------------------------------------------------------
// 5b. Total per metode pembayaran (periode + filter yang sama)
//     Satu query GROUP BY, tanpa N+1. Metode di luar enum tetap
//     ditampilkan sebagai baris tambahan (label apa adanya).
// ---------------------------------------------------------------------
$metode_info = [
    'tunai'    => ['Tunai',    'bi-cash-stack', 'success',   'text-white'],
    'transfer' => ['Transfer', 'bi-bank',       'primary',   'text-white'],
    'QRIS'     => ['QRIS',     'bi-qr-code',    'warning',   'text-dark'],
];
$metode_kosong = [
    'jml'             => 0,
    'total'           => 0.0,
    'total_ini'       => 0.0,
    'total_tunggakan' => 0.0,
    'total_muka'      => 0.0,
];
$metode_ringkas = [];
foreach ($metode_info as $kunci => $_) {
    $metode_ringkas[$kunci] = $metode_kosong;
}

$sql_metode   = "SELECT p.metode_pembayaran AS metode,
                        COUNT(*) AS jml,
                        COALESCE(SUM(p.jumlah_bayar), 0) AS total";
$tipe_metode  = '';
$nilai_metode = [];
if ($f_odalan > 0) {
    // Placeholder SELECT muncul lebih dulu daripada placeholder WHERE.
    $sql_metode .= ",
                        COALESCE(SUM(CASE WHEN p.id_piodalan = ? THEN p.jumlah_bayar ELSE 0 END), 0) AS total_ini,
                        COALESCE(SUM(CASE WHEN p.id_piodalan < ? THEN p.jumlah_bayar ELSE 0 END), 0) AS total_tunggakan,
                        COALESCE(SUM(CASE WHEN p.id_piodalan > ? THEN p.jumlah_bayar ELSE 0 END), 0) AS total_muka";
    $tipe_metode  = 'iii';
    $nilai_metode = [$f_odalan, $f_odalan, $f_odalan];
}
$sql_metode  .= ' FROM pembayaran p' . $where_sql . ' GROUP BY p.metode_pembayaran';
$tipe_metode .= $tipe;
$nilai_metode = array_merge($nilai_metode, $nilai);

if ($stmt = $koneksi->prepare($sql_metode)) {
    $stmt->bind_param($tipe_metode, ...$nilai_metode);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $kunci = (string) $r['metode'];
        // Enum menyimpan 'QRIS' kapital; samakan agar tidak terpecah dua baris.
        foreach ($metode_info as $k => $_) {
            if (strcasecmp($k, $kunci) === 0) {
                $kunci = $k;
                break;
            }
        }
        if (!isset($metode_ringkas[$kunci])) {
            $metode_ringkas[$kunci] = $metode_kosong;
        }
        $metode_ringkas[$kunci]['jml']             += (int) $r['jml'];
        $metode_ringkas[$kunci]['total']           += (float) $r['total'];
        $metode_ringkas[$kunci]['total_ini']       += (float) ($r['total_ini'] ?? 0);
        $metode_ringkas[$kunci]['total_tunggakan'] += (float) ($r['total_tunggakan'] ?? 0);
        $metode_ringkas[$kunci]['total_muka']      += (float) ($r['total_muka'] ?? 0);
    }
    $stmt->close();
}

// ---------------------------------------------------------------------
// 6. Daftar pembayaran
// ---------------------------------------------------------------------
$data_rows = [];
$sql = "SELECT p.id_pembayaran, p.id_piodalan, s.user_id, s.nama, o.nama_odalan,
               p.jumlah_bayar, p.metode_pembayaran, p.tanggal_bayar, p.status,
               u.username, w.nama_wilayah, sw.nama_sub
        FROM pembayaran p
        JOIN semeton s ON p.id_semeton = s.id
        JOIN odalan o ON p.id_piodalan = o.id
        LEFT JOIN user u ON p.id_user = u.id_user
        LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
        LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub"
        . $where_sql .
        ' ORDER BY p.tanggal_bayar DESC, p.id_pembayaran DESC';
if ($stmt = $koneksi->prepare($sql)) {
    $stmt->bind_param($tipe, ...$nilai);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $data_rows[] = $r;
    }
    $stmt->close();
}

// ---------------------------------------------------------------------
// 7. URL bantu (mempertahankan filter aktif)
// ---------------------------------------------------------------------
$url_filter = static function (array $ubah = []) use ($f_odalan, $f_dari, $f_sampai, $f_petugas): string {
    $param = [
        'menu'      => 'rekap_hari',
        'id_odalan' => $f_odalan,
        'dari'      => $f_dari,
        'sampai'    => $f_sampai,
    ];
    if ($f_petugas > 0) {
        $param['petugas'] = $f_petugas;
    }
    $param = array_merge($param, $ubah);
    return 'index.php?' . http_build_query($param);
};
$url_petugas = static function (int $id) use ($url_filter): string {
    return $id > 0 ? $url_filter(['petugas' => $id]) : $url_filter(['petugas' => null]);
};

$judul_periode = ($f_dari === $f_sampai)
    ? date('d-m-Y', strtotime($f_dari))
    : date('d-m-Y', strtotime($f_dari)) . ' s.d. ' . date('d-m-Y', strtotime($f_sampai));

// Ubah metode pembayaran dari halaman ini hanya untuk role yang sama
// dengan endpoint edit_pembayaran.php (admin + bendahara).
$boleh_ubah_metode = in_array(kbap_role(), ['admin', 'bendahara'], true);
?>
<main class="app-main">
    <div class="app-content-header py-3">
        <div class="container-fluid">
            <div class="row align-items-center g-3">
                <div class="col-lg-7">
                    <h3 class="mb-1 text-primary fw-bold">
                        <i class="bi bi-receipt-cutoff"></i> Rekap Bayar &amp; Tunggakan
                        <small class="text-muted fs-6">(<?= $esc($judul_periode) ?>)</small>
                    </h3>
                    <p class="text-muted small mb-0">
                        <span class="badge bg-primary"><?= number_format($jml_masuk, 0, ',', '.') ?> transaksi</span>
                        <span class="badge bg-success">acara: <?= $esc($nama_acara) ?></span>
                        <?php if ($f_petugas > 0) : ?>
                            <span class="badge bg-info text-dark">petugas: <?= $esc($petugas_list[$f_petugas]) ?></span>
                            <a href="<?= $esc($url_petugas(0)) ?>" class="small ms-1">reset petugas</a>
                        <?php endif; ?>
                    </p>
                    <?php if (in_array(kbap_role(), ['admin', 'bendahara'], true)) : ?>
                        <a class="btn btn-sm btn-outline-success"
                           href="aksi/export_csv.php?jenis=rekap_hari&amp;id_odalan=<?= (int) $f_odalan ?>&amp;dari=<?= $esc($f_dari) ?>&amp;sampai=<?= $esc($f_sampai) ?><?= $f_petugas > 0 ? '&amp;petugas=' . (int) $f_petugas : '' ?>&amp;csrf_token=<?= $esc(kbap_csrf_token()) ?>">
                            <i class="bi bi-filetype-csv"></i> Unduh CSV
                        </a>
                        <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
                           href="konten/laporan_rekap_odalan.php?id_odalan=<?= (int) $f_odalan ?>&amp;dari=<?= $esc($f_dari) ?>&amp;sampai=<?= $esc($f_sampai) ?><?= $f_petugas > 0 ? '&amp;petugas=' . (int) $f_petugas : '' ?>">
                            <i class="bi bi-printer"></i> Cetak Laporan
                        </a>
                    <?php endif; ?>
                </div>
                <div class="col-lg-5">
                    <div class="card shadow-sm border-primary mb-0">
                        <div class="card-body p-2">
                            <form method="GET" action="index.php" class="row g-2 align-items-end">
                                <input type="hidden" name="menu" value="rekap_hari">
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1" for="filterOdalan">
                                        <i class="bi bi-calendar-event"></i> Odalan acara
                                    </label>
                                    <select id="filterOdalan" name="id_odalan" class="form-select form-select-sm">
                                        <?php foreach ($odalan_list as $id_o => $o) : ?>
                                            <option value="<?= (int) $id_o ?>" <?= $f_odalan === (int) $id_o ? 'selected' : '' ?>>
                                                <?= $esc($o['nama_odalan']) ?> (<?= $esc(date('d-m-Y', strtotime((string) $o['tanggal']))) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-semibold mb-1" for="filterDari">Dari</label>
                                    <input type="date" id="filterDari" name="dari" class="form-control form-control-sm" value="<?= $esc($f_dari) ?>">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-semibold mb-1" for="filterSampai">Sampai</label>
                                    <input type="date" id="filterSampai" name="sampai" class="form-control form-control-sm" value="<?= $esc($f_sampai) ?>">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-semibold mb-1" for="filterPetugas">Petugas</label>
                                    <select id="filterPetugas" name="petugas" class="form-select form-select-sm">
                                        <option value="0" <?= $f_petugas === 0 ? 'selected' : '' ?>>Semua</option>
                                        <?php foreach ($petugas_list as $id_p => $nama_p) : ?>
                                            <option value="<?= (int) $id_p ?>" <?= $f_petugas === (int) $id_p ? 'selected' : '' ?>>
                                                <?= $esc($nama_p) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-3 d-flex gap-1">
                                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                                        <i class="bi bi-funnel"></i> Terapkan
                                    </button>
                                    <a href="index.php?menu=rekap_hari" class="btn btn-light btn-sm" title="Reset filter">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <!-- Kartu pemisahan: total masuk dipecah per jenis -->
            <div class="row g-3 mb-4">
                <div class="col-md-6 col-xl-3">
                    <div class="card border-0 bg-primary text-white shadow h-100">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-uppercase mb-1 small opacity-75">Total Masuk</h6>
                                    <h3 class="mb-0 fw-bold"><?= $rupiah($total_masuk) ?></h3>
                                    <small class="opacity-75"><?= number_format($jml_masuk, 0, ',', '.') ?> transaksi</small>
                                </div>
                                <div class="fs-1 opacity-25"><i class="bi bi-wallet2"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="card border-0 bg-success text-white shadow h-100">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-uppercase mb-1 small opacity-75">Odalan Ini</h6>
                                    <h3 class="mb-0 fw-bold"><?= $rupiah($total_ini) ?></h3>
                                    <small class="opacity-75"><?= number_format($jml_ini, 0, ',', '.') ?> transaksi</small>
                                </div>
                                <div class="fs-1 opacity-25"><i class="bi bi-check2-circle"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="card border-0 bg-danger text-white shadow h-100">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-uppercase mb-1 small opacity-75">Tunggakan</h6>
                                    <h3 class="mb-0 fw-bold"><?= $rupiah($total_tunggakan) ?></h3>
                                    <small class="opacity-75"><?= number_format($jml_tunggakan, 0, ',', '.') ?> transaksi (odalan lama)</small>
                                </div>
                                <div class="fs-1 opacity-25"><i class="bi bi-exclamation-triangle"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="card border-0 bg-info text-dark shadow h-100">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-uppercase mb-1 small opacity-75">Bayar di Muka</h6>
                                    <h3 class="mb-0 fw-bold"><?= $rupiah($total_muka) ?></h3>
                                    <small class="opacity-75"><?= number_format($jml_muka, 0, ',', '.') ?> transaksi (odalan setelahnya)</small>
                                </div>
                                <div class="fs-1 opacity-25"><i class="bi bi-fast-forward"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total per metode pembayaran (mengikuti filter aktif) -->
            <?php if ($jml_masuk > 0) : ?>
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white py-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold text-primary">
                            <i class="bi bi-credit-card-2-front"></i> Total per Metode Pembayaran
                        </h6>
                        <span class="badge bg-dark">
                            <?= $rupiah($total_masuk) ?> &middot; <?= number_format($jml_masuk, 0, ',', '.') ?> transaksi
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0" id="tabelMetode">
                                <thead class="table-light">
                                    <tr>
                                        <th>Metode</th>
                                        <th class="text-end">Transaksi</th>
                                        <th class="text-end">Nominal</th>
                                        <th class="text-end">Porsi</th>
                                        <th class="text-end">Odalan Ini</th>
                                        <th class="text-end">Tunggakan</th>
                                        <th class="text-end">Di Muka</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($metode_ringkas as $kunci => $m) :
                                        [$label_m, $ikon_m, $warna_m, $teks_m] = $metode_info[$kunci] ?? [ucfirst((string) $kunci), 'bi-three-dots', 'secondary', 'text-white'];
                                        $porsi_m = $total_masuk > 0 ? ((float) $m['total'] / $total_masuk) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-<?= $esc($warna_m) ?> <?= $esc($teks_m) ?>">
                                                    <i class="bi <?= $esc($ikon_m) ?> me-1"></i><?= $esc($label_m) ?>
                                                </span>
                                            </td>
                                            <td class="text-end"><?= number_format((int) $m['jml'], 0, ',', '.') ?></td>
                                            <td class="text-end fw-bold"><?= $rupiah($m['total']) ?></td>
                                            <td class="text-end" style="min-width:130px;">
                                                <div class="progress" style="height:8px;">
                                                    <div class="progress-bar bg-<?= $esc($warna_m) ?>" style="width:<?= number_format($porsi_m, 1, '.', '') ?>%"></div>
                                                </div>
                                                <small class="text-muted"><?= number_format($porsi_m, 1, ',', '.') ?>%</small>
                                            </td>
                                            <td class="text-end text-success"><?= $m['total_ini'] > 0 ? $rupiah($m['total_ini']) : '&mdash;' ?></td>
                                            <td class="text-end text-danger"><?= $m['total_tunggakan'] > 0 ? $rupiah($m['total_tunggakan']) : '&mdash;' ?></td>
                                            <td class="text-end text-primary"><?= $m['total_muka'] > 0 ? $rupiah($m['total_muka']) : '&mdash;' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th>TOTAL</th>
                                        <th class="text-end"><?= number_format($jml_masuk, 0, ',', '.') ?></th>
                                        <th class="text-end"><?= $rupiah($total_masuk) ?></th>
                                        <th class="text-end">100%</th>
                                        <th class="text-end"><?= $rupiah($total_ini) ?></th>
                                        <th class="text-end"><?= $rupiah($total_tunggakan) ?></th>
                                        <th class="text-end"><?= $rupiah($total_muka) ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Rincian tunggakan per odalan lama -->
            <?php if (!empty($rincian_tunggakan)) : ?>
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="mb-0 fw-bold text-danger">
                            <i class="bi bi-list-check"></i> Rincian Tunggakan per Odalan
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0" id="tabelTunggakan">
                                <thead class="table-light">
                                    <tr>
                                        <th>Odalan yang Dibayar</th>
                                        <th>Tanggal Odalan</th>
                                        <th class="text-end">Transaksi</th>
                                        <th class="text-end">Nominal</th>
                                        <th class="text-end">Porsi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rincian_tunggakan as $rt) :
                                        $porsi = $total_tunggakan > 0 ? ((float) $rt['total'] / $total_tunggakan) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td class="fw-semibold"><?= $esc($rt['nama_odalan']) ?></td>
                                            <td class="small text-muted"><?= $esc(date('d-m-Y', strtotime((string) $rt['tanggal']))) ?></td>
                                            <td class="text-end"><?= number_format((int) $rt['jml'], 0, ',', '.') ?></td>
                                            <td class="text-end fw-bold"><?= $rupiah($rt['total']) ?></td>
                                            <td class="text-end" style="min-width:120px;">
                                                <div class="progress" style="height:8px;">
                                                    <div class="progress-bar bg-danger" style="width:<?= number_format($porsi, 1, '.', '') ?>%"></div>
                                                </div>
                                                <small class="text-muted"><?= number_format($porsi, 1, ',', '.') ?>%</small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="2">TOTAL TUNGGAKAN</th>
                                        <th class="text-end"><?= number_format($jml_tunggakan, 0, ',', '.') ?></th>
                                        <th class="text-end text-danger"><?= $rupiah($total_tunggakan) ?></th>
                                        <th class="text-end">100%</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <?php if (empty($data_rows)) : ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Belum ada pembayaran tercatat pada periode ini<?= $f_petugas > 0 ? ' untuk petugas ini' : '' ?>.
                        </div>
                    <?php else : ?>
                    <?php if ($boleh_ubah_metode) : ?>
                        <!--
                            Form ubah metode massal. Form sengaja TIDAK membungkus tabel
                            agar tombol DataTables (Excel/PDF) tidak ikut menjadi submit.
                            Checkbox di tabel terhubung lewat atribut form="formMetode".
                        -->
                        <form id="formMetode" method="POST" action="aksi/aksi_metode_bayar.php"
                              class="d-flex flex-wrap gap-2 align-items-center bg-light border rounded-2 px-3 py-2 mb-3">
                            <?= kbap_csrf_field() ?>
                            <input type="hidden" name="id_odalan" value="<?= (int) $f_odalan ?>">
                            <input type="hidden" name="dari" value="<?= $esc($f_dari) ?>">
                            <input type="hidden" name="sampai" value="<?= $esc($f_sampai) ?>">
                            <input type="hidden" name="petugas" value="<?= (int) $f_petugas ?>">

                            <span class="small text-muted me-auto">
                                <i class="bi bi-check2-square me-1"></i>
                                Centang transaksi yang metodenya salah, lalu pilih metode baru dan klik <strong>Ubah Metode</strong>.
                            </span>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnPilihSemua">
                                <i class="bi bi-check-all"></i> Pilih semua
                            </button>
                            <select name="metode_baru" id="metodeBaru" class="form-select form-select-sm" style="max-width:170px;">
                                <option value="">-- pilih metode --</option>
                                <option value="tunai">Tunai</option>
                                <option value="transfer">Transfer</option>
                                <option value="QRIS">QRIS</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-warning" id="btnUbahMetode" disabled>
                                <i class="bi bi-arrow-repeat"></i> Ubah Metode
                                <span class="badge bg-dark ms-1" id="jmlTerpilih">0</span>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnResetPilih">
                                <i class="bi bi-x-circle"></i> Reset
                            </button>
                        </form>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table id="example" class="table table-hover align-middle" style="width:100%">
                            <thead class="table-dark">
                                <tr>
                                    <?php if ($boleh_ubah_metode) : ?>
                                        <th class="text-center" style="width:38px;" data-orderable="false" data-priority="1" title="Pilih transaksi">&nbsp;</th>
                                    <?php endif; ?>
                                    <th>ID</th>
                                    <th>ID Semeton</th>
                                    <th>Nama</th>
                                    <th>Wilayah/Sub</th>
                                    <th>Piodalan Dibayar</th>
                                    <th>Jenis</th>
                                    <th>Jumlah Bayar</th>
                                    <th>Metode</th>
                                    <th>Waktu</th>
                                    <th>Petugas</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data_rows as $row) :
                                    $metode = strtolower((string) $row['metode_pembayaran']);
                                    $icon   = ($metode === 'transfer') ? 'bi-bank' : (($metode === 'qris') ? 'bi-qr-code' : 'bi-cash-stack');
                                    $jam    = strtotime((string) $row['tanggal_bayar']);
                                    $id_od  = (int) $row['id_piodalan'];

                                    if ($f_odalan > 0 && $id_od === $f_odalan) {
                                        $jenis_teks = 'Odalan ini';
                                        $jenis_kelas = 'bg-success';
                                    } elseif ($f_odalan > 0 && $id_od < $f_odalan) {
                                        $jenis_teks = 'Tunggakan';
                                        $jenis_kelas = 'bg-danger';
                                    } elseif ($f_odalan > 0 && $id_od > $f_odalan) {
                                        $jenis_teks = 'Di muka';
                                        $jenis_kelas = 'bg-info text-dark';
                                    } else {
                                        $jenis_teks = '-';
                                        $jenis_kelas = 'bg-secondary';
                                    }
                                    ?>
                                    <tr>
                                        <?php if ($boleh_ubah_metode) : ?>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input cek-bayar"
                                                       form="formMetode" name="id_pembayaran[]"
                                                       value="<?= (int) $row['id_pembayaran'] ?>"
                                                       aria-label="Pilih pembayaran #<?= (int) $row['id_pembayaran'] ?>">
                                            </td>
                                        <?php endif; ?>
                                        <td class="text-muted small">#<?= (int) $row['id_pembayaran'] ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= $esc($row['user_id']) ?></span></td>
                                        <td class="fw-bold text-dark"><?= $esc($row['nama']) ?></td>
                                        <td class="small text-muted">
                                            <?= $esc($row['nama_wilayah'] !== null ? $row['nama_wilayah'] : '-') ?> /<br>
                                            <span class="fw-light"><?= $esc($row['nama_sub'] !== null ? $row['nama_sub'] : '-') ?></span>
                                        </td>
                                        <td><?= $esc($row['nama_odalan']) ?></td>
                                        <td><span class="badge <?= $jenis_kelas ?>"><?= $esc($jenis_teks) ?></span></td>
                                        <td class="text-end fw-bold"><?= $rupiah($row['jumlah_bayar']) ?></td>
                                        <td>
                                            <span class="small"><i class="bi <?= $icon ?> me-1"></i> <?= $esc(ucfirst($metode)) ?></span>
                                        </td>
                                        <td class="small text-muted text-center"><?= $jam ? date('H:i', $jam) : '-' ?></td>
                                        <td class="fw-semibold text-primary"><?= $esc($row['username'] !== null ? $row['username'] : '-') ?></td>
                                        <td>
                                            <?php if ($row['status'] === 'lunas') : ?>
                                                <span class="badge rounded-pill bg-success px-3">Lunas</span>
                                            <?php else : ?>
                                                <span class="badge rounded-pill bg-warning text-dark px-3">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($boleh_ubah_metode && !empty($data_rows)) : ?>
            <script>
                (function () {
                    var form = document.getElementById('formMetode');
                    if (!form) { return; }

                    var btnSemua = document.getElementById('btnPilihSemua');
                    var btnUbah  = document.getElementById('btnUbahMetode');
                    var btnReset = document.getElementById('btnResetPilih');
                    var metode   = document.getElementById('metodeBaru');
                    var label    = document.getElementById('jmlTerpilih');

                    // Hanya checkbox pada baris data (bukan salinan "child row" DataTables Responsive).
                    function daftar() {
                        return Array.prototype.slice
                            .call(document.querySelectorAll('input.cek-bayar'))
                            .filter(function (c) {
                                var tr = c.closest('tr');
                                return tr && !tr.classList.contains('child');
                            });
                    }

                    function segarkan() {
                        var semua = daftar();
                        var n = semua.filter(function (c) { return c.checked; }).length;
                        if (label) { label.textContent = n; }
                        if (btnUbah) { btnUbah.disabled = (n === 0 || !metode || metode.value === ''); }
                        if (btnSemua) {
                            btnSemua.disabled = semua.length === 0;
                            btnSemua.innerHTML = (n > 0 && n === semua.length)
                                ? '<i class="bi bi-x-square"></i> Batal pilih'
                                : '<i class="bi bi-check-all"></i> Pilih semua';
                        }
                    }

                    // Delegasi: aman walau DataTables memindahkan/menyusun ulang baris.
                    document.addEventListener('change', function (e) {
                        var t = e.target;
                        if (!t) { return; }
                        if (t.classList && t.classList.contains('cek-bayar')) { segarkan(); }
                        if (t === metode) { segarkan(); }
                    });

                    if (btnSemua) {
                        btnSemua.addEventListener('click', function () {
                            var semua = daftar();
                            var semuaTerpilih = semua.length > 0 && semua.every(function (c) { return c.checked; });
                            semua.forEach(function (c) { c.checked = !semuaTerpilih; });
                            segarkan();
                        });
                    }

                    if (btnReset) {
                        btnReset.addEventListener('click', function () {
                            daftar().forEach(function (c) { c.checked = false; });
                            if (metode) { metode.value = ''; }
                            segarkan();
                        });
                    }

                    form.addEventListener('submit', function (e) {
                        var n = daftar().filter(function (c) { return c.checked; }).length;
                        if (n === 0 || !metode || metode.value === '' || btnUbah.dataset.proses === '1') {
                            e.preventDefault();
                            return;
                        }
                        btnUbah.dataset.proses = '1';
                        btnUbah.disabled = true;
                        btnUbah.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menyimpan...';
                    });

                    // DataTables menyembunyikan baris saat pencarian; hitungan ikut menyesuaikan.
                    if (window.jQuery) {
                        window.jQuery(document).on('draw.dt search.dt', function () {
                            window.setTimeout(segarkan, 0);
                        });
                    }

                    // Tombol kembali aktif lagi bila halaman dipulihkan dari cache (tombol Back).
                    window.addEventListener('pageshow', function () {
                        if (btnUbah) {
                            btnUbah.dataset.proses = '';
                            segarkan();
                        }
                    });

                    segarkan();
                })();
            </script>
            <?php endif; ?>

            <p class="text-muted small mt-3 mb-0">
                <i class="bi bi-info-circle"></i>
                <strong>Odalan ini</strong> = pembayaran untuk tagihan odalan yang dipilih.
                <strong>Tunggakan</strong> = pembayaran tagihan odalan yang lebih lama (dibayar pada periode ini).
                <strong>Bayar di muka</strong> = pembayaran tagihan odalan yang lebih baru.
                Klasifikasi memakai <em>tanggal bayar</em>, bukan tanggal odalan.
            </p>
        </div>
    </div>
</main>
