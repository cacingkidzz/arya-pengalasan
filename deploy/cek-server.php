<?php
// =====================================================================
// ALAT CEK SERVER — KBAP (Arya Pengalasan Pusat)
//
// Berkas ini HANYA untuk memastikan hosting siap dipakai. Unggah ke
// `public_html/cek-server.php`, buka lewat browser, lalu HAPUS kembali.
//
// KEAMANAN
//   - Ganti token di bawah sebelum diunggah (atau biarkan dan hapus
//     segera setelah selesai).
//   - Tanpa token yang benar, halaman ini menjawab 404.
//   - Tidak menulis apa pun ke database (hanya SELECT COUNT).
//   - Tidak pernah menampilkan password.
// =====================================================================

const KBAP_CEK_TOKEN = 'GANTI_TOKEN_INI';

// ---------------------------------------------------------------- util
function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function token_benar(): bool
{
    $token = $_POST['token'] ?? $_GET['token'] ?? '';
    if (!is_string($token) || KBAP_CEK_TOKEN === '' || KBAP_CEK_TOKEN === 'GANTI_TOKEN_INI') {
        return false;
    }
    return hash_equals(KBAP_CEK_TOKEN, $token);
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

if (!token_benar()) {
    http_response_code(404);
    exit('Not found');
}

$root = is_dir(__DIR__ . '/admin') ? __DIR__ : dirname(__DIR__);
$token_raw = $_POST['token'] ?? $_GET['token'] ?? '';
$token = h(is_string($token_raw) ? $token_raw : '');

/** Ambil nilai POST sebagai string (aman bila dikirim sebagai array). */
function post_str(string $nama, string $default = ''): string
{
    $nilai = $_POST[$nama] ?? $default;
    return is_string($nilai) ? $nilai : $default;
}

$cek = [];
function tambah(string $nama, bool $ok, string $nilai = '', string $catatan = ''): void
{
    global $cek;
    $cek[] = ['nama' => $nama, 'ok' => $ok, 'nilai' => $nilai, 'catatan' => $catatan];
}

// ---------------------------------------------------------------- cek dasar
tambah('Versi PHP', version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION, 'Minimal PHP 8.0');

foreach (['mysqli', 'gd', 'mbstring', 'json', 'session'] as $ext) {
    tambah('Ekstensi ' . $ext, extension_loaded($ext), extension_loaded($ext) ? 'aktif' : 'tidak aktif');
}
tambah('Ekstensi fileinfo', extension_loaded('fileinfo'), extension_loaded('fileinfo') ? 'aktif' : 'tidak aktif (disarankan)');

$koneksi_file = $root . '/admin/koneksi.php';
$ada_koneksi  = is_file($koneksi_file);
$isi_koneksi  = $ada_koneksi ? (string) file_get_contents($koneksi_file) : '';
$placeholder  = $isi_koneksi !== ''
    && (strpos($isi_koneksi, '__DB_') !== false || strpos($isi_koneksi, 'nama_user_database') !== false);
tambah(
    'admin/koneksi.php',
    $ada_koneksi && !$placeholder,
    $ada_koneksi ? ($placeholder ? 'ada, masih placeholder' : 'ada') : 'belum ada',
    'Buat dari deploy/koneksi.public_html.php'
);

foreach ([
    'admin/index.php'          => 'Shell admin',
    'admin/login.php'          => 'Halaman login',
    'admin/security.php'       => 'Guard keamanan',
    'admin/dist/css/adminlte.css' => 'Aset CSS',
    'admin/dist/js/adminlte.js'   => 'Aset JS',
    'admin/libs/phpqrcode/qrlib.php' => 'Library QR',
] as $rel => $label) {
    tambah($label, is_file($root . '/' . $rel), is_file($root . '/' . $rel) ? 'ada' : 'HILANG', $rel);
}

$folder_media = [
    'admin/uploads'          => 'unggahan nota',
    'admin/uploads/nota'     => 'berkas nota',
    'admin/kartu_anggota'    => 'kartu JPG',
    'admin/qr_codes'         => 'QR PNG',
];
foreach ($folder_media as $rel => $label) {
    $path = $root . '/' . $rel;
    $ada  = is_dir($path);
    $tulis = $ada && is_writable($path);
    tambah(
        'Folder ' . $label,
        $ada && $tulis,
        !$ada ? 'belum ada' : ($tulis ? 'bisa ditulis' : 'TIDAK bisa ditulis'),
        $rel
    );
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443');
tambah('HTTPS', $https, $https ? 'aktif' : 'belum aktif', 'Aktifkan SSL di hosting sebelum dipakai publik');

// ---------------------------------------------------------------- uji kredensial manual
$uji = null;
if (post_str('aksi') === 'uji_kredensial') {
    $host = trim(post_str('db_host', 'localhost')) ?: 'localhost';
    $user = trim(post_str('db_user'));
    $pass = post_str('db_pass');
    $nama = trim(post_str('db_name'));

    if ($user === '' || $nama === '') {
        $uji = ['ok' => false, 'pesan' => 'User dan nama database wajib diisi.'];
    } else {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $c = new mysqli($host, $user, $pass, $nama);
            $c->set_charset('utf8mb4');
            $server = $c->server_info;
            $charset = $c->character_set_name();
            $jumlah = [];
            foreach (['semeton', 'odalan', 'hutang', 'pembayaran', 'dana_punia'] as $t) {
                $r = $c->query('SELECT COUNT(*) AS n FROM `' . $t . '`');
                $jumlah[$t] = $r ? (int) $r->fetch_assoc()['n'] : null;
            }
            $c->close();
            $uji = [
                'ok' => true,
                'pesan' => 'Koneksi berhasil.',
                'detail' => 'Server ' . $server . ' · charset ' . $charset,
                'jumlah' => $jumlah,
            ];
        } catch (Throwable $e) {
            $uji = ['ok' => false, 'pesan' => 'Koneksi gagal: ' . $e->getMessage()];
        }
    }
}

// ---------------------------------------------------------------- uji lewat koneksi.php
if (($_GET['mode'] ?? '') === 'koneksi') {
    $selesai = false;
    register_shutdown_function(function () use (&$selesai) {
        if (!$selesai) {
            echo '<div class="box bad"><strong>GAGAL.</strong> admin/koneksi.php berhenti sebelum selesai — '
               . 'biasanya karena kredensial salah atau database tidak ada. '
               . 'Pesan aslinya tercetak di atas dan detailnya ada di error_log hosting.</div>';
            echo '</div></body></html>';
        }
    });

    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Uji koneksi.php</title><style>'
       . 'body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:24px;background:#f6f8fa;color:#1f2328}'
       . '.box{background:#fff;border:1px solid #d0d7de;border-radius:8px;padding:16px;margin:12px 0}'
       . '.ok{border-color:#1a7f37;background:#f0fff4}.bad{border-color:#cf222e;background:#fff5f5}'
       . 'code{background:#eff1f3;padding:1px 5px;border-radius:4px}'
       . '</style></head><body><h1>Uji admin/koneksi.php</h1>';

    if (!is_file($koneksi_file)) {
        echo '<div class="box bad">Berkas <code>admin/koneksi.php</code> tidak ditemukan.</div></div></body></html>';
        exit;
    }

    require $koneksi_file;
    $selesai = true;

    if (!isset($koneksi) || !($koneksi instanceof mysqli)) {
        echo '<div class="box bad">admin/koneksi.php selesai tanpa membuat variabel <code>$koneksi</code>.</div>'
           . '</div></body></html>';
        exit;
    }

    $server  = $koneksi->server_info;
    $charset = $koneksi->character_set_name();
    $jumlah  = [];
    foreach (['semeton', 'odalan', 'hutang', 'pembayaran', 'dana_punia', 'transaksi'] as $t) {
        $r = $koneksi->query('SELECT COUNT(*) AS n FROM `' . $t . '`');
        $jumlah[$t] = $r ? (int) $r->fetch_assoc()['n'] : null;
    }

    echo '<div class="box ok"><strong>BERHASIL.</strong> Koneksi memakai <code>admin/koneksi.php</code> bekerja.'
       . '<br>Server: ' . h($server) . ' · charset: ' . h($charset) . '</div>';
    echo '<div class="box"><strong>Isi tabel (baca saja):</strong><ul>';
    foreach ($jumlah as $t => $n) {
        echo '<li><code>' . h($t) . '</code>: ' . ($n === null ? 'tidak bisa dibaca' : h($n)) . ' baris</li>';
    }
    echo '</ul><p>Hapus <code>cek-server.php</code> setelah selesai.</p></div>';
    echo '</div></body></html>';
    exit;
}

// ---------------------------------------------------------------- render
$gagal = 0;
foreach ($cek as $c) {
    if (!$c['ok']) {
        $gagal++;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Cek Server — KBAP</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: system-ui, "Segoe UI", Arial, sans-serif; margin: 0; padding: 24px; background: #f6f8fa; color: #1f2328; }
        h1 { font-size: 1.4rem; margin: 0 0 4px; }
        h2 { font-size: 1.05rem; margin: 24px 0 8px; }
        .sub { color: #57606a; margin: 0 0 20px; font-size: .9rem; }
        .box { background: #fff; border: 1px solid #d0d7de; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .ok { border-left: 4px solid #1a7f37; }
        .bad { border-left: 4px solid #cf222e; }
        .warn { border-left: 4px solid #bf8700; background: #fff8e6; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eaeef2; vertical-align: top; }
        th { background: #f6f8fa; font-weight: 600; }
        .badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: .78rem; font-weight: 700; }
        .b-ok { background: #dafbe1; color: #116329; }
        .b-bad { background: #ffebe9; color: #a40e26; }
        code { background: #eff1f3; padding: 1px 5px; border-radius: 4px; font-size: .85em; }
        input[type=text], input[type=password] { width: 100%; max-width: 320px; padding: 6px 8px; border: 1px solid #d0d7de; border-radius: 6px; }
        label { display: block; margin: 8px 0 2px; font-size: .85rem; font-weight: 600; }
        button { margin-top: 12px; padding: 7px 14px; border: 0; border-radius: 6px; background: #1f6feb; color: #fff; font-weight: 600; cursor: pointer; }
        a.btn { display: inline-block; margin-top: 12px; padding: 7px 14px; border-radius: 6px; background: #1f6feb; color: #fff; text-decoration: none; font-weight: 600; font-size: .9rem; }
        ul { margin: 6px 0; padding-left: 20px; }
    </style>
</head>
<body>
    <h1>Cek Server — KBAP</h1>
    <p class="sub">Hasil pemeriksaan: <strong><?= (int) (count($cek) - $gagal) ?>/<?= count($cek) ?></strong> sesuai.
        Hapus berkas ini setelah selesai.</p>

    <?php if ($gagal > 0) : ?>
        <div class="box warn"><strong>Ada <?= (int) $gagal ?> hal yang perlu diperbaiki.</strong>
            Lihat baris bertanda merah di bawah.</div>
    <?php else : ?>
        <div class="box ok"><strong>Semua pemeriksaan dasar lolos.</strong>
            Lanjutkan dengan uji koneksi database di bagian bawah.</div>
    <?php endif; ?>

    <div class="box">
        <table>
            <thead><tr><th>Pemeriksaan</th><th>Hasil</th><th>Nilai</th><th>Catatan</th></tr></thead>
            <tbody>
            <?php foreach ($cek as $c) : ?>
                <tr>
                    <td><?= h($c['nama']) ?></td>
                    <td><span class="badge <?= $c['ok'] ? 'b-ok' : 'b-bad' ?>"><?= $c['ok'] ? 'OK' : 'GAGAL' ?></span></td>
                    <td><?= h($c['nilai']) ?></td>
                    <td><code><?= h($c['catatan']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2>1. Uji koneksi memakai admin/koneksi.php</h2>
    <div class="box">
        <p>Membuka halaman ini akan menjalankan <code>admin/koneksi.php</code> apa adanya —
            persis seperti saat aplikasi dipakai.</p>
        <a class="btn" href="?token=<?= $token ?>&amp;mode=koneksi">Jalankan uji koneksi.php</a>
    </div>

    <h2>2. Uji kredensial database (tanpa menyimpan apa pun)</h2>
    <div class="box">
        <form method="post" autocomplete="off">
            <input type="hidden" name="token" value="<?= $token ?>">
            <input type="hidden" name="aksi" value="uji_kredensial">
            <label>Host</label>
            <input type="text" name="db_host" value="localhost">
            <label>User database</label>
            <input type="text" name="db_user" placeholder="namauser_dbuser">
            <label>Password</label>
            <input type="password" name="db_pass" placeholder="password database">
            <label>Nama database</label>
            <input type="text" name="db_name" placeholder="namauser_kbap">
            <button type="submit">Uji Kredensial</button>
        </form>

        <?php if ($uji !== null) : ?>
            <div class="box <?= $uji['ok'] ? 'ok' : 'bad' ?>" style="margin-top:16px;margin-bottom:0;">
                <strong><?= $uji['ok'] ? 'BERHASIL.' : 'GAGAL.' ?></strong>
                <?= h($uji['pesan']) ?>
                <?php if (!empty($uji['detail'])) : ?>
                    <div style="margin-top:6px;color:#57606a;"><?= h($uji['detail']) ?></div>
                <?php endif; ?>
                <?php if (!empty($uji['jumlah'])) : ?>
                    <ul>
                        <?php foreach ($uji['jumlah'] as $t => $n) : ?>
                            <li><code><?= h($t) ?></code>: <?= $n === null ? 'tidak bisa dibaca' : h($n) ?> baris</li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="box warn">
        <strong>Jangan lupa:</strong> hapus <code>public_html/cek-server.php</code> setelah selesai.
        Berkas ini membuka informasi struktur database bila token diketahui.
    </div>
</body>
</html>
