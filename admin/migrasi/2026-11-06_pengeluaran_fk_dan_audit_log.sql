-- =====================================================================
-- Migrasi: FK `pengeluaran` -> `odalan` + tabel `audit_log`
-- Tanggal : 2026-11-06 (revisi 2026-11-07)
-- Sifat   : ADDITIVE. Tidak mengubah satu pun baris data lama.
--
-- CATATAN PENTING:
--   Ternyata `pengeluaran` SUDAH punya FK `pengeluaran_ibfk_1`
--   (`id_piodalan` -> `odalan`.`id`, tanpa ON DELETE = RESTRICT).
--   Jadi migrasi ini TIDAK menambah FK baru bila sudah ada; ia hanya
--   memastikan FK tersedia (untuk DB lain/produksi yang mungkin beda).
--   Jangan pasang FK kedua di kolom yang sama (mubazir).
--
--   `audit_log` adalah tabel baru, kosong sampai kode aksi mencatat.
-- Aman dijalankan ulang.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Pastikan ada FK dari pengeluaran.id_piodalan ke odalan.id
-- ---------------------------------------------------------------------
SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pengeluaran'
    AND COLUMN_NAME = 'id_piodalan'
    AND REFERENCED_TABLE_NAME = 'odalan'
    AND REFERENCED_COLUMN_NAME = 'id'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE `pengeluaran` ADD CONSTRAINT `fk_pengeluaran_odalan`
     FOREIGN KEY (`id_piodalan`) REFERENCES `odalan` (`id`)
     ON DELETE RESTRICT ON UPDATE CASCADE',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. Tabel audit_log
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`          BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `nama_tabel`  VARCHAR(64)  NOT NULL,
  `aksi`        VARCHAR(16)  NOT NULL,           -- INSERT / UPDATE / DELETE / CANCEL / LOGIN
  `id_record`   VARCHAR(64)  DEFAULT NULL,
  `id_user`     INT(11)      DEFAULT NULL,
  `username`    VARCHAR(100) DEFAULT NULL,
  `waktu`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_lama`   LONGTEXT     DEFAULT NULL,        -- JSON
  `data_baru`   LONGTEXT     DEFAULT NULL,        -- JSON
  `keterangan`  VARCHAR(255) DEFAULT NULL,
  `ip`          VARCHAR(45)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_tabel_record` (`nama_tabel`, `id_record`),
  KEY `idx_audit_waktu` (`waktu`),
  KEY `idx_audit_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Verifikasi
-- ---------------------------------------------------------------------
SELECT 'jumlah_fk_pengeluaran_ke_odalan' AS info, COUNT(*) AS nilai
FROM information_schema.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pengeluaran'
  AND COLUMN_NAME = 'id_piodalan'
  AND REFERENCED_TABLE_NAME = 'odalan';

SELECT 'pengeluaran_total' AS info, COUNT(*) AS nilai, SUM(jumlah) AS nominal FROM `pengeluaran`;

SELECT 'audit_log_baris' AS info, COUNT(*) AS nilai FROM `audit_log`;
