<?php
// =====================================================================
// CONTOH konfigurasi lokal (XAMPP) — JANGAN diunggah ke hosting.
//
// Cara pakai:
//   1. Salin file ini menjadi `admin/koneksi.local.php`.
//   2. Sesuaikan user/password MySQL lokal Anda.
//
// File ini hanya dibaca oleh `admin/koneksi.php` bila aplikasi diakses
// dari localhost / 127.0.0.1 / ::1 atau lewat CLI. Di server produksi
// file ini diabaikan, sehingga kredensial produksi tetap dipakai.
//
// `admin/koneksi.local.php` sudah masuk .gitignore.
// =====================================================================

$host     = 'localhost';
$username = 'root';
$password = '';
$database = 'kbap';
