-- =====================================================================
-- Migrasi: UNIQUE index `semeton.nik`
-- Tanggal : 2026-11-07
-- Sifat   : MENGUBAH DATA (604 baris) + perubahan skema (UNIQUE index).
--           Disetujui user ("lanjutkan" pada item terakhir).
--
-- Langkah 1: normalisasi NIK kosong / "-" menjadi NULL.
--   597 baris berisi string kosong, 7 baris berisi "-", 0 baris NULL.
--   NULL boleh berulang pada UNIQUE index, sehingga anggota tanpa NIK
--   tetap bisa banyak.
-- Langkah 2: pasang UNIQUE index pada `nik` (hanya bila 0 duplikat NIK nyata).
--
-- Saat migrasi dijalankan ada 3 NIK terisi (id 38, 471, 520) dan ketiganya
-- sudah unik. Nomornya sengaja tidak ditulis di repo publik.
--
-- Aman dijalankan ulang: UPDATE hanya menyentuh '' dan '-', index dicek dulu.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Normalisasi NIK kosong / strip menjadi NULL
-- ---------------------------------------------------------------------
UPDATE `semeton`
SET `nik` = NULL
WHERE `nik` IS NOT NULL
  AND TRIM(`nik`) IN ('', '-');

-- ---------------------------------------------------------------------
-- 2. UNIQUE index pada nik
-- ---------------------------------------------------------------------
SET @dup_nik := (
  SELECT COUNT(*) FROM (
    SELECT `nik` FROM `semeton`
    WHERE `nik` IS NOT NULL
    GROUP BY `nik` HAVING COUNT(*) > 1
  ) d
);

SET @idx_nik := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'semeton'
    AND INDEX_NAME = 'uq_semeton_nik'
);

SET @sql := CASE
  WHEN @dup_nik > 0 THEN 'DO 0'
  WHEN @idx_nik > 0 THEN 'DO 0'
  ELSE 'ALTER TABLE `semeton` ADD UNIQUE KEY `uq_semeton_nik` (`nik`)'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- Verifikasi
-- ---------------------------------------------------------------------
SELECT 'nik_null' AS info, COUNT(*) AS nilai FROM `semeton` WHERE `nik` IS NULL
UNION ALL
SELECT 'nik_terisi', COUNT(*) FROM `semeton` WHERE `nik` IS NOT NULL
UNION ALL
SELECT 'nik_duplikat_grup', COUNT(*) FROM (
  SELECT `nik` FROM `semeton` WHERE `nik` IS NOT NULL GROUP BY `nik` HAVING COUNT(*) > 1
) x;

SELECT 'index_terpasang' AS info, COUNT(*) AS nilai
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'semeton'
  AND INDEX_NAME = 'uq_semeton_nik';

SELECT 'semeton_total' AS info, COUNT(*) AS baris FROM `semeton`;
