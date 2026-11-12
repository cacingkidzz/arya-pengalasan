<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('rekap_filter'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// DIPERKERAS + AKURAT (2026-11-10)
// Sebelumnya:
//  - `id_piodalan` dari URL disisipkan MENTAH ke 4 query -> SQL injection
//    (uji: `id_piodalan=1' OR '1'='1` menampilkan 1.808 baris, bukan 604).
//  - input array (`id_piodalan[]=3`) memicu warning + fatal error.
//  - `MAX(metode_pembayaran)` memilih enum TERBESAR (QRIS > transfer > tunai),
//    bukan pembayaran TERAKHIR; `MAX(username)` tidak berhubungan dengan
//    pembayaran terakhir.
//  - INNER JOIN wilayah/sub wilayah menyembunyikan semeton tanpa wilayah.
//  - nama/nama wilayah/petugas dirender mentah (XSS).
// Sekarang: id divalidasi terhadap daftar odalan, semua query prepared,
// metode terakhir diambil dari pembayaran dengan tanggal terbaru,
// wilayah memakai LEFT JOIN, dan seluruh output di-escape.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$rupiah = static function ($n) {
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
};
$tanggal_rapi = static function ($t, string $format = 'd-m-Y H:i') {
    if ($t === null || $t === '' || $t === '-') {
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

// 2. Filter (divalidasi, bukan disisipkan ke SQL)
$f_odalan = (isset($_GET['id_piodalan']) && is_scalar($_GET['id_piodalan'])) ? (int) $_GET['id_piodalan'] : 0;
if (!array_key_exists($f_odalan, $odalan_list)) {
    $f_odalan = 0; // halaman meminta user memilih piodalan
}
$f_metode = (isset($_GET['filter_metode']) && is_scalar($_GET['filter_metode'])) ? (string) $_GET['filter_metode'] : 'Semua';
if (!in_array($f_metode, ['Semua', 'tunai', 'transfer', 'QRIS'], true)) {
    $f_metode = 'Semua';
}
$nama_piodalan = $f_odalan > 0 ? $odalan_list[$f_odalan] : '';

// 3. Total pembayaran (sesuai filter metode) — dihitung di SQL
$total_pembayaran = 0.0;
if ($f_odalan > 0) {
    $sql_total = 'SELECT COALESCE(SUM(jumlah_bayar), 0) AS total FROM pembayaran
                  WHERE id_piodalan = ? AND dibatalkan_at IS NULL';
    $tipe_total  = 'i';
    $nilai_total = [$f_odalan];
    if ($f_metode !== 'Semua') {
        $sql_total   .= ' AND metode_pembayaran = ?';
        $tipe_total  .= 's';
        $nilai_total[] = $f_metode;
    }
    if ($stmt = $koneksi->prepare($sql_total)) {
        $stmt->bind_param($tipe_total, ...$nilai_total);
        $stmt->execute();
        $total_pembayaran = (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }
}

// 4. Data hutang + pembayaran terakhir per semeton
$data_rows = [];
if ($f_odalan > 0) {
    // Subquery: tepat satu baris per semeton = pembayaran TERAKHIR (tanggal terbaru).
    $sql_pay = "SELECT p1.id_semeton, p1.tanggal_bayar, p1.metode_pembayaran, u.username
                FROM pembayaran p1
                LEFT JOIN user u ON u.id_user = p1.id_user
                WHERE p1.id_piodalan = ? AND p1.dibatalkan_at IS NULL";
    $tipe_pay  = 'i';
    $nilai_pay = [$f_odalan];
    if ($f_metode !== 'Semua') {
        $sql_pay    .= ' AND p1.metode_pembayaran = ?';
        $tipe_pay   .= 's';
        $nilai_pay[] = $f_metode;
    }
    $sql_pay .= " AND p1.id_pembayaran = (
                    SELECT p2.id_pembayaran FROM pembayaran p2
                    WHERE p2.id_semeton = p1.id_semeton
                      AND p2.id_piodalan = p1.id_piodalan
                      AND p2.dibatalkan_at IS NULL";
    if ($f_metode !== 'Semua') {
        $sql_pay    .= ' AND p2.metode_pembayaran = ?';
        $tipe_pay   .= 's';
        $nilai_pay[] = $f_metode;
    }
    $sql_pay .= ' ORDER BY p2.tanggal_bayar DESC, p2.id_pembayaran DESC LIMIT 1)';

    $sql = "SELECT s.user_id, s.nama, w.nama_wilayah, sw.nama_sub, s.jenis_user, h.jumlah_hutang,
                   COALESCE(p.tanggal_bayar, '-') AS tanggal_bayar,
                   COALESCE(p.metode_pembayaran, '-') AS metode_pembayaran,
                   COALESCE(p.username, '-') AS username,
                   CASE WHEN h.jumlah_hutang = 0 THEN 'Lunas' ELSE 'Belum Lunas' END AS status_hutang
            FROM hutang h
            JOIN semeton s ON h.id_semeton = s.id
            LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
            LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
            LEFT JOIN ($sql_pay) p ON p.id_semeton = h.id_semeton
            WHERE h.id_odalan = ?";
    $tipe  = $tipe_pay . 'i';
    $nilai = array_merge($nilai_pay, [$f_odalan]);
    if ($f_metode !== 'Semua') {
        $sql .= ' AND p.id_semeton IS NOT NULL'; // hanya yang punya pembayaran dengan metode itu
    }
    $sql .= ' ORDER BY s.user_id ASC';

    if ($stmt = $koneksi->prepare($sql)) {
        $stmt->bind_param($tipe, ...$nilai);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $data_rows[] = $r;
        }
        $stmt->close();
    }
}

$jumlah_baris = count($data_rows);
$jumlah_lunas = 0;
foreach ($data_rows as $r) {
    if ((float) $r['jumlah_hutang'] == 0.0) {
        $jumlah_lunas++;
    }
}

$url_metode = static function (string $metode) use ($f_odalan): string {
    return 'index.php?' . http_build_query([
        'menu'          => 'rekap_filter',
        'id_piodalan'   => $f_odalan,
        'filter_metode' => $metode,
    ]);
};
$cetak_link = 'konten/cetak_bayar.php?id_odalan=' . $f_odalan
    . ($f_metode !== 'Semua' ? '&filter_metode=' . rawurlencode($f_metode) : '');
// cetak_bayar.php hanya untuk admin & bendahara; bagian_data tetap bisa membuka
// halaman ini, jadi tombol cetak disembunyikan agar tidak menabrak "Akses ditolak".
$boleh_cetak = in_array(kbap_role(), ['admin', 'bendahara'], true);
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <h3 class="mb-0" id="judul-tabel">Rekap Urunan Per Piodalan<?= $nama_piodalan !== '' ? ' - ' . $esc($nama_piodalan) : '' ?></h3>
                    <?php if ($f_odalan > 0) : ?>
                        <p class="text-muted small mb-0">
                            <span class="badge bg-secondary"><?= number_format($jumlah_baris, 0, ',', '.') ?> baris</span>
                            <span class="badge bg-success"><?= number_format($jumlah_lunas, 0, ',', '.') ?> lunas</span>
                            <span class="badge bg-warning text-dark"><?= number_format($jumlah_baris - $jumlah_lunas, 0, ',', '.') ?> belum lunas</span>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <form method="GET" action="index.php">
                        <input type="hidden" name="menu" value="rekap_filter">
                        <input type="hidden" name="filter_metode" value="<?= $esc($f_metode) ?>">
                        <label for="piodalan" class="fw-bold">Pilih Piodalan:</label>
                        <select class="form-select w-50" name="id_piodalan" id="piodalan" onchange="this.form.submit()">
                            <option value="">-- Pilih Piodalan --</option>
                            <?php foreach ($odalan_list as $id_o => $nama_o) : ?>
                                <option value="<?= (int) $id_o ?>" <?= $f_odalan === (int) $id_o ? 'selected' : '' ?>>
                                    <?= $esc($nama_o) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>

            <?php if ($f_odalan === 0) : ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i> Silakan pilih piodalan terlebih dahulu.
                </div>
            <?php else : ?>
                <div class="row mb-3 align-items-center">
                    <div class="col-md-8">
                        <p class="mb-2 fw-bold">Filter Metode Pembayaran:</p>
                        <?php foreach (['Semua', 'tunai', 'transfer', 'QRIS'] as $metode) : ?>
                            <a href="<?= $esc($url_metode($metode)) ?>"
                               class="btn <?= $f_metode === $metode ? 'btn-primary' : 'btn-outline-secondary' ?> btn-sm me-2">
                                <?= $esc(ucfirst($metode)) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="alert alert-success p-2 mb-0">
                            <strong>Total Pembayaran (<?= $esc(ucfirst($f_metode)) ?>):</strong>
                            <span class="fw-bold"><?= $rupiah($total_pembayaran) ?></span>
                        </div>
                    </div>
                </div>

                <div class="card p-4">
                    <?php if ($boleh_cetak) : ?>
                        <div class="d-flex justify-content-end mb-3">
                            <a href="<?= $esc($cetak_link) ?>" class="btn btn-primary" target="_blank" rel="noopener">
                                <i class="fas fa-print"></i> Cetak Laporan PDF
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if ($jumlah_baris === 0) : ?>
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            <?php if ($f_metode !== 'Semua') : ?>
                                Tidak ada pembayaran menggunakan metode <strong><?= $esc(ucfirst($f_metode)) ?></strong> untuk piodalan ini.
                            <?php else : ?>
                                Tidak ada data hutang untuk piodalan ini.
                            <?php endif; ?>
                        </div>
                    <?php else : ?>
                    <div class="table-responsive">
                        <table id="example" class="table table-bordered table-striped">
                            <thead class="table-dark text-center">
                                <tr>
                                    <th>User ID</th>
                                    <th>Nama</th>
                                    <th>Wilayah</th>
                                    <th>Sub Wilayah</th>
                                    <th>Jenis User</th>
                                    <th>Tgl Bayar Terakhir</th>
                                    <th>Metode Bayar Terakhir</th>
                                    <th>Hutang (Sisa)</th>
                                    <th>Petugas Terakhir</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data_rows as $row) : ?>
                                    <?php $lunas = (float) $row['jumlah_hutang'] == 0.0; ?>
                                        <tr>
                                            <td><?= $esc($row['user_id']) ?></td>
                                            <td><?= $esc($row['nama']) ?></td>
                                            <td><?= $esc($row['nama_wilayah'] ?? '-') ?></td>
                                            <td><?= $esc($row['nama_sub'] ?? '-') ?></td>
                                            <td><?= $esc(ucfirst((string) $row['jenis_user'])) ?></td>
                                            <td class="text-center text-nowrap"><?= $esc($tanggal_rapi($row['tanggal_bayar'])) ?></td>
                                            <td class="text-center"><?= $esc($row['metode_pembayaran'] === '-' ? '-' : strtoupper((string) $row['metode_pembayaran'])) ?></td>
                                            <td class="text-end"><?= $rupiah($row['jumlah_hutang']) ?></td>
                                            <td><?= $esc($row['username']) ?></td>
                                            <td class="<?= $lunas ? 'table-success' : 'table-warning' ?>">
                                                <?= $esc($row['status_hutang']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
