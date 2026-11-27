<?php
/**
 * pembayaran_massal.php
 *
 * Kasir kolektif: mencatat pembayaran urunan untuk BEBERAPA semeton sekaligus
 * dalam satu wilayah pada satu odalan (mis. saat penagihan di banjar).
 *
 * Setiap semeton tetap mendapat satu baris `pembayaran` sendiri (nomor nota
 * masing-masing) lewat aksi/proses_pembayaran_massal.php. Halaman ini hanya
 * menampilkan daftar tagihan dan mengirim id semeton yang dipilih.
 *
 * Tanpa perubahan skema. Nominal tidak bisa diubah di sini: server selalu
 * memakai sisa `hutang.jumlah_hutang`.
 */

require_once __DIR__ . '/../security.php';
kbap_guard_menu('pembayaran_massal');

require_once __DIR__ . '/../koneksi.php';

$esc = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$rp = static function ($v): string {
    return 'Rp ' . number_format((float) $v, 0, ',', '.');
};

// ---------------------------------------------------------------------
// Daftar odalan & wilayah
// ---------------------------------------------------------------------
$odalan_list = [];
if ($res = mysqli_query($koneksi, "SELECT id, nama_odalan, tanggal, iuran_ngarep, iuran_ngempi
                                   FROM odalan ORDER BY tanggal DESC, id DESC")) {
    while ($r = mysqli_fetch_assoc($res)) {
        $odalan_list[] = $r;
    }
}

$wilayah_list = [];
if ($res = mysqli_query($koneksi, "SELECT id_wilayah, nama_wilayah FROM wilayah ORDER BY nama_wilayah ASC")) {
    while ($r = mysqli_fetch_assoc($res)) {
        $wilayah_list[] = $r;
    }
}

// Sub wilayah dipakai untuk mempersempit daftar (mis. satu banjar/dusun).
$sub_list = [];
if ($res = mysqli_query($koneksi, "SELECT id_sub, id_wilayah, nama_sub FROM sub_wilayah ORDER BY nama_sub ASC")) {
    while ($r = mysqli_fetch_assoc($res)) {
        $sub_list[] = $r;
    }
}

// ---------------------------------------------------------------------
// Filter
// ---------------------------------------------------------------------
$f_odalan = (isset($_GET['id_odalan']) && is_scalar($_GET['id_odalan'])) ? (int) $_GET['id_odalan'] : 0;
$id_odalan_sah = array_map('intval', array_column($odalan_list, 'id'));
if ($f_odalan <= 0 || !in_array($f_odalan, $id_odalan_sah, true)) {
    // Default: odalan terbaru.
    $f_odalan = $odalan_list ? (int) $odalan_list[0]['id'] : 0;
}

$f_wilayah = (isset($_GET['wilayah']) && is_scalar($_GET['wilayah'])) ? (int) $_GET['wilayah'] : 0;
$id_wilayah_sah = array_map('intval', array_column($wilayah_list, 'id_wilayah'));
if ($f_wilayah > 0 && !in_array($f_wilayah, $id_wilayah_sah, true)) {
    $f_wilayah = 0;
}

// Sub wilayah harus benar-benar milik wilayah yang dipilih.
$f_sub = (isset($_GET['sub']) && is_scalar($_GET['sub'])) ? (int) $_GET['sub'] : 0;
if ($f_sub > 0) {
    $sub_cocok = false;
    foreach ($sub_list as $sw) {
        if ((int) $sw['id_sub'] === $f_sub) {
            $sub_cocok = ((int) $sw['id_wilayah'] === $f_wilayah);
            break;
        }
    }
    if (!$sub_cocok) {
        $f_sub = 0;
    }
}

$f_q = (isset($_GET['q']) && is_string($_GET['q'])) ? trim($_GET['q']) : '';
$f_tampil = (isset($_GET['tampil']) && is_string($_GET['tampil'])) ? $_GET['tampil'] : 'belum';
if (!in_array($f_tampil, ['belum', 'semua'], true)) {
    $f_tampil = 'belum';
}

$odalan_aktif = null;
foreach ($odalan_list as $o) {
    if ((int) $o['id'] === $f_odalan) {
        $odalan_aktif = $o;
        break;
    }
}
$wilayah_aktif = null;
foreach ($wilayah_list as $w) {
    if ((int) $w['id_wilayah'] === $f_wilayah) {
        $wilayah_aktif = $w;
        break;
    }
}

$sub_aktif         = null;
$sub_untuk_wilayah = [];
$sub_json          = [];
foreach ($sub_list as $sw) {
    if ((int) $sw['id_wilayah'] === $f_wilayah) {
        $sub_untuk_wilayah[] = $sw;
    }
    if ($f_sub > 0 && (int) $sw['id_sub'] === $f_sub) {
        $sub_aktif = $sw;
    }
    // Data untuk mengisi dropdown sub wilayah di sisi klien (tanpa reload).
    $sub_json[(int) $sw['id_wilayah']][] = [
        'id'   => (int) $sw['id_sub'],
        'nama' => (string) $sw['nama_sub'],
    ];
}

// ---------------------------------------------------------------------
// Statistik (wilayah + odalan; tidak terpengaruh pencarian/opsi tampil)
// ---------------------------------------------------------------------
$stat = [
    'jml'     => 0,
    'lunas'   => 0,
    'belum'   => 0,
    'sisa'    => 0.0,
    'tagihan' => 0.0,
];
if ($f_wilayah > 0 && $f_odalan > 0) {
    $sql_stat = "SELECT COUNT(*) AS jml,
                        SUM(CASE WHEN h.status = 'lunas' OR h.jumlah_hutang <= 0 THEN 1 ELSE 0 END) AS lunas,
                        SUM(CASE WHEN h.status <> 'lunas' AND h.jumlah_hutang > 0 THEN 1 ELSE 0 END) AS belum,
                        COALESCE(SUM(CASE WHEN h.status <> 'lunas' AND h.jumlah_hutang > 0
                                          THEN h.jumlah_hutang ELSE 0 END), 0) AS sisa,
                        COALESCE(SUM(h.jumlah_tagihan_asli), 0) AS tagihan
                 FROM semeton s
                 JOIN hutang h ON h.id_semeton = s.id AND h.id_odalan = ?
                 WHERE s.wilayah = ?";
    $tipe_stat  = 'ii';
    $nilai_stat = [$f_odalan, $f_wilayah];
    if ($f_sub > 0) {
        $sql_stat   .= ' AND s.sub_wilayah = ?';
        $tipe_stat  .= 'i';
        $nilai_stat[] = $f_sub;
    }
    if ($stmt = $koneksi->prepare($sql_stat)) {
        $stmt->bind_param($tipe_stat, ...$nilai_stat);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $stat['jml']     = (int) $row['jml'];
            $stat['lunas']   = (int) $row['lunas'];
            $stat['belum']   = (int) $row['belum'];
            $stat['sisa']    = (float) $row['sisa'];
            $stat['tagihan'] = (float) $row['tagihan'];
        }
        $stmt->close();
    }
}

// ---------------------------------------------------------------------
// Daftar tagihan per semeton
// ---------------------------------------------------------------------
$data_rows = [];
if ($f_wilayah > 0 && $f_odalan > 0) {
    $sql = "SELECT s.id, s.user_id, s.nama, s.jenis_user, s.status AS status_semeton,
                   w.nama_wilayah, sw.nama_sub,
                   h.id AS id_hutang, h.jumlah_hutang, h.jumlah_tagihan_asli, h.status,
                   p.id_pembayaran, p.tanggal_bayar, p.metode_pembayaran
            FROM semeton s
            JOIN hutang h ON h.id_semeton = s.id AND h.id_odalan = ?
            LEFT JOIN wilayah w ON w.id_wilayah = s.wilayah
            LEFT JOIN sub_wilayah sw ON sw.id_sub = s.sub_wilayah
            LEFT JOIN pembayaran p ON p.id_semeton = s.id AND p.id_piodalan = h.id_odalan
                                   AND p.dibatalkan_at IS NULL
            WHERE s.wilayah = ?";
    $tipe  = 'ii';
    $nilai = [$f_odalan, $f_wilayah];

    if ($f_sub > 0) {
        $sql    .= ' AND s.sub_wilayah = ?';
        $tipe   .= 'i';
        $nilai[] = $f_sub;
    }

    if ($f_q !== '') {
        $sql    .= ' AND (s.nama LIKE ? OR s.user_id LIKE ?)';
        $like    = '%' . $f_q . '%';
        $tipe   .= 'ss';
        $nilai[] = $like;
        $nilai[] = $like;
    }
    if ($f_tampil === 'belum') {
        $sql .= " AND h.status <> 'lunas' AND h.jumlah_hutang > 0";
    }
    $sql .= ' ORDER BY sw.nama_sub ASC, s.nama ASC';

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

$url_reset = 'index.php?menu=pembayaran_massal';
?>

<main class="app-main">
    <div class="app-content-header py-4">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h3 class="fw-bold mb-1 text-dark">Pembayaran Massal per Wilayah</h3>
                    <p class="text-muted small mb-0">
                        Catat urunan beberapa semeton sekaligus saat penagihan kolektif.
                        Setiap semeton tetap mendapat nota tersendiri.
                    </p>
                </div>
                <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                    <a href="index.php?menu=pembayaran" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Daftar Pembayaran
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <!-- ================= FILTER ================= -->
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-body py-3">
                    <form method="GET" action="index.php" class="row g-2 align-items-end">
                        <input type="hidden" name="menu" value="pembayaran_massal">

                        <div class="col-12 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="f-odalan">Odalan</label>
                            <select id="f-odalan" name="id_odalan" class="form-select">
                                <?php foreach ($odalan_list as $o) : ?>
                                    <option value="<?= (int) $o['id'] ?>" <?= $f_odalan === (int) $o['id'] ? 'selected' : '' ?>>
                                        <?= $esc($o['nama_odalan']) ?>
                                        (<?= date('d-m-Y', strtotime((string) $o['tanggal']) ?: time()) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="f-wilayah">Wilayah</label>
                            <select id="f-wilayah" name="wilayah" class="form-select" required>
                                <option value="">&mdash; Pilih wilayah &mdash;</option>
                                <?php foreach ($wilayah_list as $w) : ?>
                                    <option value="<?= (int) $w['id_wilayah'] ?>" <?= $f_wilayah === (int) $w['id_wilayah'] ? 'selected' : '' ?>>
                                        <?= $esc($w['nama_wilayah']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="f-sub">Sub Wilayah</label>
                            <select id="f-sub" name="sub" class="form-select" <?= $f_wilayah > 0 ? '' : 'disabled' ?>>
                                <option value="">
                                    <?= $f_wilayah > 0 ? '&mdash; Semua sub wilayah &mdash;' : '&mdash; Pilih wilayah dulu &mdash;' ?>
                                </option>
                                <?php foreach ($sub_untuk_wilayah as $sw) : ?>
                                    <option value="<?= (int) $sw['id_sub'] ?>" <?= $f_sub === (int) $sw['id_sub'] ? 'selected' : '' ?>>
                                        <?= $esc($sw['nama_sub']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="f-q">Cari</label>
                            <input type="search" id="f-q" name="q" class="form-control"
                                value="<?= $esc($f_q) ?>" placeholder="Nama / ID">
                        </div>

                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="f-tampil">Tampilkan</label>
                            <select id="f-tampil" name="tampil" class="form-select">
                                <option value="belum" <?= $f_tampil === 'belum' ? 'selected' : '' ?>>Belum lunas saja</option>
                                <option value="semua" <?= $f_tampil === 'semua' ? 'selected' : '' ?>>Semua (termasuk lunas)</option>
                            </select>
                        </div>

                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-1"></i> Tampilkan
                            </button>
                            <?php if ($f_wilayah > 0 || $f_sub > 0 || $f_q !== '' || $f_tampil !== 'belum') : ?>
                                <a href="<?= $esc($url_reset) ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-rotate-left me-1"></i> Reset
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($f_wilayah <= 0) : ?>
                <div class="alert alert-info d-flex align-items-center">
                    <i class="fas fa-circle-info fs-4 me-3"></i>
                    <div>
                        Pilih <strong>odalan</strong> dan <strong>wilayah</strong> terlebih dahulu,
                        lalu klik <strong>Tampilkan</strong> untuk melihat daftar tagihan anggota.
                    </div>
                </div>
            <?php elseif (!$odalan_aktif || !$wilayah_aktif) : ?>
                <div class="alert alert-warning">Data odalan atau wilayah tidak ditemukan.</div>
            <?php else : ?>

                <!-- ================= RINGKASAN ================= -->
                <div class="row g-2 mb-3">
                    <div class="col-6 col-lg-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-3">
                                <div class="text-muted small">Anggota bertagihan</div>
                                <div class="fs-4 fw-bold"><?= number_format($stat['jml'], 0, ',', '.') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-3">
                                <div class="text-muted small">Belum lunas</div>
                                <div class="fs-4 fw-bold text-danger"><?= number_format($stat['belum'], 0, ',', '.') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-3">
                                <div class="text-muted small">Sudah lunas</div>
                                <div class="fs-4 fw-bold text-success"><?= number_format($stat['lunas'], 0, ',', '.') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-3">
                                <div class="text-muted small">Sisa tagihan</div>
                                <div class="fs-5 fw-bold text-danger"><?= $rp($stat['sisa']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (empty($data_rows)) : ?>
                    <div class="alert alert-success d-flex align-items-center">
                        <i class="fas fa-circle-check fs-4 me-3"></i>
                        <div>
                            Tidak ada tagihan yang cocok.
                            <?php if ($f_tampil === 'belum' && $stat['belum'] === 0) : ?>
                                Semua anggota <?= $sub_aktif ? 'sub wilayah' : 'wilayah' ?>
                                <strong><?= $esc($sub_aktif ? $sub_aktif['nama_sub'] : $wilayah_aktif['nama_wilayah']) ?></strong>
                                sudah lunas untuk odalan ini.
                            <?php else : ?>
                                Coba ubah kata kunci pencarian atau pilih "Semua (termasuk lunas)".
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else : ?>

                    <!-- ================= FORM PEMBAYARAN MASSAL ================= -->
                    <form method="POST" action="aksi/proses_pembayaran_massal.php" id="formMassal">
                        <?= kbap_csrf_field() ?>
                        <input type="hidden" name="id_piodalan" value="<?= (int) $f_odalan ?>">
                        <input type="hidden" name="wilayah" value="<?= (int) $f_wilayah ?>">
                        <input type="hidden" name="sub" value="<?= (int) $f_sub ?>">

                        <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
                            <div class="card-header bg-white py-3">
                                <div class="row g-2 align-items-end">
                                    <div class="col-12 col-lg-5">
                                        <div class="d-flex flex-wrap gap-2 align-items-center">
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnPilihSemua">
                                                <i class="fas fa-check-double me-1"></i> Pilih semua belum lunas
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnKosongkan">
                                                <i class="fas fa-xmark me-1"></i> Kosongkan
                                            </button>
                                            <span class="badge bg-primary" id="badgePilih">0 dipilih</span>
                                        </div>
                                    </div>
                                    <div class="col-6 col-lg-3">
                                        <label class="form-label small text-muted mb-1" for="f-tgl">Tanggal bayar</label>
                                        <input type="datetime-local" class="form-control" id="f-tgl"
                                            name="tgl_bayar" value="<?= date('Y-m-d\TH:i') ?>" required>
                                    </div>
                                    <div class="col-6 col-lg-2">
                                        <label class="form-label small text-muted mb-1" for="f-metode">Metode</label>
                                        <select class="form-select" id="f-metode" name="metode_pembayaran" required>
                                            <option value="tunai">Tunai</option>
                                            <option value="transfer">Transfer</option>
                                            <option value="QRIS">QRIS</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-lg-2">
                                        <label class="form-label small text-muted mb-1">Total dipilih</label>
                                        <div class="fw-bold text-primary" id="totalPilih">Rp 0</div>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="tabelMassal">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:40px;" class="text-center">
                                                <input type="checkbox" class="form-check-input" id="cekSemua"
                                                    title="Pilih semua yang belum lunas">
                                            </th>
                                            <th>ID</th>
                                            <th>Nama</th>
                                            <th>Sub Wilayah</th>
                                            <th class="text-end">Tagihan</th>
                                            <th class="text-end">Sisa</th>
                                            <th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($data_rows as $row) :
                                            $lunas = ($row['status'] === 'lunas' || (float) $row['jumlah_hutang'] <= 0);
                                            $sisa  = $lunas ? 0.0 : (float) $row['jumlah_hutang'];
                                            ?>
                                            <tr class="<?= $lunas ? 'table-light text-muted' : '' ?>">
                                                <td class="text-center">
                                                    <input type="checkbox"
                                                        class="form-check-input cek-bayar"
                                                        name="id_semeton[]"
                                                        value="<?= (int) $row['id'] ?>"
                                                        data-nominal="<?= (float) $sisa ?>"
                                                        data-nama="<?= $esc($row['nama']) ?>"
                                                        <?= $lunas ? 'disabled' : '' ?>>
                                                </td>
                                                <td class="fw-bold"><?= $esc($row['user_id']) ?></td>
                                                <td>
                                                    <div class="fw-semibold"><?= $esc($row['nama']) ?></div>
                                                    <span class="badge <?= $row['jenis_user'] === 'ngarep' ? 'bg-success' : ($row['jenis_user'] === 'ngempi' ? 'bg-primary' : 'bg-secondary') ?>"
                                                        style="font-size:10px;"><?= $esc(strtoupper((string) $row['jenis_user'])) ?></span>
                                                    <?php if (($row['status_semeton'] ?? '') === 'tidak_aktif') : ?>
                                                        <span class="badge bg-warning text-dark" style="font-size:10px;">NONAKTIF</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $esc($row['nama_sub'] ?? '-') ?></td>
                                                <td class="text-end"><?= $rp($row['jumlah_tagihan_asli']) ?></td>
                                                <td class="text-end fw-bold <?= $lunas ? 'text-success' : 'text-danger' ?>">
                                                    <?= $lunas ? 'Lunas' : $rp($sisa) ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($lunas) : ?>
                                                        <span class="badge rounded-pill bg-success">Lunas</span>
                                                        <?php if (!empty($row['id_pembayaran'])) : ?>
                                                            <div class="small text-muted mt-1">
                                                                <?= $esc(ucfirst((string) $row['metode_pembayaran'])) ?>
                                                                &middot;
                                                                <?= date('d-m-Y', strtotime((string) $row['tanggal_bayar']) ?: time()) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php else : ?>
                                                        <span class="badge rounded-pill bg-danger">Belum</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="card-footer bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="small text-muted">
                                    Odalan <strong><?= $esc($odalan_aktif['nama_odalan']) ?></strong>
                                    &middot; Wilayah <strong><?= $esc($wilayah_aktif['nama_wilayah']) ?></strong>
                                    <?php if ($sub_aktif) : ?>
                                        &middot; Sub <strong><?= $esc($sub_aktif['nama_sub']) ?></strong>
                                    <?php endif; ?>
                                    &middot; <?= number_format(count($data_rows), 0, ',', '.') ?> baris tampil
                                </div>
                                <button type="submit" class="btn btn-success px-4" id="btnMassal" disabled>
                                    <i class="fas fa-money-bill-wave me-1"></i>
                                    <span id="labelMassal">Bayar 0 Semeton</span>
                                </button>
                            </div>
                        </div>
                    </form>

                <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>
</main>

<script>
    // Isi dropdown sub wilayah mengikuti wilayah terpilih, tanpa reload.
    (function() {
        var data    = <?= json_encode($sub_json, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var wilayah = document.getElementById('f-wilayah');
        var sub     = document.getElementById('f-sub');
        if (!wilayah || !sub) return;

        function isi(pertahankan) {
            var idWilayah = wilayah.value;
            var lama = pertahankan ? sub.value : '';

            sub.innerHTML = '';
            var kosong = document.createElement('option');
            kosong.value = '';
            kosong.textContent = idWilayah ? '\u2014 Semua sub wilayah \u2014' : '\u2014 Pilih wilayah dulu \u2014';
            sub.appendChild(kosong);

            if (idWilayah && data[idWilayah]) {
                data[idWilayah].forEach(function(s) {
                    var o = document.createElement('option');
                    o.value = s.id;
                    o.textContent = s.nama;
                    if (pertahankan && String(s.id) === String(lama)) {
                        o.selected = true;
                    }
                    sub.appendChild(o);
                });
            }
            sub.disabled = !idWilayah;
        }

        wilayah.addEventListener('change', function() { isi(false); });
        isi(true);
    })();
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var form      = document.getElementById('formMassal');
        if (!form) return;

        var cekSemua  = document.getElementById('cekSemua');
        var btnSemua  = document.getElementById('btnPilihSemua');
        var btnKosong = document.getElementById('btnKosongkan');
        var btnBayar  = document.getElementById('btnMassal');
        var badge     = document.getElementById('badgePilih');
        var totalEl   = document.getElementById('totalPilih');
        var labelEl   = document.getElementById('labelMassal');

        function daftar() {
            return Array.prototype.slice.call(document.querySelectorAll('.cek-bayar:not(:disabled)'));
        }

        function rupiah(n) {
            return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(Number(n) || 0));
        }

        function hitung() {
            var n = 0, total = 0;
            daftar().forEach(function(c) {
                if (c.checked) {
                    n++;
                    total += Number(c.dataset.nominal) || 0;
                }
            });
            if (badge)   badge.textContent = n + ' dipilih';
            if (totalEl) totalEl.textContent = rupiah(total);
            if (labelEl) labelEl.textContent = 'Bayar ' + n + ' Semeton';
            if (btnBayar) btnBayar.disabled = n === 0;

            if (cekSemua) {
                var semua = daftar();
                var terpilih = semua.filter(function(c) { return c.checked; }).length;
                cekSemua.checked = semua.length > 0 && terpilih === semua.length;
                cekSemua.indeterminate = terpilih > 0 && terpilih < semua.length;
            }
        }

        document.querySelectorAll('.cek-bayar').forEach(function(c) {
            c.addEventListener('change', hitung);
        });

        if (cekSemua) {
            cekSemua.addEventListener('change', function() {
                daftar().forEach(function(c) { c.checked = cekSemua.checked; });
                hitung();
            });
        }

        if (btnSemua) {
            btnSemua.addEventListener('click', function() {
                daftar().forEach(function(c) { c.checked = true; });
                hitung();
            });
        }

        if (btnKosong) {
            btnKosong.addEventListener('click', function() {
                document.querySelectorAll('.cek-bayar').forEach(function(c) { c.checked = false; });
                hitung();
            });
        }

        // Konfirmasi sebelum mengirim (pakai Swal bila tersedia).
        form.addEventListener('submit', function(e) {
            if (form.dataset.kirim === '1') {
                return; // lanjutkan submit yang sudah dikonfirmasi
            }
            e.preventDefault();

            var n = 0, total = 0;
            daftar().forEach(function(c) {
                if (c.checked) {
                    n++;
                    total += Number(c.dataset.nominal) || 0;
                }
            });
            if (n === 0) {
                return;
            }

            var lanjut = function() {
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }
                form.dataset.kirim = '1';
                if (btnBayar) {
                    btnBayar.disabled = true;
                    btnBayar.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Memproses...';
                }
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            };

            if (typeof Swal === 'undefined') {
                if (window.confirm('Catat pembayaran ' + n + ' semeton sebesar ' + rupiah(total) + '?')) {
                    lanjut();
                }
                return;
            }

            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi Pembayaran Massal',
                html: 'Catat pembayaran <strong>' + n + ' semeton</strong> sebesar <strong>' + rupiah(total) + '</strong>?<br>'
                    + '<span class="text-muted small">Setiap semeton akan mendapat nota tersendiri.</span>',
                showCancelButton: true,
                confirmButtonText: 'Ya, Bayar',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#198754'
            }).then(function(hasil) {
                if (hasil.isConfirmed) {
                    lanjut();
                }
            });
        });

        // Kembalikan tombol saat halaman dipulihkan dari cache (tombol Back).
        window.addEventListener('pageshow', function() {
            if (form.dataset.kirim === '1') {
                form.dataset.kirim = '';
                if (btnBayar) {
                    btnBayar.innerHTML = '<i class="fas fa-money-bill-wave me-1"></i><span id="labelMassal">Bayar 0 Semeton</span>';
                    labelEl = document.getElementById('labelMassal');
                }
                hitung();
            }
        });

        hitung();
    });
</script>
