<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('rekap_punia_hari_ini'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// DIPERBAIKI (2026-11-10)
// Sebelumnya: nama donatur & keterangan dirender mentah (XSS), judul
// menampilkan <span id="tanggal-hari-ini"></span> yang tidak pernah diisi,
// dan tidak ada keadaan kosong. Sekarang: query prepared, output di-escape,
// tanggal ditampilkan, dan total dihitung di SQL.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$rupiah = static function ($n) {
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
};

$tanggal_hari_ini = date('Y-m-d');

$data_rows = [];
$total_punia = 0.0;
if ($stmt = $koneksi->prepare('SELECT id_punia, nama_donatur, jumlah_punia, metode_pembayaran, tanggal_punia, keterangan
                               FROM dana_punia
                               WHERE tanggal_punia = ?
                               ORDER BY tanggal_punia DESC, id_punia DESC')) {
    $stmt->bind_param('s', $tanggal_hari_ini);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $data_rows[] = $r;
        $total_punia += (float) $r['jumlah_punia'];
    }
    $stmt->close();
}
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <h3 class="mb-0" id="judul-tabel">Rekap Dana Punia Hari Ini
                        <span class="text-muted fs-6">(<?= date('d-m-Y') ?>)</span>
                    </h3>
                    <p class="text-muted small mb-0">
                        <span class="badge bg-secondary"><?= number_format(count($data_rows), 0, ',', '.') ?> donasi</span>
                        <span class="badge bg-success"><?= $rupiah($total_punia) ?></span>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">
            <div class="card p-4">
                <div class="row">
                    <div class="col-12">
                    <?php if (empty($data_rows)) : ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Belum ada dana punia yang tercatat hari ini.
                        </div>
                    <?php else : ?>
                        <div class="table-responsive">
                            <table id="example" class="table table-bordered table-striped">
                                <thead class="table-dark text-center">
                                    <tr>
                                        <th>ID Punia</th>
                                        <th>Nama Donatur</th>
                                        <th>Jumlah Punia</th>
                                        <th>Metode Pembayaran</th>
                                        <th>Tanggal Punia</th>
                                        <th>Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data_rows as $row) : ?>
                                        <?php $ts = strtotime((string) $row['tanggal_punia']); ?>
                                        <tr>
                                            <td><?= (int) $row['id_punia'] ?></td>
                                            <td><?= $esc($row['nama_donatur']) ?></td>
                                            <td class="text-end"><?= $rupiah($row['jumlah_punia']) ?></td>
                                            <td><?= $esc(ucfirst((string) $row['metode_pembayaran'])) ?></td>
                                            <td class="text-center text-nowrap"><?= $ts ? date('d-m-Y', $ts) : '-' ?></td>
                                            <td><?= $esc($row['keterangan'] !== null && trim((string) $row['keterangan']) !== '' ? $row['keterangan'] : '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-info">
                                        <td></td>
                                        <td class="text-end fw-bold">Total Dana Punia Hari Ini:</td>
                                        <td class="text-end fw-bold"><?= $rupiah($total_punia) ?></td>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
