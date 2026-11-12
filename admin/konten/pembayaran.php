<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('pembayaran'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';
?>
<main class="app-main">
    <style>
        .stats-card {
            border: none;
            border-radius: 12px;
            transition: transform 0.2s;
        }

        .stats-card:hover {
            transform: translateY(-5px);
        }

        .bg-soft-success {
            background-color: #d1e7dd;
            color: #0f5132;
        }

        .bg-soft-danger {
            background-color: #f8d7da;
            color: #842029;
        }

        .bg-soft-primary {
            background-color: #cfe2ff;
            color: #084298;
        }

        #tabelPembayaran {
            border-radius: 10px;
            overflow: hidden;
        }

        #tabelPembayaran thead th {
            background-color: #f8f9fa;
            color: #495057;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #dee2e6;
        }

        /* Scanner UI */
        .scanner-window {
            position: relative;
            background: #000;
            border-radius: 15px;
            overflow: hidden;
            border: 4px solid #fff;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .scan-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.1);
            pointer-events: none;
            z-index: 2;
        }

        .scan-line {
            position: absolute;
            width: 100%;
            height: 3px;
            background: #00ff00;
            box-shadow: 0 0 15px #00ff00;
            top: 0;
            animation: scanAnim 2s infinite linear;
            z-index: 3;
        }

        @keyframes scanAnim {
            0% {
                top: 0%;
            }

            100% {
                top: 100%;
            }
        }
    </style>

    <?php
    // =====================================================================
    // PAGINATION SERVER-SIDE (2026-11-08)
    // Sebelumnya seluruh 607 baris dirender sekaligus (±839 KB HTML) dan
    // DataTables sisi klien hanya menyaring baris yang sudah tampil.
    // Sekarang filter + paging dijalankan di SQL (prepared statement).
    // Parameter: q (nama/user_id), wilayah (id), status (Lunas/Belum Lunas),
    //            per (0=semua, 25, 50, 100, 250), hal (nomor halaman).
    // Kartu statistik tetap dihitung dari SELURUH data (bukan hasil filter).
    // =====================================================================
    $esc = static function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };

    // Daftar wilayah untuk dropdown filter (23 baris, satu query)
    $wilayah_list = [];
    if ($q_wilayah = mysqli_query($koneksi, "SELECT id_wilayah, nama_wilayah FROM wilayah ORDER BY nama_wilayah ASC")) {
        while ($r = mysqli_fetch_assoc($q_wilayah)) {
            $wilayah_list[] = $r;
        }
    }

    $f_q       = (isset($_GET['q']) && is_string($_GET['q'])) ? trim($_GET['q']) : '';
    $f_wilayah = (isset($_GET['wilayah']) && is_scalar($_GET['wilayah'])) ? (int) $_GET['wilayah'] : 0;
    $f_status  = (isset($_GET['status']) && is_string($_GET['status'])) ? $_GET['status'] : '';
    if (!in_array($f_status, ['Lunas', 'Belum Lunas'], true)) {
        $f_status = '';
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
        $where[] = '(s.nama LIKE ? OR s.user_id LIKE ?)';
        $like    = '%' . $f_q . '%';
        $tipe   .= 'ss';
        array_push($nilai, $like, $like);
    }
    if ($f_wilayah > 0) {
        $where[]  = 's.wilayah = ?';
        $tipe    .= 'i';
        $nilai[]  = $f_wilayah;
    }
    $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $sql_select = "SELECT s.id, s.user_id, s.nama, s.jenis_user, w.nama_wilayah, sw.nama_sub,
              COALESCE(SUM(h.jumlah_hutang), 0) AS total_hutang,
              CASE WHEN COALESCE(SUM(h.jumlah_hutang), 0) = 0 THEN 'Lunas' ELSE 'Belum Lunas' END AS status
              FROM semeton s
              LEFT JOIN hutang h ON s.id = h.id_semeton
              LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
              LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub";
    $sql_group = " GROUP BY s.id, s.user_id, s.nama, s.jenis_user, w.nama_wilayah, sw.nama_sub";

    $sql_agregat        = $sql_select . $where_sql . $sql_group;
    $sql_agregat_global = $sql_select . $sql_group;

    // --- Statistik global (tidak terpengaruh filter halaman) ---
    $total_anggota = 0;
    $total_lunas   = 0;
    $total_belum   = 0;
    if ($res_stat = $koneksi->query("SELECT COUNT(*) AS total,
            SUM(CASE WHEN x.total_hutang = 0 THEN 1 ELSE 0 END) AS lunas,
            SUM(CASE WHEN x.total_hutang > 0 THEN 1 ELSE 0 END) AS belum
            FROM ($sql_agregat_global) x")) {
        $stat          = $res_stat->fetch_assoc();
        $total_anggota = (int) ($stat['total'] ?? 0);
        $total_lunas   = (int) ($stat['lunas'] ?? 0);
        $total_belum   = (int) ($stat['belum'] ?? 0);
        $res_stat->free();
    }

    // --- Total baris sesuai filter ---
    $jumlah_filter = 0;
    $sql_count = "SELECT COUNT(*) AS c FROM ($sql_agregat) x";
    if ($f_status !== '') {
        $sql_count .= " WHERE x.status = ?";
    }
    if ($stmt = $koneksi->prepare($sql_count)) {
        if ($f_status !== '') {
            $stmt->bind_param($tipe . 's', ...array_merge($nilai, [$f_status]));
        } elseif ($tipe !== '') {
            $stmt->bind_param($tipe, ...$nilai);
        }
        $stmt->execute();
        $jumlah_filter = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }

    // --- Normalisasi halaman ---
    $total_hal = ($per > 0) ? max(1, (int) ceil($jumlah_filter / $per)) : 1;
    if ($hal > $total_hal) {
        $hal = $total_hal;
    }
    $offset = ($per > 0) ? ($hal - 1) * $per : 0;

    // --- Ambil satu halaman data ---
    $data_rows = [];
    $sql = "SELECT * FROM ($sql_agregat) x";
    if ($f_status !== '') {
        $sql .= " WHERE x.status = ?";
    }
    $sql .= " ORDER BY x.user_id ASC";
    if ($per > 0) {
        $sql .= " LIMIT ? OFFSET ?";
    }
    if ($stmt = $koneksi->prepare($sql)) {
        $nilai_data = $nilai;
        if ($f_status !== '') {
            $nilai_data[] = $f_status;
        }
        if ($per > 0) {
            $nilai_data[] = $per;
            $nilai_data[] = $offset;
            $stmt->bind_param($tipe . ($f_status !== '' ? 's' : '') . 'ii', ...$nilai_data);
        } elseif ($nilai_data) {
            $stmt->bind_param($tipe . ($f_status !== '' ? 's' : ''), ...$nilai_data);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $data_rows[] = $r;
        }
        $stmt->close();
    }

    // --- URL bantu pagination (mempertahankan filter) ---
    $param_dasar = ['menu' => 'pembayaran'];
    if ($f_q !== '') {
        $param_dasar['q'] = $f_q;
    }
    if ($f_wilayah > 0) {
        $param_dasar['wilayah'] = $f_wilayah;
    }
    if ($f_status !== '') {
        $param_dasar['status'] = $f_status;
    }
    if ($per !== 50) {
        $param_dasar['per'] = $per;
    }
    $url_hal = static function (int $h) use ($param_dasar): string {
        return 'index.php?' . http_build_query(array_merge($param_dasar, ['hal' => $h]));
    };
    $url_reset = 'index.php?menu=pembayaran';

    // Rentang baris yang sedang tampil
    $dari   = $jumlah_filter > 0 ? $offset + 1 : 0;
    $sampai = $per > 0 ? min($offset + $per, $jumlah_filter) : $jumlah_filter;
    ?>

    <div class="app-content-header py-4">
        <div class="container-fluid">
            <div class="row align-items-center mb-3">
                <div class="col-sm-6">
                    <h3 class="fw-bold mb-0 text-dark">Manajemen Pembayaran</h3>
                    <p class="text-muted small">Kelola iuran dan pantau status pelunasan semeton.</p>
                </div>
                <div class="col-sm-6 text-end">
                    <button class="btn btn-primary shadow px-4 py-2 me-2" onclick="scanQR()">
                        <i class="fas fa-qrcode me-2"></i> Scan QR Pembayaran
                    </button>
                    <a href="index.php?menu=laporan_rekap_semeton" class="btn btn-outline-success px-4 py-2">
                        <i class="fas fa-print me-2"></i> Format Penagihan
                    </a>
                </div>
            </div>

            <div class="row g-1 mb-1">
                <div class="col-md-4">
                    <div class="card stats-card bg-primary text-white p-3 shadow-sm">
                        <div class="d-flex justify-content-between">
                            <div>
                                <p class="mb-0 small opacity-75">Total Anggota</p>
                                <h3 class="mb-0 fw-bold"><?= number_format($total_anggota, 0, ',', '.'); ?></h3>
                            </div>
                            <i class="fas fa-users fs-1 opacity-25"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stats-card bg-success text-white p-3 shadow-sm">
                        <div class="d-flex justify-content-between">
                            <div>
                                <p class="mb-0 small opacity-75">Sudah Lunas</p>
                                <h3 class="mb-0 fw-bold"><?= $total_lunas; ?></h3>
                            </div>
                            <i class="fas fa-check-circle fs-1 opacity-25"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stats-card bg-danger text-white p-3 shadow-sm">
                        <div class="d-flex justify-content-between">
                            <div>
                                <p class="mb-0 small opacity-75">Belum Lunas</p>
                                <h3 class="mb-0 fw-bold"><?= $total_belum; ?></h3>
                            </div>
                            <i class="fas fa-clock fs-1 opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <!-- Filter & pencarian server-side -->
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-body py-3">
                    <form method="GET" action="index.php" class="row g-2 align-items-end">
                        <input type="hidden" name="menu" value="pembayaran">
                        <div class="col-12 col-lg-4">
                            <label class="form-label small text-muted mb-1" for="filter-q">Cari semeton</label>
                            <input type="search" id="filter-q" name="q" class="form-control" value="<?= $esc($f_q) ?>" placeholder="Nama atau ID anggota (APPxxxxx)">
                        </div>
                        <div class="col-6 col-lg-3">
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
                                <option value="Lunas" <?= $f_status === 'Lunas' ? 'selected' : '' ?>>Lunas</option>
                                <option value="Belum Lunas" <?= $f_status === 'Belum Lunas' ? 'selected' : '' ?>>Belum Lunas</option>
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
                            <?php if ($f_q !== '' || $f_wilayah > 0 || $f_status !== '' || $per !== 50) : ?>
                                <a href="<?= $esc($url_reset) ?>" class="btn btn-outline-secondary" title="Reset filter">
                                    <i class="fas fa-rotate-left"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
                <div class="table-responsive">
                    <table id="tabelPembayaran" class="table table-hover align-middle w-100 mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nama Lengkap</th>
                                <th>Wilayah</th>
                                <th>Sub Wilayah</th>
                                <th>Jumlah Hutang</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Opsi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($jumlah_filter === 0) : ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-5">
                                        <?php if ($f_q !== '' || $f_wilayah > 0 || $f_status !== '') : ?>
                                            Tidak ada semeton yang cocok dengan filter. <a href="<?= $esc($url_reset) ?>">Reset filter</a>.
                                        <?php else : ?>
                                            Belum ada data semeton.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($data_rows as $row) : ?>
                                <tr>
                                    <td class="fw-bold">#<?= $esc($row['user_id']); ?></td>
                                    <td>
                                        <div class="fw-bold"><?= $esc($row['nama']); ?></div>
                                        <small class="badge <?php
                                                            echo $row['jenis_user'] == 'ngarep' ? 'bg-soft-success' : ($row['jenis_user'] == 'ngempi' ? 'bg-soft-danger' : 'bg-soft-primary');
                                                            ?> px-2 py-1" style="font-size: 10px;"><?= $esc(strtoupper((string) $row['jenis_user'])); ?></small>
                                    </td>
                                    <td><?= $esc($row['nama_wilayah']); ?></td>
                                    <td><?= $esc($row['nama_sub']); ?></td>
                                    <td class="fw-bold text-dark">Rp <?= number_format((float) $row['total_hutang'], 0, ',', '.'); ?></td>
                                    <td class="text-center">
                                        <?php if ($row['status'] == 'Lunas') : ?>
                                            <span class="badge rounded-pill bg-success px-3 py-2"><i class="fas fa-check me-1"></i> Lunas</span>
                                        <?php else : ?>
                                            <span class="badge rounded-pill bg-danger px-3 py-2"><i class="fas fa-exclamation-triangle me-1"></i> Belum Lunas</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="index.php?menu=pembayaran_detail&id=<?= $esc($row['user_id']); ?>" class="btn btn-sm btn-light border rounded-pill px-3 shadow-sm">
                                            <i class="fas fa-arrow-right text-primary me-1"></i> Detail
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Info & navigasi halaman -->
                <div class="card-footer bg-white border-top px-4 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="text-muted small">
                        Menampilkan <strong><?= (int) $dari ?></strong>&ndash;<strong><?= (int) $sampai ?></strong>
                        dari <strong><?= number_format($jumlah_filter, 0, ',', '.') ?></strong> semeton
                        <?php if ($f_q !== '' || $f_wilayah > 0 || $f_status !== '') : ?>
                            <span class="text-secondary">(difilter dari <?= number_format($total_anggota, 0, ',', '.') ?> total)</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($per > 0 && $total_hal > 1) : ?>
                        <nav aria-label="Navigasi halaman pembayaran">
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
</main>

<div class="modal fade" id="qrScannerModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Scan QR Pembayaran</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="stopScan()"></button>
            </div>
            <div class="modal-body p-0">
                <div id="reader" style="width: 100%;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary w-100" onclick="stopScan()">Tutup</button>
            </div>
        </div>
    </div>
</div>
<script>
    // Inisialisasi variabel global
    var html5QrCode = null;

    // 1. Fungsi untuk Membuka Modal & Jalankan Kamera
    function scanQR() {
        // Membuka modal menggunakan Bootstrap Native
        var myModal = new bootstrap.Modal(document.getElementById('qrScannerModal'));
        myModal.show();
    }

    // 2. Event Listener saat modal muncul (Native JS)
    document.getElementById('qrScannerModal').addEventListener('shown.bs.modal', function() {
        console.log("Memulai Kamera...");

        if (html5QrCode === null) {
            html5QrCode = new Html5Qrcode("reader");
        }

        const config = {
            fps: 10,
            qrbox: {
                width: 250,
                height: 250
            },
            aspectRatio: 1.0
        };

        html5QrCode.start({
                facingMode: "environment"
            },
            config,
            function(decodedText) {
                // Berhasil Scan
                if (navigator.vibrate) navigator.vibrate(100);
                let userId = decodedText.replace("ID: ", "").trim();
                window.location.href = "index.php?menu=pembayaran_detail&id=" + userId;
                stopScan();
            },
            function(err) {
                // Abaikan error saat mencari frame
            }
        ).catch(function(err) {
            console.error("Gagal Kamera:", err);
            // Coba kamera depan jika belakang gagal
            html5QrCode.start({
                facingMode: "user"
            }, config, function(decodedText) {
                let userId = decodedText.replace("ID: ", "").trim();
                window.location.href = "index.php?menu=pembayaran_detail&id=" + userId;
                stopScan();
            }).catch(function(e) {
                alert("Kamera tidak ditemukan atau izin ditolak.");
            });
        });
    });

    // 3. Fungsi Stop Kamera
    function stopScan() {
        if (html5QrCode && html5QrCode.isScanning) {
            html5QrCode.stop().then(() => {
                console.log("Kamera Berhenti.");
                bootstrap.Modal.getInstance(document.getElementById('qrScannerModal')).hide();
            });
        } else {
            var modalEl = document.getElementById('qrScannerModal');
            var modalInst = bootstrap.Modal.getInstance(modalEl);
            if (modalInst) modalInst.hide();
        }
    }

    // Pastikan mati saat modal ditutup manual
    document.getElementById('qrScannerModal').addEventListener('hidden.bs.modal', function() {
        if (html5QrCode && html5QrCode.isScanning) {
            html5QrCode.stop();
        }
    });
</script>