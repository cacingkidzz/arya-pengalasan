# Deploy ke `public_html` (hosting cPanel)

Folder ini berisi berkas bantu untuk mengunggah KBAP ke hosting shared/cPanel.
Semua perintah di bawah dijalankan dari **root proyek** (folder yang sama dengan
`README.md`).

| Berkas | Fungsi |
|---|---|
| `public_html.htaccess` | Template `.htaccess` untuk root `public_html` (keamanan, blokir dump/log, header, cache). |
| `koneksi.public_html.php` | Template `admin/koneksi.php` untuk hosting. Placeholder `__DB_*__` diisi otomatis oleh skrip. |
| `buat-paket.ps1` | Membuat ZIP siap unggah: menyalin kode, membuat `admin/koneksi.php`, menyertakan `.htaccess`, membuat folder media. |
| `cek-server.php` | Alat diagnosa sekali pakai: cek PHP, ekstensi, folder tulis, dan koneksi DB. **Hapus setelah dipakai.** |
| `out/` | Hasil ZIP (tidak ikut repo). |

> Folder `deploy/` **tidak perlu** diunggah ke hosting. Yang diunggah adalah isi ZIP.

---

## 0. Ringkas: 6 langkah

1. Buat database + user di cPanel, catat nama/user/password.
2. Impor dump `database/hosting/kbap_*.sql` lewat phpMyAdmin.
3. Buat paket: `.\deploy\buat-paket.ps1 -DbUser ... -DbPass ... -DbName ...`
4. Unggah ZIP ke `public_html`, extract, hapus ZIP.
5. Pastikan folder media bisa ditulis (755/775) dan `.htaccess` ikut terekstrak.
6. Buka `https://domain-anda/admin/login.php`, ganti semua password.

---

## 1. Prasyarat hosting

| Komponen | Syarat |
|---|---|
| PHP | **8.1+** (disarankan 8.2) |
| Ekstensi | `mysqli`, `gd`, `mbstring`, `json`, `session` (disarankan `fileinfo`) |
| Database | MySQL 5.7+ / MariaDB 10.4+ |
| Web server | Apache (`.htaccess` dipakai). Nginx: lihat bagian [Nginx](#nginx). |
| Ruang | ±15 MB kode + media (nota & kartu bisa ratusan MB) |

Cek cepat lewat cPanel → **Select PHP Version** → *Extensions*.

**Tanpa internet:** semua aset UI sudah lokal di `admin/dist/vendor/` (±6 MB,
67 berkas), jadi halaman admin tidak butuh koneksi keluar. Kalau perlu
memperbarui aset, jalankan `deploy/lokalkan-aset.ps1` di komputer yang
tersambung internet (skrip mengunduh ulang dari CDN lalu menulis ulang tautan
di 15 berkas PHP).

---

## 2. Membuat paket ZIP

### 2a. Dengan kredensial (paling praktis)

```powershell
.\deploy\buat-paket.ps1 `
    -DbUser "namauser_dbuser" `
    -DbPass "password_database" `
    -DbName "namauser_kbap"
```

Hasil: `deploy\out\kbap-public_html-YYYYMMDD-HHmm.zip`, dan di dalamnya
`admin/koneksi.php` sudah terisi kredensial tersebut.

### 2b. Tanpa kredensial (diisi di server)

```powershell
.\deploy\buat-paket.ps1
```

`admin/koneksi.php` di paket masih berisi `__DB_USER__` dan kawan-kawan.
Setelah unggah, edit lewat cPanel File Manager (atau unggah ulang file itu saja).

### Pilihan lain

| Parameter | Efek |
|---|---|
| `-DenganMedia` | Ikutkan `admin/uploads/nota/*`, `admin/kartu_anggota/*`, `admin/qr_codes/*`. Paket bisa sangat besar. |
| `-DenganCekServer` | Ikutkan `cek-server.php` ke paket. |
| `-DenganArsip` | Ikutkan folder `arsip/` (berisi halaman lama). |
| `-TanpaHtaccess` | Jangan sertakan `.htaccess` root (bila hosting sudah punya aturan sendiri). |
| `-TanpaZip` | Hanya siapkan folder kerja, tanpa ZIP. |
| `-Keluaran "D:\tmp"` | Ganti folder hasil. |

**Tidak pernah ikut ke paket:** `.git/`, `AGENTS.md`, `error_log`, `*.log`,
`database/`, `deploy/`, `admin/koneksi.local.php`, `admin/koneksi.rahasia.php`,
`admin/uploads/nota/*`, `admin/kartu_anggota/*`, `admin/qr_codes/*`
(kecuali `-DenganMedia`).

---

## 3. Membuat database di cPanel

1. cPanel → **MySQL® Databases**.
2. **Create New Database** → mis. `kbap` (nama lengkapnya menjadi `namauser_kbap`).
3. **Add New User** → mis. `dbuser` + password kuat → catat.
4. **Add User To Database** → pilih database → centang **ALL PRIVILEGES**.
5. Catat tiga nilai: `namauser_kbap`, `namauser_dbuser`, password.

> Host hampir selalu `localhost`. Bila hosting memakai host lain, cek di
> email/panel (biasanya `localhost` atau `127.0.0.1`).

---

## 4. Mengimpor database

1. cPanel → **phpMyAdmin** → pilih database tadi.
2. Tab **Import** → pilih `database/hosting/kbap_YYYY-MM-DD.sql`.
3. **Character set: `utf8mb4`** (jangan `latin1`).
4. Centang *Partial import* hanya bila file terpotong batas unggah — lebih baik
   naikkan `upload_max_filesize`/`post_max_size` lewat *MultiPHP INI Editor*.
5. Setelah selesai: 15 tabel, `semeton` 607 baris, `pembayaran` 1.497 baris.

Bila ingin instalasi **kosong** (tanpa data warga): impor
`database/kbap_struktur.sql`, lalu jalankan migrasi di `admin/migrasi/`
berurutan dan buat akun admin pertama (lihat `database/README.md`).

---

## 5. Mengunggah ke `public_html`

### Cara A — File Manager (tanpa FTP)

1. cPanel → **File Manager** → `public_html`.
2. **Upload** ZIP hasil langkah 2.
3. Klik kanan ZIP → **Extract** → `public_html` → Extract.
4. Hapus ZIP.
5. Aktifkan **Settings → Show Hidden Files** untuk memastikan `.htaccess` ada.

Struktur akhirnya:

```
public_html/
├── .htaccess              <- dari deploy/public_html.htaccess
├── index.php              <- halaman publik
├── cek_hutang.php, punia.php, search*.php, ...
└── admin/
    ├── index.php
    ├── login.php
    ├── koneksi.php        <- dari deploy/koneksi.public_html.php
    ├── konten/  aksi/  dist/  libs/
    ├── uploads/           <- 755/775, berisi .htaccess
    ├── kartu_anggota/     <- 755/775
    └── qr_codes/          <- 755/775
```

### Cara B — FTP/SFTP

Ekstrak ZIP di komputer, lalu unggah **isi** folder (bukan folder ZIP-nya)
ke `public_html`. Pastikan `.htaccess` ikut terkirim (klien FTP: aktifkan
*show hidden files*).

### Cara C — subfolder

Bila ingin `https://domain-anda/kbap/`, ekstrak ke `public_html/kbap/`.
Aplikasi memakai path relatif, jadi tidak ada yang perlu diubah — kecuali
`.htaccess` yang tetap harus di `public_html/kbap/.htaccess`.

---

## 6. Koneksi database (`admin/koneksi.php`)

Tiga cara, pilih salah satu.

### 6a. Sudah terisi otomatis (langkah 2a)

Tidak ada yang perlu dilakukan. Buka `admin/login.php`; bila muncul
"Koneksi database gagal", lihat [Pemecahan masalah](#pemecahan-masalah).

### 6b. Edit manual

cPanel → File Manager → `public_html/admin/koneksi.php` → **Edit**:

```php
$host     = "localhost";
$username = "namauser_dbuser";
$password = "password_database";
$database = "namauser_kbap";
```

Simpan. Jangan mengubah bagian lain.

### 6c. Environment variable (opsional, lebih aman)

Bila hosting mendukung `SetEnv` (Apache) atau panel environment:

```
KBAP_DB_HOST=localhost
KBAP_DB_USER=namauser_dbuser
KBAP_DB_PASS=password_database
KBAP_DB_NAME=namauser_kbap
```

`admin/koneksi.php` memprioritaskan environment ini di atas nilai default.

> **Jangan** menaruh `admin/koneksi.local.php` di hosting. File itu hanya
> dibaca di localhost/CLI, tetapi tetap tidak perlu diunggah.

---

## 7. Permission folder media

Lewat File Manager (klik kanan → **Change Permissions**) atau FTP:

| Folder | Permission |
|---|---|
| `admin/uploads` | `755` (pakai `775` bila 755 gagal menulis) |
| `admin/uploads/nota` | `755` / `775` |
| `admin/kartu_anggota` | `755` / `775` |
| `admin/qr_codes` | `755` / `775` |

Uji dengan `cek-server.php` (langkah 8). Tanpa izin tulis, unggah nota dan
generate kartu/QR akan gagal.

Bila ada nota lama, unggah isi `admin/uploads/nota/` dari komputer ke folder
yang sama. Kartu & QR **tidak perlu** diunggah — bisa dibuat ulang dari menu
*Semeton*.

---

## 8. Cek server (sekali pakai)

1. Buka `deploy/cek-server.php`, ganti:
   ```php
   const KBAP_CEK_TOKEN = 'GANTI_TOKEN_INI';
   ```
   menjadi token acak, mis. `kbap-cek-7f3a91`.
2. Unggah ke `public_html/cek-server.php`.
3. Buka `https://domain-anda/cek-server.php?token=kbap-cek-7f3a91`.
4. Periksa tabel: PHP, ekstensi, `admin/koneksi.php`, folder media, HTTPS.
5. Klik **Jalankan uji koneksi.php** dan/atau isi form kredensial.
6. **Hapus `cek-server.php`.** Selama token masih placeholder, halaman ini
   menjawab 404, tetapi tetap jangan dibiarkan.

---

## 9. Setelah terpasang

1. Buka `https://domain-anda/admin/login.php`.
2. **Ganti semua password** (menu *Data Pokok → User*). Password bawaan dari
   dump adalah password lama proyek.
3. Hapus akun yang tidak dipakai.
4. Aktifkan HTTPS, lalu buka `deploy/public_html.htaccess` bagian 7 dan
   hilangkan tanda komentar pada aturan redirect HTTPS.
5. Jadwalkan backup database (cPanel → *Backup* atau `mysqldump` cron).
6. Bila kartu anggota dipakai: menu *Semeton* → **QR Semua**, lalu
   **Buat Kartu** per anggota atau cetak massal.

---

## 10. Isi `.htaccess` yang dipasang

`deploy/public_html.htaccess` melakukan:

| Bagian | Efek |
|---|---|
| 1 | `Options -Indexes`, `DirectoryIndex`, charset UTF-8 |
| 2 | Tolak akses ke `.env`/`.git`, `*.sql`, `*.bak`, `*.log`, `error_log`, `*.md`, dan `koneksi*.php` |
| 3 | `RedirectMatch 404` untuk `/database`, `/arsip`, `/deploy`, `/migrasi`, `/.git` |
| 4 | Blokir eksekusi PHP di `admin/uploads`, `kartu_anggota`, `qr_codes` |
| 5 | Header `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` (kamera diizinkan untuk pemindai QR) |
| 6 | Cache aset statis 7 hari |
| 7 | Redirect HTTPS (**nonaktif** — aktifkan setelah SSL siap) |
| 8 | Pesan 403/404 sederhana |

Bila hosting memakai Nginx atau melarang `Options`/`Header`, lihat bagian
berikut.

---

## 11. Pemecahan masalah

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| **HTTP 500** di semua halaman | `.htaccess` memakai direktif yang tidak diizinkan (`Options`, `Header`, `php_flag`) | Rename `.htaccess` → `.htaccess.off`, muat ulang. Bila normal, hapus baris bermasalah satu per satu. |
| **"Koneksi database gagal"** | Kredensial salah, user belum diberi akses, atau nama DB salah | Jalankan `cek-server.php` mode kredensial. Pastikan user ditambahkan ke DB dengan ALL PRIVILEGES. |
| Halaman admin **blank** | `display_errors` mati + ada fatal error | Buka `error_log` di `public_html` atau cPanel → *Errors*. Sementara set `display_errors=1` lewat *MultiPHP INI Editor*. |
| **403** saat buka halaman | Aturan `.htaccess` terlalu ketat / `mod_authz_core` berbeda | Cek apakah yang diblokir memang berkas sensitif. Sesuaikan `<FilesMatch>`. |
| Login **berhasil lalu terlempar** ke halaman login | Session tidak tersimpan (folder session penuh / cookie) | Cek `session.save_path` di phpinfo, pastikan HTTPS/konsisten domain. |
| **Unggah nota gagal** | Folder tidak bisa ditulis | Set `755`/`775` pada `admin/uploads/nota`. |
| **Buat kartu/QR gagal** | `gd` tidak aktif, font/background hilang, folder tidak writable | Pastikan ekstensi `gd` aktif, `admin/dist/assets/img/kartu.png` & `admin/dist/font/arial.ttf` ada, folder `kartu_anggota`/`qr_codes` writable. |
| **Data rusak/Ã¢â‚¬â„¢** | Impor dengan charset salah | Impor ulang dengan **utf8mb4**, pastikan koneksi `set_charset('utf8mb4')`. |
| **404** di halaman publik | Ekstrak ZIP ke folder yang salah | Pastikan `index.php` ada langsung di `public_html` (bukan `public_html/kbap/kbap/`). |
| **Import dump gagal** (file too large) | Batas unggah PHP | Naikkan `upload_max_filesize`/`post_max_size`, atau impor per tabel. |

---

## 12. Memperbarui aplikasi (redeploy)

1. Backup database + folder media (`uploads/nota`, `kartu_anggota`).
2. Buat paket baru: `.\deploy\buat-paket.ps1 -DbUser ... -DbName ...`
   (tanpa `-DenganMedia` agar kecil).
3. Di hosting, **jangan** hapus `admin/uploads/`, `admin/kartu_anggota/`,
   `admin/qr_codes/`, dan `admin/koneksi.php`.
4. Ekstrak ZIP menimpa berkas lama (File Manager akan menanyakan *overwrite*).
   Karena ZIP tidak memuat folder media, isinya aman.
5. Bila ada migrasi baru, jalankan berurutan dari `admin/migrasi/` lewat
   phpMyAdmin (atau minta bantuan CLI hosting).
6. Uji: login, dashboard, satu pembayaran percobaan (lalu batalkan), cetak laporan.

## 13. Rollback

- Kode: simpan ZIP versi sebelumnya; ekstrak ulang untuk kembali.
- Database: restore dump yang dibuat tepat sebelum pembaruan.
- Jangan pernah "rollback" dengan menghapus tabel secara manual.

---

## Nginx

Nginx tidak membaca `.htaccess`. Aturan minimal yang setara:

```nginx
location ~* ^/(database|arsip|deploy|migrasi|\.git) { return 404; }
location ~* ^/admin/koneksi.*\.php$ { return 403; }
location ~* \.(sql|log|md|bak|zip|gz|tar)$ { return 403; }
location ~* ^/admin/(uploads|kartu_anggota|qr_codes)/.*\.(php|phtml|phar|pl|py|cgi|sh)$ { return 403; }
add_header X-Content-Type-Options nosniff;
add_header X-Frame-Options SAMEORIGIN;
add_header Referrer-Policy strict-origin-when-cross-origin;
add_header Permissions-Policy "geolocation=(), microphone=(), camera=(self)";
```

---

## Catatan

- Aplikasi memakai path relatif, sehingga aman di root domain maupun subfolder.
- `admin/koneksi.php` **tidak pernah** masuk repo (`.gitignore`), termasuk
  `admin/koneksi.local.php` dan `admin/koneksi.rahasia.php`.
- Dump berisi data pribadi warga. Simpan di `database/hosting/` (gitignored)
  dan jangan pernah menaruhnya di `public_html`.
- Skrip `buat-paket.ps1` hanya membaca berkas; ia tidak menyentuh database.
