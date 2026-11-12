# KBAP — Sistem Informasi Krama Bali (Arya Pengalasan Pusat)

Aplikasi web PHP native untuk mengelola data warga (semeton), tagihan urunan
per odalan, pembayaran, dana punia, pengeluaran, dan laporan keuangan.

Dibuat sederhana: tanpa framework, tanpa Composer, cukup PHP + MySQL/MariaDB.
Cocok dijalankan di XAMPP (lokal) maupun shared hosting cPanel.

---

## Daftar Isi

- [Fitur](#fitur)
- [Kebutuhan Sistem](#kebutuhan-sistem)
- [Struktur Folder](#struktur-folder)
- [Pemasangan di XAMPP](#pemasangan-di-xampp)
- [Pemasangan di Hosting](#pemasangan-di-hosting)
- [Konfigurasi Koneksi](#konfigurasi-koneksi)
- [Akun & Hak Akses](#akun--hak-akses)
- [Alur Kerja Harian](#alur-kerja-harian)
- [Keamanan](#keamanan)
- [Pencadangan Data](#pencadangan-data)
- [Catatan Pengembangan](#catatan-pengembangan)

---

## Fitur

### Data warga
- Data semeton (anggota) lengkap: identitas, data pendukung (NIK, No. KK,
  jenis kelamin, tempat/tanggal lahir, agama, golongan darah, status
  perkawinan, pendidikan, pekerjaan, nama orang tua, catatan), kontak, dan
  wilayah/sub wilayah.
- Pencarian & filter server-side (nama, NIK, No. KK, no HP, wilayah, status,
  jenis kelamin), pagination, dan ekspor CSV.
- Usulan pendaftaran anggota baru dari halaman publik, diverifikasi admin.
- Wilayah & sub wilayah dengan pengaman: tidak bisa dihapus bila masih dipakai.
- Kartu anggota (JPG) + QR code per anggota, bisa dibuat satu-satu atau massal.

### Urunan & pembayaran
- Odalan (piodalan) dengan tarif berbeda untuk `ngarep` dan `ngempi`.
- Generate tagihan massal untuk seluruh anggota dalam satu odalan.
- Kasir pembayaran: tunai / transfer / QRIS, dengan pemindai QR anggota.
- **Pencegahan pembayaran ganda** berlapis: kunci baris `SELECT ... FOR UPDATE`
  di server + unique index `uq_pembayaran_aktif` di database.
- Pembatalan pembayaran bersifat *soft-cancel* (tidak menghapus riwayat) dan
  otomatis memulihkan sisa tagihan.
- Pemisahan jelas antara **pembayaran odalan berjalan**, **tunggakan** odalan
  lama, dan **bayar di muka**.

### Dana punia
- Pencatatan donasi per odalan, rekap per odalan / per hari, cetak laporan.

### Pengeluaran
- Model lama (`pengeluaran`) dan model baru berbasis nota
  (`transaksi` + `detail_transaksi`) dibaca berdampingan di satu laporan.
- Kategori pengeluaran dengan pengaman hapus.
- Cetak laporan A4 siap PDF.

### Laporan
- Rekap urunan & pembayaran global / per odalan / per anggota.
- Rekap tunggakan (historis) + ringkasan per wilayah.
- Laba/rugi per odalan (punia + urunan − pengeluaran).
- Laporan kasir per periode dengan pemisahan odalan ini / tunggakan / di muka.
- Ekspor CSV (semeton, tunggakan, urunan, punia, kasir, laba/rugi).

### Audit & keamanan
- Tabel `audit_log`: mencatat INSERT/UPDATE/DELETE/CANCEL/LOGIN beserta
  pengguna, waktu, IP, dan data lama/baru (password tidak pernah dicatat).
- Login dengan `password_verify()` (bcrypt), CSRF token, prepared statement,
  escape output, dan pembatasan menu per role.

---

## Kebutuhan Sistem

| Komponen | Minimum | Disarankan |
|---|---|---|
| PHP | 8.0 | 8.1 / 8.2 |
| MySQL / MariaDB | MySQL 5.7 / MariaDB 10.4 | MariaDB 10.4+ |
| Ekstensi PHP | `mysqli`, `gd`, `mbstring`, `json` | + `fileinfo` |
| Web server | Apache (mod_rewrite tidak wajib) | Apache 2.4 |

Aplikasi **tidak** memerlukan Composer, Node.js, atau internet saat dipakai —
semua aset UI (Bootstrap, jQuery, DataTables, SweetAlert2) sudah ada di
`admin/dist/`.

---

## Struktur Folder

```
.
├── index.php                  # halaman publik: pencarian & cek urunan
├── cek_hutang.php             # cek tagihan berdasarkan User ID
├── punia.php                  # daftar dana punia publik
├── search.php / search_semeton.php
├── form_tambah_semeton.php    # pendaftaran anggota baru (publik)
├── admin/
│   ├── index.php              # shell admin (sidebar + konten)
│   ├── konten.php             # router menu admin
│   ├── sidebar.php            # definisi menu terpusat per role
│   ├── security.php           # guard login/role, CSRF, audit log
│   ├── koneksi.php            # kredensial DB  (TIDAK ikut repo)
│   ├── koneksi.contoh.php     # template kredensial
│   ├── konten/                # tampilan tiap menu
│   ├── aksi/                  # proses tulis (simpan/ubah/hapus)
│   ├── migrasi/               # migrasi SQL/PHP bertahap
│   ├── dist/                  # aset UI (Bootstrap, jQuery, DataTables)
│   ├── libs/phpqrcode/        # generator QR
│   ├── uploads/               # nota unggahan   (TIDAK ikut repo)
│   ├── kartu_anggota/         # kartu JPG hasil generate (TIDAK ikut repo)
│   └── qr_codes/              # QR PNG hasil generate    (TIDAK ikut repo)
├── database/
│   ├── kbap_struktur.sql      # struktur 15 tabel (boleh dipublikasikan)
│   └── hosting/               # dump data asli (TIDAK ikut repo)
├── arsip/                     # berkas lama yang tidak dipakai
└── .gitignore
```

---

## Pemasangan di XAMPP

1. **Salin proyek** ke `C:\xampp\htdocs\data.aryapengalasan`.
2. **Nyalakan Apache + MySQL** dari XAMPP Control Panel.
3. **Buat database** `kbap` lewat phpMyAdmin (`http://localhost/phpmyadmin`).
4. **Impor database**:
   - Dengan data asli → impor `database/hosting/kbap_*.sql`.
   - Tanpa data → impor `database/kbap_struktur.sql`, lalu jalankan migrasi
     di `admin/migrasi/` berurutan dan buat akun admin pertama (lihat
     `database/README.md`).
5. **Konfigurasi koneksi**:
   ```bat
   copy admin\koneksi.contoh.php admin\koneksi.php
   copy admin\koneksi.local.contoh.php admin\koneksi.local.php
   ```
   Sesuaikan `admin/koneksi.local.php` (default XAMPP: `root`, password kosong).
6. **Pastikan folder media bisa ditulis** (Windows umumnya sudah):
   `admin/uploads`, `admin/uploads/nota`, `admin/kartu_anggota`, `admin/qr_codes`.
7. Buka `http://localhost/data.aryapengalasan/admin/login.php`.

---

## Pemasangan di Hosting

1. **Unggah kode** (tanpa `database/hosting/`, tanpa `admin/koneksi.php`,
   tanpa `admin/uploads/*`, `kartu_anggota/*`, `qr_codes/*`) ke `public_html`
   atau subfolder, mis. `public_html/kbap`.
2. **Buat database & user** di cPanel → MySQL Databases. Catat nama
   database, user, dan password (di hosting biasanya berawalan `namauser_`).
3. **Impor dump** lewat phpMyAdmin → tab Import → pilih
   `database/hosting/kbap_*.sql` → karakter `utf8mb4`.
4. **Buat `admin/koneksi.php`** di server (File Manager → New File), isi:
   ```php
   <?php
   $host     = "localhost";
   $username = "namauser_dbuser";
   $password = "password_db";
   $database = "namauser_kbap";
   ```
   Cara termudah: unggah `admin/koneksi.contoh.php`, rename menjadi
   `koneksi.php`, lalu edit 3 baris di atas.
5. **Set permission** folder media ke `755` (atau `775` bila perlu tulis):
   `admin/uploads`, `admin/uploads/nota`, `admin/kartu_anggota`, `admin/qr_codes`.
6. **Unggah berkas nota lama** (bila ada) ke `admin/uploads/nota/` — tidak
   ikut repo karena berukuran besar.
7. Buka `https://domain-anda/admin/login.php`, lalu **ganti semua password**.
8. Bila kartu anggota ingin dipakai, buka menu *Semeton* → **Buat Kartu**
   (per anggota) atau **QR Semua** untuk membuat QR massal.

> Jangan pernah mengunggah folder `database/` ke hosting. Bila terlanjur,
> `.htaccess` di dalamnya sudah memblokir akses, tetapi tetap hapus.

---

## Konfigurasi Koneksi

`admin/koneksi.php` memilih kredensial dengan urutan:

1. **Environment variable** `KBAP_DB_USER` (+ `KBAP_DB_PASS`, `KBAP_DB_HOST`,
   `KBAP_DB_NAME`) — dipakai untuk pengujian/CLI.
2. **`admin/koneksi.local.php`** — hanya dibaca bila diakses dari
   `localhost` / `127.0.0.1` / `::1` atau lewat CLI. Aman ditinggalkan di
   server produksi karena diabaikan.
3. **Nilai default** di `admin/koneksi.php` — dipakai di produksi.

Koneksi memakai `utf8mb4`. Bila koneksi gagal, pengguna hanya melihat pesan
umum (detail masuk `error_log`), sehingga kredensial tidak bocor.

Contoh uji cepat lewat CLI:

```bat
set KBAP_DB_USER=root
set KBAP_DB_NAME=kbap
C:\xampp\php\php.exe -l admin\security.php
```

---

## Akun & Hak Akses

Ada 3 peran (`user.akses`):

| Peran | Bisa mengakses |
|---|---|
| `admin` | Semua menu, termasuk User, Audit Hutang/Bayar, Double Pembayaran, Jejak Audit. |
| `bendahara` | Kasir, pembayaran, punia, seluruh laporan keuangan & cetak. Tidak bisa kelola user/wilayah/odalan. |
| `bagian_data` | Data semeton, wilayah, sub wilayah, odalan, kategori, pengeluaran, rekap dasar. Tidak bisa punia & laporan keuangan tertentu. |

Daftar menu per peran didefinisikan **satu tempat**: fungsi
`kbap_allowed_menus()` di `admin/security.php`. Sidebar (`admin/sidebar.php`)
dan router (`admin/konten.php`) sama-sama memakainya, sehingga menu yang
tampil selalu bisa dibuka.

Password disimpan sebagai hash **bcrypt**. Untuk memindahkan password lama
yang masih plaintext (mis. hasil import database lama):

```bat
cd admin\migrasi
C:\xampp\php\php.exe 2026-11-12_password_bcrypt.php --dry-run
C:\xampp\php\php.exe 2026-11-12_password_bcrypt.php
```

Skrip ini idempoten (yang sudah hash dilewati) dan tidak pernah mencetak
password/hash.

---

## Alur Kerja Harian

1. **Tambah odalan** — menu *Data Pokok → Piodalan*. Isi nama, tanggal, tarif
   `ngarep` & `ngempi`. Tagihan seluruh anggota langsung dibuat otomatis.
2. **Terima pembayaran** — menu *Transaksi → Pembayaran* → cari anggota
   (atau pindai QR di halaman detail) → pilih odalan → simpan.
3. **Lihat uang masuk hari ini** — menu *Transaksi → Kasir & Tunggakan*:
   pilih odalan acara + periode, lihat pemisahan odalan ini / tunggakan /
   bayar di muka, lalu **Cetak Laporan** atau **Unduh CSV**.
4. **Catat pengeluaran** — menu *Transaksi → Pengeluaran* → pilih odalan →
   tambah nota → isi rincian barang.
5. **Laporan** — grup *Laporan Urunan*, *Tunggakan & Cetak*, dan
   *Laporan Keuangan*.

---

## Keamanan

- Semua halaman admin memerlukan login; setiap file di `admin/konten/` dan
  `admin/aksi/` memanggil `kbap_guard()` / `kbap_guard_menu()` sendiri,
  sehingga akses langsung tanpa lewat `index.php` tetap ditolak.
- Semua form POST memakai **CSRF token** (disisipkan otomatis oleh JS global
  di `admin/index.php`).
- Semua query yang melibatkan input pengguna memakai **prepared statement**.
- Output di-escape dengan `htmlspecialchars()`.
- Pembayaran ganda dicegah di dua lapis (lock baris + unique index).
- Odalan/wilayah/sub wilayah/kategori yang masih punya data **tidak bisa
  dihapus**, agar riwayat keuangan tidak hilang.
- Berkas yang wajib dijaga agar tidak masuk repo: `admin/koneksi.php`,
  `admin/koneksi.local.php`, `database/hosting/*.sql`, `AGENTS.md`.

Setelah pemasangan:

1. Ganti semua password bawaan.
2. Hapus akun yang tidak dipakai.
3. Aktifkan HTTPS di hosting.
4. Backup database secara berkala.

---

## Pencadangan Data

```bat
mysqldump -u root --single-transaction --skip-triggers --no-tablespaces ^
  --default-character-set=utf8mb4 kbap > backup_kbap_2026-11-12.sql
```

Simpan juga folder `admin/uploads/nota/` dan `admin/kartu_anggota/` — berkas
gambar tidak tersimpan di database, hanya nama filenya.

---

## Catatan Pengembangan

- Proyek **tanpa framework & tanpa Composer**; setiap halaman admin adalah
  file PHP mandiri yang di-include oleh `admin/konten.php`.
- Timezone aplikasi: `Asia/Makassar` (diatur di `admin/index.php`).
- Karakter set seluruh tabel: `utf8mb4_general_ci`.
- Perubahan skema dicatat sebagai file migrasi di `admin/migrasi/` dengan nama
  `YYYY-MM-DD_deskripsi.sql`; migrasi harus **idempoten**.
- Uji tulis jangan pernah dijalankan pada database berisi data nyata — gunakan
  database kloning (`mysqldump` → `CREATE DATABASE kbap_uji` → impor).

---

## Lisensi

Belum ditentukan. Bila ingin dipublikasikan sebagai perangkat lunak bebas,
tambahkan berkas `LICENSE` (mis. MIT) sesuai keputusan pemilik proyek.
