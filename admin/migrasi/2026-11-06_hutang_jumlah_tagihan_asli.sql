-- =====================================================================
-- Migrasi: kolom `hutang`.`jumlah_tagihan_asli`
-- Tanggal : 2026-11-06
-- Tujuan  : menyimpan nominal tagihan asli per semeton per odalan.
--           Sebelumnya `proses_pembayaran.php` menolkan `jumlah_hutang`
--           saat lunas, sehingga nominal tagihan asli hilang.
--
-- Nilai awal (backfill) diambil dari TARIF ODALAN sesuai jenis_user:
--   ngarep -> odalan.iuran_ngarep
--   lainnya (ngempi) -> odalan.iuran_ngempi
--
-- Sifat  : ADDITIVE. Tidak mengubah jumlah baris maupun status.
--           Aman dijalankan ulang (ADD COLUMN IF NOT EXISTS + WHERE = 0).
-- Catatan: `jumlah_hutang` tetap dipakai sebagai SISA tagihan (0 = lunas),
--           jadi kode lama tidak perlu diubah untuk tetap berjalan.
-- =====================================================================

ALTER TABLE `hutang`
  ADD COLUMN IF NOT EXISTS `jumlah_tagihan_asli` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `jumlah_hutang`;

UPDATE `hutang` h
JOIN `semeton` s ON s.id = h.id_semeton
JOIN `odalan` o ON o.id = h.id_odalan
SET h.jumlah_tagihan_asli = CASE
      WHEN s.jenis_user = 'ngarep' THEN o.iuran_ngarep
      ELSE o.iuran_ngempi
    END
WHERE h.jumlah_tagihan_asli = 0;

-- ---------------------------------------------------------------------
-- Verifikasi setelah migrasi (harus: tagihan_nol = 0)
-- ---------------------------------------------------------------------
SELECT COUNT(*)                                   AS total_baris,
       SUM(jumlah_tagihan_asli = 0)               AS tagihan_nol,
       SUM(status = 'lunas')                      AS lunas,
       SUM(status = 'belum_lunas')                AS belum_lunas,
       SUM(jumlah_hutang)                         AS total_sisa_tagihan,
       SUM(jumlah_tagihan_asli)                   AS total_tagihan_asli
FROM `hutang`;

-- Cek silang: tagihan asli harus sama dengan tarif odalan.
SELECT COUNT(*) AS tidak_sesuai_tarif
FROM `hutang` h
JOIN `semeton` s ON s.id = h.id_semeton
JOIN `odalan` o ON o.id = h.id_odalan
WHERE h.jumlah_tagihan_asli <> CASE
        WHEN s.jenis_user = 'ngarep' THEN o.iuran_ngarep
        ELSE o.iuran_ngempi
      END;
