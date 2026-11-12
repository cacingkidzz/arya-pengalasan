-- =====================================================================
-- Migrasi: FK `pembayaran.id_piodalan` -> `odalan.id` diubah dari
--          ON DELETE CASCADE menjadi ON DELETE RESTRICT
-- Tanggal : 2026-11-10
-- Sifat   : perubahan SKEMA saja. Tidak mengubah/menghapus satu baris pun.
--
-- LATAR BELAKANG (dibuktikan di DB kloning `kbap_uji`, lihat AGENTS.md 10.29):
--   `pembayaran_ibfk_2` semula ON DELETE CASCADE. Menghapus satu odalan
--   akan MENGHAPUS SELURUH riwayat pembayaran odalan itu tanpa peringatan
--   (uji: hapus odalan 1 -> 530 transaksi / Rp43.900.000 lenyap,
--    total pembayaran 1.497 -> 967).
--
--   Pengaman di level aplikasi sudah dipasang (aksi_odalan.php menolak
--   hapus odalan yang punya pembayaran/punia/pengeluaran/nota), tetapi
--   CASCADE di DB tetap berbahaya bila ada jalur lain (mis. SQL manual).
--   Migrasi ini menjadikan DB ikut menolak, bukan menghapus.
--
-- CATATAN:
--   - `pembayaran_ibfk_1` (id_semeton -> semeton) TIDAK disentuh di sini;
--     perubahan itu perlu persetujuan terpisah (lihat AGENTS.md 10.30).
--   - ON UPDATE RESTRICT dipakai agar setara dengan perilaku sebelumnya
--     (dulu tanpa ON UPDATE = RESTRICT/NO ACTION).
--   - Index `id_piodalan` pada `pembayaran` sudah ada, jadi DROP/ADD FK
--     tidak membuat index baru.
--
-- Aman dijalankan ulang (idempoten): bila DELETE_RULE sudah RESTRICT,
-- migrasi tidak melakukan apa pun.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 0. Prasyarat: tidak boleh ada pembayaran yatim (id_piodalan tak dikenal)
-- ---------------------------------------------------------------------
SELECT 'pembayaran_yatim' AS info, COUNT(*) AS nilai
FROM `pembayaran` p
LEFT JOIN `odalan` o ON o.id = p.id_piodalan
WHERE o.id IS NULL;

-- ---------------------------------------------------------------------
-- 1. Cari nama constraint FK yang sekarang menempel
--    (biasanya `pembayaran_ibfk_2`, tetapi dicari dinamis agar aman
--     bila nama di DB lain berbeda).
-- ---------------------------------------------------------------------
SET @nama_fk := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pembayaran'
    AND COLUMN_NAME = 'id_piodalan'
    AND REFERENCED_TABLE_NAME = 'odalan'
  LIMIT 1
);

SET @delete_rule := (
  SELECT DELETE_RULE
  FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pembayaran'
    AND CONSTRAINT_NAME = @nama_fk
  LIMIT 1
);

SELECT 'fk_lama' AS info, @nama_fk AS nama, @delete_rule AS delete_rule;

-- ---------------------------------------------------------------------
-- 2. Lepas FK lama HANYA bila ada dan aturannya bukan RESTRICT
-- ---------------------------------------------------------------------
SET @sql_drop := CASE
  WHEN @nama_fk IS NULL THEN 'DO 0'
  WHEN @delete_rule = 'RESTRICT' THEN 'DO 0'
  ELSE CONCAT('ALTER TABLE `pembayaran` DROP FOREIGN KEY `', @nama_fk, '`')
END;
PREPARE stmt_drop FROM @sql_drop;
EXECUTE stmt_drop;
DEALLOCATE PREPARE stmt_drop;

-- ---------------------------------------------------------------------
-- 3. Pasang kembali sebagai RESTRICT (hanya bila belum RESTRICT / belum ada)
-- ---------------------------------------------------------------------
SET @sql_add := CASE
  WHEN @delete_rule = 'RESTRICT' THEN 'DO 0'
  ELSE 'ALTER TABLE `pembayaran` ADD CONSTRAINT `pembayaran_ibfk_2`
          FOREIGN KEY (`id_piodalan`) REFERENCES `odalan` (`id`)
          ON DELETE RESTRICT ON UPDATE RESTRICT'
END;
PREPARE stmt_add FROM @sql_add;
EXECUTE stmt_add;
DEALLOCATE PREPARE stmt_add;

-- ---------------------------------------------------------------------
-- Verifikasi
-- ---------------------------------------------------------------------
SELECT 'fk_pembayaran_odalan' AS info, rc.CONSTRAINT_NAME AS nama,
       rc.DELETE_RULE, rc.UPDATE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
  AND rc.TABLE_NAME = 'pembayaran'
  AND rc.REFERENCED_TABLE_NAME = 'odalan';

SELECT 'pembayaran_total' AS info, COUNT(*) AS baris,
       SUM(jumlah_bayar) AS nominal,
       SUM(CASE WHEN dibatalkan_at IS NULL THEN 1 ELSE 0 END) AS sah
FROM `pembayaran`;
