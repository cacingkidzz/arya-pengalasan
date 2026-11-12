-- =====================================================================
-- Migrasi: pembatalan pembayaran (soft-cancel + jejak audit)
-- Tanggal : 2026-11-06
-- Tujuan  : pembayaran yang dibatalkan tidak lagi DIHAPUS (hard delete),
--           tetapi ditandai lewat `dibatalkan_at` sehingga riwayat
--           (siapa, kapan, alasan) tetap tersimpan dan bisa ditelusuri.
--           Semua laporan WAJIB menambahkan `dibatalkan_at IS NULL`.
--
-- Sifat   : ADDITIVE. Tidak mengubah 1.497 baris lama (semua NULL =
--           dianggap pembayaran sah). Aman dijalankan ulang.
-- =====================================================================

ALTER TABLE `pembayaran`
  ADD COLUMN IF NOT EXISTS `dibatalkan_at` DATETIME NULL AFTER `status`;

ALTER TABLE `pembayaran`
  ADD COLUMN IF NOT EXISTS `dibatalkan_oleh` INT(11) NULL AFTER `dibatalkan_at`;

ALTER TABLE `pembayaran`
  ADD COLUMN IF NOT EXISTS `alasan_batal` VARCHAR(255) NULL AFTER `dibatalkan_oleh`;

ALTER TABLE `pembayaran`
  ADD INDEX IF NOT EXISTS `idx_pembayaran_dibatalkan` (`dibatalkan_at`);

-- ---------------------------------------------------------------------
-- Verifikasi setelah migrasi (harus: total = 1497, dibatalkan = 0)
-- ---------------------------------------------------------------------
SELECT COUNT(*)                          AS total_baris,
       SUM(dibatalkan_at IS NOT NULL)    AS dibatalkan,
       SUM(dibatalkan_at IS NULL)        AS pembayaran_sah,
       SUM(jumlah_bayar)                 AS total_nominal,
       SUM(CASE WHEN dibatalkan_at IS NULL THEN jumlah_bayar ELSE 0 END) AS total_nominal_sah
FROM `pembayaran`;
