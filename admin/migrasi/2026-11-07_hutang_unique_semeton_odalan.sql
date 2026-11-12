-- =====================================================================
-- Migrasi: UNIQUE index `hutang (id_semeton, id_odalan)`
-- Tanggal : 2026-11-07
-- Sifat   : perubahan SKEMA. Tidak mengubah/menghapus satu baris pun.
--
-- Tujuan: mencegah satu semeton punya 2 tagihan untuk odalan yang sama
--         (kasus yang baru dibersihkan di 2026-11-07_hutang_hapus_duplikat.sql).
--
-- Prasyarat (sudah terpenuhi di `kbap`): 0 grup duplikat.
-- Bila masih ada duplikat, migrasi ini SENGAJA tidak memasang index dan
-- menampilkan pesan agar dibersihkan lebih dulu.
--
-- Index lama `id_semeton` dan `id_odalan` TIDAK dihapus:
--   - `id_odalan` dibutuhkan FK ke `odalan`;
--   - menghapusnya bukan urusan migrasi ini.
--
-- Aman dijalankan ulang (dicek lewat information_schema).
-- =====================================================================

SET @dup := (
  SELECT COUNT(*) FROM (
    SELECT id_semeton, id_odalan FROM `hutang`
    GROUP BY id_semeton, id_odalan HAVING COUNT(*) > 1
  ) d
);

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'hutang'
    AND INDEX_NAME = 'uq_hutang_semeton_odalan'
);

SET @sql := CASE
  WHEN @dup > 0 THEN 'DO 0'
  WHEN @idx > 0 THEN 'DO 0'
  ELSE 'ALTER TABLE `hutang` ADD UNIQUE KEY `uq_hutang_semeton_odalan` (`id_semeton`, `id_odalan`)'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- Verifikasi
-- ---------------------------------------------------------------------
SELECT 'grup_duplikat' AS info, @dup AS nilai,
       CASE WHEN @dup = 0 THEN 'aman' ELSE 'MASIH ADA DUPLIKAT - index tidak dipasang' END AS status;

SELECT 'index_terpasang' AS info, COUNT(*) AS nilai
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'hutang'
  AND INDEX_NAME = 'uq_hutang_semeton_odalan';

SELECT 'hutang_total' AS info, COUNT(*) AS baris,
       SUM(jumlah_hutang) AS total_sisa,
       SUM(jumlah_tagihan_asli) AS total_tagihan_asli
FROM `hutang`;
