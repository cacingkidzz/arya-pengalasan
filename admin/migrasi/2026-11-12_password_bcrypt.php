<?php
/**
 * Hash password pengguna yang masih plaintext menjadi bcrypt.
 *
 * Tanggal : 2026-11-12
 * Sifat   : PERUBAHAN DATA (nilai kolom `user.password`)
 *
 * Kenapa skrip, bukan SQL?
 *   MariaDB tidak punya fungsi bcrypt. Hash harus dibuat PHP agar cocok
 *   dengan `password_verify()` di `admin/cek_login.php`.
 *
 * Sifat idempoten
 *   Password yang sudah bcrypt/argon dilewati. Menjalankan ulang aman.
 *   Password pengguna TIDAK berubah; hanya cara penyimpanannya.
 *
 * Jalankan dari folder `admin/migrasi`:
 *   C:\xampp\php\php.exe 2026-11-12_password_bcrypt.php
 *   C:\xampp\php\php.exe 2026-11-12_password_bcrypt.php --dry-run
 *
 * Catatan produksi
 *   Jalankan sekali setelah import database. Skrip ini tidak mencetak
 *   password/hash, hanya jumlah akun yang diubah.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya boleh dijalankan lewat CLI.\n");
}

require __DIR__ . '/../koneksi.php';

$dry_run = in_array('--dry-run', $argv ?? [], true);

/** True bila nilai sudah hash modern (bcrypt/argon). */
$sudah_hash = static function (string $nilai): bool {
    $info = password_get_info($nilai);
    return !empty($info['algo']);
};

$res = $koneksi->query('SELECT id_user, username, password FROM user ORDER BY id_user');
if (!$res) {
    fwrite(STDERR, "Gagal membaca tabel user: " . $koneksi->error . "\n");
    exit(1);
}

$total = 0;
$diubah = 0;
$dilewati = 0;
$gagal = 0;

$upd = $koneksi->prepare('UPDATE user SET password = ? WHERE id_user = ?');

while ($row = $res->fetch_assoc()) {
    $total++;
    $asli = (string) $row['password'];

    if ($sudah_hash($asli)) {
        $dilewati++;
        continue;
    }

    $hash = password_hash($asli, PASSWORD_DEFAULT);
    if ($hash === false) {
        $gagal++;
        continue;
    }

    if ($dry_run) {
        $diubah++;
        continue;
    }

    $id = (int) $row['id_user'];
    $upd->bind_param('si', $hash, $id);
    if ($upd->execute() && $upd->affected_rows === 1) {
        $diubah++;
    } else {
        $gagal++;
    }
}

$upd->close();
$koneksi->close();

printf(
    "%s total=%d diubah=%d sudah_hash=%d gagal=%d\n",
    $dry_run ? '[DRY-RUN]' : '[SELESAI]',
    $total,
    $diubah,
    $dilewati,
    $gagal
);

exit($gagal > 0 ? 1 : 0);
