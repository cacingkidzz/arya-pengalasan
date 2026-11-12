<?php
require_once __DIR__ . '/../security.php';
kbap_guard_menu('semeton_baru'); // wajib login + hak akses menu

require_once __DIR__ . '/../koneksi.php';

// =====================================================================
// RAPIH + AMAN (2026-11-09)
// Sebelumnya: setiap baris merender 2 modal (detail + edit) DAN satu blok
// <script> di dalam <tbody> (HTML tidak valid), dropdown wilayah diulang
// per baris (2 x 23 <option>), dan seluruh data (nama/nik/email/no_hp/
// alamat) dirender mentah → XSS. Sekarang: 3 modal bersama (detail, edit,
// tambah) + 1 handler hapus, wilayah diambil sekali, sub wilayah dikirim
// sebagai JSON, dan semua output di-escape.
// =====================================================================
$esc = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

// 1. Daftar wilayah (23 baris) — dipakai dropdown tambah & edit
$wilayah_list = [];
if ($q_w = $koneksi->query("SELECT id_wilayah, nama_wilayah FROM wilayah ORDER BY nama_wilayah ASC")) {
    while ($r = $q_w->fetch_assoc()) {
        $wilayah_list[] = $r;
    }
}

// 2. Sub wilayah dikelompokkan per wilayah (85 baris) → JSON untuk dropdown
$sub_by_wilayah = [];
if ($q_s = $koneksi->query("SELECT id_sub, id_wilayah, nama_sub FROM sub_wilayah ORDER BY nama_sub ASC")) {
    while ($r = $q_s->fetch_assoc()) {
        $sub_by_wilayah[(int) $r['id_wilayah']][] = [
            'id'   => (int) $r['id_sub'],
            'nama' => $r['nama_sub'],
        ];
    }
}
$sub_json = json_encode($sub_by_wilayah, JSON_UNESCAPED_UNICODE);

// 3. Data usulan (LEFT JOIN agar usulan tanpa wilayah tetap tampil)
$daftar_usulan = [];
$sql_usulan = "SELECT s.*, w.nama_wilayah, sw.nama_sub
               FROM semeton_baru s
               LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
               LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
               ORDER BY s.id ASC";
if ($q_u = $koneksi->query($sql_usulan)) {
    while ($r = $q_u->fetch_assoc()) {
        $daftar_usulan[] = $r;
    }
}
$total_usulan = count($daftar_usulan);
?>
<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-8">
                    <h3 class="mb-0 fw-bold text-dark">Data Semeton (Usulan Baru)</h3>
                    <p class="text-muted small mb-0">
                        Usulan pendaftaran yang belum diverifikasi menjadi anggota.
                        <span class="badge bg-secondary ms-1"><?= (int) $total_usulan ?> usulan</span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="card p-4">
                <div class="row mb-3">
                    <div class="col-12">
                        <a href="#" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalTambahUsulan">
                            <i class="fa-solid fa-plus"></i> Tambah Semeton
                        </a>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <table id="example" class="table table-bordered table-striped table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th width="60">No</th>
                                    <th>Nama</th>
                                    <th width="60">JK</th>
                                    <th>NIK / No. KK</th>
                                    <th>No HP</th>
                                    <th>Wilayah</th>
                                    <th width="150">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($daftar_usulan)) : ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">
                                            <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                                            Belum ada usulan anggota baru.
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <?php $no = 1; foreach ($daftar_usulan as $row) : ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td><?= $esc($row['nama']) ?></td>
                                            <td class="text-center"><?= $row['jenis_kelamin'] === 'L' ? 'L' : ($row['jenis_kelamin'] === 'P' ? 'P' : '-') ?></td>
                                            <td>
                                                <?= $esc($row['nik'] !== null && trim((string) $row['nik']) !== '' ? $row['nik'] : '-') ?>
                                                <?php if (!empty($row['no_kk'])) : ?>
                                                    <div class="text-muted small">KK: <?= $esc($row['no_kk']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $esc($row['no_hp'] !== null && trim((string) $row['no_hp']) !== '' ? $row['no_hp'] : '-') ?></td>
                                            <td>
                                                <?php if ($row['nama_wilayah']) : ?>
                                                    <?= $esc($row['nama_wilayah']) ?>
                                                    <?php if ($row['nama_sub']) : ?>
                                                        <span class="text-muted small">- <?= $esc($row['nama_sub']) ?></span>
                                                    <?php endif; ?>
                                                <?php else : ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button type="button" class="btn btn-sm btn-success"
                                                        data-bs-toggle="modal" data-bs-target="#modalDetailUsulan"
                                                        data-nama="<?= $esc($row['nama']) ?>"
                                                        data-jk="<?= $row['jenis_kelamin'] === 'L' ? 'Laki-laki' : ($row['jenis_kelamin'] === 'P' ? 'Perempuan' : '') ?>"
                                                        data-nik="<?= $esc($row['nik']) ?>"
                                                        data-kk="<?= $esc($row['no_kk']) ?>"
                                                        data-lahir="<?= $esc(trim(((string) $row['tempat_lahir']) . (!empty($row['tanggal_lahir']) ? ', ' . date('d-m-Y', strtotime((string) $row['tanggal_lahir'])) : ''), " ,")) ?>"
                                                        data-agama="<?= $esc($row['agama']) ?>"
                                                        data-gol="<?= $esc($row['golongan_darah']) ?>"
                                                        data-kawin="<?= $esc(str_replace('_', ' ', (string) $row['status_perkawinan'])) ?>"
                                                        data-pendidikan="<?= $esc($row['pendidikan']) ?>"
                                                        data-pekerjaan="<?= $esc($row['pekerjaan']) ?>"
                                                        data-ayah="<?= $esc($row['nama_ayah']) ?>"
                                                        data-ibu="<?= $esc($row['nama_ibu']) ?>"
                                                        data-catatan="<?= $esc($row['catatan']) ?>"
                                                        data-email="<?= $esc($row['email']) ?>"
                                                        data-hp="<?= $esc($row['no_hp']) ?>"
                                                        data-alamat="<?= $esc($row['alamat']) ?>"
                                                        data-wilayah="<?= $esc($row['nama_wilayah']) ?>"
                                                        data-sub="<?= $esc($row['nama_sub']) ?>"
                                                        title="Lihat detail">
                                                        <i class="fa-solid fa-search"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-primary"
                                                        data-bs-toggle="modal" data-bs-target="#modalEditUsulan"
                                                        data-id="<?= (int) $row['id'] ?>"
                                                        data-nama="<?= $esc($row['nama']) ?>"
                                                        data-jk="<?= $esc((string) $row['jenis_kelamin']) ?>"
                                                        data-nik="<?= $esc($row['nik']) ?>"
                                                        data-kk="<?= $esc($row['no_kk']) ?>"
                                                        data-tempat-lahir="<?= $esc((string) $row['tempat_lahir']) ?>"
                                                        data-tanggal-lahir="<?= $esc((string) $row['tanggal_lahir']) ?>"
                                                        data-agama="<?= $esc((string) $row['agama']) ?>"
                                                        data-gol="<?= $esc((string) $row['golongan_darah']) ?>"
                                                        data-kawin="<?= $esc((string) $row['status_perkawinan']) ?>"
                                                        data-pendidikan="<?= $esc((string) $row['pendidikan']) ?>"
                                                        data-pekerjaan="<?= $esc((string) $row['pekerjaan']) ?>"
                                                        data-ayah="<?= $esc((string) $row['nama_ayah']) ?>"
                                                        data-ibu="<?= $esc((string) $row['nama_ibu']) ?>"
                                                        data-catatan="<?= $esc((string) $row['catatan']) ?>"
                                                        data-email="<?= $esc($row['email']) ?>"
                                                        data-hp="<?= $esc($row['no_hp']) ?>"
                                                        data-alamat="<?= $esc($row['alamat']) ?>"
                                                        data-wilayah="<?= (int) $row['wilayah'] ?>"
                                                        data-sub="<?= (int) $row['sub_wilayah'] ?>"
                                                        title="Edit usulan">
                                                        <i class="fas fa-pen-to-square"></i>
                                                    </button>
                                                    <a href="aksi/aksi_semeton_baru.php?aksi=hapus&id=<?= (int) $row['id'] ?>&csrf_token=<?= $esc(kbap_csrf_token()) ?>"
                                                        class="btn btn-sm btn-danger btn-hapus-usulan"
                                                        data-nama="<?= $esc($row['nama']) ?>"
                                                        title="Hapus usulan">
                                                        <i class="fas fa-xmark"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Modal detail usulan (read-only) -->
<div class="modal fade" id="modalDetailUsulan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-id-card me-2"></i>Detail Usulan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <h4 class="text-center mb-1" id="detail-nama">-</h4>
                <p class="text-center text-muted mb-3" id="detail-nik">-</p>
                <table class="table table-sm table-borderless mb-0">
                    <tr><th width="130">Jenis Kelamin</th><td id="detail-jk">-</td></tr>
                    <tr><th>No. KK</th><td id="detail-kk">-</td></tr>
                    <tr><th>Tempat / Tgl Lahir</th><td id="detail-lahir">-</td></tr>
                    <tr><th>Agama</th><td id="detail-agama">-</td></tr>
                    <tr><th>Gol. Darah</th><td id="detail-gol">-</td></tr>
                    <tr><th>Status Kawin</th><td id="detail-kawin">-</td></tr>
                    <tr><th>Pendidikan</th><td id="detail-pendidikan">-</td></tr>
                    <tr><th>Pekerjaan</th><td id="detail-pekerjaan">-</td></tr>
                    <tr><th>Nama Ayah</th><td id="detail-ayah">-</td></tr>
                    <tr><th>Nama Ibu</th><td id="detail-ibu">-</td></tr>
                    <tr><th>Email</th><td id="detail-email">-</td></tr>
                    <tr><th>No HP</th><td id="detail-hp">-</td></tr>
                    <tr><th>Alamat</th><td id="detail-alamat">-</td></tr>
                    <tr><th>Wilayah</th><td id="detail-wilayah">-</td></tr>
                    <tr><th>Sub Wilayah</th><td id="detail-sub">-</td></tr>
                    <tr><th>Catatan</th><td id="detail-catatan">-</td></tr>
                </table>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal edit bersama (diisi dari atribut data-* tombol yang diklik) -->
<div class="modal fade" id="modalEditUsulan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form action="aksi/aksi_semeton_baru.php" method="POST">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id" id="edit-id" value="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit Usulan Semeton</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-nama">Nama <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit-nama" name="nama" maxlength="255" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="edit-nik">NIK</label>
                            <input type="text" class="form-control" id="edit-nik" name="nik" maxlength="16" inputmode="numeric" placeholder="16 digit (opsional)">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="edit-kk">No. KK</label>
                            <input type="text" class="form-control" id="edit-kk" name="no_kk" maxlength="16" inputmode="numeric" placeholder="16 digit (opsional)">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold" for="edit-jk">Jenis Kelamin</label>
                            <select class="form-select" id="edit-jk" name="jenis_kelamin">
                                <option value="">- Tidak diisi -</option>
                                <option value="L">Laki-laki</option>
                                <option value="P">Perempuan</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold" for="edit-tempat-lahir">Tempat Lahir</label>
                            <input type="text" class="form-control" id="edit-tempat-lahir" name="tempat_lahir" maxlength="100">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold" for="edit-tanggal-lahir">Tanggal Lahir</label>
                            <input type="date" class="form-control" id="edit-tanggal-lahir" name="tanggal_lahir">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold" for="edit-agama">Agama</label>
                            <input type="text" class="form-control" id="edit-agama" name="agama" maxlength="30" list="daftarAgamaUsulan">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold" for="edit-gol">Golongan Darah</label>
                            <select class="form-select" id="edit-gol" name="golongan_darah">
                                <option value="">- Tidak diisi -</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="AB">AB</option>
                                <option value="O">O</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold" for="edit-kawin">Status Perkawinan</label>
                            <select class="form-select" id="edit-kawin" name="status_perkawinan">
                                <option value="">- Tidak diisi -</option>
                                <option value="belum_kawin">Belum Kawin</option>
                                <option value="kawin">Kawin</option>
                                <option value="cerai_hidup">Cerai Hidup</option>
                                <option value="cerai_mati">Cerai Mati</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="edit-pendidikan">Pendidikan</label>
                            <input type="text" class="form-control" id="edit-pendidikan" name="pendidikan" maxlength="50">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="edit-pekerjaan">Pekerjaan</label>
                            <input type="text" class="form-control" id="edit-pekerjaan" name="pekerjaan" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="edit-ayah">Nama Ayah</label>
                            <input type="text" class="form-control" id="edit-ayah" name="nama_ayah" maxlength="255">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="edit-ibu">Nama Ibu</label>
                            <input type="text" class="form-control" id="edit-ibu" name="nama_ibu" maxlength="255">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-email">Email</label>
                        <input type="email" class="form-control" id="edit-email" name="email" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-hp">No HP <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit-hp" name="no_hp" maxlength="15" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="edit-alamat">Alamat <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="edit-alamat" name="alamat" rows="2" required></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="edit-wilayah">Nama Wilayah</label>
                            <select class="form-select" id="edit-wilayah" name="id_wilayah" required onchange="isiSubEdit(this.value)">
                                <option value="">-- Pilih Wilayah --</option>
                                <?php foreach ($wilayah_list as $w) : ?>
                                    <option value="<?= (int) $w['id_wilayah'] ?>"><?= $esc($w['nama_wilayah']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="edit-sub">Sub Wilayah</label>
                            <select class="form-select" id="edit-sub" name="sub_wilayah" required>
                                <option value="">-- Pilih Sub Wilayah --</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-bold" for="edit-catatan">Catatan</label>
                        <input type="text" class="form-control" id="edit-catatan" name="catatan" maxlength="255" placeholder="Catatan internal (opsional)">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal tambah usulan -->
<div class="modal fade" id="modalTambahUsulan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form action="aksi/aksi_semeton_baru.php" method="POST">
                <input type="hidden" name="aksi" value="tambah">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i>Tambah Semeton</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-nama">Nama <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="tambah-nama" name="nama" maxlength="255" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="tambah-nik">NIK</label>
                            <input type="text" class="form-control" id="tambah-nik" name="nik" maxlength="16" inputmode="numeric" placeholder="16 digit (opsional)">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="tambah-kk">No. KK</label>
                            <input type="text" class="form-control" id="tambah-kk" name="no_kk" maxlength="16" inputmode="numeric" placeholder="16 digit (opsional)">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold" for="tambah-jk">Jenis Kelamin</label>
                            <select class="form-select" id="tambah-jk" name="jenis_kelamin">
                                <option value="">- Tidak diisi -</option>
                                <option value="L">Laki-laki</option>
                                <option value="P">Perempuan</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold" for="tambah-tempat-lahir">Tempat Lahir</label>
                            <input type="text" class="form-control" id="tambah-tempat-lahir" name="tempat_lahir" maxlength="100">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold" for="tambah-tanggal-lahir">Tanggal Lahir</label>
                            <input type="date" class="form-control" id="tambah-tanggal-lahir" name="tanggal_lahir">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold" for="tambah-agama">Agama</label>
                            <input type="text" class="form-control" id="tambah-agama" name="agama" maxlength="30" list="daftarAgamaUsulan">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold" for="tambah-gol">Golongan Darah</label>
                            <select class="form-select" id="tambah-gol" name="golongan_darah">
                                <option value="">- Tidak diisi -</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="AB">AB</option>
                                <option value="O">O</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold" for="tambah-kawin">Status Perkawinan</label>
                            <select class="form-select" id="tambah-kawin" name="status_perkawinan">
                                <option value="">- Tidak diisi -</option>
                                <option value="belum_kawin">Belum Kawin</option>
                                <option value="kawin">Kawin</option>
                                <option value="cerai_hidup">Cerai Hidup</option>
                                <option value="cerai_mati">Cerai Mati</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="tambah-pendidikan">Pendidikan</label>
                            <input type="text" class="form-control" id="tambah-pendidikan" name="pendidikan" maxlength="50">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="tambah-pekerjaan">Pekerjaan</label>
                            <input type="text" class="form-control" id="tambah-pekerjaan" name="pekerjaan" maxlength="100">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="tambah-ayah">Nama Ayah</label>
                            <input type="text" class="form-control" id="tambah-ayah" name="nama_ayah" maxlength="255">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="tambah-ibu">Nama Ibu</label>
                            <input type="text" class="form-control" id="tambah-ibu" name="nama_ibu" maxlength="255">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-email">Email</label>
                        <input type="email" class="form-control" id="tambah-email" name="email" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-hp">No HP <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="tambah-hp" name="no_hp" maxlength="15" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="tambah-alamat">Alamat <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="tambah-alamat" name="alamat" rows="2" required></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="tambah-wilayah">Nama Wilayah</label>
                            <select class="form-select" id="tambah-wilayah" name="id_wilayah" required onchange="isiSubTambah(this.value)">
                                <option value="">-- Pilih Wilayah --</option>
                                <?php foreach ($wilayah_list as $w) : ?>
                                    <option value="<?= (int) $w['id_wilayah'] ?>"><?= $esc($w['nama_wilayah']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="tambah-sub">Sub Wilayah</label>
                            <select class="form-select" id="tambah-sub" name="sub_wilayah" required>
                                <option value="">-- Pilih Sub Wilayah --</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-bold" for="tambah-catatan">Catatan</label>
                        <input type="text" class="form-control" id="tambah-catatan" name="catatan" maxlength="255" placeholder="Catatan internal (opsional)">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<datalist id="daftarAgamaUsulan">
    <option value="Hindu"></option>
    <option value="Islam"></option>
    <option value="Kristen"></option>
    <option value="Katolik"></option>
    <option value="Buddha"></option>
    <option value="Konghucu"></option>
</datalist>

<script>
// Sub wilayah dari server (85 baris) — tanpa AJAX per baris.
var SUB_WILAYAH = <?= $sub_json ?: '{}' ?>;

function isiSubTambah(idWilayah, terpilih) {
    var target = document.getElementById('tambah-sub');
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

function isiSubEdit(idWilayah, terpilih) {
    var target = document.getElementById('edit-sub');
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

document.addEventListener('DOMContentLoaded', function () {
    // 1. Modal detail: isi dari data-* (textContent = aman)
    var modalDetail = document.getElementById('modalDetailUsulan');
    if (modalDetail) {
        modalDetail.addEventListener('show.bs.modal', function (event) {
            var tombol = event.relatedTarget;
            if (!tombol) return;
            var d = tombol.dataset;
            var isi = function (id, nilai) {
                var el = document.getElementById(id);
                if (el) el.textContent = (nilai && String(nilai).trim() !== '') ? nilai : '-';
            };
            isi('detail-nama', d.nama);
            isi('detail-jk', d.jk);
            isi('detail-nik', d.nik);
            isi('detail-kk', d.kk);
            isi('detail-lahir', d.lahir);
            isi('detail-agama', d.agama);
            isi('detail-gol', d.gol);
            isi('detail-kawin', d.kawin);
            isi('detail-pendidikan', d.pendidikan);
            isi('detail-pekerjaan', d.pekerjaan);
            isi('detail-ayah', d.ayah);
            isi('detail-ibu', d.ibu);
            isi('detail-email', d.email);
            isi('detail-hp', d.hp);
            isi('detail-alamat', d.alamat);
            isi('detail-wilayah', d.wilayah);
            isi('detail-sub', d.sub);
            isi('detail-catatan', d.catatan);
        });
    }

    // 2. Modal edit: isi dari data-* lalu bangun dropdown sub wilayah
    var modalEdit = document.getElementById('modalEditUsulan');
    if (modalEdit) {
        modalEdit.addEventListener('show.bs.modal', function (event) {
            var tombol = event.relatedTarget;
            if (!tombol) return;
            var d = tombol.dataset;
            document.getElementById('edit-id').value = d.id || '';
            document.getElementById('edit-nama').value = d.nama || '';
            document.getElementById('edit-nik').value = d.nik || '';
            document.getElementById('edit-kk').value = d.kk || '';
            document.getElementById('edit-jk').value = d.jk || '';
            document.getElementById('edit-tempat-lahir').value = d.tempatLahir || '';
            document.getElementById('edit-tanggal-lahir').value = d.tanggalLahir || '';
            document.getElementById('edit-agama').value = d.agama || '';
            document.getElementById('edit-gol').value = d.gol || '';
            document.getElementById('edit-kawin').value = d.kawin || '';
            document.getElementById('edit-pendidikan').value = d.pendidikan || '';
            document.getElementById('edit-pekerjaan').value = d.pekerjaan || '';
            document.getElementById('edit-ayah').value = d.ayah || '';
            document.getElementById('edit-ibu').value = d.ibu || '';
            document.getElementById('edit-catatan').value = d.catatan || '';
            document.getElementById('edit-email').value = d.email || '';
            document.getElementById('edit-hp').value = d.hp || '';
            document.getElementById('edit-alamat').value = d.alamat || '';
            document.getElementById('edit-wilayah').value = d.wilayah || '';
            isiSubEdit(d.wilayah || '', d.sub || '');
        });
    }

    // 3. Konfirmasi hapus (satu handler untuk semua baris)
    document.addEventListener('click', function (event) {
        var tautan = event.target.closest('a.btn-hapus-usulan');
        if (!tautan) return;
        event.preventDefault();
        var nama = tautan.getAttribute('data-nama') || '';
        var url = tautan.getAttribute('href');
        if (typeof Swal === 'undefined') {
            if (window.confirm('Hapus usulan "' + nama + '"?')) {
                window.location.href = url;
            }
            return;
        }
        Swal.fire({
            title: 'Peringatan!',
            text: 'Apakah kamu yakin menghapus usulan "' + nama + '" ?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then(function (result) {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    });
});
</script>
