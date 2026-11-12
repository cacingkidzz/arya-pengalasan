-- =====================================================================
-- Migrasi: petakan `dana_punia.id_piodalan = 0` + pasang FK ke `odalan`
-- Tanggal : 2026-11-07
-- Sifat   : MENGUBAH DATA (390 baris) + perubahan skema (FK).
--           Disetujui user dengan pilihan "Opsi B - odalan berikutnya".
--
-- Aturan pemetaan (Opsi B):
--   donasi tanpa odalan dipetakan ke odalan PERTAMA yang tanggalnya
--   >= tanggal donasi (ORDER BY tanggal ASC, id ASC).
--   Logikanya: punia dikumpulkan untuk odalan yang akan datang.
--   Hasil yang diharapkan: 235 -> odalan 1, 154 -> odalan 2, 1 -> odalan 3.
--
-- Tidak ada baris yang dihapus. Nominal tidak berubah.
-- Kolom `id_piodalan` tetap NOT NULL (tidak diubah jadi NULLable).
--
-- Aman dijalankan ulang: UPDATE hanya menyentuh id_piodalan = 0, dan FK
-- hanya ditambah bila belum ada.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Petakan donasi orphan ke odalan berikutnya
-- ---------------------------------------------------------------------
UPDATE `dana_punia` d
SET d.`id_piodalan` = (
  SELECT o.`id` FROM `odalan` o
  WHERE o.`tanggal` >= DATE(d.`tanggal_punia`)
  ORDER BY o.`tanggal` ASC, o.`id` ASC
  LIMIT 1
)
WHERE d.`id_piodalan` = 0
  AND EXISTS (
    SELECT 1 FROM `odalan` o2 WHERE o2.`tanggal` >= DATE(d.`tanggal_punia`)
  );

-- ---------------------------------------------------------------------
-- 2. FK dana_punia.id_piodalan -> odalan.id (RESTRICT)
-- ---------------------------------------------------------------------
SET @punia_orphan := (
  SELECT COUNT(*) FROM `dana_punia` d
  WHERE NOT EXISTS (SELECT 1 FROM `odalan` o WHERE o.`id` = d.`id_piodalan`)
);
SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'dana_punia'
    AND COLUMN_NAME = 'id_piodalan'
    AND REFERENCED_TABLE_NAME = 'odalan'
);
SET @sql := CASE
  WHEN @punia_orphan > 0 THEN 'DO 0'
  WHEN @fk_exists  > 0   THEN 'DO 0'
  ELSE 'ALTER TABLE `dana_punia` ADD CONSTRAINT `fk_dana_punia_odalan`
          FOREIGN KEY (`id_piodalan`) REFERENCES `odalan` (`id`)
          ON DELETE RESTRICT ON UPDATE CASCADE'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- Verifikasi
-- ---------------------------------------------------------------------
SELECT 'sisa_orphan_id0' AS info, COUNT(*) AS nilai FROM `dana_punia` WHERE `id_piodalan` = 0;

SELECT 'sisa_orphan_tak_dikenal' AS info, COUNT(*) AS nilai FROM `dana_punia` d
WHERE NOT EXISTS (SELECT 1 FROM `odalan` o WHERE o.`id` = d.`id_piodalan`);

SELECT 'fk_terpasang' AS info, @fk_exists AS sudah_ada_sebelumnya, @punia_orphan AS orphan_saat_itu;

SELECT d.`id_piodalan`, COUNT(*) AS jml, SUM(d.`jumlah_punia`) AS nominal
FROM `dana_punia` d GROUP BY d.`id_piodalan` ORDER BY d.`id_piodalan`;

SELECT 'total_punia' AS info, COUNT(*) AS baris, SUM(`jumlah_punia`) AS nominal FROM `dana_punia`;
