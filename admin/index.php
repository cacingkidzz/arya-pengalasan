<?php
require_once __DIR__ . '/security.php';
kbap_guard(); // wajib login
include "koneksi.php";
date_default_timezone_set('Asia/Makassar');
?>
<!doctype html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>PDAP | Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?= htmlspecialchars(kbap_csrf_token(), ENT_QUOTES, 'UTF-8') ?>" />
    <meta name="title" content="AdminLTE v4 | Dashboard" />
    <meta name="author" content="ColorlibHQ" />
    <meta name="description" content="AdminLTE is a Free Bootstrap 5 Admin Dashboard." />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.1/styles/overlayscrollbars.min.css" integrity="sha256-tZHrRjVqNSRyWg2wbppGnT833E/Ys0DHWGwT04GiqQg=" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha256-9kPW/n5nn53j4WMRYAxe9c1rCY96Oogo/MKSVdKzPmI=" crossorigin="anonymous" />
    <link rel="stylesheet" href="dist/css/adminlte.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css" integrity="sha256-4MX+61mt9NVvvuPjUWdUdyfZfxSB1/Rf9WtqRHgG5S0=" crossorigin="anonymous" />

    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css" integrity="sha256-+uGLJmmTKOqBr+2E6KDYs/NRsHxSkONXFHUL0fy2O/4=" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        /* Desain Minimalis & Clean */
        :root {
            --primary-light: #f8f9fa;
            --border-soft: #eaedf1;
            --text-dark: #2c3e50;
            --accent-blue: #3498db;
        }

        body {
            font-family: 'Source Sans 3', system-ui, -apple-system, sans-serif;
            background-color: #f4f6f9;
            color: var(--text-dark);
        }

        /* Navbar Customization */
        .app-header {
            background-color: #ffffff !important;
            border-bottom: 1px solid var(--border-soft);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
        }

        .navbar-nav .nav-link {
            font-weight: 500;
        }

        .badge {
            font-weight: 500;
            letter-spacing: 0.5px;
            padding: 0.4em 0.8em;
            border-radius: 6px;
        }

        /* Sidebar Customization */
        .app-sidebar {
            background-color: #ffffff !important;
            border-right: 1px solid var(--border-soft);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.02) !important;
        }

        .sidebar-brand {
            border-bottom: 1px solid var(--border-soft);
        }

        .brand-text {
            color: var(--text-dark);
            font-weight: 600 !important;
        }

        /* DataTables Customization */
        .dataTables_wrapper {
            padding: 10px 0;
        }

        .dataTables_filter {
            margin-bottom: 20px;
            margin-top: 10px;
        }

        /* Input Search Minimalis berbentuk Pill */
        .dataTables_filter input {
            padding: 8px 15px;
            width: 350px !important;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            outline: none;
            transition: all 0.3s ease;
            background-color: #f8f9fa;
        }

        .dataTables_filter input:focus {
            background-color: #ffffff;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        @media (max-width: 768px) {
            .dataTables_filter input {
                width: 100% !important;
            }
        }

        /* Perbaikan Pagination DataTables agar Rapi & Minimalis */
        .dataTables_wrapper .dataTables_paginate {
            padding-top: 15px !important;
            display: flex !important;
            justify-content: flex-end !important;
            gap: 2px;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border: 1px solid var(--border-soft) !important;
            background: #fff !important;
            color: #666 !important;
            border-radius: 6px !important;
            padding: 6px 12px !important;
            margin: 0 2px !important;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: var(--accent-blue) !important;
            color: white !important;
            border-color: var(--accent-blue) !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #f0f0f0 !important;
            color: var(--accent-blue) !important;
            border-color: #ccc !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Merapikan Info (Showing x of x entries) */
        .dataTables_info {
            padding-top: 15px !important;
            font-size: 0.875rem;
            color: #6c757d;
        }

        /* Memperbaiki Search Bar agar tidak menempel ke tabel */
        .dataTables_filter {
            margin-bottom: 20px !important;
        }

        .sticky-col {
            position: sticky;
            left: 0;
            background: white;
            z-index: 2;
            border-right: 1px solid #dee2e6;
        }

        /* Overlay Loading Minimalis */
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(5px);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .spinner {
            width: 45px;
            height: 45px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--accent-blue);
            border-radius: 50%;
            animation: spin 1s cubic-bezier(0.55, 0.15, 0.45, 0.85) infinite;
        }

        .loading-text {
            margin-top: 15px;
            color: var(--text-dark);
            font-weight: 500;
            letter-spacing: 1px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /*
         * Perbaikan modal "scrollable" yang memakai pola <form> membungkus
         * .modal-header / .modal-body / .modal-footer (mis. modal Tambah & Edit
         * pada Usulan Semeton Baru). Bootstrap mengharapkan .modal-body sebagai
         * anak langsung .modal-content; kalau ada <form> di antaranya, form
         * menjadi flex item biasa sehingga .modal-body tidak bisa mengecil dan
         * isinya terpotong (tidak bisa digulir). Aturan ini membuat form menjadi
         * kolom flex penuh sehingga .modal-body kembali bisa digulir.
         */
        .modal-dialog-scrollable .modal-content > form {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
        }
    </style>
</head>

<body class="layout-fixed sidebar-expand-lg">

    <div id="loading-overlay">
        <div class="spinner"></div>
        <div class="loading-text">Memuat Data...</div>
    </div>

    <div class="app-wrapper">
        <nav class="app-header navbar navbar-expand">
            <div class="container-fluid">
                <ul class="navbar-nav align-items-center">
                    <li class="nav-item">
                        <a class="nav-link text-dark" data-lte-toggle="sidebar" href="#" role="button">
                            <i class="bi bi-list fs-4"></i>
                        </a>
                    </li>
                    <li class="nav-item d-none d-md-flex align-items-center ms-2">
                        <a href="#" class="nav-link text-dark fw-bold">
                            <i class="bi bi-person-circle text-secondary me-1"></i> <?= $_SESSION['nama'] ?>
                        </a>
                        <span class="mx-2 text-muted">|</span>
                        <?php if ($_SESSION['akses'] == 'admin') { ?>
                            <div class="badge bg-danger-subtle text-danger border border-danger-subtle">Admin</div>
                        <?php } elseif ($_SESSION['akses'] == 'bendahara') { ?>
                            <div class="badge bg-success-subtle text-success border border-success-subtle">Bendahara</div>
                        <?php } else { ?>
                            <div class="badge bg-info-subtle text-info border border-info-subtle">Korwil</div>
                        <?php } ?>
                    </li>
                </ul>
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item d-none d-lg-block me-4">
                        <span class="text-muted small fw-medium">
                            <i class="bi bi-calendar3 me-1"></i> <span id="tanggal-hari-ini"></span>
                        </span>
                    </li>
                    <li class="nav-item dropdown user-menu">
                        <a href="#" class="nav-link text-dark">
                            <i class="bi bi-diagram-3 text-primary me-1"></i>
                            <span class="d-none d-md-inline fw-medium">KELUARGA BESAR ARYA PENGALASAN PUSAT BEDULU</span>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
        <aside class="app-sidebar shadow-sm" data-bs-theme="light">
            <div class="sidebar-brand">
                <a href="./index.html" class="brand-link text-decoration-none">
                    <img src="dist/assets/img/bg-logo.png" alt="Logo" class="brand-image opacity-75 shadow-sm" />
                    <span class="brand-text fw-light">PusatData<b>AP</b></span>
                </a>
            </div>
            <?php
            if ($_SESSION['akses'] == 'admin') {
                include "sidebar1.php";
            } elseif ($_SESSION['akses'] == 'bendahara') {
                include "sidebar2.php";
            } else {
                include "sidebar3.php";
            }
            ?>
        </aside>
        <main class="app-main" id="content" style="display: none;">
            <?php include "konten.php" ?>
        </main>
        <footer class="app-footer bg-white border-top">
            <div class="float-end d-none d-sm-inline text-muted">
                <i class="far fa-envelope"></i> cacing.kidzz@gmail.com
            </div>
            <strong class="text-dark">
                Copyright &copy; 2025 <a href="#" class="text-decoration-none text-primary">Cacing Kidzz</a>.
            </strong>
            <span class="text-muted">All rights reserved.</span>
        </footer>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.1/browser/overlayscrollbars.browser.es6.min.js" integrity="sha256-dghWARbRe2eLlIJ56wNB+b760ywulqK3DzZYEpsg2fQ=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" integrity="sha384-0pUGZvbkm6XF6gxjEnlmuGrJXVbNuzT9qBBavbLwCsOGabYfZo0T0to5eqruptLy" crossorigin="anonymous"></script>
    <script src="dist/js/adminlte.js"></script>

    <script>
        const SELECTOR_SIDEBAR_WRAPPER = '.sidebar-wrapper';
        const Default = {
            scrollbarTheme: 'os-theme-dark',
            /* Ubah ke dark agar kontras dengan sidebar terang */
            scrollbarAutoHide: 'leave',
            scrollbarClickScroll: true,
        };
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarWrapper = document.querySelector(SELECTOR_SIDEBAR_WRAPPER);
            if (sidebarWrapper && typeof OverlayScrollbarsGlobal?.OverlayScrollbars !== 'undefined') {
                OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
                    scrollbars: {
                        theme: Default.scrollbarTheme,
                        autoHide: Default.scrollbarAutoHide,
                        clickScroll: Default.scrollbarClickScroll,
                    },
                });
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" integrity="sha256-ipiJrswvAR4VAx/th+6zWsdeYmVae0iJuiR+6OqHJHQ=" crossorigin="anonymous"></script>
    <script>
        const connectedSortables = document.querySelectorAll('.connectedSortable');
        connectedSortables.forEach((connectedSortable) => {
            let sortable = new Sortable(connectedSortable, {
                group: 'shared',
                handle: '.card-header',
            });
        });

        const cardHeaders = document.querySelectorAll('.connectedSortable .card-header');
        cardHeaders.forEach((cardHeader) => {
            cardHeader.style.cursor = 'move';
        });
    </script>

    <!-- <script src="https://rawgit.com/schmich/instascan-builds/master/instascan.min.js"></script> -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.min.js" integrity="sha256-+vh8GkaU7C9/wbSLIcwq82tQ2wTf44aOHA8HlBMwRI8=" crossorigin="anonymous"></script>

    <script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/js/jsvectormap.min.js" integrity="sha256-/t1nN2956BT869E6H4V1dnt0X5pAQHPytli+1nTZm2Y=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/maps/world.js" integrity="sha256-XPpPaZlU8S/HWf7FZLAncLg2SAkP8ScUTII89x9D3lY=" crossorigin="anonymous"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.4/html5-qrcode.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

    <script>
        $(document).ready(function() {
            let judulTabel = $("#judul-tabel").text().trim();

            // Tabel Utama (Tanpa Pagination - Scroll Only)
            // Hanya diinisialisasi bila tabelnya benar-benar ada di halaman ini
            // (sebagian halaman memakai pagination server-side tanpa DataTables).
            let table = $('#example').length ? $('#example').DataTable({
                "scrollY": "620px",
                "scrollCollapse": true,
                "paging": false,
                "responsive": true,
                "dom": '<"d-flex justify-content-between align-items-center mb-3"Bf>rt', // Merapikan posisi Button & Search
                "buttons": [{
                        extend: 'excelHtml5',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm rounded-2 shadow-sm'
                    },
                    {
                        extend: 'pdfHtml5',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-danger btn-sm rounded-2 shadow-sm',
                        orientation: 'landscape'
                    }
                ],
                "language": {
                    search: "_INPUT_",
                    searchPlaceholder: "Cari data..."
                }
            }) : null;

            // Tabel Kedua (Dengan Pagination)
            let table1 = $('#example1').length ? $('#example1').DataTable({
                "responsive": true,
                "pageLength": 10,
                "dom": '<"top"f>rt<"d-flex justify-content-between align-items-center"ip>', // Info di kiri, Paginate di kanan
                "language": {
                    search: "_INPUT_",
                    searchPlaceholder: "Cari data...",
                    info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
                    paginate: {
                        previous: "<i class='bi bi-chevron-left'></i>",
                        next: "<i class='bi bi-chevron-right'></i>"
                    }
                }
            }) : null;

            // PENTING: Perbaikan agar kolom tidak berantakan saat loading selesai.
            // Ekstensi Responsive belum tentu dimuat, jadi `.responsive` dicek dulu
            // (sebelumnya baris ini melempar TypeError di semua halaman ber-#example).
            setTimeout(function() {
                if (table) {
                    table.columns.adjust();
                    if (table.responsive) {
                        table.responsive.recalc();
                    }
                }
                if (table1) {
                    table1.columns.adjust();
                    if (table1.responsive) {
                        table1.responsive.recalc();
                    }
                }
            }, 1200);
        });
    </script>

    <?php if (isset($_GET['status']) && $_GET['status'] == 'success') : ?>
        <script>
            Swal.fire({
                title: "Sukses!",
                text: "<?= $_GET['generated'] ?> QR Code berhasil dibuat!",
                icon: "success",
                confirmButtonText: "OK"
            });
        </script>
    <?php endif; ?>
    <?php if (isset($_GET['status']) && $_GET['status'] == 'sukses'): ?>
        <script>
            Swal.fire({
                title: 'Berhasil!',
                text: 'Data berhasil disimpan.',
                icon: 'success',
                confirmButtonText: 'OK'
            });
        </script>
    <?php elseif (isset($_GET['status']) && $_GET['status'] == 'edit'): ?>
        <script>
            Swal.fire({
                title: 'Berhasil!',
                text: 'Data berhasil di Edit.',
                icon: 'info',
                confirmButtonText: 'OK'
            });
        </script>
    <?php elseif (isset($_GET['status']) && $_GET['status'] == 'pembayaran'): ?>
        <script>
            Swal.fire({
                title: 'Berhasil!',
                text: 'Pembayaran berhasil.',
                icon: 'info',
                confirmButtonText: 'OK'
            });
        </script>
    <?php elseif (isset($_GET['status']) && $_GET['status'] == 'gagal'): ?>
        <script>
            Swal.fire({
                title: 'Error!',
                text: 'Gagal menyimpan Data.',
                icon: 'info',
                confirmButtonText: 'OK'
            });
        </script>
    <?php elseif (isset($_GET['status']) && $_GET['status'] == 'kartu'): ?>
        <?php
        $kartu_created = isset($_GET['created']) && is_scalar($_GET['created']) ? (int) $_GET['created'] : 0;
        $kartu_updated = isset($_GET['updated']) && is_scalar($_GET['updated']) ? (int) $_GET['updated'] : 0;
        $kartu_gagal   = isset($_GET['gagal']) && is_scalar($_GET['gagal']) ? (int) $_GET['gagal'] : 0;
        $kartu_gagal_id = isset($_GET['gagal_id']) && is_scalar($_GET['gagal_id'])
            ? htmlspecialchars((string) $_GET['gagal_id'], ENT_QUOTES, 'UTF-8')
            : '';
        ?>
        <script>
            Swal.fire({
                title: <?= $kartu_gagal > 0 ? '"Selesai dengan Catatan"' : '"Berhasil!"' ?>,
                html: <?= json_encode(
                    $kartu_created . ' kartu dibuat, ' . $kartu_updated . ' diperbarui.'
                    . ($kartu_gagal > 0
                        ? '<br><br><span class="text-danger">' . $kartu_gagal . ' kartu GAGAL dibuat'
                          . ($kartu_gagal_id !== '' ? ' (' . $kartu_gagal_id . ')' : '') . '.</span>'
                        : ''),
                    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                ) ?>,
                icon: <?= $kartu_gagal > 0 ? '"warning"' : '"success"' ?>,
                confirmButtonText: 'OK'
            });
        </script>
    <?php elseif (isset($_GET['status']) && $_GET['status'] == 'data_invalid'): ?>
        <script>
            Swal.fire({
                title: "Data Tidak Valid!",
                html: "Periksa kembali isian berikut:<br><span class='text-start d-inline-block mt-2'>" +
                    "&bull; Nama wajib diisi (maks. 255 karakter)<br>" +
                    "&bull; NIK dan No. KK harus 16 digit angka (boleh dikosongkan)<br>" +
                    "&bull; Email harus format yang benar<br>" +
                    "&bull; Tanggal lahir harus tanggal yang nyata<br>" +
                    "&bull; Wilayah dan sub wilayah harus dipilih dan saling cocok</span>",
                icon: "error",
                confirmButtonText: 'OK'
            });
        </script>
    <?php elseif (isset($_GET['status']) && $_GET['status'] == 'nik_terdaftar'): ?>
        <script>
            Swal.fire({
                title: "Gagal!",
                text: "NIK Sudah terdaftar!",
                icon: "error",
                confirmButtonText: 'OK'
            });
        </script>
    <?php elseif (isset($_GET['status']) && $_GET['status'] == 'hapus'): ?>
        <script>
            Swal.fire({
                title: "Berhasil!",
                text: "Data Kamu Sudah Terhapus",
                icon: "success",
                confirmButtonText: 'OK'
            });
        </script>
    <?php elseif (isset($_GET['status']) && $_GET['status'] == 'dipakai'): ?>
        <script>
            Swal.fire({
                title: "Tidak Bisa Dihapus!",
                text: "Data ini masih dipakai oleh data lain (anggota / pembayaran / punia / pengeluaran). Pindahkan atau hapus data terkait terlebih dahulu.",
                icon: "warning",
                confirmButtonText: 'OK'
            });
        </script>
    <?php endif; ?>

    <script>
        function getSubWilayah(idWilayah) {
            $.ajax({
                type: "POST",
                url: "aksi/get_sub_wilayah.php",
                data: {
                    id_wilayah: idWilayah
                },
                success: function(response) {
                    $("#sub_wilayah").html(response);
                }
            });
        }
    </script>
    <script>
        function subWilayah(id, idWilayah) {
            $.ajax({
                url: 'aksi/get_sub_wilayah1.php',
                type: 'POST',
                data: {
                    id_wilayah: idWilayah
                },
                success: function(response) {
                    $('#sub_wilayah1' + id).html(response);
                }
            });
        }
    </script>

    <script>
        window.onload = function() {
            const loadingOverlay = document.getElementById('loading-overlay');
            const content = document.getElementById('content');

            // Beri sedikit transisi halus saat overlay hilang
            loadingOverlay.style.transition = "opacity 0.5s ease";
            loadingOverlay.style.opacity = "0";

            setTimeout(() => {
                loadingOverlay.style.display = 'none';
                content.style.display = 'block'; // Konten yang sudah disembunyikan sekarang tampil
            }, 500);
        };
    </script>

    <script>
        const today = new Date();
        const options = {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        };
        const tanggalIndo = today.toLocaleDateString('id-ID', options);
        document.getElementById("tanggal-hari-ini").innerText = tanggalIndo;
    </script>

    <script>
        // ===== Proteksi CSRF global untuk semua form & tautan aksi =====
        window.KBAP_CSRF = document.querySelector('meta[name="csrf-token"]')
            ? document.querySelector('meta[name="csrf-token"]').content
            : '';

        function kbapInjectCsrf(root) {
            (root || document).querySelectorAll('form').forEach(function (form) {
                var method = (form.getAttribute('method') || 'get').toLowerCase();
                if (method === 'post' && !form.querySelector('input[name="csrf_token"]')) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'csrf_token';
                    input.value = window.KBAP_CSRF;
                    form.appendChild(input);
                }
            });
        }

        function kbapUrl(url) {
            if (!url || !window.KBAP_CSRF) return url;
            return url + (url.indexOf('?') === -1 ? '?' : '&') + 'csrf_token=' + encodeURIComponent(window.KBAP_CSRF);
        }

        document.addEventListener('DOMContentLoaded', function () {
            kbapInjectCsrf(document);
            // Form yang dimuat belakangan (modal/AJAX) tetap mendapat token.
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    m.addedNodes.forEach(function (node) {
                        if (node.nodeType === 1) kbapInjectCsrf(node);
                    });
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        });
    </script>
</body>

</html>