-- =====================================================================
-- Migrasi: bersihkan pembayaran ganda (duplikat) — soft-cancel
-- Tanggal : 2026-11-12
-- Sifat   : PERUBAHAN DATA (soft-cancel). TIDAK menghapus satu baris pun;
--           nominal & riwayat tetap tersimpan, hanya ditandai batal.
--
-- LATAR BELAKANG (lihat AGENTS.md 10.37):
--   Sebelum 2026-11-12, `proses_pembayaran.php` memakai pola baca-cek-tulis
--   TANPA kunci baris. Bila dua request datang bersamaan (klik ganda /
--   jaringan lambat / retry browser), keduanya membaca status `belum_lunas`
--   dan sama-sama menyisipkan pembayaran untuk tagihan yang sama.
--   Akibatnya ada 8 grup / 17 baris pembayaran aktif untuk 8 tagihan.
--
-- ATURAN PEMBERSIHAN:
--   Untuk setiap pasangan (id_semeton, id_piodalan) yang punya lebih dari
--   satu pembayaran AKTIF (dibatalkan_at IS NULL), pertahankan baris dengan
--   id_pembayaran TERKECIL (pembayaran asli/pertama) dan soft-cancel sisanya.
--   Semua nominal dalam satu grup identik, sehingga menyisakan yang pertama
--   sudah tepat.
--
--   `hutang` TIDAK disentuh: masih ada satu pembayaran sah, jadi tagihan
--   tetap `lunas` dan `jumlah_hutang` tetap 0.
--
-- DAMPAK ANGKA (DB lokal `kbap`):
--   pembayaran aktif 17 baris -> 8 baris; nominal aktif Rp170.680.000 ->
--   Rp169.480.000. Selisih Rp1.200.000 adalah kelebihan bayar yang selama
--   ini ikut terhitung. Setelah bersih: Rp169.480.000 = tagihan asli
--   Rp203.265.000 - sisa Rp33.685.000 - Rp100.000 (tagihan semeton 199 /
--   odalan 2 yang memang dinyatakan lunas oleh bendahara, tanpa pembayaran).
--
-- Aman dijalankan ulang (idempoten): setelah bersih, tidak ada grup dengan
-- lebih dari satu pembayaran aktif, sehingga tidak ada baris yang diubah.
--
-- CATATAN PRODUKSI: `dibatalkan_oleh` diisi 1 (akun admin). Sesuaikan bila
-- id admin di DB produksi berbeda.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 0. Kondisi SEBELUM migrasi
-- ---------------------------------------------------------------------
SELECT 'SEBELUM: grup duplikat' AS info, COUNT(*) AS nilai
FROM (
    SELECT id_semeton, id_piodalan
    FROM `pembayaran`
    WHERE `dibatalkan_at` IS NULL
    GROUP BY id_semeton, id_piodalan
    HAVING COUNT(*) > 1
) x;

SELECT 'SEBELUM: baris berlebih' AS info, COALESCE(SUM(n - 1), 0) AS nilai
FROM (
    SELECT COUNT(*) AS n
    FROM `pembayaran`
    WHERE `dibatalkan_at` IS NULL
    GROUP BY id_semeton, id_piodalan
    HAVING COUNT(*) > 1
) x;

-- ---------------------------------------------------------------------
-- 1. Soft-cancel baris duplikat (sisakan id_pembayaran terkecil)
--    Derived table dipakai agar aman dari error 1093.
-- ---------------------------------------------------------------------
UPDATE `pembayaran` p
JOIN (
    SELECT `id_semeton`, `id_piodalan`, MIN(`id_pembayaran`) AS id_simpan
    FROM `pembayaran`
    WHERE `dibatalkan_at` IS NULL
    GROUP BY `id_semeton`, `id_piodalan`
    HAVING COUNT(*) > 1
) d
  ON d.`id_semeton`  = p.`id_semeton`
 AND d.`id_piodalan` = p.`id_piodalan`
SET p.`dibatalkan_at` = NOW(),
    p.`dibatalkan_oleh` = 1,
    p.`alasan_batal` = 'Dihapus dari halaman data double pembayaran'
WHERE p.`dibatalkan_at` IS NULL
  AND p.`id_pembayaran` <> d.`id_simpan`;

-- ---------------------------------------------------------------------
-- 2. Kondisi SESUDAH migrasi (harus 0 grup duplikat)
-- ---------------------------------------------------------------------
SELECT 'SESUDAH: grup duplikat' AS info, COUNT(*) AS nilai
FROM (
    SELECT id_semeton, id_piodalan
    FROM `pembayaran`
    WHERE `dibatalkan_at` IS NULL
    GROUP BY id_semeton, id_piodalan
    HAVING COUNT(*) > 1
) x;

SELECT 'SESUDAH: pembayaran aktif' AS info,
       COUNT(*) AS baris,
       COALESCE(SUM(jumlah_bayar), 0) AS nominal
FROM `pembayaran`
WHERE `dibatalkan_at` IS NULL;
