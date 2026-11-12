<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('pembayaran_detail'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

$dataHutang = [];
$userInfo = null;
$koneksi_error = false;

if (!isset($koneksi) || (isset($koneksi) && $koneksi->connect_error)) {
    $koneksi_error = true;
} else {

    $query = "SELECT 
        u.username, h.id_user, s.user_id, s.nama, s.jenis_user, 
        w.nama_wilayah, sw.nama_sub, o.nama_odalan, o.id AS id_odalan, 
        h.jumlah_hutang, h.status,
        (SELECT p.id_pembayaran FROM pembayaran p 
         WHERE p.id_semeton = s.id AND p.id_piodalan = o.id AND p.dibatalkan_at IS NULL
         ORDER BY p.id_pembayaran DESC LIMIT 1) AS id_pembayaran
    FROM hutang h
    JOIN semeton s ON h.id_semeton = s.id
    JOIN odalan o ON h.id_odalan = o.id
    LEFT JOIN user u ON h.id_user = u.id_user
    LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
    LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
    WHERE s.user_id = ?
    ORDER BY o.tanggal DESC;";

    $stmt = $koneksi->prepare($query);
    if ($stmt) {
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $dataHutang[] = $row;
        }
        $stmt->close();
    }

    $userInfo = !empty($dataHutang) ? $dataHutang[0] : null;

    // Riwayat pembayaran yang dibatalkan (soft-cancel) untuk semeton ini.
    $dataBatal = [];
    $stmtB = $koneksi->prepare(
        "SELECT p.id_pembayaran, p.jumlah_bayar, p.tanggal_bayar, p.dibatalkan_at,
                p.alasan_batal, o.nama_odalan, ub.username AS oleh
         FROM pembayaran p
         JOIN semeton s ON s.id = p.id_semeton
         JOIN odalan o ON o.id = p.id_piodalan
         LEFT JOIN user ub ON ub.id_user = p.dibatalkan_oleh
         WHERE s.user_id = ? AND p.dibatalkan_at IS NOT NULL
         ORDER BY p.dibatalkan_at DESC"
    );
    if ($stmtB) {
        $stmtB->bind_param('s', $id);
        $stmtB->execute();
        $resB = $stmtB->get_result();
        while ($rowB = $resB->fetch_assoc()) {
            $dataBatal[] = $rowB;
        }
        $stmtB->close();
    }
}
?>

<main class="app-main">

    <!-- HEADER -->
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-3">Detail Pembayaran Semeton</h3>
            <a href="index.php?menu=pembayaran" class="nav-link">
                <i class="fas fa-angles-left"></i> Kembali
            </a>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <?php if ($koneksi_error) : ?>
                <div class="alert alert-danger">Koneksi database gagal</div>
            <?php endif; ?>

            <!-- DATA -->
            <div class="card shadow-sm p-4 mb-4">
                <h5 class="text-primary fw-bold mb-3">
                    <i class="fas fa-user-circle"></i> Data Semeton
                </h5>

                <div class="row">
                    <div class="col-lg-6">
                        <ul class="list-group border rounded">
                            <li class="list-group-item">
                                ID <span class="float-end"><?= htmlspecialchars((string) ($userInfo['user_id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                            </li>
                            <li class="list-group-item">
                                Nama <span class="float-end"><?= htmlspecialchars((string) ($userInfo['nama'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                            </li>
                            <li class="list-group-item">
                                Jenis <span class="badge bg-info float-end"><?= htmlspecialchars((string) ($userInfo['jenis_user'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                            </li>
                        </ul>
                    </div>

                    <div class="col-lg-6">
                        <ul class="list-group border rounded">
                            <li class="list-group-item">
                                Wilayah <span class="float-end"><?= htmlspecialchars((string) ($userInfo['nama_wilayah'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                            </li>
                            <li class="list-group-item">
                                Sub <span class="float-end"><?= htmlspecialchars((string) ($userInfo['nama_sub'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                            </li>
                            <li class="list-group-item">
                                Total
                                <span class="float-end text-danger fw-bold">
                                    Rp <?= number_format(array_sum(array_column($dataHutang, 'jumlah_hutang')), 0, ',', '.') ?>
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- TABEL -->
            <div class="card shadow-sm p-4">
                <h5 class="text-primary fw-bold mb-3">
                    <i class="fas fa-receipt"></i> Histori Kewajiban
                </h5>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>No</th>
                                <th>Piodalan</th>
                                <th>Iuran</th>
                                <th>Status</th>
                                <th>Petugas</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($dataHutang as $i => $row): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>

                                    <td>
                                        <strong><?= htmlspecialchars($row['nama_odalan'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    </td>

                                    <td class="text-end">
                                        Rp <?= number_format($row['jumlah_hutang'], 0, ',', '.') ?>
                                    </td>

                                    <td>
                                        <?php if ($row['status'] == 'lunas'): ?>
                                            <span class="badge bg-success">Lunas</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Belum</span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= htmlspecialchars((string) ($row['username'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>

                                    <td>
                                        <?php if ($row['status'] != 'lunas'): ?>

                                            <button class="btn btn-warning btn-sm bayar-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalBayar"
                                                data-userid="<?= htmlspecialchars((string) $row['user_id'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-piodalan="<?= (int) $row['id_odalan'] ?>"
                                                data-jenis="<?= htmlspecialchars((string) $row['jenis_user'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-nama="<?= htmlspecialchars((string) $row['nama'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-nama-piodalan="<?= htmlspecialchars((string) $row['nama_odalan'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-hutang="<?= (float) $row['jumlah_hutang'] ?>">
                                                Bayar
                                            </button>

                                        <?php else: ?>

                                            <a href="cetak_nota.php?id=<?= urlencode((string) $row['user_id']) ?>&piodalan=<?= (int) $row['id_odalan'] ?>"
                                                class="btn btn-success btn-sm" target="_blank">
                                                Nota
                                            </a>

                                            <!-- EDIT -->
                                            <button class="btn btn-info btn-sm edit-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalEdit"
                                                data-id="<?= (int) $row['id_pembayaran'] ?>"
                                                data-user="<?= htmlspecialchars((string) $row['user_id'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-piodalan="<?= (int) $row['id_odalan'] ?>">
                                                Edit
                                            </button>

                                            <!-- BATAL -->
                                            <button class="btn btn-danger btn-sm batal-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalBatal"
                                                data-id="<?= (int) $row['id_pembayaran'] ?>"
                                                data-user="<?= htmlspecialchars((string) $row['user_id'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-piodalan="<?= (int) $row['id_odalan'] ?>">
                                                Batal
                                            </button>

                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                    </table>
                </div>
            </div>

            <?php if (!empty($dataBatal)): ?>
                <!-- RIWAYAT PEMBATALAN (soft-cancel) -->
                <div class="card shadow-sm p-4 mt-4">
                    <h5 class="text-danger fw-bold mb-3">
                        <i class="fas fa-ban"></i> Riwayat Pembayaran Dibatalkan
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Piodalan</th>
                                    <th class="text-end">Nominal</th>
                                    <th>Tanggal Bayar</th>
                                    <th>Dibatalkan</th>
                                    <th>Oleh</th>
                                    <th>Alasan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dataBatal as $b): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $b['nama_odalan'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="text-end">Rp <?= number_format((float) $b['jumlah_bayar'], 0, ',', '.') ?></td>
                                        <td><?= htmlspecialchars((string) $b['tanggal_bayar'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $b['dibatalkan_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($b['oleh'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($b['alasan_batal'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</main>


<!-- ================= MODAL BAYAR ================= -->
<div class="modal fade" id="modalBayar">
    <div class="modal-dialog">
        <div class="modal-content">

            <div class="modal-header bg-warning text-white">
                <h5>Proses Pembayaran</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form action="aksi/proses_pembayaran.php" method="POST" id="formBayar">
                <div class="modal-body">

                    <input type="hidden" name="id_semeton" id="id_semeton">
                    <input type="hidden" name="id_piodalan" id="id_piodalan">
                    <input type="hidden" name="jenis_user" id="jenis_user">
                    <input type="hidden" name="jumlah_iuran_nominal" id="jumlah_iuran_nominal">

                    <label>Nama</label>
                    <input type="text" id="nama_semeton" class="form-control mb-2" readonly>

                    <label>Piodalan</label>
                    <input type="text" id="nama_piodalan" class="form-control mb-2" readonly>

                    <label>Jumlah</label>
                    <input type="text" id="jumlah_hutang_display" class="form-control mb-2" readonly>

                    <label>Tanggal</label>
                    <input type="datetime-local" name="tgl_bayar"
                        value="<?= date('Y-m-d\TH:i') ?>" class="form-control mb-2">

                    <label>Metode</label>
                    <select name="metode_pembayaran" class="form-control">
                        <option value="tunai">Tunai</option>
                        <option value="transfer">Transfer</option>
                        <option value="QRIS">QRIS</option>
                    </select>

                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-warning" id="btnBayar">Bayar</button>
                </div>
            </form>

        </div>
    </div>
</div>


<!-- ================= MODAL EDIT ================= -->
<div class="modal fade" id="modalEdit">
    <div class="modal-dialog">
        <div class="modal-content">

            <form action="aksi/edit_pembayaran.php" method="POST">
                <div class="modal-header bg-info text-white">
                    <h5>Edit Pembayaran</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="id_pembayaran" id="edit_id">
                    <input type="hidden" name="id_semeton" id="edit_user">
                    <input type="hidden" name="id_piodalan" id="edit_piodalan">

                    <input type="datetime-local" name="tgl_bayar" id="edit_tgl" class="form-control mb-2">

                    <select name="metode_pembayaran" class="form-control">
                        <option value="tunai">Tunai</option>
                        <option value="transfer">Transfer</option>
                        <option value="QRIS">QRIS</option>
                    </select>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button class="btn btn-info">Simpan</button>
                </div>
            </form>

        </div>
    </div>
</div>


<!-- ================= MODAL BATAL ================= -->
<div class="modal fade" id="modalBatal">
    <div class="modal-dialog">
        <div class="modal-content">

            <form action="aksi/batal_pembayaran.php" method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5>Batal Pembayaran</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="id_pembayaran" id="batal_id">
                    <input type="hidden" name="id_semeton" id="batal_user">
                    <input type="hidden" name="id_piodalan" id="batal_piodalan">

                    <p class="mb-2">Yakin ingin membatalkan pembayaran?</p>
                    <label class="form-label small text-muted">Alasan pembatalan (opsional)</label>
                    <input type="text" name="alasan_batal" maxlength="255" class="form-control"
                        placeholder="mis. salah input nominal / pembayaran ganda">
                </div>

                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Tidak</button>
                    <button class="btn btn-danger">Ya</button>
                </div>
            </form>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function() {

        document.querySelectorAll('.bayar-btn').forEach(btn => {
            btn.onclick = function() {
                id_semeton.value = this.dataset.userid;
                id_piodalan.value = this.dataset.piodalan;
                jenis_user.value = this.dataset.jenis;
                nama_semeton.value = this.dataset.nama;
                nama_piodalan.value = this.dataset.namaPiodalan;
                jumlah_hutang_display.value = "Rp " + parseInt(this.dataset.hutang).toLocaleString('id-ID');
                jumlah_iuran_nominal.value = this.dataset.hutang;
            }
        });

        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.onclick = function() {
                edit_id.value = this.dataset.id;
                edit_user.value = this.dataset.user;
                edit_piodalan.value = this.dataset.piodalan;
                edit_tgl.value = new Date().toISOString().slice(0, 16);
            }
        });

        document.querySelectorAll('.batal-btn').forEach(btn => {
            btn.onclick = function() {
                batal_id.value = this.dataset.id;
                batal_user.value = this.dataset.user;
                batal_piodalan.value = this.dataset.piodalan;
            }
        });

        // ---------------------------------------------------------------
        // Cegah klik ganda pada tombol Bayar.
        // Saat jaringan lambat, pengguna sering mengklik tombol berulang kali
        // sehingga beberapa request terkirim sekaligus. Server sudah menolak
        // pembayaran kedua (kunci baris + cek pembayaran aktif), tetapi
        // tombolnya juga dinonaktifkan agar tidak ada request tambahan.
        // ---------------------------------------------------------------
        const formBayar = document.getElementById('formBayar');
        const btnBayar  = document.getElementById('btnBayar');

        if (formBayar && btnBayar) {
            formBayar.addEventListener('submit', function() {
                if (btnBayar.dataset.proses === '1') {
                    return; // submit kedua diabaikan
                }
                btnBayar.dataset.proses = '1';
                btnBayar.disabled = true;
                btnBayar.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Memproses...';
            });
        }

        // Kembalikan tombol saat halaman dipulihkan dari cache (tombol Back).
        window.addEventListener('pageshow', function() {
            if (btnBayar) {
                btnBayar.dataset.proses = '';
                btnBayar.disabled = false;
                btnBayar.innerHTML = 'Bayar';
            }
        });

    });
</script>