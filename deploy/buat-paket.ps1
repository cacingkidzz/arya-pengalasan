# ============================================================================
# buat-paket.ps1 - Membuat paket ZIP siap unggah ke public_html (hosting cPanel)
#
# CONTOH PEMAKAIAN
#   # 1. Paket dengan kredensial produksi (koneksi.php dibuat otomatis)
#   .\deploy\buat-paket.ps1 -DbUser "namauser_dbuser" -DbPass "rahasia" `
#                           -DbName "namauser_kbap"
#
#   # 2. Paket tanpa kredensial (koneksi.php masih placeholder, diisi di server)
#   .\deploy\buat-paket.ps1
#
#   # 3. Ikutkan berkas nota & kartu yang sudah ada (paket jadi besar)
#   .\deploy\buat-paket.ps1 -DenganMedia
#
#   # 4. Ikutkan alat cek-server.php (hapus lagi setelah dipakai)
#   .\deploy\buat-paket.ps1 -DenganCekServer
#
# HASIL
#   deploy\out\kbap-public_html-YYYYMMDD-HHmm.zip
#   Isi ZIP = isi public_html (aplikasi di root domain).
#
# YANG TIDAK PERNAH IKUT
#   .git, AGENTS.md, error_log, *.log, database/, arsip/, deploy/,
#   admin/koneksi.local.php, admin/uploads/nota/*, admin/kartu_anggota/*,
#   admin/qr_codes/* (kecuali -DenganMedia).
# ============================================================================

#requires -Version 5.1
[CmdletBinding()]
param(
    [string] $DbHost = 'localhost',
    [string] $DbName = '__DB_NAME__',
    [string] $DbUser = '__DB_USER__',
    [string] $DbPass = '__DB_PASS__',
    [string] $Keluaran = '',
    [switch] $DenganMedia,
    [switch] $DenganArsip,
    [switch] $DenganCekServer,
    [switch] $TanpaHtaccess,
    [switch] $TanpaZip
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$stamp = Get-Date -Format 'yyyyMMdd-HHmm'
$staging = Join-Path $env:TEMP ('opencode\kbap-deploy-' + $stamp)

if ($Keluaran -ne '') {
    $outDir = $Keluaran
} else {
    $outDir = Join-Path $PSScriptRoot 'out'
}

function Tulis($teks, $warna = 'Gray') {
    Write-Host $teks -ForegroundColor $warna
}

function EscPhp([string] $nilai) {
    # Template memakai string berkutip ganda: escape backslash, kutip ganda, dan $
    return $nilai.Replace('\', '\\').Replace('"', '\"').Replace('$', '\$')
}

function Lewati([string] $rel) {
    $r = $rel -replace '\\', '/'

    if ($r -match '(^|/)error_log$') { return $true }
    if ($r -match '\.log$') { return $true }
    if ($r -match '^admin/koneksi(\.local|\.rahasia)?\.php$') { return $true }
    if ($r -match '\.gitkeep$') { return $true }
    if ($r -match '^admin/migrasi/.*\.(zip|bak|log)$') { return $true }

    if (-not $DenganMedia) {
        if ($r -match '^admin/uploads/') {
            return ($r -ne 'admin/uploads/.htaccess')
        }
        if ($r -match '^admin/(kartu_anggota|qr_codes)/') { return $true }
    }

    return $false
}

# ---------------------------------------------------------------------------
# 0. Bersihkan & siapkan folder kerja
# ---------------------------------------------------------------------------
if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
New-Item -ItemType Directory -Force -Path $staging | Out-Null
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

Tulis ''
Tulis '=== KBAP - buat paket deploy public_html ===' 'Cyan'
Tulis ("Sumber : " + $root)
Tulis ("Kerja  : " + $staging)
Tulis ''

# ---------------------------------------------------------------------------
# 1. Halaman publik di root (hanya *.php + README)
# ---------------------------------------------------------------------------
Get-ChildItem $root -File -Filter '*.php' | ForEach-Object {
    Copy-Item $_.FullName (Join-Path $staging $_.Name) -Force
}
if (Test-Path (Join-Path $root 'README.md')) {
    Copy-Item (Join-Path $root 'README.md') (Join-Path $staging 'README.md') -Force
}

# ---------------------------------------------------------------------------
# 2. Folder admin/ (rekursif, dengan pengecualian)
# ---------------------------------------------------------------------------
$jumlahLewat = 0
Get-ChildItem (Join-Path $root 'admin') -Recurse -File -Force | ForEach-Object {
    $rel = $_.FullName.Substring($root.Length + 1)
    if (Lewati $rel) {
        $script:jumlahLewat++
        return
    }
    $tujuan = Join-Path $staging $rel
    New-Item -ItemType Directory -Force -Path (Split-Path $tujuan) | Out-Null
    Copy-Item $_.FullName $tujuan -Force
}

# ---------------------------------------------------------------------------
# 3. Arsip lama (opsional, sudah dilindungi .htaccess di dalamnya)
# ---------------------------------------------------------------------------
if ($DenganArsip -and (Test-Path (Join-Path $root 'arsip'))) {
    Copy-Item (Join-Path $root 'arsip') (Join-Path $staging 'arsip') -Recurse -Force
}

# ---------------------------------------------------------------------------
# 4. Folder media: pastikan ada + tidak bisa dieksekusi/diindeks
# ---------------------------------------------------------------------------
foreach ($rel in @('admin/uploads', 'admin/uploads/nota', 'admin/kartu_anggota', 'admin/qr_codes')) {
    $dir = Join-Path $staging $rel
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    $index = Join-Path $dir 'index.html'
    if (-not (Test-Path $index)) {
        [IO.File]::WriteAllText($index, '', (New-Object System.Text.UTF8Encoding($false)))
    }
}

# ---------------------------------------------------------------------------
# 5. admin/koneksi.php dari template hosting
# ---------------------------------------------------------------------------
$tplPath = Join-Path $PSScriptRoot 'koneksi.public_html.php'
if (-not (Test-Path $tplPath)) {
    throw "Template koneksi tidak ditemukan: $tplPath"
}
$tpl = [IO.File]::ReadAllText($tplPath)
$tpl = $tpl.Replace('__DB_HOST__', (EscPhp $DbHost))
$tpl = $tpl.Replace('__DB_USER__', (EscPhp $DbUser))
$tpl = $tpl.Replace('__DB_PASS__', (EscPhp $DbPass))
$tpl = $tpl.Replace('__DB_NAME__', (EscPhp $DbName))
[IO.File]::WriteAllText(
    (Join-Path $staging 'admin/koneksi.php'),
    $tpl,
    (New-Object System.Text.UTF8Encoding($false))
)

$kredensialKosong = ($DbUser -eq '__DB_USER__' -or $DbName -eq '__DB_NAME__')

# ---------------------------------------------------------------------------
# 6. .htaccess root
# ---------------------------------------------------------------------------
if (-not $TanpaHtaccess) {
    $htPath = Join-Path $PSScriptRoot 'public_html.htaccess'
    if (-not (Test-Path $htPath)) {
        throw "Template .htaccess tidak ditemukan: $htPath"
    }
    Copy-Item $htPath (Join-Path $staging '.htaccess') -Force
}

# ---------------------------------------------------------------------------
# 7. Alat cek server (opsional)
# ---------------------------------------------------------------------------
if ($DenganCekServer) {
    Copy-Item (Join-Path $PSScriptRoot 'cek-server.php') (Join-Path $staging 'cek-server.php') -Force
}

# ---------------------------------------------------------------------------
# 8. Ringkasan isi paket
# ---------------------------------------------------------------------------
$semuaFile = Get-ChildItem $staging -Recurse -File -Force
$totalByte = ($semuaFile | Measure-Object -Property Length -Sum).Sum
$totalMB = [math]::Round($totalByte / 1MB, 2)

Tulis '--- Ringkasan paket ---'
Tulis ("Berkas disalin   : " + $semuaFile.Count)
Tulis ("Berkas dilewati  : " + $jumlahLewat)
Tulis ("Ukuran total     : " + $totalMB + " MB")
Tulis ("admin/koneksi.php: " + $(if ($kredensialKosong) { 'PLACEHOLDER - isi kredensial di server' } else { 'kredensial terisi' })) `
    $(if ($kredensialKosong) { 'Yellow' } else { 'Green' })
Tulis ("Media (nota/kartu/QR): " + $(if ($DenganMedia) { 'IKUT (paket besar)' } else { 'tidak ikut (buat ulang di server)' })) `
    $(if ($DenganMedia) { 'Yellow' } else { 'Gray' })
Tulis ''

Tulis '--- 5 berkas terbesar ---'
$semuaFile | Sort-Object Length -Descending | Select-Object -First 5 | ForEach-Object {
    Tulis ("  {0,8:N2} MB  {1}" -f ($_.Length / 1MB), $_.FullName.Substring($staging.Length + 1))
}
Tulis ''

if ($kredensialKosong) {
    Tulis 'PERHATIAN: kredensial DB belum diisi. Setelah unggah, edit admin/koneksi.php' 'Yellow'
    Tulis '           atau jalankan ulang skrip ini dengan -DbUser/-DbPass/-DbName.' 'Yellow'
    Tulis ''
}

# ---------------------------------------------------------------------------
# 9. Buat ZIP (termasuk berkas tersembunyi seperti .htaccess)
# ---------------------------------------------------------------------------
if (-not $TanpaZip) {
    Add-Type -AssemblyName System.IO.Compression | Out-Null
    Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null

    $zipPath = Join-Path $outDir ('kbap-public_html-' + $stamp + '.zip')
    if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

    $zip = [IO.Compression.ZipFile]::Open($zipPath, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($f in $semuaFile) {
            $rel = $f.FullName.Substring($staging.Length + 1).Replace('\', '/')
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zip, $f.FullName, $rel,
                [IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally {
        $zip.Dispose()
    }

    $zipMB = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
    Tulis ("ZIP siap : " + $zipPath) 'Green'
    Tulis ("Ukuran   : " + $zipMB + " MB")
    Tulis ''
}

# ---------------------------------------------------------------------------
# 10. Petunjuk singkat
# ---------------------------------------------------------------------------
Tulis '--- Langkah unggah ---' 'Cyan'
Tulis '1. cPanel > File Manager > masuk public_html.'
Tulis '2. Upload ZIP, klik kanan > Extract. Hapus ZIP setelah selesai.'
Tulis '   (Bila memakai subfolder, mis. public_html/kbap, extract ke folder itu.)'
Tulis '3. Pastikan .htaccess ikut terekstrak (File Manager: Settings > Show Hidden Files).'
Tulis '4. Impor database lewat phpMyAdmin (dump utf8mb4), lihat database/README.md.'
Tulis '5. Buka https://domain-anda/admin/login.php lalu ganti semua password.'
Tulis '6. Bila perlu: unggah deploy/cek-server.php, jalankan, lalu HAPUS.'
Tulis ''
Tulis ("Folder kerja: " + $staging) 'DarkGray'
