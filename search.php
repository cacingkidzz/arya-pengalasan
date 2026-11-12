<?php
include 'admin/koneksi.php'; // Koneksi ke database

if (isset($_POST['query'])) {
    $search = '%' . trim((string) $_POST['query']) . '%';

    // Query gabungan semeton dan semeton_baru
    $query = "
        SELECT 'semeton' AS sumber, 
       s.id, 
       s.user_id, 
       s.nama, 
       s.nik, 
       s.email, 
       s.no_hp, 
       s.alamat, 
       w.nama_wilayah, 
       sw.nama_sub 
FROM semeton s
LEFT JOIN wilayah w ON s.wilayah = w.id_wilayah
LEFT JOIN sub_wilayah sw ON s.sub_wilayah = sw.id_sub
WHERE s.nama LIKE ? 

UNION 

SELECT 'semeton_baru' AS sumber, 
       sb.id, 
       NULL AS user_id, 
       sb.nama, 
       sb.nik, 
       sb.email, 
       sb.no_hp, 
       sb.alamat, 
       w.nama_wilayah, 
       sw.nama_sub 
FROM semeton_baru sb
LEFT JOIN wilayah w ON sb.wilayah = w.id_wilayah
LEFT JOIN sub_wilayah sw ON sb.sub_wilayah = sw.id_sub
WHERE sb.nama LIKE ?;
    ";

    $stmt = $koneksi->prepare($query);
    $stmt->bind_param("ss", $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();

    // Data pribadi (email, no HP, alamat) sengaja TIDAK ditampilkan di halaman publik.
    echo '<div class="table-responsive">';
    echo '<table id="datatable" class="table table-bordered">';
    echo '<thead><tr class="table-dark">
                <th>No</th>
                <th>Nama</th>
                <th>Wilayah</th>
                <th>Sub Wilayah</th>
              </tr></thead><tbody>';

    $no = 1;
    while ($row = $result->fetch_assoc()) {
        $nama     = htmlspecialchars((string) $row['nama'], ENT_QUOTES, 'UTF-8');
        $wilayah  = htmlspecialchars((string) ($row['nama_wilayah'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $sub      = htmlspecialchars((string) ($row['nama_sub'] ?? '-'), ENT_QUOTES, 'UTF-8');

        echo "<tr>
                    <td>{$no}</td>
                    <td>{$nama}</td>
                    <td>{$wilayah}</td>
                    <td>{$sub}</td>
                  </tr>";
        $no++;
    }
    echo '</tbody></table>';
    echo '</div>';

    $stmt->close();
}
