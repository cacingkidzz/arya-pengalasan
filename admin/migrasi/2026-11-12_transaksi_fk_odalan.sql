-- ============================================================================
-- FK `transaksi.id_piodalan` -> `odalan.id`
-- Tanggal : 2026-11-12
-- Sifat   : PERUBAHAN SKEMA (tidak mengubah baris data)
--
-- Latar belakang
--   `transaksi.id_piodalan` satu-satunya kolom relasi ke odalan yang belum
--   punya FOREIGN KEY, sehingga nota yatim bisa lolos bila pengaman aplikasi
--   dilewati. Saat migrasi dijalankan: 37 nota, 0 yatim.
--
-- Aturan
--   ON DELETE RESTRICT  -> odalan yang punya nota tidak bisa dihapus
--   ON UPDATE CASCADE   -> mengikuti pola FK dana_punia
--
-- Sifat idempoten
--   - Bila masih ada nota yatim  -> FK TIDAK dipasang (status ditampilkan).
--   - Bila FK sudah ada          -> tidak terjadi apa-apa.
--
-- Jalankan dengan:
--   mysql -u root kbap < 2026-11-12_transaksi_fk_odalan.sql
-- ============================================================================

SET @yatim := (
    SELECT COUNT(*)
    FROM transaksi t
    LEFT JOIN odalan o ON o.id = t.id_piodalan
    WHERE o.id IS NULL
);

SET @ada := (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'transaksi'
      AND CONSTRAINT_NAME = 'fk_transaksi_odalan'
);

SET @sql := IF(
    @yatim > 0,
    'SELECT ''ADA DATA YATIM - FK TIDAK DIPASANG'' AS status',
    IF(
        @ada = 0,
        'ALTER TABLE transaksi ADD CONSTRAINT fk_transaksi_odalan FOREIGN KEY (id_piodalan) REFERENCES odalan (id) ON DELETE RESTRICT ON UPDATE CASCADE',
        'SELECT ''FK fk_transaksi_odalan sudah ada'' AS status'
    )
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verifikasi:
--   SELECT CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE
--   FROM information_schema.REFERENTIAL_CONSTRAINTS
--   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi';
