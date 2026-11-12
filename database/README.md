# Folder `database/`

Folder ini menampung berkas SQL untuk pemasangan (deployment).

| Berkas | Ikut repo? | Isi |
|---|---|---|
| `kbap_struktur.sql` | **Ya** | Struktur 15 tabel (tanpa data), aman dipublikasikan. |
| `hosting/kbap_YYYY-MM-DD.sql` | **Tidak** (gitignored) | Struktur + **data asli** warga & keuangan. Hanya untuk diimpor ke hosting. |
| `../admin/migrasi/*.sql` | **Ya** | Migrasi bertahap yang sudah dijalankan di DB lokal. |

## Membuat dump sendiri

Struktur saja (boleh di-commit):

```bat
mysqldump -u root --no-data --skip-triggers --no-tablespaces ^
  --default-character-set=utf8mb4 kbap > database\kbap_struktur.sql
```

Struktur + data (JANGAN di-commit):

```bat
mysqldump -u root --single-transaction --skip-triggers --no-tablespaces ^
  --default-character-set=utf8mb4 kbap > database\hosting\kbap_2026-11-12.sql
```

## Memasang di hosting (phpMyAdmin)

1. Buat database baru + user di cPanel, mis. `namauser_kbap`.
2. Buka phpMyAdmin → pilih database → tab **Import**.
3. Unggah `database/hosting/kbap_2026-11-12.sql`, karakter **utf8mb4**, klik **Go**.
4. Salin `admin/koneksi.contoh.php` → `admin/koneksi.php`, isi kredensial hosting.
5. Buka `https://domain-anda/admin/login.php`.
6. **Segera ganti semua password** lewat menu *Data Pokok → User*.

## Urutan migrasi (bila memakai `kbap_struktur.sql` kosong)

Jalankan berurutan dari `admin/migrasi/`:

```
2026-11-06_hutang_jumlah_tagihan_asli.sql
2026-11-06_pembayaran_pembatalan.sql
2026-11-06_pengeluaran_fk_dan_audit_log.sql
2026-11-07_hutang_hapus_duplikat.sql
2026-11-07_hutang_unique_semeton_odalan.sql
2026-11-07_dana_punia_petakan_dan_fk.sql
2026-11-07_semeton_nik_unique.sql
2026-11-10_pembayaran_fk_restrict.sql
2026-11-11_semeton_data_pendukung.sql
2026-11-12_pembayaran_bersihkan_duplikat.sql
2026-11-12_pembayaran_kunci_aktif_unique.sql
2026-11-12_semeton_qr_code_normalisasi.sql
2026-11-12_charset_utf8mb4_sisa.sql
2026-11-12_transaksi_fk_odalan.sql
```

Semua migrasi bersifat **idempoten** (aman dijalankan ulang), tetapi tetap
**backup** database sebelum menjalankannya.

Password default pada dump data asli masih berupa hash bcrypt dari password
lama. Setelah import, wajib diganti satu per satu lewat menu User.

Untuk database **tanpa data** (hanya `kbap_struktur.sql`), belum ada akun
sama sekali. Buat akun admin pertama dengan SQL berikut (password awal
`GantiSaya2026`, ganti setelah login):

```sql
INSERT INTO user (nama_user, username, password, akses) VALUES
('Administrator', 'admin',
 '$2y$10$9JKKAXm69sbgx.s75us32OWnCqawUao5UDzKIj.Y18MeNZnVIvIhi',
 'admin');
```
