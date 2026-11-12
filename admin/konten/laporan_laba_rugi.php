<?php
/**
 * Laporan Laba/Rugi per Odalan
 *
 * Ringkasan keuangan tiap odalan:
 *   Pemasukan  = dana punia + urunan (pembayaran sah)
 *   Pengeluaran = pengeluaran model lama + detail transaksi model baru
 *   Saldo      = pemasukan - pengeluaran
 *
 * Hanya MEMBACA data; tidak mengubah tabel apa pun.
 */
require_once __DIR__ . '/../security.php';
kbap_guard_menu('laporan_laba_rugi');

require_once __DIR__ . '/../koneksi.php';

if (!function_exists('kbap_rupiah')) {
    function kbap_rupiah($angka): string
    {
        return 'Rp ' . number_format((float) $angka, 0, ',', '.');
    }
}
if (!function_exists('kbap_e')) {
    function kbap_e($teks): string
    {
        return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
    }
}

// ---------------------------------------------------------------------
// Satu query agregat untuk semua odalan (tanpa N+1).
// `dibatalkan_at IS NULL` wajib: pembayaran batal tidak dihitung.
// ---------------------------------------------------------------------
$sql = "SELECT o.id, o.nama_odalan, o.tanggal, o.iuran_ngarep, o.iuran_ngempi,
               (SELECT COALESCE(SUM(d.jumlah_punia), 0) FROM dana_punia d
                 WHERE d.id_piodalan = o.id) AS punia,
               (SELECT COALESCE(SUM(p.jumlah_bayar), 0) FROM pembayaran p
                 WHERE p.id_piodalan = o.id AND p.dibatalkan_at IS NULL) AS urunan,
               (SELECT COALESCE(SUM(h.jumlah_tagihan_asli), 0) FROM hutang h
                 WHERE h.id_odalan = o.id) AS tagihan,
               (SELECT COALESCE(SUM(h.jumlah_hutang), 0) FROM hutang h
                 WHERE h.id_odalan = o.id) AS sisa_tagihan,
               (SELECT COUNT(*) FROM hutang h WHERE h.id_odalan = o.id) AS jml_tagihan,
               (SELECT COUNT(*) FROM hutang h WHERE h.id_odalan = o.id AND h.status = 'lunas') AS jml_lunas,
               (SELECT COALESCE(SUM(pg.jumlah), 0) FROM pengeluaran pg
                 WHERE pg.id_piodalan = o.id) AS belanja_lama,
               (SELECT COALESCE(SUM(dt.total), 0)
                  FROM detail_transaksi dt
                  JOIN transaksi t ON t.id_transaksi = dt.id_transaksi
                 WHERE t.id_piodalan = o.id) AS belanja_baru,
               (SELECT COUNT(*) FROM transaksi t WHERE t.id_piodalan = o.id) AS jml_nota
        FROM odalan o
        ORDER BY o.tanggal ASC, o.id ASC";

$baris = [];
$tot = [
    'punia' => 0.0, 'urunan' => 0.0, 'tagihan' => 0.0, 'sisa' => 0.0,
    'belanja' => 0.0, 'masuk' => 0.0, 'saldo' => 0.0, 'jml_tagihan' => 0, 'jml_lunas' => 0,
];

if ($res = $koneksi->query($sql)) {
    while ($r = $res->fetch_assoc()) {
        $belanja = (float) $r['belanja_lama'] + (float) $r['belanja_baru'];
        $masuk   = (float) $r['punia'] + (float) $r['urunan'];

        $r['belanja_total'] = $belanja;
        $r['masuk_total']   = $masuk;
        $r['saldo']         = $masuk - $belanja;
        $r['margin']        = $masuk > 0 ? ($r['saldo'] / $masuk * 100) : 0.0;
        $r['serap']         = $masuk > 0 ? ($belanja / $masuk * 100) : 0.0;
        $r['kolektibilitas'] = (float) $r['tagihan'] > 0
            ? ((float) $r['tagihan'] - (float) $r['sisa_tagihan']) / (float) $r['tagihan'] * 100
            : 0.0;

        $baris[] = $r;

        $tot['punia']   += (float) $r['punia'];
        $tot['urunan']  += (float) $r['urunan'];
        $tot['tagihan'] += (float) $r['tagihan'];
        $tot['sisa']    += (float) $r['sisa_tagihan'];
        $tot['belanja'] += $belanja;
        $tot['masuk']   += $masuk;
        $tot['saldo']   += $r['saldo'];
        $tot['jml_tagihan'] += (int) $r['jml_tagihan'];
        $tot['jml_lunas']   += (int) $r['jml_lunas'];
    }
    $res->free();
}

$tot['margin'] = $tot['masuk'] > 0 ? ($tot['saldo'] / $tot['masuk'] * 100) : 0.0;
$tot['serap']  = $tot['masuk'] > 0 ? ($tot['belanja'] / $tot['masuk'] * 100) : 0.0;

// Skala terbesar untuk grafik batang sederhana
$skala = max(1, $tot['masuk'], $tot['belanja']);

$odalan_tanpa_belanja = [];
$odalan_rugi = [];
foreach ($baris as $b) {
    if ((float) $b['belanja_total'] <= 0) {
        $odalan_tanpa_belanja[] = $b['nama_odalan'];
    }
    if ((float) $b['saldo'] < 0) {
        $odalan_rugi[] = $b['nama_odalan'];
    }
}
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h3 class="mb-1"><i class="bi bi-graph-up-arrow text-success me-2"></i>Laporan Laba/Rugi per Odalan</h3>
                    <p class="text-muted mb-0">
                        Pemasukan (punia + urunan) dikurangi pengeluaran (nota lama &amp; baru) untuk setiap odalan.
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="aksi/export_csv.php?jenis=laba_rugi&csrf_token=<?= kbap_e(kbap_csrf_token()) ?>"
                        class="btn btn-outline-success btn-sm">
                        <i class="bi bi-filetype-csv"></i> Unduh CSV
                    </a>
                    <a href="index.php?menu=laporan_laba_rugi" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise"></i> Muat ulang
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <!-- KPI -->
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase">Total Pemasukan</div>
                            <div class="fs-4 fw-bold text-primary"><?= kbap_rupiah($tot['masuk']) ?></div>
                            <div class="small text-muted">
                                Punia <?= kbap_rupiah($tot['punia']) ?> &bull; Urunan <?= kbap_rupiah($tot['urunan']) ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase">Total Pengeluaran</div>
                            <div class="fs-4 fw-bold text-danger"><?= kbap_rupiah($tot['belanja']) ?></div>
                            <div class="small text-muted">
                                <?= number_format($tot['serap'], 1, ',', '.') ?>% dari pemasukan
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase">Saldo</div>
                            <div class="fs-4 fw-bold <?= $tot['saldo'] < 0 ? 'text-danger' : 'text-success' ?>">
                                <?= kbap_rupiah($tot['saldo']) ?>
                            </div>
                            <div class="small text-muted">
                                Margin <?= number_format($tot['margin'], 1, ',', '.') ?>%
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase">Tagihan Urunan</div>
                            <div class="fs-4 fw-bold text-dark"><?= kbap_rupiah($tot['tagihan']) ?></div>
                            <div class="small text-muted">
                                Lunas <?= (int) $tot['jml_lunas'] ?>/<?= (int) $tot['jml_tagihan'] ?> tagihan
                                &bull; sisa <?= kbap_rupiah($tot['sisa']) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RINCIAN PER ODALAN -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white">
                    <strong>Rincian per Odalan</strong>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Odalan</th>
                                <th>Tanggal</th>
                                <th class="text-end">Punia</th>
                                <th class="text-end">Urunan</th>
                                <th class="text-end">Total Masuk</th>
                                <th class="text-end">Pengeluaran</th>
                                <th class="text-end">Saldo</th>
                                <th class="text-end">Margin</th>
                                <th style="min-width:150px;">Serapan Biaya</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($baris)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">Belum ada data odalan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($baris as $b): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= kbap_e($b['nama_odalan']) ?></div>
                                            <div class="small text-muted">
                                                <?= (int) $b['jml_nota'] ?> nota &bull;
                                                <?= (int) $b['jml_lunas'] ?>/<?= (int) $b['jml_tagihan'] ?> tagihan lunas
                                            </div>
                                        </td>
                                        <td class="small"><?= kbap_e($b['tanggal']) ?></td>
                                        <td class="text-end small"><?= kbap_rupiah($b['punia']) ?></td>
                                        <td class="text-end small"><?= kbap_rupiah($b['urunan']) ?></td>
                                        <td class="text-end fw-semibold"><?= kbap_rupiah($b['masuk_total']) ?></td>
                                        <td class="text-end text-danger"><?= kbap_rupiah($b['belanja_total']) ?></td>
                                        <td class="text-end fw-bold <?= (float) $b['saldo'] < 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= kbap_rupiah($b['saldo']) ?>
                                        </td>
                                        <td class="text-end small"><?= number_format((float) $b['margin'], 1, ',', '.') ?>%</td>
                                        <td>
                                            <div class="progress" style="height: 8px;">
                                                <div class="progress-bar <?= (float) $b['serap'] > 80 ? 'bg-danger' : ((float) $b['serap'] > 50 ? 'bg-warning' : 'bg-success') ?>"
                                                    style="width: <?= min(100, max(0, (float) $b['serap'])) ?>%;"
                                                    title="<?= number_format((float) $b['serap'], 1, ',', '.') ?>%"></div>
                                            </div>
                                            <div class="small text-muted"><?= number_format((float) $b['serap'], 1, ',', '.') ?>%</div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($baris)): ?>
                            <tfoot class="table-light fw-semibold">
                                <tr>
                                    <td colspan="2">TOTAL</td>
                                    <td class="text-end"><?= kbap_rupiah($tot['punia']) ?></td>
                                    <td class="text-end"><?= kbap_rupiah($tot['urunan']) ?></td>
                                    <td class="text-end"><?= kbap_rupiah($tot['masuk']) ?></td>
                                    <td class="text-end text-danger"><?= kbap_rupiah($tot['belanja']) ?></td>
                                    <td class="text-end <?= $tot['saldo'] < 0 ? 'text-danger' : 'text-success' ?>"><?= kbap_rupiah($tot['saldo']) ?></td>
                                    <td class="text-end"><?= number_format($tot['margin'], 1, ',', '.') ?>%</td>
                                    <td><?= number_format($tot['serap'], 1, ',', '.') ?>%</td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- GRAFIK BATANG SEDERHANA (tanpa library) -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Perbandingan Pemasukan vs Pengeluaran</strong></div>
                <div class="card-body">
                    <?php foreach ($baris as $b): ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="fw-semibold"><?= kbap_e($b['nama_odalan']) ?></span>
                                <span class="text-muted">
                                    masuk <?= kbap_rupiah($b['masuk_total']) ?> &bull;
                                    keluar <?= kbap_rupiah($b['belanja_total']) ?>
                                </span>
                            </div>
                            <div class="progress mb-1" style="height: 10px;">
                                <div class="progress-bar bg-primary" style="width: <?= max(1, (float) $b['masuk_total'] / $skala * 100) ?>%"></div>
                            </div>
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar bg-danger" style="width: <?= (float) $b['belanja_total'] > 0 ? max(1, (float) $b['belanja_total'] / $skala * 100) : 0 ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($baris)): ?>
                        <div class="text-muted small">Belum ada data untuk digambarkan.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CATATAN KUALITAS DATA -->
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Catatan</strong></div>
                <div class="card-body small">
                    <ul class="mb-0">
                        <li>Urunan dihitung dari <code>pembayaran</code> yang sah (kolom <code>dibatalkan_at</code> kosong).</li>
                        <li>Pengeluaran menggabungkan dua sumber: <code>pengeluaran</code> (model lama) dan
                            <code>transaksi</code> + <code>detail_transaksi</code> (model baru).</li>
                        <li>Punia hanya dihitung bila sudah terhubung ke odalan (saat ini 0 baris tanpa odalan).</li>
                        <li>Kolom <em>Saldo</em> bukan saldo kas riil: belum memperhitungkan biaya operasional di luar odalan.</li>
                        <?php if (!empty($odalan_tanpa_belanja)): ?>
                            <li class="text-warning-emphasis">
                                <strong>Belum ada catatan pengeluaran</strong> untuk:
                                <?= kbap_e(implode(', ', $odalan_tanpa_belanja)) ?> — angka saldo odalan tersebut belum lengkap.
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($odalan_rugi)): ?>
                            <li class="text-danger"><strong>Defisit</strong> pada: <?= kbap_e(implode(', ', $odalan_rugi)) ?>.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

        </div>
    </div>
</main>
