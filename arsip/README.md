# Folder Arsip

Berisi file yang **sudah tidak dipakai** oleh aplikasi (per 2026-11-08).
Disimpan, bukan dihapus, supaya bisa dikembalikan kapan saja
(proyek ini bukan repositori git, jadi penghapusan tidak bisa di-undo).

| File | Asal | Alasan diarsipkan |
|---|---|---|
| `semeton_1.php` | `admin/konten/semeton_1.php` | Duplikat halaman data semeton; tidak ada satu pun tautan yang mengarah ke file ini. Sudah digantikan `admin/konten/semeton.php` (versi teroptimasi). |
| `rekap_semeton.php` | `admin/rekap_semeton.php` | File kosong (0 byte) sejak awal. |
| `tampil_nota.php` | `admin/konten/tampil_nota.php` | Penampil gambar nota model pengeluaran lama; tidak pernah ditautkan dari UI. Bisa dikembalikan bila pratinjau nota ingin diaktifkan lagi. |
| `aksi_pengeluaran.php` | `admin/aksi/aksi_pengeluaran.php` | Proses CRUD tabel `pengeluaran` model lama (2025). Tidak ada satu pun rujukan di UI; penggantinya `aksi/aksi_transaksi.php`. Kode lama masih memakai SQL interpolasi dan menyimpan nama berkas nota tanpa sanitasi, jadi lebih aman diarsipkan. |
| `get_laporan.php` | `admin/aksi/get_laporan.php` | Endpoint AJAX laporan pengeluaran lama; 0 rujukan. Pengganti: `konten/laporan_pengeluaran.php`. |
| `get_pengeluaran.php` | `admin/aksi/get_pengeluaran.php` | Endpoint AJAX daftar pengeluaran lama; 0 rujukan. |
| `get_transaksi.php` | `admin/aksi/get_transaksi.php` | Endpoint AJAX daftar transaksi model baru; 0 rujukan (halaman memakai query server-side). |

## Cara mengembalikan

```powershell
Move-Item arsip\semeton_1.php admin\konten\semeton_1.php
Move-Item arsip\rekap_semeton.php admin\rekap_semeton.php
Move-Item arsip\tampil_nota.php admin\konten\tampil_nota.php
Move-Item arsip\aksi_pengeluaran.php admin\aksi\aksi_pengeluaran.php
Move-Item arsip\get_laporan.php admin\aksi\get_laporan.php
Move-Item arsip\get_pengeluaran.php admin\aksi\get_pengeluaran.php
Move-Item arsip\get_transaksi.php admin\aksi\get_transaksi.php
```

Catatan: file di folder ini **tidak** ikut dijaga oleh `admin/security.php`
(tidak memakai `kbap_guard`), jadi jangan dipindahkan kembali ke web root
tanpa menambahkan penjaga login terlebih dahulu.
