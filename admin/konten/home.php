<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('home'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// --- BAGIAN LOGIKA (Ditaruh di paling atas) ---

// 1. Data Semeton & Kategori
$total_semeton = mysqli_num_rows(mysqli_query($koneksi, "SELECT user_id FROM semeton WHERE status = 'aktif'"));
$total_semeton_baru = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM semeton_baru"));
$total_tidak_aktif = mysqli_num_rows(mysqli_query($koneksi, "SELECT user_id FROM semeton WHERE status = 'tidak_aktif'"));

// Query untuk Total Ngarep & Ngempi secara global
$total_ngarep = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM semeton WHERE jenis_user = 'ngarep' AND status = 'aktif'"))['total'] ?? 0;
$total_ngempi = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM semeton WHERE jenis_user = 'ngempi' AND status = 'aktif'"))['total'] ?? 0;

$grand_total_semeton = $total_semeton + $total_semeton_baru;

// 2. Data Keuangan (Hari Ini) + pemisahan odalan ini / tunggakan / bayar di muka
$tanggal_hari_ini = date('Y-m-d');
$pembayaran_hari_ini   = 0.0;
$bayar_hari_ini_ini    = 0.0;
$bayar_hari_ini_tunggakan = 0.0;
$bayar_hari_ini_muka   = 0.0;

// 3. Odalan terakhir (dipakai KPI "Urunan Terkumpul" & dasar pemisahan)
$id_last_odalan = (int) ($koneksi->query("SELECT id FROM odalan ORDER BY tanggal DESC, id DESC LIMIT 1")->fetch_assoc()['id'] ?? 0);

if ($stmt = $koneksi->prepare(
    "SELECT COALESCE(SUM(p.jumlah_bayar), 0) AS total,
            COALESCE(SUM(CASE WHEN p.id_piodalan = ? THEN p.jumlah_bayar ELSE 0 END), 0) AS ini,
            COALESCE(SUM(CASE WHEN p.id_piodalan < ? THEN p.jumlah_bayar ELSE 0 END), 0) AS tunggakan,
            COALESCE(SUM(CASE WHEN p.id_piodalan > ? THEN p.jumlah_bayar ELSE 0 END), 0) AS muka
     FROM pembayaran p
     WHERE DATE(p.tanggal_bayar) = ? AND p.dibatalkan_at IS NULL"
)) {
    $stmt->bind_param('iiis', $id_last_odalan, $id_last_odalan, $id_last_odalan, $tanggal_hari_ini);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($r) {
        $pembayaran_hari_ini      = (float) $r['total'];
        if ($id_last_odalan > 0) {
            $bayar_hari_ini_ini       = (float) $r['ini'];
            $bayar_hari_ini_tunggakan = (float) $r['tunggakan'];
            $bayar_hari_ini_muka      = (float) $r['muka'];
        }
    }
}

$punia_hari_ini = $koneksi->query("SELECT SUM(jumlah_punia) AS total FROM dana_punia WHERE DATE(tanggal_punia) = '$tanggal_hari_ini'")->fetch_assoc()['total'] ?? 0;

// 4. Data Piutang & Urunan Odalan Terakhir
$total_urunan_terbayar = 0;
if ($id_last_odalan > 0) {
    $stmt = $koneksi->prepare("SELECT SUM(jumlah_bayar) AS total FROM pembayaran WHERE id_piodalan = ? AND dibatalkan_at IS NULL");
    $stmt->bind_param("i", $id_last_odalan);
    $stmt->execute();
    $total_urunan_terbayar = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
}
$total_hutang = $koneksi->query("SELECT SUM(jumlah_hutang) AS total FROM hutang WHERE jumlah_hutang > 0")->fetch_assoc()['total'] ?? 0;
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0 fw-bold text-primary">Ringkasan Data Semeton Arya Pengalasan</h3>
                </div>
                <div class="col-sm-6 text-end">
                    <span class="badge bg-light text-dark p-2 border shadow-sm"><i class="bi bi-calendar3 me-2"></i><?= date('d F Y') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-lg-3 col-6">
                    <div class="small-box shadow-sm border-0 bg-primary text-white">
                        <div class="inner">
                            <h3><?= $total_semeton ?></h3>
                            <p>Semeton Aktif (Total)</p>
                        </div>
                        <div class="icon"><i class="bi bi-people-fill"></i></div>
                    </div>
                </div>
                <div class="col-lg-3 col-6">
                    <div class="small-box shadow-sm border-0 bg-success text-white">
                        <div class="inner">
                            <h3><?= $total_semeton_baru ?></h3>
                            <p>Pendaftar Baru</p>
                        </div>
                        <div class="icon"><i class="bi bi-person-plus-fill"></i></div>
                    </div>
                </div>
                <div class="col-lg-3 col-6">
                    <div class="small-box shadow-sm border-0 bg-info text-white">
                        <div class="inner">
                            <h3><?= $total_ngarep ?></h3>
                            <p>Total Semeton Ngarep</p>
                        </div>
                        <div class="icon"><i class="bi bi-house-door-fill"></i></div>
                    </div>
                </div>
                <div class="col-lg-3 col-6">
                    <div class="small-box shadow-sm border-0 bg-secondary text-white">
                        <div class="inner">
                            <h3><?= $total_ngempi ?></h3>
                            <p>Total Semeton Ngempi</p>
                        </div>
                        <div class="icon"><i class="bi bi-house-fill"></i></div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-5">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="card-title fw-bold mb-0"><i class="bi bi-cash-stack me-2 text-success"></i>Arus Kas Hari Ini</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <small class="text-muted d-block">Urunan Masuk</small>
                                    <span class="h4 fw-bold text-success">Rp <?= number_format($pembayaran_hari_ini, 0, ',', '.') ?></span>
                                    <?php if ($pembayaran_hari_ini > 0) : ?>
                                        <div class="mt-2 d-flex flex-wrap gap-1">
                                            <span class="badge bg-success" title="Pembayaran tagihan odalan terbaru">
                                                Odalan ini: Rp <?= number_format($bayar_hari_ini_ini, 0, ',', '.') ?>
                                            </span>
                                            <span class="badge bg-danger" title="Melunasi tagihan odalan yang lebih lama">
                                                Tunggakan: Rp <?= number_format($bayar_hari_ini_tunggakan, 0, ',', '.') ?>
                                            </span>
                                            <span class="badge bg-info text-dark" title="Membayar tagihan odalan yang belum berjalan">
                                                Di muka: Rp <?= number_format($bayar_hari_ini_muka, 0, ',', '.') ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="vr mx-3"></div>
                                <div>
                                    <small class="text-muted d-block">Dana Punia</small>
                                    <span class="h4 fw-bold text-dark">Rp <?= number_format($punia_hari_ini, 0, ',', '.') ?></span>
                                </div>
                            </div>
                            <hr>
                            <div class="mt-3">
                                <small class="text-muted d-block">Piutang Semeton (Belum Bayar)</small>
                                <span class="h3 fw-bold text-danger">Rp <?= number_format($total_hutang, 0, ',', '.') ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm bg-light">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <div class="p-3 bg-white rounded-circle shadow-sm">
                                        <i class="bi bi-calendar-check text-primary h3"></i>
                                    </div>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h6 class="mb-1 text-muted">Urunan Terkumpul (Odalan Terakhir)</h6>
                                    <h4 class="fw-bold mb-0 text-primary">Rp <?= number_format($total_urunan_terbayar, 0, ',', '.') ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning mt-4 border-0 shadow-sm">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-database-fill me-3 h4 mb-0"></i>
                            <div>
                                <h6 class="mb-0 fw-bold">Grand Total Database</h6>
                                <small>Total data masuk: <strong><?= $grand_total_semeton ?></strong> jiwa (Aktif + Baru)</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-7">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-warning py-3">
                            <h5 class="card-title fw-bold mb-0 text-dark"><i class="bi bi-geo-alt-fill me-2"></i>Distribusi Per Wilayah</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light text-uppercase small fw-bold">
                                        <tr>
                                            <th class="ps-3">Wilayah</th>
                                            <th>Sub Wilayah</th>
                                            <th class="text-center">Ngarep</th>
                                            <th class="text-center">Ngempi</th>
                                            <th class="text-center text-primary">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $query_rekap = "SELECT w.nama_wilayah, s.nama_sub, 
                                                        COUNT(CASE WHEN sm.jenis_user = 'ngarep' AND sm.status = 'aktif' THEN 1 END) AS j_ngarep,
                                                        COUNT(CASE WHEN sm.jenis_user = 'ngempi' AND sm.status = 'aktif' THEN 1 END) AS j_ngempi,
                                                        COUNT(CASE WHEN sm.status = 'aktif' THEN sm.id END) AS t_semeton
                                                        FROM wilayah w
                                                        JOIN sub_wilayah s ON w.id_wilayah = s.id_wilayah
                                                        LEFT JOIN semeton sm ON sm.sub_wilayah = s.id_sub
                                                        GROUP BY w.nama_wilayah, s.nama_sub
                                                        ORDER BY w.nama_wilayah, s.nama_sub";
                                        $result_rekap = $koneksi->query($query_rekap);

                                        $last_wilayah = "";
                                        while ($row = $result_rekap->fetch_assoc()): ?>
                                            <tr>
                                                <td class="ps-3 fw-bold text-dark">
                                                    <?= ($last_wilayah != $row['nama_wilayah']) ? $row['nama_wilayah'] : "" ?>
                                                </td>
                                                <td><span class="badge bg-secondary opacity-75 fw-normal"><?= $row['nama_sub'] ?></span></td>
                                                <td class="text-center"><?= $row['j_ngarep'] ?></td>
                                                <td class="text-center"><?= $row['j_ngempi'] ?></td>
                                                <td class="text-center fw-bold text-primary"><?= $row['t_semeton'] ?></td>
                                            </tr>
                                        <?php
                                            $last_wilayah = $row['nama_wilayah'];
                                        endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>