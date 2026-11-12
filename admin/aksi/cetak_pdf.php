<?php
/**
 * Cetak / simpan PDF Laporan Pengeluaran per odalan.
 *
 * Diperbarui 2026-11-12:
 *  - Mencetak **kedua model data** (pengeluaran lama + transaksi baru). Dulu
 *    odalan 1 (hanya data lama) tercetak kosong, dan odalan 3 hanya data baru.
 *  - `id_odalan` divalidasi + prepared statement (sebelumnya disisipkan mentah
 *    ke SQL → SQL injection).
 *  - Role disamakan dengan menu `laporan_pengeluaran` (admin, bendahara,
 *    bagian_data). Dulu `bagian_data` melihat tombol tetapi ditolak 403.
 *  - Auto-print `onload` diganti toolbar "Cetak / Simpan PDF" + "Kembali".
 */
require_once __DIR__ . '/../security.php';
kbap_guard(['admin', 'bendahara', 'bagian_data'], false);

require_once __DIR__ . '/../koneksi.php';

$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$fmt_tanggal = static function ($t): string {
    $ts = strtotime((string) $t);
    return $ts ? date('d-m-Y', $ts) : '-';
};
$rupiah = static function ($n): string {
    return number_format((float) $n, 0, ',', '.');
};

$id_odalan = (isset($_GET['id_odalan']) && is_scalar($_GET['id_odalan'])) ? (int) $_GET['id_odalan'] : 0;

$odalan = null;
if ($id_odalan > 0) {
    $stmt = $koneksi->prepare('SELECT id, nama_odalan, tanggal FROM odalan WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $id_odalan);
        $stmt->execute();
        $odalan = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

if (!$odalan) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Odalan tidak ditemukan</title>
        <style>
            body { font-family: Arial, Helvetica, sans-serif; padding: 40px; color: #333; }
            a { color: #0d6efd; }
        </style>
    </head>
    <body>
        <h2>Odalan tidak ditemukan</h2>
        <p>Parameter <code>id_odalan</code> tidak valid atau odalan sudah dihapus.</p>
        <p><a href="../index.php?menu=laporan_pengeluaran">&larr; Kembali ke laporan pengeluaran</a></p>
    </body>
    </html>
    <?php
    exit;
}

$nama_odalan = (string) $odalan['nama_odalan'];
$tanggal_odalan = (string) $odalan['tanggal'];

// ---------------------------------------------------------------------------
// Kumpulkan blok data: catatan lama (per baris) dan nota baru (per nota).
// ---------------------------------------------------------------------------
$blok = [];
$jml_lama = 0;
$jml_nota = 0;
$jml_rincian = 0;
$grand_total = 0.0;

// Model lama
$stmt = $koneksi->prepare(
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
);
if ($stmt) {
    $stmt->bind_param('i', $id_odalan);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $blok[] = [
            'tanggal'  => (string) $r['tanggal'],
            'rank'     => 0,
            'urut'     => (int) $r['id_pengeluaran'],
            'tipe'     => 'lama',
            'nama'     => (string) $r['nama_pengeluaran'],
            'rincian'  => (string) ($r['keterangan'] ?? ''),
            'kategori' => (string) $r['nama_kategori'],
            'jumlah'   => (float) $r['jumlah'],
        ];
        $jml_lama++;
        $grand_total += (float) $r['jumlah'];
    }
    $stmt->close();
}

// Model baru (dikelompokkan per nota)
$nota_map = [];
$stmt = $koneksi->prepare(
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
);
if ($stmt) {
    $stmt->bind_param('i', $id_odalan);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $id_t = (int) $r['id_transaksi'];
        if (!isset($nota_map[$id_t])) {
            $nota_map[$id_t] = [
                'tanggal' => (string) $r['tanggal'],
                'rank'    => 1,
                'urut'    => $id_t,
                'tipe'    => 'nota',
                'nama'    => (string) ($r['nota'] ?? ''),
                'items'   => [],
                'total'   => 0.0,
            ];
        }
        $nota_map[$id_t]['items'][] = [
            'nama'  => (string) $r['nama_barang'],
            'qty'   => (float) $r['qty'],
            'satuan' => (string) $r['satuan'],
            'harga' => (float) $r['harga'],
            'total' => (float) $r['total'],
        ];
        $nota_map[$id_t]['total'] += (float) $r['total'];
        $jml_rincian++;
        $grand_total += (float) $r['total'];
    }
    $stmt->close();
}
foreach ($nota_map as $n) {
    $blok[] = $n;
    $jml_nota++;
}

usort($blok, static function ($a, $b) {
    $c = strcmp((string) $a['tanggal'], (string) $b['tanggal']);
    if ($c !== 0) {
        return $c;
    }
    if ($a['rank'] !== $b['rank']) {
        return $a['rank'] <=> $b['rank'];
    }
    return $a['urut'] <=> $b['urut'];
});

// Kelompokkan per tanggal untuk subtotal harian.
$per_tanggal = [];
foreach ($blok as $b) {
    $per_tanggal[$b['tanggal']][] = $b;
}

$logo_path = __DIR__ . '/../dist/assets/img/bg-logo.png';
$logo_url  = '../dist/assets/img/bg-logo.png';
$jumlah_baris = $jml_lama + $jml_rincian;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LPJ Pengeluaran - <?= $esc($nama_odalan) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #222;
            margin: 0;
            padding: 12px;
            background: #f1f5f9;
        }
        .sheet {
            background: #fff;
            max-width: 210mm;
            margin: 0 auto;
            padding: 12mm 10mm;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .12);
        }
        .toolbar {
            max-width: 210mm;
            margin: 0 auto 10px;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
        .toolbar a, .toolbar button {
            font: inherit;
            font-size: 9pt;
            text-decoration: none;
            border: 1px solid #94a3b8;
            background: #fff;
            color: #1e293b;
            padding: 6px 14px;
            border-radius: 6px;
            cursor: pointer;
        }
        .toolbar button.primary {
            background: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
        }
        .header { text-align: center; margin-bottom: 12px; }
        .header img { display: block; margin: 0 auto 6px; }
        .header h2 { margin: 0; font-size: 13pt; letter-spacing: .5px; text-transform: uppercase; }
        .header .odalan { font-weight: bold; font-size: 11pt; margin-top: 4px; }
        .header .tanggal { font-style: italic; font-size: 9pt; color: #555; }

        .ringkasan {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0 12px;
            font-size: 9pt;
        }
        .ringkasan td {
            border: 1px solid #cbd5e1;
            padding: 5px 8px;
        }
        .ringkasan .label { color: #475569; width: 18%; }
        .ringkasan .nilai { font-weight: bold; }

        table.data {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        table.data th, table.data td {
            border: 1px solid #000;
            padding: 3px 4px;
            font-size: 8pt;
            vertical-align: top;
            word-wrap: break-word;
        }
        table.data th {
            background: #e2e8f0;
            text-transform: uppercase;
            font-size: 7.5pt;
            text-align: center;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
        tr.subtotal td { background: #f1f5f9; font-weight: bold; }
        tr.subtotal-nota td { background: #f8fafc; font-style: italic; }
        tr.grand td { background: #e2e8f0; font-weight: bold; }

        .ttd {
            width: 100%;
            margin-top: 28px;
            border: none;
            font-size: 9pt;
        }
        .ttd td { border: none; text-align: center; padding: 0; }
        .ttd .ruang { height: 55px; }

        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            body { background: #fff; padding: 0; font-size: 9pt; }
            .sheet { box-shadow: none; max-width: none; padding: 0; margin: 0; }
            .no-print { display: none !important; }
            tr { page-break-inside: avoid; }
            thead { display: table-header-group; }
        }
    </style>
</head>

<body>
    <div class="toolbar no-print">
        <a href="../index.php?menu=laporan_pengeluaran&id_piodalan=<?= (int) $id_odalan ?>">&larr; Kembali</a>
        <button type="button" class="primary" onclick="window.print()">Cetak / Simpan PDF</button>
    </div>

    <div class="sheet">
        <div class="header">
            <?php if (is_file($logo_path)): ?>
                <img src="<?= $esc($logo_url) ?>" alt="Logo" width="46">
            <?php endif; ?>
            <h2>Laporan Pertanggung Jawaban Pengeluaran</h2>
            <div class="odalan"><?= $esc($nama_odalan) ?></div>
            <div class="tanggal">Tanggal Kegiatan: <?= $esc($fmt_tanggal($tanggal_odalan)) ?></div>
        </div>

        <table class="ringkasan">
            <tr>
                <td class="label">Total Pengeluaran</td>
                <td class="nilai text-right" style="width:22%;">Rp <?= $esc($rupiah($grand_total)) ?></td>
                <td class="label">Jumlah Baris</td>
                <td class="nilai text-right"><?= (int) $jumlah_baris ?></td>
            </tr>
            <tr>
                <td class="label">Catatan Lama</td>
                <td class="nilai text-right"><?= (int) $jml_lama ?></td>
                <td class="label">Nota / Rincian</td>
                <td class="nilai text-right"><?= (int) $jml_nota ?> / <?= (int) $jml_rincian ?></td>
            </tr>
        </table>

        <table class="data">
            <colgroup>
                <col style="width: 5%;">
                <col style="width: 9%;">
                <col style="width: 21%;">
                <col style="width: 29%;">
                <col style="width: 11%;">
                <col style="width: 7%;">
                <col style="width: 9%;">
                <col style="width: 9%;">
            </colgroup>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tanggal</th>
                    <th>Nota / Uraian</th>
                    <th>Rincian</th>
                    <th>Kategori</th>
                    <th>Qty</th>
                    <th>Harga</th>
                    <th>Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($per_tanggal)): ?>
                    <tr>
                        <td colspan="8" class="text-center" style="padding: 14px;">
                            Belum ada pengeluaran untuk odalan ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    $no = 1;
                    foreach ($per_tanggal as $tanggal => $daftar):
                        $subtotal = 0.0;
                        foreach ($daftar as $b) {
                            $subtotal += $b['tipe'] === 'nota' ? (float) $b['total'] : (float) $b['jumlah'];
                        }
                    ?>
                        <?php foreach ($daftar as $b): ?>
                            <?php if ($b['tipe'] === 'lama'): ?>
                                <tr>
                                    <td class="text-center"><?= $no++ ?></td>
                                    <td class="text-center"><?= $esc($fmt_tanggal($b['tanggal'])) ?></td>
                                    <td><?= $esc($b['nama'] !== '' ? $b['nama'] : '-') ?></td>
                                    <td><?= $esc($b['rincian'] !== '' ? $b['rincian'] : '-') ?></td>
                                    <td class="text-center"><?= $esc($b['kategori']) ?></td>
                                    <td class="text-center">-</td>
                                    <td class="text-right">-</td>
                                    <td class="text-right"><?= $esc($rupiah($b['jumlah'])) ?></td>
                                </tr>
                            <?php else: ?>
                                <?php $jml_item = count($b['items']); ?>
                                <?php foreach ($b['items'] as $i => $it): ?>
                                    <tr>
                                        <?php if ($i === 0): ?>
                                            <td class="text-center" rowspan="<?= (int) $jml_item ?>"><?= $no++ ?></td>
                                            <td class="text-center" rowspan="<?= (int) $jml_item ?>"><?= $esc($fmt_tanggal($b['tanggal'])) ?></td>
                                            <td rowspan="<?= (int) $jml_item ?>"><?= $esc($b['nama'] !== '' ? $b['nama'] : 'Nota') ?></td>
                                        <?php endif; ?>
                                        <td><?= $esc($it['nama'] !== '' ? $it['nama'] : '-') ?></td>
                                        <?php if ($i === 0): ?>
                                            <td class="text-center" rowspan="<?= (int) $jml_item ?>">-</td>
                                        <?php endif; ?>
                                        <td class="text-center">
                                            <?= $esc(rtrim(rtrim(number_format((float) $it['qty'], 2, ',', '.'), '0'), ',')) ?>
                                            <?= $esc($it['satuan']) ?>
                                        </td>
                                        <td class="text-right"><?= $esc($rupiah($it['harga'])) ?></td>
                                        <td class="text-right"><?= $esc($rupiah($it['total'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="subtotal-nota">
                                    <td colspan="7" class="text-right">Total <?= $esc($b['nama'] !== '' ? $b['nama'] : 'nota') ?></td>
                                    <td class="text-right"><?= $esc($rupiah($b['total'])) ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <tr class="subtotal">
                            <td colspan="7" class="text-right">Subtotal <?= $esc($fmt_tanggal($tanggal)) ?></td>
                            <td class="text-right"><?= $esc($rupiah($subtotal)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="grand">
                        <td colspan="7" class="text-right" style="padding: 7px;">GRAND TOTAL KESELURUHAN</td>
                        <td class="text-right" style="padding: 7px;">Rp <?= $esc($rupiah($grand_total)) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <table class="ttd">
            <tr>
                <td colspan="2">Bedulu, <?= $esc(date('d-m-Y')) ?></td>
            </tr>
            <tr>
                <td style="height: 18px;"></td>
                <td style="height: 18px;"></td>
            </tr>
            <tr>
                <td>Ketua Panitia Odalan</td>
                <td>Bendahara Odalan</td>
            </tr>
            <tr>
                <td class="ruang"></td>
                <td class="ruang"></td>
            </tr>
            <tr>
                <td>( ............................................ )</td>
                <td>( ............................................ )</td>
            </tr>
        </table>
    </div>
</body>

</html>
