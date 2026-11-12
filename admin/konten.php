 <?php
    require_once __DIR__ . '/security.php';

    // Mengecek parameter menu yang ada di URL
    if (isset($_GET['menu'])) {
        $menu = $_GET['menu'];

        // Otorisasi menu berdasarkan role (sidebar saja tidak cukup).
        if (!in_array($menu, kbap_allowed_menus(kbap_role()), true)) {
            echo '<div class="alert alert-danger m-4"><strong>Akses ditolak.</strong> Role Anda tidak memiliki izin membuka menu ini.</div>';
            return;
        }

        // Menyertakan halaman berdasarkan menu yang dipilih
        switch ($menu) {
            case 'home':
                include('konten/home.php'); // Menyertakan halaman home.php
                break;
            case 'user':
                include('konten/user.php'); // Menyertakan halaman aplikasi.php
                break;
            case 'wilayah':
                include('konten/wilayah.php'); // Menyertakan halaman aplikasi.php
                break;
            case 'sub_wilayah':
                include('konten/sub_wilayah.php'); // Menyertakan halaman guru.php
                break;
            case 'piodalan':
                include('konten/piodalan.php'); // Menyertakan halaman guru.php
                break;
            case 'semeton':
                include('konten/semeton.php'); // Menyertakan halaman guru.php
                break;
            case 'pembayaran':
                include('konten/pembayaran.php'); // Menyertakan halaman siswa.php
                break;
            case 'pembayaran_detail':
                include('konten/pembayaran_detail.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'rekap_urunan':
                include('konten/rekap_urunan.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'rekap_filter':
                include('konten/rekap_filter.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'rekap_hari':
                include('konten/rekap_hari.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'rekap_bayar':
                include('konten/rekap_bayar.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'semeton_baru':
                include('konten/semeton_baru.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'rekap_bayar_piodalan':
                include('konten/rekap_bayar_piodalan.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'dana_punia':
                include('konten/dana_punia.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'rekap_punia_hari_ini':
                include('konten/rekap_punia_hari_ini.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'rekap_punia_filter':
                include('konten/rekap_punia_filter.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'rekap_punia':
                // Alias lama (dipakai oleh redirect aksi_dana_punia.php)
                include('konten/rekap_punia_filter.php');
                break;
            case 'kategori_pengeluaran':
                include('konten/kategori_pengeluaran.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'pengeluaran':
                include('konten/pengeluaran.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'laporan_pengeluaran':
                include('konten/laporan_pengeluaran.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'detail_transaksi':
                include('konten/detail_transaksi.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'rekap_piodalan_sinkron':
                include('konten/rekap_piodalan_sinkron.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'double':
                include('konten/double.php'); // Menyertakan halaman tambah guru.php
                break;
            case 'rekap_tunggakan':
                include('konten/rekap_tunggakan.php');
                break;
            case 'laporan_laba_rugi':
                include('konten/laporan_laba_rugi.php');
                break;
            case 'audit_log':
                include('konten/audit_log.php');
                break;
            default:
                echo "<h2 style: margin:20px;>Halaman Tidak Ditemukan</h2>";
                echo "<p>Menu yang Anda pilih tidak ada.</p>";
                break;
        }
    } else {
        include('konten/home.php'); // Default, menampilkan home.php jika tidak ada parameter menu
    }
    ?>