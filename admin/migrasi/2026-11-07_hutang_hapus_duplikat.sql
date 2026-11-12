-- =====================================================================
-- Migrasi: bersihkan duplikat `hutang` (id_semeton, id_odalan)
-- Tanggal : 2026-11-07
-- Sifat   : MENGHAPUS DATA (1 baris). Disetujui user.
--
-- Kasus: semeton id 199 (tidak_aktif) punya 2 baris
--        tagihan untuk odalan 3:
--          id 1818 -> 55.000, belum_lunas
--          id 1819 -> 55.000, belum_lunas   <-- duplikat, dihapus
--        Tidak ada pembayaran sama sekali untuk pasangan (199, 3),
--        jadi penghapusan tidak menyentuh uang.
--
-- Yang disisakan: baris dengan id TERKECIL (1818).
--
-- Efek agregat di `kbap`:
--   hutang         1.809 -> 1.808 baris
--   belum_lunas      320 -> 319
--   total sisa   33.740.000 -> 33.685.000  (-55.000, koreksi tagihan dobel)
--   total tagihan asli 203.320.000 -> 203.265.000
--
-- Aman dijalankan ulang: DELETE bersyarat + penjaga "tidak ada pembayaran".
-- =====================================================================

-- Pengaman: hanya hapus bila benar-benar duplikat & belum pernah dibayar.
DELETE h
FROM `hutang` h
JOIN `hutang` keep
  ON keep.`id_semeton` = h.`id_semeton`
 AND keep.`id_odalan`  = h.`id_odalan`
 AND keep.`id`        < h.`id`
WHERE h.`id` = 1819
  AND NOT EXISTS (
    SELECT 1 FROM `pembayaran` p
    WHERE p.`id_semeton`  = h.`id_semeton`
      AND p.`id_piodalan` = h.`id_odalan`
  );

-- ---------------------------------------------------------------------
-- Verifikasi
-- ---------------------------------------------------------------------
SELECT 'grup_duplikat_tersisa' AS info, COUNT(*) AS nilai FROM (
  SELECT id_semeton, id_odalan FROM `hutang`
  GROUP BY id_semeton, id_odalan HAVING COUNT(*) > 1
) x;

SELECT 'hutang_total' AS info, COUNT(*) AS nilai,
       SUM(jumlah_hutang) AS total_sisa,
       SUM(jumlah_tagihan_asli) AS total_tagihan_asli,
       SUM(status = 'belum_lunas') AS belum_lunas
FROM `hutang`;

SELECT id, id_semeton, id_odalan, jumlah_hutang, jumlah_tagihan_asli, status
FROM `hutang` WHERE id_semeton = 199 AND id_odalan = 3;
