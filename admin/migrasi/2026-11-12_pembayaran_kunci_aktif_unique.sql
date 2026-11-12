-- =====================================================================
-- Migrasi: jaminan anti pembayaran ganda di level database
-- Tanggal : 2026-11-12
-- Sifat   : perubahan SKEMA + INDEX. Tidak mengubah/menghapus baris.
--
-- LATAR BELAKANG (lihat AGENTS.md 10.37):
--   Pengaman di aplikasi sudah dipasang (`proses_pembayaran.php` memakai
--   SELECT ... FOR UPDATE + cek pembayaran aktif), tetapi DB sendiri belum
--   menolak pembayaran kedua untuk tagihan yang sama.
--
--   MariaDB 10.4 TIDAK mendukung unique index parsial
--   (`... WHERE dibatalkan_at IS NULL`), sehingga dipakai kolom generated:
--
--     kunci_aktif = IF(dibatalkan_at IS NULL,
--                      CONCAT(id_semeton, '-', id_piodalan),
--                      NULL)
--
--   - Pembayaran aktif  -> kunci_aktif berisi "12-3" (unik per tagihan).
--   - Pembayaran batal  -> kunci_aktif NULL; NULL boleh berulang pada
--     UNIQUE index, jadi pembatalan tidak memblokir pembayaran baru.
--
--   Dengan begitu aturan "satu tagihan hanya boleh punya satu pembayaran
--   aktif" dijaga oleh database, bukan hanya oleh kode.
--
-- PRASYARAT: jalankan dulu
--   `2026-11-12_pembayaran_bersihkan_duplikat.sql`
--   Index TIDAK akan dipasang bila masih ada duplikat (migrasi ini hanya
--   menampilkan status, tidak memaksa).
--
-- Aman dijalankan ulang (idempoten): kolom & index hanya ditambah bila
-- belum ada.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Tambah kolom generated `kunci_aktif` bila belum ada
-- ---------------------------------------------------------------------
SET @ada_kolom := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pembayaran'
      AND COLUMN_NAME = 'kunci_aktif'
);

SET @sql := IF(@ada_kolom = 0,
    'ALTER TABLE `pembayaran`
       ADD COLUMN `kunci_aktif` VARCHAR(32)
       GENERATED ALWAYS AS (IF(`dibatalkan_at` IS NULL,
                               CONCAT(`id_semeton`, ''-'', `id_piodalan`),
                               NULL)) STORED',
    'SELECT ''kolom kunci_aktif sudah ada'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. Cek duplikat (harus 0 sebelum index dipasang)
-- ---------------------------------------------------------------------
SET @duplikat := (
    SELECT COUNT(*)
    FROM (
        SELECT 1
        FROM `pembayaran`
        WHERE `dibatalkan_at` IS NULL
        GROUP BY `id_semeton`, `id_piodalan`
        HAVING COUNT(*) > 1
    ) x
);

SELECT 'grup duplikat aktif' AS info, @duplikat AS nilai;

-- ---------------------------------------------------------------------
-- 3. Pasang UNIQUE index bila belum ada dan data sudah bersih
-- ---------------------------------------------------------------------
SET @ada_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pembayaran'
      AND INDEX_NAME = 'uq_pembayaran_aktif'
);

SET @sql := IF(@ada_index > 0,
    'SELECT ''index uq_pembayaran_aktif sudah ada'' AS info',
    IF(@duplikat > 0,
        'SELECT ''MASIH ADA DUPLIKAT - index TIDAK dipasang. Jalankan migrasi pembersihan dulu.'' AS info',
        'ALTER TABLE `pembayaran` ADD UNIQUE KEY `uq_pembayaran_aktif` (`kunci_aktif`)'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 4. Verifikasi
-- ---------------------------------------------------------------------
SELECT 'kolom kunci_aktif' AS info, COUNT(*) AS nilai
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pembayaran'
  AND COLUMN_NAME = 'kunci_aktif';

SELECT 'index uq_pembayaran_aktif' AS info, COUNT(*) AS nilai
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pembayaran'
  AND INDEX_NAME = 'uq_pembayaran_aktif';

SELECT 'pembayaran aktif' AS info, COUNT(*) AS baris,
       COALESCE(SUM(jumlah_bayar), 0) AS nominal
FROM `pembayaran`
WHERE `dibatalkan_at` IS NULL;
