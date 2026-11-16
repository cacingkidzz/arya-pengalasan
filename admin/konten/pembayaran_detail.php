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
        u.username, h.id_user, s.id AS id_semeton, s.user_id, s.nama, s.jenis_user, s.no_hp, 
        w.nama_wilayah, sw.nama_sub, o.nama_odalan, o.id AS id_odalan, o.tanggal AS tanggal_odalan,
        h.jumlah_hutang, h.jumlah_tagihan_asli, h.status,
        p.id_pembayaran, p.jumlah_bayar, p.metode_pembayaran, p.tanggal_bayar,
        up.nama_user AS petugas_bayar
    FROM hutang h
    JOIN semeton s ON h.id_semeton = s.id
    JOIN odalan o ON h.id_odalan = o.id
    LEFT JOIN user u ON h.id_user = u.id_user
    LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
    LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
    LEFT JOIN pembayaran p ON p.id_semeton = s.id AND p.id_piodalan = o.id AND p.dibatalkan_at IS NULL
    LEFT JOIN user up ON up.id_user = p.id_user
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

    // No. HP untuk tombol isi/ubah cepat (fitur 2026-11-15).
    $no_hp_isi = '';
    if ($userInfo) {
        $no_hp_isi = trim((string) ($userInfo['no_hp'] ?? ''));
        if ($no_hp_isi === '-') {
            $no_hp_isi = '';
        }
    }

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
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                <span>No. HP</span>
                                <button type="button"
                                    class="btn btn-sm <?= $no_hp_isi !== '' ? 'btn-outline-primary' : 'btn-primary' ?> rounded-pill btn-no-hp"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalNoHp"
                                    data-userid="<?= htmlspecialchars((string) ($userInfo['user_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    data-nama="<?= htmlspecialchars((string) ($userInfo['nama'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    data-nohp="<?= htmlspecialchars($no_hp_isi, ENT_QUOTES, 'UTF-8') ?>"
                                    title="Klik untuk mengisi / mengubah No. HP">
                                    <?php if ($no_hp_isi !== '') : ?>
                                        <i class="fas fa-phone me-1"></i><?= htmlspecialchars($no_hp_isi, ENT_QUOTES, 'UTF-8') ?>
                                        <i class="fas fa-pen ms-1" style="font-size: 10px;"></i>
                                    <?php else : ?>
                                        <i class="fas fa-plus me-1"></i>Isi No. HP
                                    <?php endif; ?>
                                </button>
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
                                <?php
                                // Nomor nota dipakai di PDF & pesan WhatsApp.
                                $nomor_nota = '';
                                if (!empty($row['id_pembayaran'])) {
                                    $ts_nota = strtotime((string) $row['tanggal_bayar']);
                                    $nomor_nota = 'NOTA-' . date('Ym', $ts_nota ?: time()) . '-'
                                        . str_pad((string) $row['id_pembayaran'], 5, '0', STR_PAD_LEFT);
                                }

                                // Data untuk tombol WhatsApp (dipakai juga oleh PDF nota).
                                $wa_data = [
                                    'data-nama'    => (string) $row['nama'],
                                    'data-userid'  => (string) $row['user_id'],
                                    'data-nohp'    => (string) preg_replace('/[^0-9]/', '', (string) ($row['no_hp'] ?? '')),
                                    'data-odalan'  => (string) $row['nama_odalan'],
                                    'data-jumlah'  => (string) (float) ($row['jumlah_bayar'] ?? 0),
                                    'data-tanggal' => (string) ($row['tanggal_bayar'] ?? ''),
                                    'data-tanggal-odalan' => (string) ($row['tanggal_odalan'] ?? ''),
                                    'data-metode'  => (string) ($row['metode_pembayaran'] ?? ''),
                                    'data-petugas' => (string) ($row['petugas_bayar'] ?? ''),
                                    'data-wilayah' => (string) ($row['nama_wilayah'] ?? ''),
                                    'data-sub'     => (string) ($row['nama_sub'] ?? ''),
                                    'data-jenis'   => ucfirst((string) $row['jenis_user']),
                                    'data-nota'    => $nomor_nota,
                                ];
                                $wa_atribut = '';
                                foreach ($wa_data as $kunci => $nilai) {
                                    $wa_atribut .= ' ' . $kunci . '="' . htmlspecialchars($nilai, ENT_QUOTES, 'UTF-8') . '"';
                                }
                                ?>
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

                                            <!-- PENGINGAT TUNGGAKAN (WhatsApp) -->
                                            <button type="button"
                                                class="btn btn-outline-success btn-sm btn-wa"
                                                data-status="belum"
                                                data-tagihan="<?= (float) $row['jumlah_hutang'] ?>"
                                                <?= $wa_atribut ?>
                                                title="Kirim pengingat tagihan via WhatsApp">
                                                <i class="fab fa-whatsapp"></i> WA
                                            </button>

                                        <?php else: ?>

                                            <a href="cetak_nota.php?id=<?= urlencode((string) $row['user_id']) ?>&piodalan=<?= (int) $row['id_odalan'] ?>"
                                                class="btn btn-success btn-sm" target="_blank">
                                                Nota
                                            </a>

                                            <!-- KIRIM NOTA PDF (WhatsApp) -->
                                            <button type="button"
                                                class="btn btn-success btn-sm btn-wa"
                                                data-status="lunas"
                                                data-tagihan="<?= (float) $row['jumlah_tagihan_asli'] ?>"
                                                <?= $wa_atribut ?>
                                                title="Kirim nota PDF via WhatsApp">
                                                <i class="fab fa-whatsapp"></i> WA
                                            </button>

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


<!-- ================= MODAL NO. HP ================= -->
<div class="modal fade" id="modalNoHp" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <form action="aksi/aksi_no_hp.php" method="POST" id="formNoHp">
                <?= kbap_csrf_field() ?>
                <input type="hidden" name="aksi" value="simpan_no_hp">
                <input type="hidden" name="sumber" value="detail">
                <input type="hidden" name="user_id" id="noHpUserId">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-phone me-2"></i>No. HP Semeton</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <p class="small text-muted mb-1">Semeton</p>
                    <p class="fw-bold mb-3" id="noHpNama">-</p>

                    <label class="form-label" for="noHpInput">No. HP / WhatsApp</label>
                    <input type="tel" class="form-control" id="noHpInput" name="no_hp"
                        maxlength="15" inputmode="numeric" autocomplete="off"
                        placeholder="08xxxxxxxxxx">
                    <div class="form-text">
                        Hanya angka, <strong>8&ndash;15 digit</strong>. Boleh diawali <code>0</code> atau <code>62</code>.
                        Kosongkan lalu simpan untuk menghapus nomor.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Simpan No. HP</button>
                </div>
            </form>
        </div>
    </div>
</div>


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
        // Modal No. HP: isi otomatis dari tombol yang diklik.
        // ---------------------------------------------------------------
        const modalNoHp = document.getElementById('modalNoHp');
        const inputNoHp = document.getElementById('noHpInput');

        if (modalNoHp && inputNoHp) {
            modalNoHp.addEventListener('show.bs.modal', function(event) {
                const btn = event.relatedTarget;
                if (!btn) return;
                document.getElementById('noHpUserId').value = btn.getAttribute('data-userid') || '';
                document.getElementById('noHpNama').textContent = btn.getAttribute('data-nama') || '-';
                inputNoHp.value = btn.getAttribute('data-nohp') || '';
            });

            modalNoHp.addEventListener('shown.bs.modal', function() {
                inputNoHp.focus();
                inputNoHp.select();
            });

            inputNoHp.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }

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

    // =================================================================
    // Tombol WhatsApp: kirim nota PDF / pengingat tagihan.
    //
    // - Ponsel (Android/iOS): PDF langsung dibagikan lewat share sheet
    //   sehingga bisa dipilih WhatsApp (file terlampir otomatis).
    // - Desktop: chat WhatsApp dibuka di tab baru dan PDF diunduh,
    //   petugas tinggal melampirkan berkasnya pada chat tersebut.
    // =================================================================
    (function() {
        'use strict';

        const BULAN_ID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];

        // ---------------------------------------------------------
        // Pembantu format
        // ---------------------------------------------------------
        function tanggalIndo(teks, denganJam) {
            const m = String(teks || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
            if (!m) return '-';
            let hasil = parseInt(m[3], 10) + ' ' + (BULAN_ID[parseInt(m[2], 10) - 1] || '') + ' ' + m[1];
            if (denganJam && m[4]) {
                hasil += ' ' + m[4] + ':' + m[5];
            }
            return hasil;
        }

        function rupiah(nilai) {
            return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(Number(nilai) || 0));
        }

        function angkaKeKata(angka) {
            angka = Math.round(Number(angka) || 0);
            if (angka < 0) return 'minus ' + angkaKeKata(-angka);
            const satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima',
                'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'
            ];
            if (angka < 12) return satuan[angka];
            if (angka < 20) return (angkaKeKata(angka - 10) + ' belas').trim();
            if (angka < 100) return (angkaKeKata(Math.floor(angka / 10)) + ' puluh ' + angkaKeKata(angka % 10)).trim();
            if (angka < 200) return ('seratus ' + angkaKeKata(angka - 100)).trim();
            if (angka < 1000) return (angkaKeKata(Math.floor(angka / 100)) + ' ratus ' + angkaKeKata(angka % 100)).trim();
            if (angka < 2000) return ('seribu ' + angkaKeKata(angka - 1000)).trim();
            if (angka < 1e6) return (angkaKeKata(Math.floor(angka / 1e3)) + ' ribu ' + angkaKeKata(angka % 1e3)).trim();
            if (angka < 1e9) return (angkaKeKata(Math.floor(angka / 1e6)) + ' juta ' + angkaKeKata(angka % 1e6)).trim();
            if (angka < 1e12) return (angkaKeKata(Math.floor(angka / 1e9)) + ' miliar ' + angkaKeKata(angka % 1e9)).trim();
            return (angkaKeKata(Math.floor(angka / 1e12)) + ' triliun ' + angkaKeKata(angka % 1e12)).trim();
        }

        function terbilang(nilai) {
            const kata = angkaKeKata(nilai).replace(/\s+/g, ' ').trim();
            return (kata ? kata.charAt(0).toUpperCase() + kata.slice(1) : 'Nol') + ' rupiah';
        }

        /** 08xx / 8xx / 62xx -> 62xx (format wa.me). */
        function nomorWa(noHp) {
            let digit = String(noHp || '').replace(/[^0-9]/g, '');
            if (!digit) return '';
            if (digit.charAt(0) === '0') {
                digit = '62' + digit.slice(1);
            } else if (digit.charAt(0) === '8') {
                digit = '62' + digit;
            }
            return digit;
        }

        function pesanNota(d) {
            return 'Om Swastyastu, Bapak/Ibu *' + d.nama + '*.\n\n' +
                'Terima kasih, pembayaran iuran odalan *' + d.odalan + '* sebesar *' + rupiah(d.jumlah) + '*' +
                ' telah kami terima' + (d.tanggal ? ' pada ' + tanggalIndo(d.tanggal, true) : '') +
                (d.metode ? ' (metode ' + d.metode + ')' : '') + '.\n\n' +
                'Nota pembayaran resmi terlampir dalam bentuk PDF. Mohon disimpan sebagai bukti pembayaran.\n\n' +
                'Arya Pengalasan Pusat\nBedulu, Gianyar, Bali';
        }

        function pesanPengingat(d) {
            return 'Om Swastyastu, Bapak/Ibu *' + d.nama + '*.\n\n' +
                'Kami mengingatkan bahwa iuran odalan *' + d.odalan + '* sebesar *' + rupiah(d.tagihan) + '*' +
                ' belum tercatat lunas.\n\n' +
                'Pembayaran dapat dilakukan melalui kasir/petugas Arya Pengalasan Pusat.\n\n' +
                'Terima kasih.';
        }

        function bukaWhatsApp(jendela, url) {
            if (jendela && !jendela.closed) {
                try {
                    jendela.location.href = url;
                    jendela.focus();
                    return;
                } catch (e) {
                    // lanjut ke cara biasa
                }
            }
            const baru = window.open(url, '_blank');
            if (!baru) {
                window.location.href = url;
            }
        }

        function unduhBlob(blob, namaFile) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = namaFile;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function() {
                URL.revokeObjectURL(url);
            }, 4000);
        }

        // ---------------------------------------------------------
        // PDF nota (pdfmake, sudah dimuat di index.php)
        // ---------------------------------------------------------
        let logoCache = null;

        function logoDataUrl() {
            if (logoCache) return Promise.resolve(logoCache);
            return new Promise(function(resolve) {
                const img = new Image();
                img.onload = function() {
                    try {
                        const sisi = 200;
                        const kanvas = document.createElement('canvas');
                        kanvas.width = sisi;
                        kanvas.height = sisi;
                        kanvas.getContext('2d').drawImage(img, 0, 0, sisi, sisi);
                        logoCache = kanvas.toDataURL('image/png');
                        resolve(logoCache);
                    } catch (e) {
                        resolve('');
                    }
                };
                img.onerror = function() {
                    resolve('');
                };
                img.src = 'dist/assets/img/bg-logo.png';
            });
        }

        function buatPdfNota(d) {
            return logoDataUrl().then(function(logo) {
                const label = function(teks) {
                    return { text: teks, color: '#64748b', fillColor: '#f8fafc', fontSize: 8.5 };
                };
                const nilai = function(teks, opsi) {
                    const hasil = (teks === '' || teks === null || typeof teks === 'undefined') ? '-' : String(teks);
                    return Object.assign({ text: hasil, fontSize: 8.5 }, opsi || {});
                };
                const judulBlok = function(teks) {
                    return {
                        table: {
                            widths: ['*'],
                            body: [
                                [{
                                    text: teks,
                                    bold: true,
                                    fontSize: 8,
                                    color: '#1e3c72',
                                    fillColor: '#eef2f7',
                                    margin: [6, 3, 6, 3]
                                }]
                            ]
                        },
                        layout: 'noBorders',
                        margin: [0, 10, 0, 3]
                    };
                };
                const layoutTabel = {
                    hLineWidth: function(i, node) {
                        return (i === 0 || i === node.table.body.length) ? 0 : 0.5;
                    },
                    vLineWidth: function() {
                        return 0;
                    },
                    hLineColor: function() {
                        return '#e2e8f0';
                    },
                    paddingLeft: function() {
                        return 6;
                    },
                    paddingRight: function() {
                        return 6;
                    },
                    paddingTop: function() {
                        return 4;
                    },
                    paddingBottom: function() {
                        return 4;
                    }
                };

                const sekarang = new Date();
                const duaAngka = function(n) {
                    return String(n).padStart(2, '0');
                };
                const dicetak = duaAngka(sekarang.getDate()) + '/' + duaAngka(sekarang.getMonth() + 1) + '/' +
                    sekarang.getFullYear() + ' ' + duaAngka(sekarang.getHours()) + ':' + duaAngka(sekarang.getMinutes());

                const dd = {
                    pageSize: 'A4',
                    pageMargins: [42, 36, 42, 40],
                    defaultStyle: { font: 'Roboto', fontSize: 9, color: '#1f2937' },
                    content: [
                        {
                            columns: [
                                logo ? { image: logo, width: 52 } : { text: '', width: 0 },
                                {
                                    width: '*',
                                    margin: [12, 3, 0, 0],
                                    stack: [
                                        { text: 'ARYA PENGALASAN PUSAT', bold: true, fontSize: 16, color: '#1e3c72' },
                                        { text: 'Bedulu, Gianyar, Bali', fontSize: 9, color: '#475569', margin: [0, 3, 0, 0] },
                                        { text: 'Sistem Informasi Krama Bali - Nota Pembayaran Urunan', italics: true, fontSize: 8, color: '#7c8794', margin: [0, 1, 0, 0] }
                                    ]
                                }
                            ]
                        },
                        {
                            canvas: [
                                { type: 'line', x1: 0, y1: 0, x2: 511, y2: 0, lineWidth: 2, lineColor: '#1e3c72' },
                                { type: 'line', x1: 0, y1: 3, x2: 511, y2: 3, lineWidth: 0.7, lineColor: '#1e3c72' }
                            ],
                            margin: [0, 12, 0, 0]
                        },
                        {
                            columns: [
                                {
                                    width: '*',
                                    stack: [
                                        { text: 'NOTA PEMBAYARAN URUNAN', bold: true, fontSize: 13, color: '#111827' },
                                        { text: 'Bukti pembayaran iuran odalan anggota', fontSize: 8, color: '#6b7280', margin: [0, 2, 0, 0] }
                                    ]
                                },
                                {
                                    width: 'auto',
                                    alignment: 'right',
                                    stack: [
                                        { text: 'No. Nota', fontSize: 8, color: '#6b7280' },
                                        { text: d.nota || '-', bold: true, fontSize: 10, color: '#1e3c72' },
                                        { text: 'Dicetak: ' + dicetak, fontSize: 8, color: '#6b7280' }
                                    ]
                                }
                            ],
                            margin: [0, 14, 0, 0]
                        },
                        judulBlok('DATA ANGGOTA'),
                        {
                            table: {
                                widths: ['22%', '28%', '22%', '28%'],
                                body: [
                                    [label('Nama'), nilai(d.nama, { bold: true, colSpan: 3 }), '', ''],
                                    [label('User ID'), nilai(d.userid), label('Jenis Anggota'), nilai(d.jenis)],
                                    [label('Wilayah'), nilai(d.wilayah), label('Sub Wilayah'), nilai(d.sub)]
                                ]
                            },
                            layout: layoutTabel
                        },
                        judulBlok('RINCIAN PEMBAYARAN'),
                        {
                            table: {
                                widths: ['22%', '28%', '22%', '28%'],
                                body: [
                                    [label('Odalan'), nilai(d.odalan, { colSpan: 3 }), '', ''],
                                    [label('Tanggal Odalan'), nilai(tanggalIndo(d.tanggalOdalan)), label('Tanggal Bayar'), nilai(tanggalIndo(d.tanggal, true))],
                                    [label('Metode'), nilai(d.metode ? d.metode.charAt(0).toUpperCase() + d.metode.slice(1) : '-'), label('Petugas'), nilai(d.petugas)],
                                    [label('Tagihan'), nilai(rupiah(d.tagihan)), label('Status'), { text: 'LUNAS', bold: true, fontSize: 8, color: '#15803d', fillColor: '#ecfdf3' }]
                                ]
                            },
                            layout: layoutTabel
                        },
                        {
                            table: {
                                widths: ['*', 'auto'],
                                body: [
                                    [{
                                        stack: [
                                            { text: 'JUMLAH DIBAYAR', bold: true, fontSize: 8, color: '#475569' },
                                            { text: 'Terbilang: ' + terbilang(d.jumlah), italics: true, fontSize: 8, color: '#64748b', margin: [0, 2, 0, 0] }
                                        ],
                                        fillColor: '#f3f7ff'
                                    }, {
                                        text: rupiah(d.jumlah),
                                        bold: true,
                                        fontSize: 17,
                                        color: '#1e3c72',
                                        alignment: 'right',
                                        fillColor: '#f3f7ff'
                                    }]
                                ]
                            },
                            layout: {
                                hLineWidth: function() {
                                    return 1.2;
                                },
                                vLineWidth: function() {
                                    return 1.2;
                                },
                                hLineColor: function() {
                                    return '#1e3c72';
                                },
                                vLineColor: function() {
                                    return '#1e3c72';
                                },
                                paddingLeft: function() {
                                    return 10;
                                },
                                paddingRight: function() {
                                    return 12;
                                },
                                paddingTop: function() {
                                    return 7;
                                },
                                paddingBottom: function() {
                                    return 7;
                                }
                            },
                            margin: [0, 12, 0, 0]
                        },
                        {
                            columns: [
                                {
                                    width: '*',
                                    stack: [
                                        { text: 'Catatan', bold: true, fontSize: 8.2, color: '#475569' },
                                        { text: 'Nota ini merupakan bukti pembayaran yang sah untuk iuran odalan ' + d.odalan + '. Mohon disimpan dengan baik sebagai kelengkapan administrasi. Terima kasih atas partisipasi Anda.', fontSize: 8, color: '#64748b', margin: [0, 2, 0, 0] }
                                    ]
                                },
                                {
                                    width: 180,
                                    alignment: 'center',
                                    stack: [
                                        { text: 'Bedulu, ' + tanggalIndo(d.tanggal), fontSize: 8.5, color: '#334155' },
                                        { text: 'Bendahara,', bold: true, fontSize: 8.5, color: '#334155', margin: [0, 2, 0, 40] },
                                        { text: d.petugas || '(................................)', bold: true, fontSize: 8.5, color: '#111827' }
                                    ]
                                }
                            ],
                            margin: [0, 16, 0, 0]
                        },
                        {
                            canvas: [{ type: 'line', x1: 0, y1: 0, x2: 511, y2: 0, lineWidth: 0.6, lineColor: '#cbd5e1', dash: { length: 3, space: 3 } }],
                            margin: [0, 16, 0, 4]
                        },
                        {
                            columns: [
                                { text: 'Arya Pengalasan Pusat - Bedulu, Gianyar, Bali', fontSize: 7.5, color: '#94a3b8' },
                                { text: 'Nota ' + (d.nota || '-') + ' - dicetak ' + dicetak, fontSize: 7.5, color: '#94a3b8', alignment: 'right' }
                            ]
                        }
                    ]
                };

                return new Promise(function(resolve, reject) {
                    const batas = setTimeout(function() {
                        reject(new Error('Pembuatan PDF melebihi batas waktu.'));
                    }, 20000);

                    try {
                        pdfMake.createPdf(dd).getBuffer(function(buffer) {
                            clearTimeout(batas);
                            try {
                                resolve(new Blob([buffer], { type: 'application/pdf' }));
                            } catch (e) {
                                reject(e);
                            }
                        });
                    } catch (e) {
                        clearTimeout(batas);
                        reject(e);
                    }
                });
            });
        }

        function namaFileNota(d) {
            const odalan = String(d.odalan || 'odalan').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
            return 'Nota-' + (d.userid || 'anggota') + '-' + odalan + '.pdf';
        }

        // ---------------------------------------------------------
        // Klik tombol WhatsApp
        // ---------------------------------------------------------
        document.querySelectorAll('.btn-wa').forEach(function(tombol) {
            tombol.addEventListener('click', async function() {
                if (tombol.dataset.proses === '1') return;

                const d = tombol.dataset;
                const nomor = nomorWa(d.nohp);

                if (!nomor) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'warning',
                            title: 'No. HP belum diisi',
                            text: 'Isi No. HP/WhatsApp semeton terlebih dahulu, lalu klik tombol WA lagi.'
                        }).then(function() {
                            const btnHp = document.querySelector('.btn-no-hp');
                            if (btnHp) btnHp.click();
                        });
                    } else {
                        alert('No. HP semeton belum diisi.');
                    }
                    return;
                }

                const lunas = d.status === 'lunas';
                const pesan = lunas ? pesanNota(d) : pesanPengingat(d);
                const urlWa = 'https://wa.me/' + nomor + '?text=' + encodeURIComponent(pesan);

                // Pengingat: langsung buka WhatsApp (tanpa PDF).
                if (!lunas) {
                    bukaWhatsApp(null, urlWa);
                    return;
                }

                // Nota: siapkan PDF, lalu kirim.
                if (typeof pdfMake === 'undefined') {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'PDF tidak tersedia',
                            text: 'Pustaka pembuat PDF belum termuat. Muat ulang halaman lalu coba lagi.'
                        });
                    }
                    return;
                }

                const ponsel = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
                // Desktop: buka tab kosong lebih dulu agar tidak diblokir popup.
                const jendela = ponsel ? null : window.open('', '_blank');

                tombol.dataset.proses = '1';
                tombol.disabled = true;

                try {
                    const blob = await buatPdfNota(d);
                    const namaFile = namaFileNota(d);
                    const berkas = new File([blob], namaFile, { type: 'application/pdf' });

                    // Ponsel: bagikan PDF langsung lewat share sheet (pilih WhatsApp).
                    if (ponsel && navigator.canShare && navigator.canShare({ files: [berkas] })) {
                        try {
                            await navigator.share({ files: [berkas], text: pesan });
                            return;
                        } catch (err) {
                            if (err && err.name === 'AbortError') return;
                            // Gagal berbagi: lanjut ke unduh + buka chat.
                        }
                    }

                    unduhBlob(blob, namaFile);
                    bukaWhatsApp(jendela, urlWa);

                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Nota siap dikirim',
                            html: 'PDF <b>' + namaFile + '</b> sudah diunduh.<br>' +
                                'Lampirkan berkas tersebut pada chat WhatsApp yang baru terbuka, lalu tekan Kirim.',
                            confirmButtonText: 'Mengerti'
                        });
                    }
                } catch (err) {
                    if (jendela && !jendela.closed) jendela.close();
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal membuat nota PDF',
                            text: 'Silakan coba lagi. Bila tetap gagal, gunakan tombol Nota lalu simpan sebagai PDF.'
                        });
                    }
                } finally {
                    tombol.dataset.proses = '';
                    tombol.disabled = false;
                }
            });
        });

    })();
</script>