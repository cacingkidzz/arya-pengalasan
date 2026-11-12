# Folder Arsip

Berisi file yang **sudah tidak dipakai** oleh aplikasi (per 2026-11-08).
Disimpan, bukan dihapus, supaya bisa dikembalikan kapan saja
(proyek ini bukan repositori git, jadi penghapusan tidak bisa di-undo).

| File | Asal | Alasan diarsipkan |
|---|---|---|
| `semeton_1.php` | `admin/konten/semeton_1.php` | Duplikat halaman data semeton; tidak ada satu pun tautan yang mengarah ke file ini. Sudah digantikan `admin/konten/semeton.php` (versi teroptimasi). |
| `rekap_semeton.php` | `admin/rekap_semeton.php` | File kosong (0 byte) sejak awal. |
| `tampil_nota.php` | `admin/konten/tampil_nota.php` | Penampil gambar nota model pengeluaran lama; tidak pernah ditautkan dari UI. Bisa dikembalikan bila pratinjau nota ingin diaktifkan lagi. |

## Cara mengembalikan

```powershell
Move-Item arsip\semeton_1.php admin\konten\semeton_1.php
Move-Item arsip\rekap_semeton.php admin\rekap_semeton.php
Move-Item arsip\tampil_nota.php admin\konten\tampil_nota.php
```

Catatan: file di folder ini **tidak** ikut dijaga oleh `admin/security.php`
(tidak memakai `kbap_guard`), jadi jangan dipindahkan kembali ke web root
tanpa menambahkan penjaga login terlebih dahulu.
