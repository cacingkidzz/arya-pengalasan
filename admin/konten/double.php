<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('double'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// DIPERBAIKI (2026-11-10)
// Sebelumnya: nama/nama odalan/petugas dirender mentah (XSS), tidak ada
// keadaan kosong, dan konfirmasi hapus memakai confirm() bawaan browser
// (tidak konsisten dengan halaman lain). Total di sini tetap dihitung di
// sisi klien karena SEMUA baris dirender (tanpa DataTables/pagination),
// sehingga angkanya selalu cocok dengan yang tampil.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$rupiah = static function ($n) {
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
};

$sql = "SELECT p.id_pembayaran, s.user_id, s.nama, o.nama_odalan,
               p.jumlah_bayar, p.metode_pembayaran, p.tanggal_bayar,
               p.status, p.id_user, u.username, w.nama_wilayah, sw.nama_sub
        FROM pembayaran p
        JOIN semeton s ON p.id_semeton = s.id
        JOIN odalan o ON p.id_piodalan = o.id
        LEFT JOIN user u ON p.id_user = u.id_user
        LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
        LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
        JOIN (
            SELECT id_semeton, id_piodalan
            FROM pembayaran
            WHERE dibatalkan_at IS NULL
            GROUP BY id_semeton, id_piodalan
            HAVING COUNT(*) > 1
        ) dup
          ON p.id_semeton = dup.id_semeton
         AND p.id_piodalan = dup.id_piodalan
        WHERE p.dibatalkan_at IS NULL
        ORDER BY p.id_semeton, p.id_piodalan, p.tanggal_bayar DESC, p.id_pembayaran DESC";

$data_rows = [];
$total_double = 0.0;
if ($res = $koneksi->query($sql)) {
    while ($r = $res->fetch_assoc()) {
        $data_rows[] = $r;
        $total_double += (float) $r['jumlah_bayar'];
    }
    $res->free();
}

$petugas_list = [];
foreach ($data_rows as $r) {
    $nama_petugas = $r['username'] !== null ? $r['username'] : '-';
    if (!in_array($nama_petugas, $petugas_list, true)) {
        $petugas_list[] = $nama_petugas;
    }
}
sort($petugas_list);
?>

<main class="app-main">
    <div class="container-fluid py-3">
        <h3 class="text-danger fw-bold">
            <i class="bi bi-exclamation-triangle"></i> Data Double Pembayaran
        </h3>
        <p class="text-muted small">
            Daftar semeton yang punya lebih dari satu pembayaran sah pada odalan yang sama.
            <span class="badge bg-danger" id="badgeJumlah"><?= number_format(count($data_rows), 0, ',', '.') ?> baris</span>
            <span class="badge bg-dark"><?= $rupiah($total_double) ?></span>
        </p>

        <!-- FILTER -->
        <div class="mb-3">
            <label class="visually-hidden" for="filterPetugas">Filter petugas</label>
            <select id="filterPetugas" class="form-select form-select-sm" style="width:200px">
                <option value="">Semua Petugas</option>
                <?php foreach ($petugas_list as $nama_petugas) : ?>
                    <option value="<?= $esc($nama_petugas) ?>"><?= $esc($nama_petugas) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- TOTAL -->
        <div class="card bg-danger text-white mb-3">
            <div class="card-body">
                <h6>Total Data Double (sesuai filter)</h6>
                <h3 id="displayTotal" class="mb-0"><?= $rupiah($total_double) ?></h3>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card shadow-sm">
            <div class="card-body table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Nama</th>
                            <th>Piodalan</th>
                            <th>Jumlah</th>
                            <th>Tanggal</th>
                            <th>Petugas</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($data_rows)) : ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fa-solid fa-circle-check fa-2x mb-2 d-block text-success"></i>
                                    Tidak ada pembayaran ganda. Semua bersih.
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($data_rows as $row) : ?>
                                <?php
                                $ts = strtotime((string) $row['tanggal_bayar']);
                                $nama_petugas = $row['username'] !== null ? $row['username'] : '-';
                                ?>
                                <tr class="item-baris table-danger">
                                    <td>#<?= (int) $row['id_pembayaran'] ?></td>
                                    <td><?= $esc($row['nama']) ?></td>
                                    <td><?= $esc($row['nama_odalan']) ?></td>
                                    <td class="col-jumlah" data-value="<?= $esc($row['jumlah_bayar']) ?>">
                                        <?= $rupiah($row['jumlah_bayar']) ?>
                                    </td>
                                    <td class="text-nowrap"><?= $ts ? date('d-m-Y H:i', $ts) : '-' ?></td>
                                    <td class="col-petugas"><?= $esc($nama_petugas) ?></td>
                                    <td>
                                        <?php if ($row['status'] === 'lunas') : ?>
                                            <span class="badge bg-success">Lunas</span>
                                        <?php else : ?>
                                            <span class="badge bg-warning text-dark">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-danger btn-hapus"
                                                data-id="<?= (int) $row['id_pembayaran'] ?>"
                                                data-nama="<?= $esc($row['nama']) ?>"
                                                data-jumlah="<?= $esc($rupiah($row['jumlah_bayar'])) ?>"
                                                title="Batalkan pembayaran ini">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var rows = document.querySelectorAll('.item-baris');
        var filter = document.getElementById('filterPetugas');
        var totalDisplay = document.getElementById('displayTotal');

        function hitungTotal() {
            var total = 0;
            document.querySelectorAll('.item-baris').forEach(function (row) {
                if (row.style.display !== 'none') {
                    total += parseFloat(row.querySelector('.col-jumlah').dataset.value) || 0;
                }
            });
            totalDisplay.innerText = 'Rp ' + total.toLocaleString('id-ID');
        }

        if (filter) {
            filter.addEventListener('change', function () {
                var val = this.value;
                rows.forEach(function (row) {
                    var petugas = row.querySelector('.col-petugas').innerText.trim();
                    row.style.display = (val === '' || petugas === val) ? '' : 'none';
                });
                hitungTotal();
            });
        }

        hitungTotal();

        // Batalkan pembayaran (soft-cancel di endpoint)
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-hapus');
            if (!btn) {
                return;
            }
            var id = btn.getAttribute('data-id');
            var nama = btn.getAttribute('data-nama') || '';
            var jumlah = btn.getAttribute('data-jumlah') || '';
            var row = btn.closest('tr');

            function kirim() {
                fetch('aksi/hapus_pembayaran.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(window.KBAP_CSRF)
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.status === 'sukses') {
                            row.remove();
                            var badge = document.getElementById('badgeJumlah');
                            if (badge) {
                                badge.innerText = document.querySelectorAll('.item-baris').length.toLocaleString('id-ID') + ' baris';
                            }
                            hitungTotal();
                        } else {
                            alert('Gagal membatalkan: ' + (data.message || data.pesan || 'tidak diketahui'));
                        }
                    })
                    .catch(function () { alert('Gagal menghubungi server.'); });
            }

            if (typeof Swal === 'undefined') {
                if (window.confirm('Batalkan pembayaran #' + id + ' dari "' + nama + '" (' + jumlah + ')?')) {
                    kirim();
                }
                return;
            }
            Swal.fire({
                title: 'Batalkan Pembayaran?',
                text: 'Pembayaran #' + id + ' dari "' + nama + '" (' + jumlah + ') akan dibatalkan (bukan dihapus permanen).',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Ya, Batalkan!',
                cancelButtonText: 'Batal'
            }).then(function (result) {
                if (result.isConfirmed) {
                    kirim();
                }
            });
        });
    });
</script>
