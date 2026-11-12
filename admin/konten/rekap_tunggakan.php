<?php
/**
 * Rekap Tunggakan Pembayaran
 *
 * Membandingkan tanggal_bayar dengan tanggal odalan untuk menentukan
 * odalan mana yang "aktif" saat pembayaran dilakukan:
 *   - id_piodalan = odalan aktif   -> tepat waktu
 *   - id_piodalan < odalan aktif   -> TUNGGAKAN (membayar odalan lama)
 *   - id_piodalan > odalan aktif   -> bayar di muka (penagihan awal)
 *   - tidak ada odalan aktif       -> sebelum odalan pertama
 *
 * Hanya MEMBACA data; tidak mengubah tabel apa pun.
 */
require_once __DIR__ . '/../security.php';
kbap_guard_menu('rekap_tunggakan'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

$id_piodalan_filter = isset($_GET['id_piodalan']) ? (int) $_GET['id_piodalan'] : 0;

// --- Daftar odalan untuk dropdown -------------------------------------
$list_odalan = [];
if ($res_odalan = $koneksi->query("SELECT id, nama_odalan, tanggal FROM odalan ORDER BY tanggal ASC, id ASC")) {
    while ($r = $res_odalan->fetch_assoc()) {
        $list_odalan[] = $r;
    }
    $res_odalan->free();
}

// --- Ambil semua pembayaran sah + odalan aktif saat bayar -------------
$sql = "SELECT p.id_pembayaran, p.id_semeton, s.user_id, s.nama, s.jenis_user,
               w.nama_wilayah, sw.nama_sub,
               p.id_piodalan, o.nama_odalan, o.tanggal AS tanggal_odalan,
               p.tanggal_bayar, p.jumlah_bayar, p.metode_pembayaran,
               (SELECT o2.id FROM odalan o2
                 WHERE o2.tanggal <= DATE(p.tanggal_bayar)
                 ORDER BY o2.tanggal DESC, o2.id DESC LIMIT 1) AS id_aktif
        FROM pembayaran p
        JOIN odalan o  ON o.id = p.id_piodalan
        JOIN semeton s ON s.id = p.id_semeton
        LEFT JOIN wilayah w      ON s.wilayah = w.id_wilayah
        LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
        WHERE p.dibatalkan_at IS NULL
        ORDER BY p.tanggal_bayar DESC, s.nama ASC";

$ringkasan = [
    'tepat'     => ['jml' => 0, 'nominal' => 0.0],
    'tunggakan' => ['jml' => 0, 'nominal' => 0.0],
    'muka'      => ['jml' => 0, 'nominal' => 0.0],
    'pra'       => ['jml' => 0, 'nominal' => 0.0],
];
$per_odalan  = [];
$tunggakan   = [];
$total_baris = 0;

foreach ($list_odalan as $o) {
    $per_odalan[(int) $o['id']] = [
        'nama_odalan'       => $o['nama_odalan'],
        'tanggal'           => $o['tanggal'],
        'jml_bayar'         => 0,
        'nominal'           => 0.0,
        'jml_tunggakan'     => 0,
        'nominal_tunggakan' => 0.0,
    ];
}

if ($result = $koneksi->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $total_baris++;
        $id_bayar = (int) $row['id_piodalan'];
        $id_aktif = $row['id_aktif'] === null ? null : (int) $row['id_aktif'];
        $nominal  = (float) $row['jumlah_bayar'];

        if ($id_aktif === null) {
            $kategori = 'pra';
        } elseif ($id_bayar === $id_aktif) {
            $kategori = 'tepat';
        } elseif ($id_bayar < $id_aktif) {
            $kategori = 'tunggakan';
        } else {
            $kategori = 'muka';
        }

        $ringkasan[$kategori]['jml']++;
        $ringkasan[$kategori]['nominal'] += $nominal;

        if (isset($per_odalan[$id_bayar])) {
            $per_odalan[$id_bayar]['jml_bayar']++;
            $per_odalan[$id_bayar]['nominal'] += $nominal;
            if ($kategori === 'tunggakan') {
                $per_odalan[$id_bayar]['jml_tunggakan']++;
                $per_odalan[$id_bayar]['nominal_tunggakan'] += $nominal;
            }
        }

        if ($kategori === 'tunggakan' && ($id_piodalan_filter === 0 || $id_piodalan_filter === $id_bayar)) {
            $row['telat_hari'] = (int) (strtotime((string) $row['tanggal_bayar']) - strtotime((string) $row['tanggal_odalan'])) / 86400;
            $tunggakan[] = $row;
        }
    }
    $result->free();
}

$total_tunggakan_nominal = $ringkasan['tunggakan']['nominal'];
$total_semua_nominal     = $ringkasan['tepat']['nominal'] + $ringkasan['tunggakan']['nominal']
                         + $ringkasan['muka']['nominal'] + $ringkasan['pra']['nominal'];

// --- Ringkasan tunggakan per wilayah (mengikuti filter odalan) --------
$per_wilayah = [];
foreach ($tunggakan as $t) {
    $kunci = trim((string) ($t['nama_wilayah'] ?? ''));
    if ($kunci === '') {
        $kunci = '(tanpa wilayah)';
    }
    if (!isset($per_wilayah[$kunci])) {
        $per_wilayah[$kunci] = ['jml' => 0, 'nominal' => 0.0, 'anggota' => []];
    }
    $per_wilayah[$kunci]['jml']++;
    $per_wilayah[$kunci]['nominal'] += (float) $t['jumlah_bayar'];
    $per_wilayah[$kunci]['anggota'][(string) $t['user_id']] = true;
}
uasort($per_wilayah, static function ($a, $b) {
    return $b['nominal'] <=> $a['nominal'];
});
$wilayah_tertinggi = $per_wilayah ? array_key_first($per_wilayah) : null;

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
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h3 class="mb-1">Rekap Tunggakan Pembayaran</h3>
                    <p class="text-muted mb-0">
                        Pembayaran dihitung <strong>tunggakan</strong> bila dilakukan saat odalan yang lebih baru sudah berlangsung.
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="aksi/export_csv.php?jenis=tunggakan&csrf_token=<?= kbap_e(kbap_csrf_token()) ?>"
                        class="btn btn-outline-success btn-sm">
                        <i class="bi bi-filetype-csv"></i> Unduh CSV
                    </a>
                    <a href="index.php?menu=rekap_tunggakan" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise"></i> Muat ulang
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <!-- RINGKASAN KATEGORI -->
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase">Tepat Waktu</div>
                            <div class="fs-4 fw-bold text-success"><?= (int) $ringkasan['tepat']['jml'] ?></div>
                            <div class="small text-muted"><?= kbap_rupiah($ringkasan['tepat']['nominal']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100 border-start border-danger border-4">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase">Tunggakan</div>
                            <div class="fs-4 fw-bold text-danger"><?= (int) $ringkasan['tunggakan']['jml'] ?></div>
                            <div class="small text-muted"><?= kbap_rupiah($ringkasan['tunggakan']['nominal']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase">Bayar Di Muka</div>
                            <div class="fs-4 fw-bold text-primary"><?= (int) $ringkasan['muka']['jml'] ?></div>
                            <div class="small text-muted"><?= kbap_rupiah($ringkasan['muka']['nominal']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase">Sebelum Odalan Pertama</div>
                            <div class="fs-4 fw-bold text-secondary"><?= (int) $ringkasan['pra']['jml'] ?></div>
                            <div class="small text-muted"><?= kbap_rupiah($ringkasan['pra']['nominal']) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-light border small">
                Total pembayaran sah: <strong><?= (int) $total_baris ?></strong> transaksi,
                nominal <strong><?= kbap_rupiah($total_semua_nominal) ?></strong>.
                Porsi tunggakan: <strong class="text-danger"><?= kbap_rupiah($total_tunggakan_nominal) ?></strong>
                (<?= $total_semua_nominal > 0 ? number_format($total_tunggakan_nominal / $total_semua_nominal * 100, 2, ',', '.') : '0,00' ?>%).
            </div>

            <!-- RINGKASAN PER ODALAN -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <strong>Ringkasan per Odalan</strong>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Odalan</th>
                                <th>Tanggal</th>
                                <th class="text-end">Pembayaran</th>
                                <th class="text-end">Nominal</th>
                                <th class="text-end">Tunggakan (transaksi)</th>
                                <th class="text-end">Nominal Tunggakan</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($per_odalan as $id => $o): ?>
                                <tr>
                                    <td><?= kbap_e($o['nama_odalan']) ?></td>
                                    <td><?= kbap_e($o['tanggal']) ?></td>
                                    <td class="text-end"><?= (int) $o['jml_bayar'] ?></td>
                                    <td class="text-end"><?= kbap_rupiah($o['nominal']) ?></td>
                                    <td class="text-end <?= $o['jml_tunggakan'] > 0 ? 'text-danger fw-semibold' : '' ?>">
                                        <?= (int) $o['jml_tunggakan'] ?>
                                    </td>
                                    <td class="text-end"><?= kbap_rupiah($o['nominal_tunggakan']) ?></td>
                                    <td class="text-end">
                                        <?php if ($o['jml_tunggakan'] > 0): ?>
                                            <a href="index.php?menu=rekap_tunggakan&id_piodalan=<?= (int) $id ?>"
                                                class="btn btn-sm btn-outline-danger">Lihat</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- RINGKASAN PER WILAYAH -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong>Ringkasan Tunggakan per Wilayah</strong>
                    <?php if ($wilayah_tertinggi !== null): ?>
                        <span class="small text-muted">
                            Tertinggi: <strong class="text-danger"><?= kbap_e($wilayah_tertinggi) ?></strong>
                            (<?= kbap_rupiah($per_wilayah[$wilayah_tertinggi]['nominal']) ?>)
                        </span>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Wilayah (Banjar)</th>
                                <th class="text-end">Transaksi</th>
                                <th class="text-end">Anggota Terlibat</th>
                                <th class="text-end">Nominal</th>
                                <th class="text-end">Porsi</th>
                                <th style="min-width:140px;">Proporsi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($per_wilayah)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        Tidak ada tunggakan pada filter ini.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($per_wilayah as $nama_w => $w): ?>
                                    <?php
                                    $porsi = $total_tunggakan_nominal > 0 ? $w['nominal'] / $total_tunggakan_nominal * 100 : 0;
                                    ?>
                                    <tr>
                                        <td class="fw-semibold"><?= kbap_e($nama_w) ?></td>
                                        <td class="text-end"><?= (int) $w['jml'] ?></td>
                                        <td class="text-end"><?= count($w['anggota']) ?></td>
                                        <td class="text-end"><?= kbap_rupiah($w['nominal']) ?></td>
                                        <td class="text-end small"><?= number_format($porsi, 1, ',', '.') ?>%</td>
                                        <td>
                                            <div class="progress" style="height: 8px;">
                                                <div class="progress-bar bg-danger" style="width: <?= max(1, $porsi) ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($per_wilayah)): ?>
                            <tfoot class="table-light fw-semibold">
                                <tr>
                                    <td>TOTAL</td>
                                    <td class="text-end"><?= (int) $ringkasan['tunggakan']['jml'] ?></td>
                                    <td></td>
                                    <td class="text-end"><?= kbap_rupiah($total_tunggakan_nominal) ?></td>
                                    <td class="text-end">100%</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- DAFTAR DETAIL TUNGGAKAN -->
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <strong>Daftar Pembayaran Tunggakan</strong>
                        <span class="text-muted small">(<?= count($tunggakan) ?> transaksi)</span>
                    </div>
                    <div class="d-flex gap-2">
                        <form method="GET" action="index.php" class="d-flex gap-2">
                            <input type="hidden" name="menu" value="rekap_tunggakan">
                            <select name="id_piodalan" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="0">Semua odalan</option>
                                <?php foreach ($list_odalan as $o): ?>
                                    <option value="<?= (int) $o['id'] ?>" <?= $id_piodalan_filter === (int) $o['id'] ? 'selected' : '' ?>>
                                        <?= kbap_e($o['nama_odalan']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <input type="text" id="cariTunggakan" class="form-control form-control-sm"
                            placeholder="Cari nama / wilayah..." style="min-width:220px">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0" id="tabelTunggakan">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Nama Semeton</th>
                                <th>Wilayah</th>
                                <th>Odalan Dibayar</th>
                                <th>Tanggal Odalan</th>
                                <th>Tanggal Bayar</th>
                                <th class="text-end">Telat</th>
                                <th class="text-end">Nominal</th>
                                <th>Metode</th>
                                <th>Odalan Aktif Saat Bayar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tunggakan)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">
                                        Tidak ada pembayaran tunggakan pada filter ini.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tunggakan as $i => $t): ?>
                                    <?php
                                    $nama_aktif = '-';
                                    if ($t['id_aktif'] !== null) {
                                        foreach ($list_odalan as $o) {
                                            if ((int) $o['id'] === (int) $t['id_aktif']) {
                                                $nama_aktif = $o['nama_odalan'];
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= kbap_e($t['nama']) ?></div>
                                            <div class="small text-muted"><?= kbap_e($t['user_id']) ?></div>
                                        </td>
                                        <td class="small">
                                            <?= kbap_e($t['nama_wilayah'] ?? '') ?>
                                            <?php if (!empty($t['nama_sub'])): ?>
                                                <div class="text-muted"><?= kbap_e($t['nama_sub']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?= kbap_e($t['nama_odalan']) ?></td>
                                        <td class="small"><?= kbap_e($t['tanggal_odalan']) ?></td>
                                        <td class="small"><?= kbap_e($t['tanggal_bayar']) ?></td>
                                        <td class="text-end <?= $t['telat_hari'] > 30 ? 'text-danger fw-semibold' : '' ?>">
                                            <?= (int) $t['telat_hari'] ?> hari
                                        </td>
                                        <td class="text-end"><?= kbap_rupiah($t['jumlah_bayar']) ?></td>
                                        <td class="small"><?= kbap_e($t['metode_pembayaran']) ?></td>
                                        <td class="small text-muted"><?= kbap_e($nama_aktif) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white small text-muted">
                    Tunggakan dihitung dari <code>pembayaran.tanggal_bayar</code> dibanding <code>odalan.tanggal</code>.
                    Pembayaran yang sudah dibatalkan (<code>dibatalkan_at</code> terisi) tidak dihitung.
                </div>
            </div>

        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var input = document.getElementById('cariTunggakan');
        var tabel = document.getElementById('tabelTunggakan');
        if (!input || !tabel) return;

        input.addEventListener('input', function() {
            var kata = this.value.toLowerCase();
            tabel.querySelectorAll('tbody tr').forEach(function(tr) {
                tr.style.display = tr.textContent.toLowerCase().indexOf(kata) !== -1 ? '' : 'none';
            });
        });
    });
</script>
