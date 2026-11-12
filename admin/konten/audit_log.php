<?php
/**
 * Penonton jejak audit (`audit_log`).
 *
 * Menampilkan siapa melakukan apa, kapan, pada tabel apa, beserta
 * data lama/baru (JSON). Hanya MEMBACA; tidak menulis apa pun.
 */
require_once __DIR__ . '/../security.php';
kbap_guard_menu('audit_log');

require_once __DIR__ . '/../koneksi.php';

if (!function_exists('kbap_e')) {
    function kbap_e($teks): string
    {
        return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
    }
}

// --- Filter -----------------------------------------------------------
$f_tabel = isset($_GET['tabel']) ? trim((string) $_GET['tabel']) : '';
$f_aksi  = isset($_GET['aksi']) ? trim((string) $_GET['aksi']) : '';
$f_user  = isset($_GET['username']) ? trim((string) $_GET['username']) : '';
$f_dari  = isset($_GET['dari']) ? trim((string) $_GET['dari']) : '';
$f_sampai = isset($_GET['sampai']) ? trim((string) $_GET['sampai']) : '';
$f_cari  = isset($_GET['cari']) ? trim((string) $_GET['cari']) : '';

$where  = [];
$params = [];
$tipe   = '';

if ($f_tabel !== '') {
    $where[]  = 'nama_tabel = ?';
    $params[] = $f_tabel;
    $tipe    .= 's';
}
if ($f_aksi !== '') {
    $where[]  = 'aksi = ?';
    $params[] = $f_aksi;
    $tipe    .= 's';
}
if ($f_user !== '') {
    $where[]  = 'username LIKE ?';
    $params[] = '%' . $f_user . '%';
    $tipe    .= 's';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_dari)) {
    $where[]  = 'waktu >= ?';
    $params[] = $f_dari . ' 00:00:00';
    $tipe    .= 's';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_sampai)) {
    $where[]  = 'waktu <= ?';
    $params[] = $f_sampai . ' 23:59:59';
    $tipe    .= 's';
}
if ($f_cari !== '') {
    $where[]  = '(keterangan LIKE ? OR id_record LIKE ? OR data_lama LIKE ? OR data_baru LIKE ?)';
    for ($i = 0; $i < 4; $i++) {
        $params[] = '%' . $f_cari . '%';
        $tipe    .= 's';
    }
}

$sql_where = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

// --- Hitung total -----------------------------------------------------
$total = 0;
$sql_count = 'SELECT COUNT(*) AS c FROM audit_log' . $sql_where;
if ($stmt = $koneksi->prepare($sql_count)) {
    if ($tipe !== '') {
        $stmt->bind_param($tipe, ...$params);
    }
    $stmt->execute();
    $total = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

// --- Pagination -------------------------------------------------------
$per_halaman = 50;
$halaman = max(1, (int) ($_GET['hal'] ?? 1));
$offset  = ($halaman - 1) * $per_halaman;
$total_halaman = max(1, (int) ceil($total / $per_halaman));
if ($halaman > $total_halaman) {
    $halaman = $total_halaman;
    $offset  = ($halaman - 1) * $per_halaman;
}

// --- Ambil baris ------------------------------------------------------
$rows = [];
$sql = 'SELECT id, nama_tabel, aksi, id_record, id_user, username, waktu, data_lama, data_baru, keterangan, ip
        FROM audit_log' . $sql_where . ' ORDER BY id DESC LIMIT ? OFFSET ?';
if ($stmt = $koneksi->prepare($sql)) {
    $tipe_baris = $tipe . 'ii';
    $params_baris = array_merge($params, [$per_halaman, $offset]);
    $stmt->bind_param($tipe_baris, ...$params_baris);
    $stmt->execute();
    if ($res = $stmt->get_result()) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    $stmt->close();
}

// --- Daftar nilai untuk dropdown --------------------------------------
$tabel_ada = [];
if ($res = $koneksi->query('SELECT DISTINCT nama_tabel FROM audit_log ORDER BY nama_tabel')) {
    while ($r = $res->fetch_assoc()) {
        $tabel_ada[] = $r['nama_tabel'];
    }
    $res->free();
}
$aksi_ada = [];
if ($res = $koneksi->query('SELECT DISTINCT aksi FROM audit_log ORDER BY aksi')) {
    while ($r = $res->fetch_assoc()) {
        $aksi_ada[] = $r['aksi'];
    }
    $res->free();
}

// Warna badge per aksi
if (!function_exists('kbap_warna_aksi')) {
    function kbap_warna_aksi(string $aksi): string
    {
        switch (strtoupper($aksi)) {
            case 'INSERT':
                return 'bg-success';
            case 'UPDATE':
                return 'bg-primary';
            case 'DELETE':
                return 'bg-danger';
            case 'CANCEL':
                return 'bg-warning text-dark';
            case 'LOGIN':
                return 'bg-info text-dark';
            default:
                return 'bg-secondary';
        }
    }
}

// Bangun URL filter tanpa parameter tertentu (untuk pagination)
if (!function_exists('kbap_url_filter')) {
    function kbap_url_filter(array $ganti = []): string
    {
        $q = array_merge([
            'menu'     => 'audit_log',
            'tabel'    => $_GET['tabel'] ?? '',
            'aksi'     => $_GET['aksi'] ?? '',
            'username' => $_GET['username'] ?? '',
            'dari'     => $_GET['dari'] ?? '',
            'sampai'   => $_GET['sampai'] ?? '',
            'cari'     => $_GET['cari'] ?? '',
        ], $ganti);
        $q = array_filter($q, static function ($v) {
            return $v !== '' && $v !== null;
        });
        return 'index.php?' . http_build_query($q);
    }
}
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h3 class="mb-1"><i class="bi bi-clock-history text-primary me-2"></i>Jejak Audit</h3>
                    <p class="text-muted mb-0">Riwayat perubahan data: siapa, kapan, tabel apa, dan nilai lama/baru.</p>
                </div>
                <a href="index.php?menu=audit_log" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-clockwise"></i> Muat ulang
                </a>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <form method="GET" action="index.php" class="row g-2 align-items-end">
                        <input type="hidden" name="menu" value="audit_log">
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Tabel</label>
                            <select name="tabel" class="form-select form-select-sm">
                                <option value="">Semua</option>
                                <?php foreach ($tabel_ada as $t): ?>
                                    <option value="<?= kbap_e($t) ?>" <?= $f_tabel === $t ? 'selected' : '' ?>><?= kbap_e($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Aksi</label>
                            <select name="aksi" class="form-select form-select-sm">
                                <option value="">Semua</option>
                                <?php foreach ($aksi_ada as $a): ?>
                                    <option value="<?= kbap_e($a) ?>" <?= $f_aksi === $a ? 'selected' : '' ?>><?= kbap_e($a) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Username</label>
                            <input type="text" name="username" class="form-control form-control-sm" value="<?= kbap_e($f_user) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Dari tanggal</label>
                            <input type="date" name="dari" class="form-control form-control-sm" value="<?= kbap_e($f_dari) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Sampai tanggal</label>
                            <input type="date" name="sampai" class="form-control form-control-sm" value="<?= kbap_e($f_sampai) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Cari teks</label>
                            <input type="text" name="cari" class="form-control form-control-sm" value="<?= kbap_e($f_cari) ?>" placeholder="keterangan / id">
                        </div>
                        <div class="col-12 d-flex gap-2 mt-2">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Terapkan</button>
                            <a href="index.php?menu=audit_log" class="btn btn-outline-secondary btn-sm">Reset</a>
                            <span class="ms-auto small text-muted align-self-center">
                                <?= number_format($total, 0, ',', '.') ?> baris ditemukan
                            </span>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:60px;">#</th>
                                <th style="width:150px;">Waktu</th>
                                <th style="width:110px;">Aksi</th>
                                <th>Tabel / Record</th>
                                <th>Pelaku</th>
                                <th>Keterangan</th>
                                <th style="width:120px;">IP</th>
                                <th style="width:90px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        Belum ada jejak audit.
                                        <?php if ($total === 0 && $sql_where === ''): ?>
                                            <div class="small mt-1">Catatan akan muncul setelah ada aksi simpan/ubah/hapus di aplikasi.</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $i => $r): ?>
                                    <tr>
                                        <td class="text-muted small"><?= $offset + $i + 1 ?></td>
                                        <td class="small"><?= kbap_e($r['waktu']) ?></td>
                                        <td>
                                            <span class="badge <?= kbap_warna_aksi((string) $r['aksi']) ?>"><?= kbap_e($r['aksi']) ?></span>
                                        </td>
                                        <td class="small">
                                            <code><?= kbap_e($r['nama_tabel']) ?></code>
                                            <?php if (!empty($r['id_record'])): ?>
                                                <span class="text-muted">#<?= kbap_e($r['id_record']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <?= kbap_e($r['username'] ?: '-') ?>
                                            <?php if (!empty($r['id_user'])): ?>
                                                <div class="text-muted">id <?= (int) $r['id_user'] ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?= kbap_e($r['keterangan'] ?: '-') ?></td>
                                        <td class="small text-muted"><?= kbap_e($r['ip'] ?: '-') ?></td>
                                        <td class="text-end">
                                            <?php if (!empty($r['data_lama']) || !empty($r['data_baru'])): ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    data-bs-toggle="modal" data-bs-target="#modalAudit"
                                                    data-judul="<?= kbap_e($r['nama_tabel'] . ' #' . $r['id_record'] . ' — ' . $r['aksi']) ?>"
                                                    data-lama="<?= kbap_e($r['data_lama'] ?? '') ?>"
                                                    data-baru="<?= kbap_e($r['data_baru'] ?? '') ?>">
                                                    Detail
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="small text-muted">
                        Halaman <?= (int) $halaman ?> dari <?= (int) $total_halaman ?>
                        &bull; maksimal <?= (int) $per_halaman ?> baris per halaman
                    </div>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $halaman <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= kbap_e(kbap_url_filter(['hal' => max(1, $halaman - 1)])) ?>">&laquo;</a>
                            </li>
                            <?php
                            $mulai = max(1, $halaman - 2);
                            $akhir = min($total_halaman, $halaman + 2);
                            for ($p = $mulai; $p <= $akhir; $p++):
                            ?>
                                <li class="page-item <?= $p === $halaman ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= kbap_e(kbap_url_filter(['hal' => $p])) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $halaman >= $total_halaman ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= kbap_e(kbap_url_filter(['hal' => min($total_halaman, $halaman + 1)])) ?>">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>

        </div>
    </div>
</main>

<div class="modal fade" id="modalAudit" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="auditJudul">Detail Audit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h6 class="text-muted small text-uppercase">Data Lama</h6>
                <pre class="bg-light p-2 rounded small" id="auditLama" style="max-height:240px; overflow:auto;">-</pre>
                <h6 class="text-muted small text-uppercase mt-3">Data Baru</h6>
                <pre class="bg-light p-2 rounded small" id="auditBaru" style="max-height:240px; overflow:auto;">-</pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var modal = document.getElementById('modalAudit');
        if (!modal) return;

        function rapikan(teks) {
            if (!teks) return '-';
            try {
                return JSON.stringify(JSON.parse(teks), null, 2);
            } catch (e) {
                return teks;
            }
        }

        modal.addEventListener('show.bs.modal', function(event) {
            var btn = event.relatedTarget;
            if (!btn) return;
            var d = btn.dataset;
            document.getElementById('auditJudul').textContent = d.judul || 'Detail Audit';
            document.getElementById('auditLama').textContent = rapikan(d.lama);
            document.getElementById('auditBaru').textContent = rapikan(d.baru);
        });
    });
</script>
