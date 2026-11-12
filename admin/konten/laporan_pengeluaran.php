<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('laporan_pengeluaran'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

/*
 * Laporan Pengeluaran per Odalan (gabungan dua model data).
 *
 * Diperbarui 2026-11-12:
 *  - Model baru (`transaksi`/`detail_transaksi`) tidak punya kolom kategori,
 *    sehingga sebelumnya SEMUA 176 baris berlabel "Transaksi" dan nama nota
 *    hilang. Sekarang nama nota ditampilkan dan kategori dibiarkan "-".
 *  - Model lama (`pengeluaran`) punya `keterangan` (rincian belanja) yang
 *    sebelumnya tidak ditampilkan. Sekarang ikut tampil.
 *  - Tombol cetak tidak lagi bercabang dua halaman berbeda; keduanya memakai
 *    `aksi/cetak_pdf.php` yang sudah bisa mencetak kedua model.
 */
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$fmt_tanggal = static function ($t): string {
    $ts = strtotime((string) $t);
    return $ts ? date('d-m-Y', $ts) : '-';
};
$rupiah = static function ($n): string {
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
};

// 1. Daftar odalan (terbaru lebih dulu) + odalan default
$odalans = [];
if ($q_odalan = $koneksi->query("SELECT id, nama_odalan, tanggal FROM odalan ORDER BY tanggal DESC, id DESC")) {
    while ($o = $q_odalan->fetch_assoc()) {
        $odalans[(int) $o['id']] = $o;
    }
}
$last_piodalan_id = $odalans ? (int) array_key_first($odalans) : 0;

// 2. Odalan terpilih: divalidasi terhadap daftar (id tak dikenal → odalan terbaru)
$id_piodalan = (isset($_GET['id_piodalan']) && is_scalar($_GET['id_piodalan'])) ? (int) $_GET['id_piodalan'] : 0;
if ($id_piodalan <= 0 || !isset($odalans[$id_piodalan])) {
    $id_piodalan = $last_piodalan_id;
}
$nama_piodalan_terpilih = $id_piodalan > 0 ? $odalans[$id_piodalan]['nama_odalan'] : 'Pengeluaran';
$tanggal_piodalan        = $id_piodalan > 0 ? (string) $odalans[$id_piodalan]['tanggal'] : '';

/*
 * Kumpulkan item dari dua sumber.
 * Setiap item: tanggal, urutan, uraian, rincian, nota, kategori, sumber, jumlah
 */
$items      = [];
$jml_lama   = 0;
$jml_nota   = 0;

if ($id_piodalan > 0) {
    // --- Model lama: pengeluaran (2025) ---
    if ($stmt = $koneksi->prepare(
        "SELECT p.id_pengeluaran,
                DATE(p.tanggal_pengeluaran) AS tanggal,
                p.nama_pengeluaran,
                p.keterangan,
                COALESCE(k.nama_kategori, 'Tanpa Kategori') AS nama_kategori,
                p.jumlah
         FROM pengeluaran p
         LEFT JOIN kategori_pengeluaran k ON p.id_kategori = k.id_kategori
         WHERE p.id_piodalan = ?
         ORDER BY p.tanggal_pengeluaran, p.id_pengeluaran"
    )) {
        $stmt->bind_param('i', $id_piodalan);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'tanggal'  => (string) $row['tanggal'],
                'urutan'   => (int) $row['id_pengeluaran'],
                'uraian'   => (string) $row['nama_pengeluaran'],
                'rincian'  => (string) ($row['keterangan'] ?? ''),
                'nota'     => '',
                'kategori' => (string) $row['nama_kategori'],
                'sumber'   => 'Lama',
                'jumlah'   => (float) $row['jumlah'],
            ];
            $jml_lama++;
        }
        $stmt->close();
    }

    // --- Model baru: transaksi/detail_transaksi (2026) ---
    if ($stmt = $koneksi->prepare(
        "SELECT DATE(t.tanggal) AS tanggal,
                t.id_transaksi,
                t.keterangan AS nota,
                dt.id_detail,
                dt.nama_barang,
                dt.satuan,
                dt.harga,
                dt.jumlah AS qty,
                dt.total
         FROM detail_transaksi dt
         JOIN transaksi t ON t.id_transaksi = dt.id_transaksi
         WHERE t.id_piodalan = ?
         ORDER BY t.tanggal, t.id_transaksi, dt.id_detail"
    )) {
        $stmt->bind_param('i', $id_piodalan);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rincian = [];
            if ((float) $row['qty'] > 0) {
                $rincian[] = rtrim(rtrim(number_format((float) $row['qty'], 2, ',', '.'), '0'), ',')
                    . ' ' . (string) $row['satuan'];
            }
            $rincian[] = 'x ' . $rupiah($row['harga']);

            $items[] = [
                'tanggal'  => (string) $row['tanggal'],
                'urutan'   => (int) $row['id_transaksi'] * 100000 + (int) $row['id_detail'],
                'uraian'   => (string) $row['nama_barang'],
                'rincian'  => implode(' ', $rincian),
                'nota'     => (string) ($row['nota'] ?? ''),
                'kategori' => '-',
                'sumber'   => 'Nota',
                'jumlah'   => (float) $row['total'],
            ];
            $jml_nota++;
        }
        $stmt->close();
    }
}

// Urutkan: tanggal → model lama dulu → urutan
usort($items, static function ($a, $b) {
    $c = strcmp((string) $a['tanggal'], (string) $b['tanggal']);
    if ($c !== 0) {
        return $c;
    }
    $ra = $a['sumber'] === 'Lama' ? 0 : 1;
    $rb = $b['sumber'] === 'Lama' ? 0 : 1;
    if ($ra !== $rb) {
        return $ra <=> $rb;
    }
    return $a['urutan'] <=> $b['urutan'];
});

// Kelompokkan per tanggal
$grouped_pengeluarans = [];
$totalKeseluruhan = 0.0;
foreach ($items as $it) {
    $tanggal = $it['tanggal'];
    if (!isset($grouped_pengeluarans[$tanggal])) {
        $grouped_pengeluarans[$tanggal] = ['items' => [], 'total' => 0.0];
    }
    $grouped_pengeluarans[$tanggal]['items'][] = $it;
    $grouped_pengeluarans[$tanggal]['total'] += $it['jumlah'];
    $totalKeseluruhan += $it['jumlah'];
}
$total_baris = count($items);
$rentang = '-';
if ($items) {
    $tgl_awal  = (string) reset($items)['tanggal'];
    $tgl_akhir = (string) end($items)['tanggal'];
    $rentang = $fmt_tanggal($tgl_awal) . ' s.d. ' . $fmt_tanggal($tgl_akhir);
}
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-8">
                    <h3 class="mb-1 fw-bold text-dark">Data Pengeluaran - <?= $esc($nama_piodalan_terpilih) ?></h3>
                    <p class="text-muted small mb-0">
                        <?php if ($tanggal_piodalan !== ''): ?>
                            Tanggal odalan <?= $esc($fmt_tanggal($tanggal_piodalan)) ?> &middot;
                        <?php endif; ?>
                        Gabungan catatan pengeluaran lama dan nota transaksi baru.
                    </p>
                </div>
                <div class="col-sm-4 text-end">
                    <?php if ($id_piodalan > 0): ?>
                        <a href="aksi/cetak_pdf.php?id_odalan=<?= (int) $id_piodalan ?>" class="btn btn-danger shadow-sm" target="_blank" rel="noopener">
                            <i class="fa-solid fa-file-pdf me-1"></i> Cetak Laporan
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <div class="text-muted small">Total Pengeluaran</div>
                            <div class="fw-bold text-danger"><?= $esc($rupiah($totalKeseluruhan)) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <div class="text-muted small">Jumlah Baris</div>
                            <div class="fw-bold"><?= (int) $total_baris ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <div class="text-muted small">Catatan Lama / Rincian Nota</div>
                            <div class="fw-bold"><?= (int) $jml_lama ?> / <?= (int) $jml_nota ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2">
                            <div class="text-muted small">Rentang Tanggal</div>
                            <div class="fw-bold small"><?= $esc($rentang) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card p-4">
                <div class="row mb-3 align-items-end">
                    <div class="col-md-6">
                        <label for="odalan" class="form-label fw-bold small text-secondary">Pilih Odalan:</label>
                        <select id="odalan" class="form-select" onchange="window.location.href='?menu=laporan_pengeluaran&id_piodalan='+encodeURIComponent(this.value)">
                            <option value="">-- Pilih Odalan --</option>
                            <?php foreach ($odalans as $id_o => $odalan): ?>
                                <option value="<?= (int) $id_o ?>" <?= ((int) $id_o === $id_piodalan) ? 'selected' : '' ?>>
                                    <?= $esc($odalan['nama_odalan']) ?> (<?= $esc($odalan['tanggal']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 text-end small text-muted">
                        Sumber: <span class="badge bg-secondary">Lama</span> = tabel pengeluaran 2025,
                        <span class="badge bg-info text-dark">Nota</span> = transaksi 2026
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-12">
                        <div class="table-responsive">
                            <table id="pengeluaranTable" class="table table-bordered table-hover align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th style="width: 110px;">Tanggal</th>
                                        <th>Uraian</th>
                                        <th>Nota / Keterangan</th>
                                        <th style="width: 150px;">Kategori</th>
                                        <th style="width: 90px;" class="text-center">Sumber</th>
                                        <th class="text-end" style="width: 150px;">Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($grouped_pengeluarans)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">
                                                <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                                                Tidak ada data pengeluaran untuk odalan ini.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($grouped_pengeluarans as $tanggal => $data): ?>
                                            <tr class="table-primary">
                                                <td><b>Total <?= $esc($fmt_tanggal($tanggal)) ?></b></td>
                                                <td colspan="4" class="text-muted small">
                                                    <?= (int) count($data['items']) ?> baris
                                                </td>
                                                <td class="text-end"><b><?= $esc($rupiah($data['total'])) ?></b></td>
                                            </tr>

                                            <?php foreach ($data['items'] as $pengeluaran): ?>
                                                <tr>
                                                    <td><?= $esc($fmt_tanggal($tanggal)) ?></td>
                                                    <td><?= $esc($pengeluaran['uraian'] !== '' ? $pengeluaran['uraian'] : '-') ?></td>
                                                    <td class="small">
                                                        <?= $esc($pengeluaran['nota'] !== '' ? $pengeluaran['nota'] : ($pengeluaran['rincian'] !== '' ? $pengeluaran['rincian'] : '-')) ?>
                                                    </td>
                                                    <td><?= $esc($pengeluaran['kategori']) ?></td>
                                                    <td class="text-center">
                                                        <span class="badge <?= $pengeluaran['sumber'] === 'Lama' ? 'bg-secondary' : 'bg-info text-dark' ?>">
                                                            <?= $esc($pengeluaran['sumber']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-end"><?= $esc($rupiah($pengeluaran['jumlah'])) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>

                                        <tr class="table-dark">
                                            <td><b>Total Keseluruhan</b></td>
                                            <td colspan="4"></td>
                                            <td class="text-end"><b><?= $esc($rupiah($totalKeseluruhan)) ?></b></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // jQuery + DataTables + Buttons sudah dimuat oleh admin/index.php.
    // Halaman tetap aman dibuka langsung (tabel tampil sebagai HTML biasa).
    var $ = window.jQuery;
    if (!$ || !$.fn || !$.fn.DataTable || !document.getElementById('pengeluaranTable')) {
        return;
    }
    try {
        $('#pengeluaranTable').DataTable({
            paging: false,
            searching: false,
            ordering: false, // baris "Total per tanggal" ada di dalam tbody
            info: false,
            dom: 'Brt',
            buttons: [
                { extend: 'excelHtml5', text: 'Download Excel', className: 'btn btn-success btn-sm' },
                { extend: 'pdfHtml5', text: 'Download PDF', className: 'btn btn-danger btn-sm', orientation: 'portrait', pageSize: 'A4' }
            ]
        });
    } catch (e) {
        // Plugin tombol tidak tersedia: biarkan tabel HTML apa adanya.
    }
});
</script>
