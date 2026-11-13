<#
.SYNOPSIS
    Melokalkan aset CDN ke admin/dist/vendor agar aplikasi bisa berjalan tanpa internet.

.DESCRIPTION
    Skrip ini mengunduh setiap aset pihak ketiga yang sebelumnya dimuat dari
    jsdelivr / cdnjs / cdn.datatables.net / code.jquery.com / unpkg /
    fonts.googleapis.com, menyimpannya di admin/dist/vendor, lalu mengubah
    tautan di file PHP terkait menjadi path lokal.

    Dijalankan sekali (2026-11-13). Aman dijalankan ulang: berkas yang sudah
    ada ditimpa dengan versi baru dari CDN, lalu tautan ditulis ulang.

.PARAMETER HanyaUnduh
    Hanya mengunduh aset, tidak mengubah file PHP.

.PARAMETER HanyaTulis
    Hanya menulis ulang tautan di file PHP (aset dianggap sudah ada).
#>
[CmdletBinding()]
param(
    [switch]$HanyaUnduh,
    [switch]$HanyaTulis
)

$ErrorActionPreference = 'Stop'

$Root   = Split-Path -Parent $PSScriptRoot
$Vendor = Join-Path $Root 'admin\dist\vendor'

# remote URL -> path relatif terhadap admin/dist/vendor
$Aset = [ordered]@{
    # Bootstrap
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'                 = 'bootstrap/5.3.3/bootstrap.min.css'
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js'                   = 'bootstrap/5.3.3/bootstrap.min.js'
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'            = 'bootstrap/5.3.3/bootstrap.bundle.min.js'
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'                 = 'bootstrap/5.3.0/bootstrap.min.css'
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'            = 'bootstrap/5.3.0/bootstrap.bundle.min.js'

    # jQuery & Popper
    'https://code.jquery.com/jquery-3.6.0.min.js'                                             = 'jquery/jquery-3.6.0.min.js'
    'https://code.jquery.com/jquery-3.7.1.min.js'                                             = 'jquery/jquery-3.7.1.min.js'
    'https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js'               = 'popper/popper.min.js'

    # Ikon & font
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css'        = 'bootstrap-icons/bootstrap-icons.min.css'
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css'               = 'font-awesome/css/all.min.css'
    'https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css'                 = 'fontsource/source-sans-3/index.css'
    'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap'            = 'google-fonts/inter.css'

    # SweetAlert2
    'https://cdn.jsdelivr.net/npm/sweetalert2@11'                                             = 'sweetalert2/sweetalert2.all.min.js'

    # DataTables + tombol ekspor
    'https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css'                     = 'datatables/dataTables.bootstrap5.min.css'
    'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css'                         = 'datatables/jquery.dataTables.min.css'
    'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js'                           = 'datatables/jquery.dataTables.min.js'
    'https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js'                       = 'datatables/dataTables.bootstrap5.min.js'
    'https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js'                   = 'datatables/buttons/dataTables.buttons.min.js'
    'https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js'                   = 'datatables/buttons/buttons.bootstrap5.min.js'
    'https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js'                        = 'datatables/buttons/buttons.html5.min.js'
    'https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js'                        = 'datatables/buttons/buttons.print.min.js'
    'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js'                        = 'jszip/jszip.min.js'
    'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js'                    = 'pdfmake/pdfmake.min.js'
    'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js'                      = 'pdfmake/vfs_fonts.js'

    # Select2
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'                = 'select2/select2.min.css'
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'                  = 'select2/select2.min.js'
    'https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css' = 'select2/select2-bootstrap-5-theme.min.css'

    # Aset template AdminLTE
    'https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.1/styles/overlayscrollbars.min.css'  = 'overlayscrollbars/overlayscrollbars.min.css'
    'https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.1/browser/overlayscrollbars.browser.es6.min.js' = 'overlayscrollbars/overlayscrollbars.browser.es6.min.js'
    'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js'                          = 'sortablejs/Sortable.min.js'
    'https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css'                      = 'apexcharts/apexcharts.css'
    'https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.min.js'                   = 'apexcharts/apexcharts.min.js'
    'https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css'             = 'jsvectormap/jsvectormap.min.css'
    'https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/js/jsvectormap.min.js'               = 'jsvectormap/jsvectormap.min.js'
    'https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/maps/world.js'                       = 'jsvectormap/world.js'

    # Pemindai QR
    'https://unpkg.com/html5-qrcode@2.3.4/html5-qrcode.min.js'                                = 'html5-qrcode/html5-qrcode.min.js'
}

# Berkas PHP yang tautan CDN-nya ditulis ulang.
$BerkasPhp = @(
    'admin\index.php'
    'admin\login.php'
    'admin\security.php'
    'admin\cetak_punia.php'
    'admin\lihat_kartu.php'
    'admin\konten\cetak_bayar.php'
    'admin\konten\laporan_rekap_semeton.php'
    'index.php'
    'cek_hutang.php'
    'punia.php'
    'rekap_semeton.php'
    'search_semeton.php'
    'cari_semeton.php'
    'edit_semeton.php'
    'form_tambah_semeton.php'
)

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'

function Unduh($Url, $Tujuan) {
    $folder = Split-Path -Parent $Tujuan
    if (-not (Test-Path $folder)) { New-Item -ItemType Directory -Force -Path $folder | Out-Null }
    Invoke-WebRequest -Uri $Url -OutFile $Tujuan -UseBasicParsing -TimeoutSec 90 -Headers @{ 'User-Agent' = $UA }
}

function Baca-Teks($Path) { [IO.File]::ReadAllText($Path) }
function Tulis-Teks($Path, $Isi) {
    [IO.File]::WriteAllText($Path, $Isi, (New-Object System.Text.UTF8Encoding($false)))
}

# ---------------------------------------------------------------------------
# 1. Unduh aset utama
# ---------------------------------------------------------------------------
if (-not $HanyaTulis) {
    Write-Host "=== 1. Mengunduh aset ke $Vendor ==="
    $gagal = @()
    foreach ($kv in $Aset.GetEnumerator()) {
        $tujuan = Join-Path $Vendor ($kv.Value -replace '/', '\')
        try {
            Unduh $kv.Key $tujuan
            Write-Host ("  OK   {0,-58} {1,10:N0} B" -f $kv.Value, (Get-Item $tujuan).Length)
        } catch {
            $gagal += "$($kv.Key) :: $($_.Exception.Message)"
            Write-Host ("  GAGAL {0}" -f $kv.Value)
        }
    }

    # -----------------------------------------------------------------------
    # 2. Unduh berkas yang dirujuk CSS (font/webfont) + rapikan url()
    # -----------------------------------------------------------------------
    Write-Host "=== 2. Menyusul berkas rujukan CSS (font/webfont) ==="
    $cssPerlu = @(
        @{ Lokal = 'font-awesome/css/all.min.css';         Remote = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css' }
        @{ Lokal = 'bootstrap-icons/bootstrap-icons.min.css'; Remote = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css' }
        @{ Lokal = 'fontsource/source-sans-3/index.css';   Remote = 'https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css' }
        @{ Lokal = 'google-fonts/inter.css';               Remote = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap' }
    )
    $polaUrl = [regex]'url\(\s*[''"]?([^''")]+)[''"]?\s*\)'
    foreach ($c in $cssPerlu) {
        $path = Join-Path $Vendor ($c.Lokal -replace '/', '\')
        if (-not (Test-Path $path)) { Write-Host "  LEWATI (CSS belum ada): $($c.Lokal)"; continue }
        $teks = Baca-Teks $path
        $folderCss = Split-Path -Parent $path
        $jumlah = 0
        foreach ($m in $polaUrl.Matches($teks)) {
            $ref = $m.Groups[1].Value
            if ($ref -match '^(data:|#)') { continue }
            $refBersih = ($ref -split '\?')[0]
            if ($ref -match '^https?://') {
                $nama = Split-Path -Leaf $refBersih
                $tujuan = Join-Path $folderCss ('files\' + $nama)
                try {
                    Unduh $ref $tujuan
                    $teks = $teks.Replace($ref, ('files/' + $nama))
                    $jumlah++
                } catch { Write-Host "  GAGAL font: $ref" }
            } else {
                $abs = [Uri]::new([Uri]$c.Remote, $refBersih).AbsoluteUri
                $tujuan = [IO.Path]::GetFullPath((Join-Path $folderCss ($refBersih -replace '/', '\')))
                try {
                    Unduh $abs $tujuan
                    if ($ref -ne $refBersih) { $teks = $teks.Replace($ref, $refBersih) }
                    $jumlah++
                } catch { Write-Host "  GAGAL rujukan: $abs" }
            }
        }
        Tulis-Teks $path $teks
        Write-Host ("  {0,-46} {1} berkas rujukan" -f $c.Lokal, $jumlah)
    }

    if ($gagal.Count -gt 0) {
        Write-Host "=== GAGAL ($($gagal.Count)) ==="
        $gagal | ForEach-Object { Write-Host "  $_" }
        exit 1
    }
}

# ---------------------------------------------------------------------------
# 3. Tulis ulang tautan di file PHP
# ---------------------------------------------------------------------------
if (-not $HanyaUnduh) {
    Write-Host "=== 3. Menulis ulang tautan di file PHP ==="
    foreach ($relatif in $BerkasPhp) {
        $path = Join-Path $Root $relatif
        if (-not (Test-Path $path)) { Write-Host "  LEWATI (tidak ada): $relatif"; continue }

        $folder = Split-Path -Parent $path
        # Path relatif folder PHP -> admin/dist/vendor (kompatibel .NET Framework)
        $baseUri   = [Uri](($folder.TrimEnd('\')) + '\')
        $vendorUri = [Uri](($Vendor.TrimEnd('\')) + '\')
        $prefix = [Uri]::UnescapeDataString($baseUri.MakeRelativeUri($vendorUri).ToString()).TrimEnd('/')
        $teks = Baca-Teks $path
        $asli = $teks
        $ganti = 0

        foreach ($kv in $Aset.GetEnumerator()) {
            if ($teks.Contains($kv.Key)) {
                $teks = $teks.Replace($kv.Key, ($prefix + '/' + $kv.Value))
                $ganti++
            }
        }

        # Atribut SRI tidak berlaku lagi untuk berkas lokal.
        $teks = [regex]::Replace($teks, '\s+integrity="[^"]*"', '')
        $teks = [regex]::Replace($teks, '\s+crossorigin="(anonymous|use-credentials)"', '')

        if ($teks -ne $asli) {
            Tulis-Teks $path $teks
            Write-Host ("  {0,-48} {1} tautan" -f $relatif, $ganti)
        }
    }
}

Write-Host "Selesai."
