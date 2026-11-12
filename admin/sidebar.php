<?php
/**
 * sidebar.php
 * Sidebar tunggal untuk semua role.
 *
 * Sebelumnya ada tiga file (sidebar1/2/3.php) yang isinya menyimpang:
 * label berbeda untuk halaman yang sama, menu hilang di salah satu role,
 * dan menu bisa tampil walau role tidak berhak membukanya.
 *
 * Sekarang isi menu didefinisikan sekali di sini lalu DIFILTER memakai
 * kbap_allowed_menus($role), sehingga:
 *   - menu yang tampil selalu bisa dibuka (tidak ada "Akses ditolak");
 *   - tidak ada lagi duplikasi definisi antar role.
 *
 * sidebar1.php / sidebar2.php / sidebar3.php tetap ada sebagai pembungkus
 * tipis agar index.php lama dan dokumentasi tetap kompatibel.
 */

require_once __DIR__ . '/security.php';

if (!function_exists('kbap_sidebar_grup')) {
    /**
     * Definisi menu sidebar.
     *
     * Setiap item: menu (kunci di konten.php), label, ikon.
     * Opsional: href (halaman standalone), blank (buka tab baru).
     * Urutan di sini = urutan tampil di sidebar.
     *
     * @return array<int, array{judul:string, ikon:string, items:array<int, array<string, mixed>>}>
     */
    function kbap_sidebar_grup(): array
    {
        return [
            [
                'judul' => 'Data Pokok',
                'ikon'  => 'bi-clipboard-data',
                'items' => [
                    ['menu' => 'user',                 'label' => 'User',                 'ikon' => 'bi-person-badge'],
                    ['menu' => 'wilayah',              'label' => 'Wilayah',              'ikon' => 'bi-geo-alt'],
                    ['menu' => 'sub_wilayah',          'label' => 'Sub Wilayah',          'ikon' => 'bi-geo'],
                    ['menu' => 'piodalan',             'label' => 'Piodalan',             'ikon' => 'bi-calendar-event'],
                    ['menu' => 'kategori_pengeluaran', 'label' => 'Kategori Pengeluaran', 'ikon' => 'bi-tags'],
                ],
            ],
            [
                'judul' => 'Semeton',
                'ikon'  => 'bi-people-fill',
                'items' => [
                    ['menu' => 'semeton',      'label' => 'Data Semeton',        'ikon' => 'bi-person-vcard'],
                    ['menu' => 'semeton_baru', 'label' => 'Usulan Semeton Baru', 'ikon' => 'bi-person-plus'],
                ],
            ],
            [
                'judul' => 'Transaksi',
                'ikon'  => 'bi-cash-stack',
                'items' => [
                    ['menu' => 'pembayaran',  'label' => 'Pembayaran',       'ikon' => 'bi-wallet2'],
                    ['menu' => 'rekap_hari',  'label' => 'Kasir & Tunggakan', 'ikon' => 'bi-calendar-check'],
                    ['menu' => 'pengeluaran', 'label' => 'Pengeluaran',        'ikon' => 'bi-cash-coin'],
                ],
            ],
            [
                'judul' => 'Dana Punia',
                'ikon'  => 'bi-currency-dollar',
                'items' => [
                    ['menu' => 'dana_punia',           'label' => 'Data Dana Punia', 'ikon' => 'bi-journal-text'],
                    ['menu' => 'rekap_punia_hari_ini', 'label' => 'Punia Hari Ini',  'ikon' => 'bi-calendar-day'],
                    ['menu' => 'rekap_punia_filter',   'label' => 'Laporan Punia',   'ikon' => 'bi-file-earmark-bar-graph'],
                ],
            ],
            [
                'judul' => 'Laporan Urunan',
                'ikon'  => 'bi-file-earmark-text',
                'items' => [
                    ['menu' => 'rekap_urunan',         'label' => 'Rekap Urunan Global',  'ikon' => 'bi-table'],
                    ['menu' => 'rekap_bayar_piodalan', 'label' => 'Rekap Bayar Global',   'ikon' => 'bi-table'],
                    ['menu' => 'rekap_filter',         'label' => 'Rekap Urunan/Odalan',  'ikon' => 'bi-funnel'],
                    ['menu' => 'rekap_bayar',          'label' => 'Rekap Bayar/Odalan',   'ikon' => 'bi-receipt'],
                ],
            ],
            [
                'judul' => 'Tunggakan & Cetak',
                'ikon'  => 'bi-printer',
                'items' => [
                    ['menu' => 'rekap_tunggakan',       'label' => 'Rekap Tunggakan',      'ikon' => 'bi-exclamation-triangle'],
                    ['menu' => 'laporan_rekap_semeton', 'label' => 'Tunggakan/Semeton',    'ikon' => 'bi-file-earmark-person',   'href' => 'konten/laporan_rekap_semeton.php', 'blank' => true],
                    ['menu' => 'laporan_rekap_odalan',  'label' => 'Cetak Laporan Odalan', 'ikon' => 'bi-file-earmark-richtext', 'href' => 'konten/laporan_rekap_odalan.php',  'blank' => true],
                ],
            ],
            [
                'judul' => 'Laporan Keuangan',
                'ikon'  => 'bi-graph-up',
                'items' => [
                    ['menu' => 'laporan_pengeluaran', 'label' => 'Rekap Pengeluaran', 'ikon' => 'bi-receipt-cutoff'],
                    ['menu' => 'laporan_laba_rugi',   'label' => 'Laba/Rugi Odalan',  'ikon' => 'bi-bar-chart'],
                ],
            ],
            [
                'judul' => 'Audit Data',
                'ikon'  => 'bi-shield-check',
                'items' => [
                    ['menu' => 'rekap_piodalan_sinkron', 'label' => 'Audit Hutang/Bayar', 'ikon' => 'bi-arrow-left-right'],
                    ['menu' => 'double',                 'label' => 'Double Pembayaran',  'ikon' => 'bi-copy'],
                    ['menu' => 'audit_log',              'label' => 'Jejak Audit',        'ikon' => 'bi-clock-history'],
                ],
            ],
        ];
    }
}

if (!function_exists('kbap_sidebar_render')) {
    /**
     * Cetak sidebar sesuai role yang sedang login.
     * Item yang tidak ada di kbap_allowed_menus() otomatis tidak dirender.
     */
    function kbap_sidebar_render(): void
    {
        $role       = kbap_role();
        $boleh      = kbap_allowed_menus($role);
        $activeMenu = isset($_GET['menu']) && is_string($_GET['menu']) ? $_GET['menu'] : '';

        $esc  = static function ($teks): string {
            return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
        };
        $bisa = static function (string $menu) use ($boleh): bool {
            return in_array($menu, $boleh, true);
        };

        // Hanya grup yang punya minimal satu item berhak yang dirender.
        $grupTampil = [];
        foreach (kbap_sidebar_grup() as $grup) {
            $items = array_values(array_filter(
                $grup['items'],
                static function (array $item) use ($bisa): bool {
                    return $bisa((string) $item['menu']);
                }
            ));
            if (!empty($items)) {
                $grup['items'] = $items;
                $grupTampil[]  = $grup;
            }
        }
        ?>
        <div class="sidebar-wrapper">
            <nav class="mt-2">
                <!--begin::Sidebar Menu-->
                <ul
                    class="nav sidebar-menu flex-column"
                    data-lte-toggle="treeview"
                    role="menu"
                    data-accordion="false">
                    <li class="nav-item">
                        <a href="index.php?menu=home" class="nav-link <?= $activeMenu === 'home' ? 'active' : '' ?>">
                            <i class="nav-icon bi bi-speedometer"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>
                    <?php foreach ($grupTampil as $grup): ?>
                        <?php
                        $menuGrup = array_column($grup['items'], 'menu');
                        $terbuka  = in_array($activeMenu, $menuGrup, true) ? 'menu-open' : '';
                        ?>
                        <li class="nav-item <?= $terbuka ?>">
                            <a href="#" class="nav-link">
                                <i class="nav-icon bi <?= $esc($grup['ikon']) ?>"></i>
                                <p>
                                    <?= $esc($grup['judul']) ?>
                                    <i class="nav-arrow bi bi-chevron-right"></i>
                                </p>
                            </a>
                            <ul class="nav nav-treeview">
                                <?php foreach ($grup['items'] as $item): ?>
                                    <?php
                                    $menu   = (string) $item['menu'];
                                    $label  = (string) $item['label'];
                                    $ikon   = (string) ($item['ikon'] ?? 'bi-circle');
                                    $href   = (string) ($item['href'] ?? ('index.php?menu=' . $menu));
                                    $blank  = !empty($item['blank']);
                                    $aktif  = (!$blank && $activeMenu === $menu) ? 'active' : '';
                                    ?>
                                    <li class="nav-item">
                                        <a href="<?= $esc($href) ?>"
                                           class="nav-link <?= $aktif ?>"
                                           title="<?= $esc($label) ?>"
                                           <?= $blank ? 'target="_blank" rel="noopener"' : '' ?>>
                                            <i class="nav-icon bi <?= $esc($ikon) ?>"></i>
                                            <p><?= $esc($label) ?></p>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endforeach; ?>
                    <li class="nav-item">
                        <a href="logout.php" class="nav-link bg-danger text-white">
                            <i class="nav-icon bi bi-box-arrow-left"></i>
                            <p>LOGOUT</p>
                        </a>
                    </li>
                </ul>
                <!--end::Sidebar Menu-->
            </nav>
        </div>
        <?php
    }
}

kbap_sidebar_render();
