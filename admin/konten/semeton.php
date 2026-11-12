<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('semeton'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// OPTIMASI: semua query referensi dijalankan SEKALI di luar loop.
// Sebelumnya `SELECT * FROM wilayah` dieksekusi 607 kali (N+1) dan
// 2 modal per anggota (1.215 modal, ±12 MB HTML) dirender per baris.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

// Daftar wilayah (23 baris)
$wilayah_list = [];
$q_wilayah = mysqli_query($koneksi, "SELECT id_wilayah, nama_wilayah FROM wilayah ORDER BY nama_wilayah ASC");
if ($q_wilayah) {
    while ($r = mysqli_fetch_assoc($q_wilayah)) {
        $wilayah_list[] = $r;
    }
}

// Sub wilayah dikelompokkan per wilayah (85 baris) untuk filter sisi klien
$sub_by_wilayah = [];
$q_sub = mysqli_query($koneksi, "SELECT id_sub, id_wilayah, nama_sub FROM sub_wilayah ORDER BY nama_sub ASC");
if ($q_sub) {
    while ($r = mysqli_fetch_assoc($q_sub)) {
        $sub_by_wilayah[(int) $r['id_wilayah']][] = [
            'id' => (int) $r['id_sub'],
            'nama' => $r['nama_sub'],
        ];
    }
}
$sub_json = json_encode(
    $sub_by_wilayah,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

// Daftar kartu yang sudah ada, dibaca sekali (bukan 607 x file_exists)
$kartu_set = [];
foreach (glob(__DIR__ . '/../kartu_anggota/kartu_*.jpg') ?: [] as $f) {
    $kartu_set[basename($f)] = true;
}

// Pilihan jabatan (dipakai modal edit)
$jabatan_list = [
    'Ketua Umum', 'Wakil Ketua Umum', 'Ketua', 'Sekretaris Umum', 'Sekretaris',
    'Bendahara Umum', 'Bendahara', 'Korwil', 'Anggota',
];

// =====================================================================
// PAGINATION SERVER-SIDE (2026-11-08)
// Sebelumnya seluruh 607 baris dirender sekaligus (±3,7 MB HTML).
// Sekarang satu halaman = satu query LIMIT/OFFSET, dan pencarian/filter
// dijalankan di SQL (bukan DataTables sisi klien yang hanya akan mencari
// di halaman yang sedang tampil).
// Parameter: q (teks), wilayah (id), status (aktif/tidak_aktif),
//            per (0=semua, 25, 50, 100, 250), hal (nomor halaman).
// =====================================================================
$f_q       = (isset($_GET['q']) && is_string($_GET['q'])) ? trim($_GET['q']) : '';
$f_wilayah = (isset($_GET['wilayah']) && is_scalar($_GET['wilayah'])) ? (int) $_GET['wilayah'] : 0;
$f_status  = (isset($_GET['status']) && is_string($_GET['status'])) ? $_GET['status'] : '';
if (!in_array($f_status, ['aktif', 'tidak_aktif'], true)) {
    $f_status = '';
}

// Filter jenis kelamin (kolom baru 2026-11-11). '' = semua.
$f_jk = (isset($_GET['jk']) && is_string($_GET['jk'])) ? $_GET['jk'] : '';
if (!in_array($f_jk, ['L', 'P'], true)) {
    $f_jk = '';
}

$per = (isset($_GET['per']) && is_scalar($_GET['per'])) ? (int) $_GET['per'] : 50;
if (!in_array($per, [0, 25, 50, 100, 250], true)) {
    $per = 50;
}

$hal = (isset($_GET['hal']) && is_scalar($_GET['hal'])) ? (int) $_GET['hal'] : 1;
if ($hal < 1) {
    $hal = 1;
}

// --- WHERE dinamis (prepared statement) ---
$where = [];
$tipe  = '';
$nilai = [];
if ($f_q !== '') {
    $where[] = '(s.nama LIKE ? OR s.user_id LIKE ? OR s.nik LIKE ? OR s.no_hp LIKE ? OR s.no_kk LIKE ?)';
    $like    = '%' . $f_q . '%';
    $tipe   .= 'sssss';
    array_push($nilai, $like, $like, $like, $like, $like);
}
if ($f_wilayah > 0) {
    $where[]  = 's.wilayah = ?';
    $tipe    .= 'i';
    $nilai[]  = $f_wilayah;
}
if ($f_status !== '') {
    $where[]  = 's.status = ?';
    $tipe    .= 's';
    $nilai[]  = $f_status;
}
if ($f_jk !== '') {
    $where[]  = 's.jenis_kelamin = ?';
    $tipe    .= 's';
    $nilai[]  = $f_jk;
}
$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// LEFT JOIN: anggota tanpa wilayah / sub wilayah tetap tampil (dulu INNER JOIN
// membuat baris seperti itu hilang dari tabel).
$sql_dari = "FROM semeton s
             LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
             LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub";

// --- Total baris sesuai filter ---
$jumlah_filter = 0;
if ($stmt = $koneksi->prepare("SELECT COUNT(*) AS c $sql_dari$where_sql")) {
    if ($tipe !== '') {
        $stmt->bind_param($tipe, ...$nilai);
    }
    $stmt->execute();
    $jumlah_filter = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

// --- Total seluruh anggota (badge), tanpa filter ---
$total_semeton = 0;
if ($res_total = $koneksi->query("SELECT COUNT(*) AS c FROM semeton")) {
    $total_semeton = (int) ($res_total->fetch_assoc()['c'] ?? 0);
    $res_total->free();
}

// --- Normalisasi halaman ---
$total_hal = ($per > 0) ? max(1, (int) ceil($jumlah_filter / $per)) : 1;
if ($hal > $total_hal) {
    $hal = $total_hal;
}
$offset = ($per > 0) ? ($hal - 1) * $per : 0;

// --- Ambil satu halaman data ---
$baris = [];
$sql = "SELECT s.*, w.nama_wilayah, sw.nama_sub $sql_dari$where_sql ORDER BY s.user_id ASC";
if ($per > 0) {
    $sql .= " LIMIT ? OFFSET ?";
}
if ($stmt = $koneksi->prepare($sql)) {
    if ($per > 0) {
        $nilai_data = array_merge($nilai, [$per, $offset]);
        $stmt->bind_param($tipe . 'ii', ...$nilai_data);
    } elseif ($tipe !== '') {
        $stmt->bind_param($tipe, ...$nilai);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $baris[] = $r;
    }
    $stmt->close();
}

// --- URL bantu untuk pagination (mempertahankan filter) ---
$param_dasar = ['menu' => 'semeton'];
if ($f_q !== '') {
    $param_dasar['q'] = $f_q;
}
if ($f_wilayah > 0) {
    $param_dasar['wilayah'] = $f_wilayah;
}
if ($f_status !== '') {
    $param_dasar['status'] = $f_status;
}
if ($f_jk !== '') {
    $param_dasar['jk'] = $f_jk;
}
if ($per !== 50) {
    $param_dasar['per'] = $per;
}
$url_hal = static function (int $h) use ($param_dasar): string {
    return 'index.php?' . http_build_query(array_merge($param_dasar, ['hal' => $h]));
};
$url_reset = 'index.php?menu=semeton';

// CSV ikut filter aktif (kalau ada)
$param_csv = ['jenis' => 'semeton', 'csrf_token' => kbap_csrf_token()];
if ($f_q !== '') {
    $param_csv['q'] = $f_q;
}
if ($f_wilayah > 0) {
    $param_csv['wilayah'] = $f_wilayah;
}
if ($f_status !== '') {
    $param_csv['status'] = $f_status;
}
if ($f_jk !== '') {
    $param_csv['jk'] = $f_jk;
}
$url_csv = 'aksi/export_csv.php?' . http_build_query($param_csv);

// Rentang baris yang sedang tampil
$dari = $jumlah_filter > 0 ? $offset + 1 : 0;
$sampai = $per > 0 ? min($offset + $per, $jumlah_filter) : $jumlah_filter;
?>
<main class="app-main bg-light" style="min-height: 100vh;">
    <div class="app-content-header pt-4 pb-3">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="mb-1 fw-bolder text-dark" style="letter-spacing: -0.5px;">
                        <i class="fa-solid fa-users-rays text-primary me-2"></i>Data Semeton Pokok
                    </h3>
                    <p class="text-muted mb-0 fs-6">
                        Kelola data keanggotaan, cetak kartu, dan silsilah semeton.
                        <span class="badge bg-soft-primary text-primary rounded-pill ms-1"><?= (int) $total_semeton ?> anggota</span>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary shadow-sm rounded-3 px-3 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#tambah_semeton">
                        <i class="fa-solid fa-plus me-1"></i> Tambah Semeton
                    </button>
                    <a href="<?= $esc($url_csv) ?>" class="btn btn-outline-success shadow-sm rounded-3 px-3 py-2 fw-semibold">
                        <i class="fa-solid fa-file-csv me-1"></i> Unduh CSV
                    </a>
                    <a href="aksi/generate_qr.php?csrf_token=<?= $esc(kbap_csrf_token()) ?>" class="btn btn-warning shadow-sm rounded-3 px-3 py-2 fw-semibold">
                        <i class="fa-solid fa-qrcode me-1"></i> QR Semua
                    </a>
                    <form action="generate_all_kartu.php" method="POST" class="m-0">
                        <button type="submit" class="btn btn-success shadow-sm rounded-3 px-3 py-2 fw-semibold">
                            <i class="fas fa-id-card me-1"></i> Cetak Semua Kartu
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <!-- Filter & pencarian server-side -->
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body py-3">
                    <form method="GET" action="index.php" class="row g-2 align-items-end">
                        <input type="hidden" name="menu" value="semeton">
                        <div class="col-12 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="filter-q">Cari anggota</label>
                            <input type="search" id="filter-q" name="q" class="form-control" value="<?= $esc($f_q) ?>" placeholder="Nama, ID anggota, NIK, No. HP, atau No. KK">
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="filter-wilayah">Wilayah</label>
                            <select id="filter-wilayah" name="wilayah" class="form-select">
                                <option value="">Semua wilayah</option>
                                <?php foreach ($wilayah_list as $w) : ?>
                                    <option value="<?= (int) $w['id_wilayah'] ?>" <?= $f_wilayah === (int) $w['id_wilayah'] ? 'selected' : '' ?>><?= $esc($w['nama_wilayah']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="filter-status">Status</label>
                            <select id="filter-status" name="status" class="form-select">
                                <option value="">Semua status</option>
                                <option value="aktif" <?= $f_status === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                                <option value="tidak_aktif" <?= $f_status === 'tidak_aktif' ? 'selected' : '' ?>>Non-Aktif</option>
                            </select>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="filter-jk">Jenis Kelamin</label>
                            <select id="filter-jk" name="jk" class="form-select">
                                <option value="">Semua</option>
                                <option value="L" <?= $f_jk === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                                <option value="P" <?= $f_jk === 'P' ? 'selected' : '' ?>>Perempuan</option>
                            </select>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="filter-per">Baris/halaman</label>
                            <select id="filter-per" name="per" class="form-select">
                                <?php foreach ([25 => '25 baris', 50 => '50 baris', 100 => '100 baris', 250 => '250 baris', 0 => 'Semua'] as $nilai_per => $label_per) : ?>
                                    <option value="<?= (int) $nilai_per ?>" <?= $per === (int) $nilai_per ? 'selected' : '' ?>><?= $esc($label_per) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-lg-1 d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-fill" title="Terapkan filter">
                                <i class="fas fa-search"></i>
                            </button>
                            <?php if ($f_q !== '' || $f_wilayah > 0 || $f_status !== '' || $f_jk !== '' || $per !== 50) : ?>
                                <a href="<?= $esc($url_reset) ?>" class="btn btn-outline-secondary" title="Reset filter">
                                    <i class="fas fa-rotate-left"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="tabelSemeton" class="table table-hover align-middle mb-0 custom-table">
                            <thead class="bg-white text-muted">
                                <tr>
                                    <th width="25%" class="ps-4 fw-semibold text-uppercase" style="font-size: 0.75rem;">Informasi Semeton</th>
                                    <th width="15%" class="fw-semibold text-uppercase" style="font-size: 0.75rem;">Kontak</th>
                                    <th width="15%" class="fw-semibold text-uppercase" style="font-size: 0.75rem;">Wilayah (Banjar)</th>
                                    <th width="15%" class="fw-semibold text-uppercase" style="font-size: 0.75rem;">Klasifikasi</th>
                                    <th width="10%" class="fw-semibold text-uppercase text-center" style="font-size: 0.75rem;">Status</th>
                                    <th width="20%" class="pe-4 fw-semibold text-uppercase text-end" style="font-size: 0.75rem;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="border-top-0">
                                <?php if ($jumlah_filter === 0) : ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-5">
                                            <?php if ($f_q !== '' || $f_wilayah > 0 || $f_status !== '') : ?>
                                                Tidak ada anggota yang cocok dengan filter. <a href="<?= $esc($url_reset) ?>">Reset filter</a>.
                                            <?php else : ?>
                                                Belum ada data semeton.
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($baris as $row) :
                                    $user_id = $row['user_id'];
                                    $is_kartu = isset($kartu_set['kartu_' . $user_id . '.jpg']);
                                    $bg_soft = 'bg-soft-info text-info';
                                    if ($row['jenis_user'] == 'ngarep') $bg_soft = 'bg-soft-primary text-primary';
                                    if ($row['jenis_user'] == 'ngempi') $bg_soft = 'bg-soft-warning text-warning-dark';
                                ?>
                                    <tr>
                                        <td class="ps-4 py-3">
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-circle bg-light text-primary me-3 fw-bold shadow-sm d-flex justify-content-center align-items-center" style="width: 45px; height: 45px; border-radius: 50%;">
                                                    <?= $esc(strtoupper(substr((string) $row['nama'], 0, 1))) ?>
                                                </div>
                                                <div>
                                                    <h6 class="mb-0 fw-bold text-dark">
                                                        <?= $esc($row['nama']) ?>
                                                        <?php if (!empty($row['jenis_kelamin'])) : ?>
                                                            <span class="badge <?= $row['jenis_kelamin'] === 'L' ? 'bg-soft-primary text-primary' : 'bg-soft-danger text-danger' ?> rounded-pill fw-semibold ms-1" style="font-size: 0.65rem;">
                                                                <?= $row['jenis_kelamin'] === 'L' ? 'L' : 'P' ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <div class="text-muted" style="font-size: 0.8rem;">
                                                        <i class="fa-regular fa-id-badge me-1"></i><?= $esc($row['nik'] ?: '-') ?> &bull; <span class="text-primary fw-semibold"><?= $esc($user_id) ?></span>
                                                    </div>
                                                    <?php if (!empty($row['no_kk'])) : ?>
                                                        <div class="text-muted" style="font-size: 0.75rem;">
                                                            <i class="fa-solid fa-people-roof me-1"></i>KK <?= $esc($row['no_kk']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <?php if (trim((string) $row['no_hp']) !== '' && trim((string) $row['no_hp']) !== '-') : ?>
                                                <a href="https://wa.me/<?= $esc(preg_replace('/[^0-9]/', '', (string) $row['no_hp'])) ?>" target="_blank" rel="noopener" class="text-decoration-none fw-semibold text-success d-inline-flex align-items-center btn-hover-light px-2 py-1 rounded">
                                                    <i class="fa-brands fa-whatsapp fs-5 me-2"></i><?= $esc($row['no_hp']) ?>
                                                </a>
                                            <?php else : ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <div class="fw-bold text-dark" style="font-size: 0.9rem;"><?= $esc($row['nama_wilayah']) ?></div>
                                            <div class="text-muted" style="font-size: 0.8rem;"><i class="fa-solid fa-location-dot me-1 text-danger"></i><?= $esc($row['nama_sub']) ?></div>
                                        </td>

                                        <td>
                                            <span class="badge <?= $bg_soft ?> px-2 py-1 rounded-pill fw-semibold border-0 mb-1 d-inline-block text-uppercase" style="font-size: 0.7rem;">
                                                <?= $esc($row['jenis_user']) ?>
                                            </span>
                                            <div class="text-muted text-capitalize" style="font-size: 0.8rem;"><?= $esc($row['jabatan']) ?></div>
                                        </td>

                                        <td class="text-center">
                                            <?php if ($row['status'] == 'aktif') : ?>
                                                <span class="badge bg-soft-success text-success px-3 py-2 rounded-pill border-0"><i class="fa-solid fa-circle-check me-1"></i> Aktif</span>
                                            <?php else : ?>
                                                <span class="badge bg-soft-danger text-danger px-3 py-2 rounded-pill border-0">Non-Aktif</span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="pe-4 text-end">
                                            <div class="d-flex justify-content-end gap-1">
                                                <button type="button" class="btn btn-action btn-light text-secondary"
                                                    data-bs-toggle="modal" data-bs-target="#modalProfilSemeton"
                                                    title="Lihat Profil"
                                                    data-nama="<?= $esc($row['nama']) ?>"
                                                    data-user-id="<?= $esc($user_id) ?>"
                                                    data-jabatan="<?= $esc($row['jabatan']) ?>"
                                                    data-nik="<?= $esc($row['nik'] ?: '-') ?>"
                                                    data-kk="<?= $esc($row['no_kk'] ?: '-') ?>"
                                                    data-jk="<?= $row['jenis_kelamin'] === 'L' ? 'Laki-laki' : ($row['jenis_kelamin'] === 'P' ? 'Perempuan' : '-') ?>"
                                                    data-lahir="<?= $esc(trim(((string) $row['tempat_lahir']) . (($row['tanggal_lahir'] ?? '') !== '' && $row['tanggal_lahir'] !== null ? ', ' . date('d-m-Y', strtotime((string) $row['tanggal_lahir'])) : ''), " ,")) ?: '-' ?>"
                                                    data-agama="<?= $esc($row['agama'] ?: '-') ?>"
                                                    data-gol-darah="<?= $esc($row['golongan_darah'] ?: '-') ?>"
                                                    data-kawin="<?= $esc(str_replace('_', ' ', (string) $row['status_perkawinan']) ?: '-') ?>"
                                                    data-pendidikan="<?= $esc($row['pendidikan'] ?: '-') ?>"
                                                    data-pekerjaan="<?= $esc($row['pekerjaan'] ?: '-') ?>"
                                                    data-ayah="<?= $esc($row['nama_ayah'] ?: '-') ?>"
                                                    data-ibu="<?= $esc($row['nama_ibu'] ?: '-') ?>"
                                                    data-catatan="<?= $esc($row['catatan'] ?: '') ?>"
                                                    data-email="<?= $esc($row['email'] ?: '-') ?>"
                                                    data-jenis="<?= $esc($row['jenis_user']) ?>"
                                                    data-wilayah="<?= $esc($row['nama_wilayah'] ?: '-') ?>"
                                                    data-sub="<?= $esc($row['nama_sub'] ?: '-') ?>">
                                                    <i class="fas fa-eye"></i>
                                                </button>

                                                <?php if ($is_kartu) : ?>
                                                    <a href="kartu_anggota/kartu_<?= $esc($user_id) ?>.jpg?t=<?= time() ?>" target="_blank" rel="noopener" class="btn btn-action btn-light text-success" title="Cetak Kartu">
                                                        <i class="fas fa-print"></i>
                                                    </a>
                                                    <button type="button" onclick="updateKartu('<?= $esc($user_id) ?>')" class="btn btn-action btn-light text-info" title="Refresh Kartu">
                                                        <i class="fas fa-sync-alt"></i>
                                                    </button>
                                                <?php else : ?>
                                                    <button type="button" onclick="generateKartu('<?= $esc($user_id) ?>')" class="btn btn-action btn-success text-white shadow-sm" title="Buat Kartu">
                                                        <i class="fas fa-id-card"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <button type="button" class="btn btn-action btn-light text-warning"
                                                    data-bs-toggle="modal" data-bs-target="#modalEditSemeton"
                                                    title="Edit"
                                                    data-id="<?= (int) $row['id'] ?>"
                                                    data-nama="<?= $esc($row['nama']) ?>"
                                                    data-nik="<?= $esc((string) $row['nik']) ?>"
                                                    data-jk="<?= $esc((string) $row['jenis_kelamin']) ?>"
                                                    data-kk="<?= $esc((string) $row['no_kk']) ?>"
                                                    data-tempat-lahir="<?= $esc((string) $row['tempat_lahir']) ?>"
                                                    data-tanggal-lahir="<?= $esc((string) $row['tanggal_lahir']) ?>"
                                                    data-agama="<?= $esc((string) $row['agama']) ?>"
                                                    data-gol-darah="<?= $esc((string) $row['golongan_darah']) ?>"
                                                    data-kawin="<?= $esc((string) $row['status_perkawinan']) ?>"
                                                    data-pendidikan="<?= $esc((string) $row['pendidikan']) ?>"
                                                    data-pekerjaan="<?= $esc((string) $row['pekerjaan']) ?>"
                                                    data-ayah="<?= $esc((string) $row['nama_ayah']) ?>"
                                                    data-ibu="<?= $esc((string) $row['nama_ibu']) ?>"
                                                    data-catatan="<?= $esc((string) $row['catatan']) ?>"
                                                    data-no-hp="<?= $esc($row['no_hp']) ?>"
                                                    data-email="<?= $esc($row['email']) ?>"
                                                    data-alamat="<?= $esc($row['alamat']) ?>"
                                                    data-wilayah="<?= (int) $row['wilayah'] ?>"
                                                    data-sub="<?= (int) $row['sub_wilayah'] ?>"
                                                    data-jenis="<?= $esc($row['jenis_user']) ?>"
                                                    data-jabatan="<?= $esc($row['jabatan']) ?>"
                                                    data-status="<?= $esc($row['status']) ?>">
                                                    <i class="fas fa-pen"></i>
                                                </button>
                                                <button type="button" onclick="confirmDelete('<?= (int) $row['id'] ?>', '<?= $esc($row['nama']) ?>')" class="btn btn-action btn-light text-danger" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Info & navigasi halaman -->
                    <div class="card-footer bg-white border-top px-4 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="text-muted small">
                            Menampilkan <strong><?= (int) $dari ?></strong>&ndash;<strong><?= (int) $sampai ?></strong>
                            dari <strong><?= number_format($jumlah_filter, 0, ',', '.') ?></strong> anggota
                            <?php if ($f_q !== '' || $f_wilayah > 0 || $f_status !== '' || $f_jk !== '') : ?>
                                <span class="text-secondary">(difilter dari <?= number_format($total_semeton, 0, ',', '.') ?> total)</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($per > 0 && $total_hal > 1) : ?>
                            <nav aria-label="Navigasi halaman semeton">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?= $hal <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $hal <= 1 ? '#' : $esc($url_hal($hal - 1)) ?>" aria-label="Sebelumnya">&laquo;</a>
                                    </li>
                                    <?php
                                    $awal_hal  = max(1, $hal - 2);
                                    $akhir_hal = min($total_hal, $hal + 2);
                                    ?>
                                    <?php if ($awal_hal > 1) : ?>
                                        <li class="page-item <?= $hal === 1 ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= $esc($url_hal(1)) ?>">1</a>
                                        </li>
                                        <?php if ($awal_hal > 2) : ?>
                                            <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php for ($p = $awal_hal; $p <= $akhir_hal; $p++) : ?>
                                        <li class="page-item <?= $p === $hal ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= $esc($url_hal($p)) ?>"><?= $p ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <?php if ($akhir_hal < $total_hal) : ?>
                                        <?php if ($akhir_hal < $total_hal - 1) : ?>
                                            <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                        <?php endif; ?>
                                        <li class="page-item <?= $hal === $total_hal ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= $esc($url_hal($total_hal)) ?>"><?= $total_hal ?></a>
                                        </li>
                                    <?php endif; ?>
                                    <li class="page-item <?= $hal >= $total_hal ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $hal >= $total_hal ? '#' : $esc($url_hal($hal + 1)) ?>" aria-label="Berikutnya">&raquo;</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- ================= MODAL PROFIL (satu untuk semua baris) ================= -->
<div class="modal fade" id="modalProfilSemeton" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-body p-0">
                <div class="bg-primary pt-4 pb-5 px-3 text-center position-relative">
                    <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal"></button>
                    <h5 class="text-white fw-bold mb-0">Profil Semeton</h5>
                </div>
                <div class="text-center mt-n4 mb-3 position-relative">
                    <div class="bg-white p-1 rounded-circle d-inline-block shadow-sm">
                        <div class="bg-light text-primary d-flex justify-content-center align-items-center rounded-circle fs-1 fw-bold" style="width: 80px; height: 80px;" id="profilInisial">-</div>
                    </div>
                    <h5 class="fw-bold text-dark mt-2 mb-0" id="profilNama">-</h5>
                    <p class="text-primary fw-semibold mb-2" id="profilUserId">-</p>
                    <span class="badge bg-soft-primary text-primary px-3 py-1 rounded-pill" id="profilJabatan">-</span>
                </div>
                <div class="p-4 pt-0">
                    <div class="bg-light rounded-3 p-3">
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">NIK</span><span class="fw-bold text-dark small" id="profilNik">-</span></div>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">No. KK</span><span class="fw-bold text-dark small" id="profilKk">-</span></div>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">Jenis Kelamin</span><span class="fw-bold text-dark small" id="profilJk">-</span></div>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">Tempat / Tgl Lahir</span><span class="fw-bold text-dark small text-end ms-2" id="profilLahir">-</span></div>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">Agama</span><span class="fw-bold text-dark small" id="profilAgama">-</span></div>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">Gol. Darah</span><span class="fw-bold text-dark small" id="profilGolDarah">-</span></div>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">Status Kawin</span><span class="fw-bold text-dark small" id="profilKawin">-</span></div>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">Pendidikan</span><span class="fw-bold text-dark small text-end ms-2" id="profilPendidikan">-</span></div>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">Pekerjaan</span><span class="fw-bold text-dark small text-end ms-2" id="profilPekerjaan">-</span></div>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">Ayah</span><span class="fw-bold text-dark small text-end ms-2" id="profilAyah">-</span></div>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">Ibu</span><span class="fw-bold text-dark small text-end ms-2" id="profilIbu">-</span></div>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">Email</span><span class="fw-bold text-dark small text-end text-break ms-2" id="profilEmail">-</span></div>
                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span class="text-muted small">Jenis</span><span class="fw-bold text-dark text-uppercase small" id="profilJenis">-</span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted small">Wilayah</span><span class="fw-bold text-dark small text-end" id="profilWilayah">-</span></div>
                    </div>
                    <div class="alert alert-light border small mb-0 mt-3 d-none" id="profilCatatanBox">
                        <span class="text-muted d-block mb-1">Catatan</span>
                        <span id="profilCatatan">-</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL EDIT (satu untuk semua baris) ================= -->
<div class="modal fade" id="modalEditSemeton" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-light border-bottom-0 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit text-warning me-2"></i>Edit Semeton</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form action="aksi/aksi_semeton.php" method="POST">
                    <input type="hidden" name="aksi" value="edit">
                    <input type="hidden" name="id" id="edit_id">

                    <h6 class="text-uppercase text-muted fw-bold mb-2" style="font-size: .72rem;">Identitas</h6>
                    <div class="row">
                        <div class="col-md-8 mb-2">
                            <label class="form-label fs-6 fw-bold">Nama <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="nama" id="edit_nama" maxlength="255" required>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label fs-6 fw-bold">Jenis Kelamin</label>
                            <select class="form-select" name="jenis_kelamin" id="edit_jenis_kelamin">
                                <option value="">- Tidak diisi -</option>
                                <option value="L">Laki-laki</option>
                                <option value="P">Perempuan</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">NIK</label>
                            <input type="text" class="form-control" name="nik" id="edit_nik" maxlength="16" inputmode="numeric" placeholder="16 digit (opsional)">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">No. KK</label>
                            <input type="text" class="form-control" name="no_kk" id="edit_no_kk" maxlength="16" inputmode="numeric" placeholder="16 digit (opsional)">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Tempat Lahir</label>
                            <input type="text" class="form-control" name="tempat_lahir" id="edit_tempat_lahir" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Tanggal Lahir</label>
                            <input type="date" class="form-control" name="tanggal_lahir" id="edit_tanggal_lahir">
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted fw-bold mb-2 mt-2" style="font-size: .72rem;">Data Pendukung</h6>
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <label class="form-label fs-6 fw-bold">Agama</label>
                            <input type="text" class="form-control" name="agama" id="edit_agama" maxlength="30" list="daftarAgama">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label fs-6 fw-bold">Golongan Darah</label>
                            <select class="form-select" name="golongan_darah" id="edit_golongan_darah">
                                <option value="">- Tidak diisi -</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="AB">AB</option>
                                <option value="O">O</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label fs-6 fw-bold">Status Perkawinan</label>
                            <select class="form-select" name="status_perkawinan" id="edit_status_perkawinan">
                                <option value="">- Tidak diisi -</option>
                                <option value="belum_kawin">Belum Kawin</option>
                                <option value="kawin">Kawin</option>
                                <option value="cerai_hidup">Cerai Hidup</option>
                                <option value="cerai_mati">Cerai Mati</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Pendidikan</label>
                            <input type="text" class="form-control" name="pendidikan" id="edit_pendidikan" maxlength="50">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Pekerjaan</label>
                            <input type="text" class="form-control" name="pekerjaan" id="edit_pekerjaan" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Nama Ayah</label>
                            <input type="text" class="form-control" name="nama_ayah" id="edit_nama_ayah" maxlength="255">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Nama Ibu</label>
                            <input type="text" class="form-control" name="nama_ibu" id="edit_nama_ibu" maxlength="255">
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted fw-bold mb-2 mt-2" style="font-size: .72rem;">Kontak</h6>
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">No HP</label>
                            <input type="text" class="form-control" name="no_hp" id="edit_no_hp" maxlength="15">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Email</label>
                            <input type="email" class="form-control" name="email" id="edit_email" maxlength="255">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fs-6 fw-bold">Alamat</label>
                        <textarea class="form-control" name="alamat" id="edit_alamat" rows="2"></textarea>
                    </div>

                    <h6 class="text-uppercase text-muted fw-bold mb-2 mt-2" style="font-size: .72rem;">Wilayah &amp; Keanggotaan</h6>
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Nama Wilayah</label>
                            <select class="form-select" id="edit_id_wilayah" name="id_wilayah" required onchange="isiSubWilayahEdit(this.value)">
                                <option value="">-- Pilih Wilayah --</option>
                                <?php foreach ($wilayah_list as $w) : ?>
                                    <option value="<?= (int) $w['id_wilayah'] ?>"><?= $esc($w['nama_wilayah']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Sub Wilayah</label>
                            <select class="form-select" id="edit_sub_wilayah" name="sub_wilayah" required>
                                <option value="">-- Pilih Sub Wilayah --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <label class="form-label fs-6 fw-bold">Urunan</label>
                            <select class="form-select" name="jenis" id="edit_jenis" required>
                                <option value="ngarep">Ngarep</option>
                                <option value="ngempi">Ngempi</option>
                                <option value="pemangku">Pemangku</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label fs-6 fw-bold">Jabatan</label>
                            <select class="form-select" name="jabatan" id="edit_jabatan" required>
                                <?php foreach ($jabatan_list as $j) : ?>
                                    <option value="<?= $esc($j) ?>"><?= $esc($j) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label fs-6 fw-bold">Status</label>
                            <select class="form-select" name="status" id="edit_status" required>
                                <option value="aktif">Aktif</option>
                                <option value="tidak_aktif">Tidak Aktif</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fs-6 fw-bold">Catatan</label>
                        <input type="text" class="form-control" name="catatan" id="edit_catatan" maxlength="255" placeholder="Catatan internal (opsional)">
                    </div>
                    <div class="modal-footer px-0 pb-0 mt-3 border-top-0">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Tutup</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<datalist id="daftarAgama">
    <option value="Hindu"></option>
    <option value="Islam"></option>
    <option value="Kristen"></option>
    <option value="Katolik"></option>
    <option value="Buddha"></option>
    <option value="Konghucu"></option>
</datalist>

<div class="modal fade" id="tambah_semeton" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-light border-bottom-0 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-plus text-primary me-2"></i>Tambah Semeton</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form action="aksi/aksi_semeton.php" method="POST">
                    <input type="hidden" name="aksi" value="tambah">

                    <h6 class="text-uppercase text-muted fw-bold mb-2" style="font-size: .72rem;">Identitas</h6>
                    <div class="row">
                        <div class="col-md-8 mb-2">
                            <label class="form-label fs-6 fw-bold">Nama <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="nama" maxlength="255" required>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label fs-6 fw-bold">Jenis Kelamin</label>
                            <select class="form-select" name="jenis_kelamin">
                                <option value="">- Tidak diisi -</option>
                                <option value="L">Laki-laki</option>
                                <option value="P">Perempuan</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">NIK</label>
                            <input type="text" class="form-control" name="nik" maxlength="16" inputmode="numeric" placeholder="16 digit, opsional & harus unik">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">No. KK</label>
                            <input type="text" class="form-control" name="no_kk" maxlength="16" inputmode="numeric" placeholder="16 digit (opsional)">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Tempat Lahir</label>
                            <input type="text" class="form-control" name="tempat_lahir" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Tanggal Lahir</label>
                            <input type="date" class="form-control" name="tanggal_lahir">
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted fw-bold mb-2 mt-2" style="font-size: .72rem;">Data Pendukung</h6>
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <label class="form-label fs-6 fw-bold">Agama</label>
                            <input type="text" class="form-control" name="agama" maxlength="30" list="daftarAgama">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label fs-6 fw-bold">Golongan Darah</label>
                            <select class="form-select" name="golongan_darah">
                                <option value="">- Tidak diisi -</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="AB">AB</option>
                                <option value="O">O</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label fs-6 fw-bold">Status Perkawinan</label>
                            <select class="form-select" name="status_perkawinan">
                                <option value="">- Tidak diisi -</option>
                                <option value="belum_kawin">Belum Kawin</option>
                                <option value="kawin">Kawin</option>
                                <option value="cerai_hidup">Cerai Hidup</option>
                                <option value="cerai_mati">Cerai Mati</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Pendidikan</label>
                            <input type="text" class="form-control" name="pendidikan" maxlength="50">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Pekerjaan</label>
                            <input type="text" class="form-control" name="pekerjaan" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Nama Ayah</label>
                            <input type="text" class="form-control" name="nama_ayah" maxlength="255">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Nama Ibu</label>
                            <input type="text" class="form-control" name="nama_ibu" maxlength="255">
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted fw-bold mb-2 mt-2" style="font-size: .72rem;">Kontak</h6>
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">No HP</label>
                            <input type="text" class="form-control" name="no_hp" maxlength="15">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Email</label>
                            <input type="email" class="form-control" name="email" maxlength="255">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fs-6 fw-bold">Alamat</label>
                        <textarea class="form-control" name="alamat" rows="2"></textarea>
                    </div>

                    <h6 class="text-uppercase text-muted fw-bold mb-2 mt-2" style="font-size: .72rem;">Wilayah &amp; Keanggotaan</h6>
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Nama Wilayah</label>
                            <select class="form-select" id="nama_wilayah_baru" name="id_wilayah" required onchange="isiSubWilayahBaru(this.value)">
                                <option value="">-- Pilih Wilayah --</option>
                                <?php foreach ($wilayah_list as $w) : ?>
                                    <option value="<?= (int) $w['id_wilayah'] ?>"><?= $esc($w['nama_wilayah']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Sub Wilayah</label>
                            <select class="form-select" id="sub_wilayah" name="sub_wilayah" required>
                                <option value="">-- Pilih Sub Wilayah --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Urunan</label>
                            <select class="form-select" name="jenis" required>
                                <option value="ngarep">Ngarep</option>
                                <option value="ngempi">Ngempi</option>
                                <option value="pemangku">Pemangku</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fs-6 fw-bold">Jabatan</label>
                            <select class="form-select" name="jabatan" required>
                                <option value="Anggota">Anggota</option>
                                <option value="Korwil">Korwil</option>
                                <option value="Sekretaris">Sekretaris</option>
                                <option value="Sekretaris Umum">Sekretaris Umum</option>
                                <option value="Bendahara">Bendahara</option>
                                <option value="Bendahara Umum">Bendahara Umum</option>
                                <option value="Ketua">Ketua</option>
                                <option value="Wakil Ketua Umum">Wakil Ketua Umum</option>
                                <option value="Ketua Umum">Ketua Umum</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fs-6 fw-bold">Catatan</label>
                        <input type="text" class="form-control" name="catatan" maxlength="255" placeholder="Catatan internal (opsional)">
                    </div>
                    <div class="modal-footer px-0 pb-0 mt-3 border-top-0">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4">Simpan Data</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .custom-table th {
        border-bottom: 2px solid #f1f5f9 !important;
        padding: 1rem;
        color: #64748b;
        background-color: #f8fafc;
    }

    .custom-table td {
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .custom-table tr:last-child td {
        border-bottom: none;
    }

    .custom-table tbody tr:hover {
        background-color: #f8fafc;
    }

    .btn-action {
        width: 36px;
        height: 36px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        transition: all 0.2s;
        border: none;
    }

    .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .btn-hover-light:hover {
        background-color: #f0fdf4;
    }

    .bg-soft-success {
        background-color: #d1fae5 !important;
    }

    .text-success {
        color: #059669 !important;
    }

    .bg-soft-danger {
        background-color: #fee2e2 !important;
    }

    .text-danger {
        color: #dc2626 !important;
    }

    .bg-soft-primary {
        background-color: #dbeafe !important;
    }

    .text-primary {
        color: #2563eb !important;
    }

    .bg-soft-info {
        background-color: #e0f2fe !important;
    }

    .text-info {
        color: #0284c7 !important;
    }

    .bg-soft-warning {
        background-color: #fef3c7 !important;
    }

    .text-warning-dark {
        color: #d97706 !important;
    }

    .form-select,
    .form-control {
        border-radius: 8px;
    }
</style>

<script>
    // Sub wilayah dari server (85 baris) — tidak lagi memanggil AJAX per baris.
    var SUB_WILAYAH = <?= $sub_json ?: '{}' ?>;

    function isiSubWilayahBaru(idWilayah, terpilih) {
        var target = document.getElementById('sub_wilayah');
        if (!target) return;
        target.innerHTML = '<option value="">-- Pilih Sub Wilayah --</option>';
        (SUB_WILAYAH[idWilayah] || []).forEach(function (s) {
            var opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.nama;
            if (terpilih && String(terpilih) === String(s.id)) opt.selected = true;
            target.appendChild(opt);
        });
    }

    function isiSubWilayahEdit(idWilayah, terpilih) {
        var target = document.getElementById('edit_sub_wilayah');
        if (!target) return;
        target.innerHTML = '<option value="">-- Pilih Sub Wilayah --</option>';
        (SUB_WILAYAH[idWilayah] || []).forEach(function (s) {
            var opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.nama;
            if (terpilih && String(terpilih) === String(s.id)) opt.selected = true;
            target.appendChild(opt);
        });
    }

    // Modal profil: isi dari data-* tombol yang diklik
    document.addEventListener('DOMContentLoaded', function () {
        var modalProfil = document.getElementById('modalProfilSemeton');
        if (modalProfil) {
            modalProfil.addEventListener('show.bs.modal', function (event) {
                var btn = event.relatedTarget;
                if (!btn) return;
                var d = btn.dataset;
                document.getElementById('profilInisial').textContent = (d.nama || '-').charAt(0).toUpperCase();
                document.getElementById('profilNama').textContent = d.nama || '-';
                document.getElementById('profilUserId').textContent = d.userId || '-';
                document.getElementById('profilJabatan').textContent = d.jabatan || '-';
                document.getElementById('profilNik').textContent = d.nik || '-';
                document.getElementById('profilKk').textContent = d.kk || '-';
                document.getElementById('profilJk').textContent = d.jk || '-';
                document.getElementById('profilLahir').textContent = d.lahir || '-';
                document.getElementById('profilAgama').textContent = d.agama || '-';
                document.getElementById('profilGolDarah').textContent = d.golDarah || '-';
                document.getElementById('profilKawin').textContent = d.kawin || '-';
                document.getElementById('profilPendidikan').textContent = d.pendidikan || '-';
                document.getElementById('profilPekerjaan').textContent = d.pekerjaan || '-';
                document.getElementById('profilAyah').textContent = d.ayah || '-';
                document.getElementById('profilIbu').textContent = d.ibu || '-';
                document.getElementById('profilEmail').textContent = d.email || '-';
                document.getElementById('profilJenis').textContent = d.jenis || '-';
                document.getElementById('profilWilayah').innerHTML =
                    (d.wilayah || '-') + '<br><small class="text-muted">' + (d.sub || '-') + '</small>';

                var boxCatatan = document.getElementById('profilCatatanBox');
                if (d.catatan) {
                    document.getElementById('profilCatatan').textContent = d.catatan;
                    boxCatatan.classList.remove('d-none');
                } else {
                    document.getElementById('profilCatatan').textContent = '-';
                    boxCatatan.classList.add('d-none');
                }
            });
        }

        // Modal edit: isi dari data-* tombol yang diklik
        var modalEdit = document.getElementById('modalEditSemeton');
        if (modalEdit) {
            modalEdit.addEventListener('show.bs.modal', function (event) {
                var btn = event.relatedTarget;
                if (!btn) return;
                var d = btn.dataset;
                document.getElementById('edit_id').value = d.id || '';
                document.getElementById('edit_nama').value = d.nama || '';
                document.getElementById('edit_nik').value = d.nik || '';
                document.getElementById('edit_jenis_kelamin').value = d.jk || '';
                document.getElementById('edit_no_kk').value = d.kk || '';
                document.getElementById('edit_tempat_lahir').value = d.tempatLahir || '';
                document.getElementById('edit_tanggal_lahir').value = d.tanggalLahir || '';
                document.getElementById('edit_agama').value = d.agama || '';
                document.getElementById('edit_golongan_darah').value = d.golDarah || '';
                document.getElementById('edit_status_perkawinan').value = d.kawin || '';
                document.getElementById('edit_pendidikan').value = d.pendidikan || '';
                document.getElementById('edit_pekerjaan').value = d.pekerjaan || '';
                document.getElementById('edit_nama_ayah').value = d.ayah || '';
                document.getElementById('edit_nama_ibu').value = d.ibu || '';
                document.getElementById('edit_catatan').value = d.catatan || '';
                document.getElementById('edit_no_hp').value = d.noHp || '';
                document.getElementById('edit_email').value = d.email || '';
                document.getElementById('edit_alamat').value = d.alamat || '';
                document.getElementById('edit_id_wilayah').value = d.wilayah || '';
                document.getElementById('edit_jenis').value = d.jenis || 'ngarep';
                document.getElementById('edit_status').value = d.status || 'aktif';

                // Jabatan: tambahkan opsi bila belum ada di daftar
                var selJabatan = document.getElementById('edit_jabatan');
                var ada = false;
                for (var i = 0; i < selJabatan.options.length; i++) {
                    if (selJabatan.options[i].value === (d.jabatan || '')) { ada = true; break; }
                }
                if (!ada && d.jabatan) {
                    var opt = document.createElement('option');
                    opt.value = d.jabatan;
                    opt.textContent = d.jabatan;
                    selJabatan.appendChild(opt);
                }
                selJabatan.value = d.jabatan || 'Anggota';

                isiSubWilayahEdit(d.wilayah || '', d.sub || '');
            });
        }
    });

    function confirmDelete(id, nama) {
        Swal.fire({
            title: "Hapus Semeton?",
            html: `Apakah Anda yakin ingin menghapus data <b>${nama}</b> secara permanen?`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#dc2626",
            cancelButtonColor: "#64748b",
            confirmButtonText: "<i class='fas fa-trash me-1'></i> Ya, Hapus",
            cancelButtonText: "Batal",
            customClass: {
                confirmButton: 'rounded-pill px-4',
                cancelButton: 'rounded-pill px-4'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = kbapUrl("aksi/aksi_semeton.php?aksi=hapus&id=" + id);
            }
        });
    }

    function generateKartu(userId) {
        Swal.fire({
            title: 'Memproses...',
            text: 'Mempersiapkan desain kartu anggota...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading()
            }
        });
        fetch('generate_satu_kartu.php?user_id=' + encodeURIComponent(userId) + '&csrf_token=' + encodeURIComponent(window.KBAP_CSRF), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                cache: 'no-store'
            })
            .then(async (response) => {
                const teks = await response.text();
                let data = null;
                try {
                    data = JSON.parse(teks);
                } catch (e) {
                    data = null;
                }
                if (!data) {
                    throw new Error(response.ok ?
                        'Respons server tidak dikenali.' :
                        'Server menolak permintaan (HTTP ' + response.status + '). Muat ulang halaman lalu coba lagi.');
                }
                if (data.status !== 'sukses') {
                    throw new Error(data.message || 'Gagal membuat kartu.');
                }
                return data;
            })
            .then((data) => {
                return Swal.fire({
                        title: 'Berhasil!',
                        text: data.message || 'Kartu semeton berhasil dibuat.',
                        icon: 'success',
                        confirmButtonColor: '#059669',
                        customClass: {
                            confirmButton: 'rounded-pill px-4'
                        }
                    })
                    .then(() => {
                        location.reload();
                    });
            }).catch((error) => {
                Swal.fire({
                    title: 'Kartu Gagal Dibuat',
                    text: (error && error.message) ? error.message : 'Gagal memproses kartu.',
                    icon: 'error',
                    confirmButtonColor: '#dc2626',
                    customClass: {
                        confirmButton: 'rounded-pill px-4'
                    }
                });
            });
    }

    function updateKartu(userId) {
        Swal.fire({
            title: "Perbarui Kartu?",
            text: "Sistem akan membuat ulang kartu dengan data terbaru dari database.",
            icon: "info",
            showCancelButton: true,
            confirmButtonColor: "#2563eb",
            cancelButtonColor: "#64748b",
            confirmButtonText: "<i class='fas fa-sync me-1'></i> Perbarui",
            customClass: {
                confirmButton: 'rounded-pill px-4',
                cancelButton: 'rounded-pill px-4'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                generateKartu(userId);
            }
        });
    }
</script>
