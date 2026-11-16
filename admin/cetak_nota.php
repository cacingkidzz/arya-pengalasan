<?php
require_once __DIR__ . '/security.php';
kbap_guard(['admin', 'bendahara'], false);

date_default_timezone_set('Asia/Makassar');

include 'koneksi.php';

$user_id   = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$id_odalan = isset($_GET['piodalan']) ? (int) $_GET['piodalan'] : 0;

if ($user_id === '' || $id_odalan <= 0) {
    http_response_code(400);
    exit('Parameter nota tidak lengkap.');
}

/** Escape keluaran HTML. */
$esc = static function ($nilai): string {
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
};

/** Tanggal panjang berbahasa Indonesia, mis. 16 November 2026. */
function nota_tanggal_indo(?string $tanggal): string
{
    if (!$tanggal) {
        return '-';
    }
    $ts = strtotime($tanggal);
    if ($ts === false) {
        return '-';
    }
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    return date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Angka menjadi kata (untuk "terbilang"). */
function nota_angka_ke_kata(int $angka): string
{
    if ($angka < 0) {
        return 'minus ' . nota_angka_ke_kata(-$angka);
    }
    $satuan = [
        '', 'satu', 'dua', 'tiga', 'empat', 'lima',
        'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas',
    ];
    if ($angka < 12) {
        return $satuan[$angka];
    }
    if ($angka < 20) {
        return trim(nota_angka_ke_kata($angka - 10) . ' belas');
    }
    if ($angka < 100) {
        return trim(nota_angka_ke_kata(intdiv($angka, 10)) . ' puluh ' . nota_angka_ke_kata($angka % 10));
    }
    if ($angka < 200) {
        return trim('seratus ' . nota_angka_ke_kata($angka - 100));
    }
    if ($angka < 1000) {
        return trim(nota_angka_ke_kata(intdiv($angka, 100)) . ' ratus ' . nota_angka_ke_kata($angka % 100));
    }
    if ($angka < 2000) {
        return trim('seribu ' . nota_angka_ke_kata($angka - 1000));
    }
    if ($angka < 1000000) {
        return trim(nota_angka_ke_kata(intdiv($angka, 1000)) . ' ribu ' . nota_angka_ke_kata($angka % 1000));
    }
    if ($angka < 1000000000) {
        return trim(nota_angka_ke_kata(intdiv($angka, 1000000)) . ' juta ' . nota_angka_ke_kata($angka % 1000000));
    }
    if ($angka < 1000000000000) {
        return trim(nota_angka_ke_kata(intdiv($angka, 1000000000)) . ' miliar ' . nota_angka_ke_kata($angka % 1000000000));
    }
    return trim(nota_angka_ke_kata(intdiv($angka, 1000000000000)) . ' triliun ' . nota_angka_ke_kata($angka % 1000000000000));
}

/** "Terbilang" rupiah, mis. "Seratus lima puluh ribu rupiah". */
function nota_terbilang(float $nominal): string
{
    $kata = trim((string) preg_replace('/\s+/', ' ', nota_angka_ke_kata((int) round($nominal))));
    return $kata === '' ? 'Nol rupiah' : ucfirst($kata) . ' rupiah';
}

// ---------------------------------------------------------------------
// Ambil data odalan
// ---------------------------------------------------------------------
$stmt = $koneksi->prepare(
    'SELECT id, nama_odalan, tanggal, iuran_ngarep, iuran_ngempi
     FROM odalan WHERE id = ? LIMIT 1'
);
$stmt->bind_param('i', $id_odalan);
$stmt->execute();
$odalan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$odalan) {
    http_response_code(404);
    exit('Data odalan tidak ditemukan.');
}

// ---------------------------------------------------------------------
// Ambil data anggota beserta tagihan & pembayaran aktifnya
// ---------------------------------------------------------------------
$stmt = $koneksi->prepare(
    "SELECT s.id, s.nama, s.user_id, s.jenis_user, s.alamat,
            w.nama_wilayah, sw.nama_sub,
            h.jumlah_tagihan_asli, h.jumlah_hutang, h.status AS status_tagihan,
            p.id_pembayaran, p.jumlah_bayar, p.metode_pembayaran,
            p.tanggal_bayar, p.status AS status_bayar,
            u.nama_user AS nama_petugas
     FROM semeton s
     LEFT JOIN wilayah w     ON w.id_wilayah = s.wilayah
     LEFT JOIN sub_wilayah sw ON sw.id_sub = s.sub_wilayah
     LEFT JOIN hutang h      ON h.id_semeton = s.id AND h.id_odalan = ?
     LEFT JOIN pembayaran p  ON p.id_semeton = s.id AND p.id_piodalan = ? AND p.dibatalkan_at IS NULL
     LEFT JOIN user u        ON u.id_user = p.id_user
     WHERE s.user_id = ?
     LIMIT 1"
);
$stmt->bind_param('iis', $id_odalan, $id_odalan, $user_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) {
    http_response_code(404);
    exit('Data nota tidak ditemukan.');
}

if (empty($data['id_pembayaran'])) {
    http_response_code(404);
    exit('Belum ada pembayaran tercatat untuk anggota ini pada odalan tersebut.');
}

$jumlah_bayar   = (float) $data['jumlah_bayar'];
$tagihan        = (float) ($data['jumlah_tagihan_asli'] ?? 0);
$sisa_tagihan   = (float) ($data['jumlah_hutang'] ?? 0);
$tanggal_bayar  = (string) $data['tanggal_bayar'];
$masih_lunas    = $sisa_tagihan <= 0 || (string) ($data['status_tagihan'] ?? '') === 'lunas';
$metode         = (string) ($data['metode_pembayaran'] ?? '-');
$metode_label   = $metode === 'QRIS' ? 'QRIS' : ucfirst($metode);
$nomor_nota     = 'NOTA-' . date('Ym', strtotime($tanggal_bayar) ?: time()) . '-' . str_pad((string) $data['id_pembayaran'], 5, '0', STR_PAD_LEFT);
$jenis_label    = ucfirst((string) $data['jenis_user']);
$nama_wilayah   = $data['nama_wilayah'] ?: '-';
$nama_sub       = $data['nama_sub'] ?: '-';
$nama_petugas   = trim((string) ($data['nama_petugas'] ?? ''));
$dicetak        = date('d/m/Y H:i');
?><!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nota Pembayaran <?= $esc($data['user_id']) ?> - <?= $esc($odalan['nama_odalan']) ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px 12px 40px;
            background: #eef2f7;
            color: #1f2937;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            line-height: 1.45;
        }

        .sheet {
            background: #fff;
            width: 100%;
            max-width: 190mm;
            margin: 0 auto;
            padding: 13mm 12mm 11mm;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .14);
        }

        /* ---------------- Kop nota ---------------- */
        .kop {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 10px;
            border-bottom: 3px double #1e3c72;
        }

        .kop img {
            width: 62px;
            height: 62px;
            object-fit: contain;
            flex: 0 0 auto;
        }

        .kop-teks {
            flex: 1 1 auto;
            min-width: 0;
        }

        .kop-teks .nama {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            letter-spacing: .6px;
            text-transform: uppercase;
            color: #1e3c72;
            line-height: 1.2;
        }

        .kop-teks .alamat {
            margin: 3px 0 0;
            font-size: 11px;
            color: #475569;
        }

        .kop-teks .keterangan {
            margin: 2px 0 0;
            font-size: 10px;
            font-style: italic;
            color: #7c8794;
        }

        /* ---------------- Judul ---------------- */
        .judul-baris {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 16px;
            margin: 16px 0 12px;
        }

        .judul-baris h1 {
            margin: 0;
            font-size: 15px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: #111827;
        }

        .judul-baris .sub {
            margin: 3px 0 0;
            font-size: 10px;
            color: #6b7280;
        }

        .nomor-nota {
            text-align: right;
            font-size: 10px;
            color: #6b7280;
            white-space: nowrap;
        }

        .nomor-nota strong {
            display: block;
            font-size: 12px;
            color: #1e3c72;
            letter-spacing: .5px;
        }

        /* ---------------- Tabel detail ---------------- */
        .blok {
            margin-top: 10px;
        }

        .blok-judul {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 6px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #1e3c72;
        }

        .blok-judul::after {
            content: "";
            flex: 1 1 auto;
            height: 1px;
            background: #d7e0ee;
        }

        table.detail {
            width: 100%;
            border-collapse: collapse;
        }

        table.detail th,
        table.detail td {
            padding: 5px 8px;
            font-size: 11.5px;
            vertical-align: top;
            border-bottom: 1px solid #edf1f7;
        }

        table.detail th {
            width: 23%;
            font-weight: 600;
            color: #64748b;
            text-align: left;
            background: #f8fafc;
        }

        table.detail td {
            width: 27%;
            color: #111827;
        }

        table.detail td.lebar {
            width: 77%;
        }

        table.detail tr:last-child th,
        table.detail tr:last-child td {
            border-bottom: none;
        }

        .badge-lunas {
            display: inline-block;
            padding: 1px 9px;
            border: 1px solid #15803d;
            border-radius: 999px;
            background: #ecfdf3;
            color: #15803d;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
        }

        .badge-belum {
            display: inline-block;
            padding: 1px 9px;
            border: 1px solid #b45309;
            border-radius: 999px;
            background: #fffbeb;
            color: #b45309;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
        }

        /* ---------------- Kotak jumlah ---------------- */
        .jumlah {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 14px;
            padding: 11px 16px;
            border: 2px solid #1e3c72;
            border-radius: 8px;
            background: linear-gradient(135deg, #f3f7ff 0%, #eaf1fc 100%);
        }

        .jumlah .label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #475569;
        }

        .jumlah .terbilang {
            margin-top: 3px;
            font-size: 10px;
            font-style: italic;
            color: #64748b;
        }

        .jumlah .nilai {
            font-size: 20px;
            font-weight: 700;
            color: #1e3c72;
            white-space: nowrap;
        }

        /* ---------------- Tanda tangan ---------------- */
        .kaki {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-top: 18px;
        }

        .catatan {
            flex: 1 1 auto;
            max-width: 95mm;
            font-size: 9.5px;
            color: #64748b;
        }

        .catatan strong {
            display: block;
            margin-bottom: 2px;
            color: #475569;
        }

        .catatan p {
            margin: 0;
        }

        .ttd {
            flex: 0 0 62mm;
            text-align: center;
            font-size: 11px;
            color: #334155;
        }

        .ttd .tempat {
            margin-bottom: 2px;
        }

        .ttd .jabatan {
            font-weight: 600;
        }

        .ttd .ruang {
            height: 17mm;
        }

        .ttd .nama-ttd {
            display: inline-block;
            min-width: 50mm;
            padding-top: 2px;
            border-top: 1px dotted #94a3b8;
            font-weight: 600;
            color: #111827;
        }

        .garis-bawah {
            margin-top: 14px;
            padding-top: 6px;
            border-top: 1px dashed #cbd5e1;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 8.5px;
            color: #94a3b8;
        }

        /* ---------------- Tombol ---------------- */
        .aksi {
            max-width: 190mm;
            margin: 16px auto 0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .aksi button {
            font: inherit;
            padding: 7px 18px;
            border-radius: 6px;
            border: 1px solid #94a3b8;
            background: #fff;
            color: #334155;
            cursor: pointer;
        }

        .aksi button.utama {
            border-color: #1e3c72;
            background: #1e3c72;
            color: #fff;
        }

        /* ---------------- Cetak ---------------- */
        @page {
            size: A4 portrait;
            margin: 12mm 11mm;
        }

        @media print {
            body {
                padding: 0;
                background: #fff;
                font-size: 11px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .sheet {
                width: auto;
                max-width: none;
                margin: 0;
                padding: 0;
                border-radius: 0;
                box-shadow: none;
            }

            .no-print {
                display: none !important;
            }

            .jumlah,
            .badge-lunas,
            .badge-belum,
            table.detail th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>

<body onload="window.print()">
    <div class="sheet">
        <!-- Kop nota -->
        <header class="kop">
            <img src="dist/assets/img/bg-logo.png" alt="Logo Arya Pengalasan Pusat">
            <div class="kop-teks">
                <p class="nama">Arya Pengalasan Pusat</p>
                <p class="alamat">Bedulu, Gianyar, Bali</p>
                <p class="keterangan">Sistem Informasi Krama Bali &middot; Nota Pembayaran Urunan</p>
            </div>
        </header>

        <!-- Judul & nomor nota -->
        <div class="judul-baris">
            <div>
                <h1>Nota Pembayaran Urunan</h1>
                <p class="sub">Bukti pembayaran iuran odalan anggota</p>
            </div>
            <div class="nomor-nota">
                No. Nota
                <strong><?= $esc($nomor_nota) ?></strong>
                Dicetak: <?= $esc($dicetak) ?>
            </div>
        </div>

        <!-- Data anggota -->
        <div class="blok">
            <p class="blok-judul">Data Anggota</p>
            <table class="detail">
                <tr>
                    <th>Nama</th>
                    <td class="lebar" colspan="3"><?= $esc($data['nama']) ?></td>
                </tr>
                <tr>
                    <th>User ID</th>
                    <td><?= $esc($data['user_id']) ?></td>
                    <th>Jenis Anggota</th>
                    <td><?= $esc($jenis_label) ?></td>
                </tr>
                <tr>
                    <th>Wilayah</th>
                    <td><?= $esc($nama_wilayah) ?></td>
                    <th>Sub Wilayah</th>
                    <td><?= $esc($nama_sub) ?></td>
                </tr>
            </table>
        </div>

        <!-- Rincian pembayaran -->
        <div class="blok">
            <p class="blok-judul">Rincian Pembayaran</p>
            <table class="detail">
                <tr>
                    <th>Odalan</th>
                    <td class="lebar" colspan="3"><?= $esc($odalan['nama_odalan']) ?></td>
                </tr>
                <tr>
                    <th>Tanggal Odalan</th>
                    <td><?= $esc(nota_tanggal_indo((string) $odalan['tanggal'])) ?></td>
                    <th>Tanggal Bayar</th>
                    <td><?= $esc(nota_tanggal_indo($tanggal_bayar) . ' ' . date('H:i', strtotime($tanggal_bayar) ?: time())) ?></td>
                </tr>
                <tr>
                    <th>Metode</th>
                    <td><?= $esc($metode_label) ?></td>
                    <th>Petugas</th>
                    <td><?= $esc($nama_petugas !== '' ? $nama_petugas : '-') ?></td>
                </tr>
                <tr>
                    <th>Tagihan</th>
                    <td><?= $tagihan > 0 ? 'Rp ' . number_format($tagihan, 0, ',', '.') : '-' ?></td>
                    <th>Status</th>
                    <td>
                        <?php if ($masih_lunas): ?>
                            <span class="badge-lunas">Lunas</span>
                        <?php else: ?>
                            <span class="badge-belum">Sisa Rp <?= number_format($sisa_tagihan, 0, ',', '.') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Jumlah dibayar -->
        <div class="jumlah">
            <div>
                <div class="label">Jumlah Dibayar</div>
                <div class="terbilang">Terbilang: <?= $esc(nota_terbilang($jumlah_bayar)) ?></div>
            </div>
            <div class="nilai">Rp <?= number_format($jumlah_bayar, 0, ',', '.') ?></div>
        </div>

        <!-- Catatan & tanda tangan -->
        <div class="kaki">
            <div class="catatan">
                <strong>Catatan</strong>
                <p>Nota ini merupakan bukti pembayaran yang sah untuk iuran odalan
                    <em><?= $esc($odalan['nama_odalan']) ?></em>. Mohon disimpan dengan baik
                    sebagai kelengkapan administrasi. Terima kasih atas partisipasi Anda.</p>
            </div>
            <div class="ttd">
                <div class="tempat">Bedulu, <?= $esc(nota_tanggal_indo($tanggal_bayar)) ?></div>
                <div class="jabatan">Bendahara,</div>
                <div class="ruang"></div>
                <span class="nama-ttd"><?= $esc($nama_petugas !== '' ? $nama_petugas : '(................................)') ?></span>
            </div>
        </div>

        <div class="garis-bawah">
            <span>Arya Pengalasan Pusat &middot; Bedulu, Gianyar, Bali</span>
            <span>Nota <?= $esc($nomor_nota) ?> &middot; dicetak <?= $esc($dicetak) ?></span>
        </div>
    </div>

    <div class="aksi no-print">
        <button type="button" onclick="window.close()">Tutup</button>
        <button type="button" class="utama" onclick="window.print()">Cetak</button>
    </div>
</body>

</html>
