-- =====================================================================
-- KBAP — Data pendukung semeton + optimasi index (2026-11-11)
-- =====================================================================
-- Sifat perubahan:
--   * ADDITIVE: hanya MENAMBAH kolom baru (semua NULL) dan index baru.
--   * TIDAK mengubah / menghapus data lama. Kolom baru terisi NULL untuk
--     semua baris yang sudah ada (tidak ada UPDATE massal).
--   * TIDAK menyentuh tabel `pembayaran` / `hutang` selain menambah index
--     baca (isi & perilaku transaksi tetap sama).
--
-- Aman dijalankan berulang (idempoten): ADD COLUMN IF NOT EXISTS,
-- CREATE INDEX IF NOT EXISTS, dan CONVERT TO CHARACTER SET (tanpa data hilang).
--
-- Jalankan di DB lokal:  mysql -u root -D kbap < file_ini
-- Untuk produksi: BACKUP dulu, lalu jalankan saat sepi pengguna.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Kolom data pendukung pada `semeton`
--    Semua NULL-able agar anggota lama tetap valid tanpa diisi.
-- ---------------------------------------------------------------------
ALTER TABLE `semeton`
    ADD COLUMN IF NOT EXISTS `jenis_kelamin` ENUM('L','P') NULL DEFAULT NULL COMMENT 'L = laki-laki, P = perempuan' AFTER `nik`,
    ADD COLUMN IF NOT EXISTS `no_kk` VARCHAR(16) NULL DEFAULT NULL COMMENT 'Nomor Kartu Keluarga (16 digit)' AFTER `jenis_kelamin`,
    ADD COLUMN IF NOT EXISTS `tempat_lahir` VARCHAR(100) NULL DEFAULT NULL AFTER `no_kk`,
    ADD COLUMN IF NOT EXISTS `tanggal_lahir` DATE NULL DEFAULT NULL AFTER `tempat_lahir`,
    ADD COLUMN IF NOT EXISTS `agama` VARCHAR(30) NULL DEFAULT NULL AFTER `tanggal_lahir`,
    ADD COLUMN IF NOT EXISTS `golongan_darah` VARCHAR(3) NULL DEFAULT NULL COMMENT 'A / B / AB / O' AFTER `agama`,
    ADD COLUMN IF NOT EXISTS `status_perkawinan` ENUM('belum_kawin','kawin','cerai_hidup','cerai_mati') NULL DEFAULT NULL AFTER `golongan_darah`,
    ADD COLUMN IF NOT EXISTS `pendidikan` VARCHAR(50) NULL DEFAULT NULL AFTER `status_perkawinan`,
    ADD COLUMN IF NOT EXISTS `pekerjaan` VARCHAR(100) NULL DEFAULT NULL AFTER `pendidikan`,
    ADD COLUMN IF NOT EXISTS `nama_ayah` VARCHAR(255) NULL DEFAULT NULL AFTER `pekerjaan`,
    ADD COLUMN IF NOT EXISTS `nama_ibu` VARCHAR(255) NULL DEFAULT NULL AFTER `nama_ayah`,
    ADD COLUMN IF NOT EXISTS `catatan` VARCHAR(255) NULL DEFAULT NULL AFTER `nama_ibu`,
    ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE current_timestamp() AFTER `created_at`;

-- ---------------------------------------------------------------------
-- 2. Kolom yang sama pada `semeton_baru` (usulan pendaftaran publik)
--    Supaya data pendukung tidak hilang saat usulan diverifikasi.
-- ---------------------------------------------------------------------
ALTER TABLE `semeton_baru`
    ADD COLUMN IF NOT EXISTS `jenis_kelamin` ENUM('L','P') NULL DEFAULT NULL AFTER `nik`,
    ADD COLUMN IF NOT EXISTS `no_kk` VARCHAR(16) NULL DEFAULT NULL AFTER `jenis_kelamin`,
    ADD COLUMN IF NOT EXISTS `tempat_lahir` VARCHAR(100) NULL DEFAULT NULL AFTER `no_kk`,
    ADD COLUMN IF NOT EXISTS `tanggal_lahir` DATE NULL DEFAULT NULL AFTER `tempat_lahir`,
    ADD COLUMN IF NOT EXISTS `agama` VARCHAR(30) NULL DEFAULT NULL AFTER `tanggal_lahir`,
    ADD COLUMN IF NOT EXISTS `golongan_darah` VARCHAR(3) NULL DEFAULT NULL AFTER `agama`,
    ADD COLUMN IF NOT EXISTS `status_perkawinan` ENUM('belum_kawin','kawin','cerai_hidup','cerai_mati') NULL DEFAULT NULL AFTER `golongan_darah`,
    ADD COLUMN IF NOT EXISTS `pendidikan` VARCHAR(50) NULL DEFAULT NULL AFTER `status_perkawinan`,
    ADD COLUMN IF NOT EXISTS `pekerjaan` VARCHAR(100) NULL DEFAULT NULL AFTER `pendidikan`,
    ADD COLUMN IF NOT EXISTS `nama_ayah` VARCHAR(255) NULL DEFAULT NULL AFTER `pekerjaan`,
    ADD COLUMN IF NOT EXISTS `nama_ibu` VARCHAR(255) NULL DEFAULT NULL AFTER `nama_ayah`,
    ADD COLUMN IF NOT EXISTS `catatan` VARCHAR(255) NULL DEFAULT NULL AFTER `nama_ibu`,
    ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE current_timestamp() AFTER `created_at`;

-- ---------------------------------------------------------------------
-- 3. Index untuk mempercepat halaman yang sudah ada
--    (filter/pencarian/laporan; tidak mengubah hasil query)
-- ---------------------------------------------------------------------
-- semeton: pencarian nama (prefix), filter status/jenis/wilayah, cari no KK
CREATE INDEX IF NOT EXISTS `idx_semeton_nama`          ON `semeton` (`nama`);
CREATE INDEX IF NOT EXISTS `idx_semeton_kk`            ON `semeton` (`no_kk`);
CREATE INDEX IF NOT EXISTS `idx_semeton_status`        ON `semeton` (`status`);
CREATE INDEX IF NOT EXISTS `idx_semeton_jenis_user`    ON `semeton` (`jenis_user`);
CREATE INDEX IF NOT EXISTS `idx_semeton_wilayah_status` ON `semeton` (`wilayah`, `status`);
CREATE INDEX IF NOT EXISTS `idx_semeton_baru_kk`       ON `semeton_baru` (`no_kk`);

-- pembayaran: laporan per odalan hanya membaca baris yang tidak dibatalkan
CREATE INDEX IF NOT EXISTS `idx_pembayaran_piodalan_batal`   ON `pembayaran` (`id_piodalan`, `dibatalkan_at`);
CREATE INDEX IF NOT EXISTS `idx_pembayaran_semeton_piodalan` ON `pembayaran` (`id_semeton`, `id_piodalan`, `dibatalkan_at`);
CREATE INDEX IF NOT EXISTS `idx_pembayaran_tanggal`          ON `pembayaran` (`tanggal_bayar`);

-- hutang: rekap "sudah/belum lunas per odalan"
CREATE INDEX IF NOT EXISTS `idx_hutang_odalan_status` ON `hutang` (`id_odalan`, `status`);

-- dana punia: laporan per tanggal (rekap_punia_hari_ini)
CREATE INDEX IF NOT EXISTS `idx_dana_punia_tanggal` ON `dana_punia` (`tanggal_punia`);

-- transaksi: halaman pengeluaran per odalan & laporan per tanggal
CREATE INDEX IF NOT EXISTS `idx_transaksi_piodalan` ON `transaksi` (`id_piodalan`);
CREATE INDEX IF NOT EXISTS `idx_transaksi_tanggal`  ON `transaksi` (`tanggal`);

-- ---------------------------------------------------------------------
-- 4. Penyeragaman charset (opsional, tanpa kehilangan data)
--    `transaksi`, `detail_transaksi`, `silsilah` masih latin1 sedangkan
--    tabel lain utf8mb4. Konversi latin1 -> utf8mb4 bersifat lossless.
-- ---------------------------------------------------------------------
ALTER TABLE `transaksi`        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `detail_transaksi` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `silsilah`         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 5. Verifikasi cepat (jalankan manual bila perlu)
-- ---------------------------------------------------------------------
-- SHOW COLUMNS FROM semeton;
-- SELECT COUNT(*) FROM semeton;             -- harus tetap 607
-- SELECT COUNT(*) FROM pembayaran;          -- harus tetap 1.497
-- SELECT COUNT(*) FROM hutang;              -- harus tetap 1.808
