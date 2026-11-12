-- ============================================================================
-- Konversi charset sisa tabel ke utf8mb4
-- Tanggal : 2026-11-12
-- Sifat   : PERUBAHAN SKEMA (lossless, tidak mengubah isi baris)
--
-- Latar belakang
--   Sebagian besar tabel sudah utf8mb4, tetapi dua tabel masih latin1:
--     - kategori_pengeluaran
--     - pengeluaran
--   Isi kedua tabel sudah diperiksa: hanya ASCII + CRLF (0 baris non-ASCII
--   selain baris baru), sehingga konversi tidak menimbulkan mojibake.
--
-- Dampak di `kbap` (2026-11-12)
--   kategori_pengeluaran : 5 baris tetap
--   pengeluaran          : 80 baris tetap, total Rp26.962.500 tetap
--   Seluruh 15 tabel kini utf8mb4_general_ci.
--
-- Jalankan dengan:
--   mysql -u root kbap < 2026-11-12_charset_utf8mb4_sisa.sql
-- ============================================================================

ALTER TABLE kategori_pengeluaran
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

ALTER TABLE pengeluaran
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Verifikasi (harus utf8mb4_general_ci semua):
--   SELECT TABLE_NAME, TABLE_COLLATION
--   FROM information_schema.TABLES
--   WHERE TABLE_SCHEMA = DATABASE()
--   ORDER BY TABLE_NAME;
