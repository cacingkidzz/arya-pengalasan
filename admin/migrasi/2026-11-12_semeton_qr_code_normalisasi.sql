-- ============================================================================
-- Normalisasi nama file QR di `semeton.qr_code`
-- Tanggal : 2026-11-12
-- Sifat   : PERUBAHAN DATA (bukan skema). Idempoten: aman dijalankan berulang.
--
-- Latar belakang
--   Sebagian baris menyimpan `qr_code` dengan awalan folder, mis.
--   '../qr_codes/APP00607.png'. Semua pembaca QR (generator kartu, cetak kartu)
--   sudah memakai basename(), jadi nilai berawalan folder tidak merusak, tetapi
--   menyulitkan pemeriksaan file dan konsistensi data.
--
-- Aksi
--   Ganti nilai dengan bagian setelah '/' terakhir (nama file saja).
--
-- Dampak di `kbap` (2026-11-12)
--   6 baris diubah: APP00607, APP00608, APP00609, APP00610, APP00611, APP00612
--   Jumlah baris tetap 607; 0 nilai kosong; 607 nama file unik.
--   Semua file `admin/qr_codes/<nama>.png` sudah ada (tidak ada QR yang hilang).
--
-- Sebelum menjalankan di produksi:
--   1. BACKUP database.
--   2. Pastikan file QR benar-benar ada:
--      SELECT user_id, qr_code FROM semeton WHERE qr_code LIKE '%/%';
--   3. Setelah migrasi, jalankan "QR Semua" bila ada file yang ternyata hilang.
-- ============================================================================

UPDATE semeton
SET qr_code = SUBSTRING_INDEX(qr_code, '/', -1)
WHERE qr_code LIKE '%/%';

-- Verifikasi (harus 0):
--   SELECT COUNT(*) AS masih_ber_slash FROM semeton WHERE qr_code LIKE '%/%';
