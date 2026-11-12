<?php
include 'admin/koneksi.php'; // Pastikan koneksi database benar

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = trim($_POST['id_semeton']);

    if (empty($user_id)) {
        echo "<div class='alert alert-danger shadow-sm border-0 rounded-3'>
                <i class='fas fa-exclamation-triangle me-2'></i> ID Semeton tidak boleh kosong!
              </div>";
        exit;
    }

    // QUERY UPDATE: Menambahkan s.nama (Nama Semeton) dan Menghitung Total
    $query = "SELECT h.*, o.nama_odalan, o.tanggal, s.nama 
              FROM hutang h
              JOIN semeton s ON h.id_semeton = s.id 
              JOIN odalan o ON h.id_odalan = o.id
              WHERE s.user_id = ?";

    $stmt = $koneksi->prepare($query);
    if (!$stmt) {
        die("<div class='alert alert-danger'>Query Error: " . $koneksi->error . "</div>");
    }

    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Ambil data pertama untuk menampilkan Nama Semeton
        $data_hutang = [];
        $total_tunggakan = 0;
        $nama_semeton = "";

        while ($row = $result->fetch_assoc()) {
            $data_hutang[] = $row;
            $nama_semeton = $row['nama']; // Ambil nama dari hasil join
            if (strtolower($row['status']) != 'lunas') {
                $total_tunggakan += $row['jumlah_hutang'];
            }
        }

        // TAMPILAN HEADER NAMA & TOTAL
        echo "<div class='card border-0 shadow-sm rounded-4 overflow-hidden mb-4'>";
        echo "<div class='card-body bg-light border-bottom'>
                <div class='row align-items-center'>
                    <div class='col-md-7'>
                        <small class='text-muted text-uppercase fw-bold' style='font-size: 0.7rem;'>Nama Semeton</small>
                        <h4 class='fw-bold text-primary mb-0'>" . htmlspecialchars(strtoupper($nama_semeton), ENT_QUOTES, 'UTF-8') . "</h4>
                        <small class='text-muted'>ID: " . htmlspecialchars($user_id, ENT_QUOTES, 'UTF-8') . "</small>
                    </div>
                    <div class='col-md-5 text-md-end mt-3 mt-md-0'>
                        <small class='text-muted text-uppercase fw-bold' style='font-size: 0.7rem;'>Total Belum Dibayar</small>
                        <h4 class='fw-bold text-danger mb-0'>Rp " . number_format($total_tunggakan, 0, ',', '.') . "</h4>
                    </div>
                </div>
              </div>";

        // TAMPILAN TABEL RINCIAN
        echo "<div class='table-responsive'>";
        echo "<table class='table table-hover align-middle mb-0'>";
        echo "<thead class='table-light'>
                <tr>
                    <th class='ps-3'>Piodalan</th>
                    <th>Tanggal</th>
                    <th>Jumlah</th>
                    <th class='text-center'>Status</th>
                </tr>
              </thead>";
        echo "<tbody>";

        foreach ($data_hutang as $row) {
            $status = strtolower($row['status']);
            $badge_class = ($status == 'lunas') ? 'bg-success' : 'bg-danger';
            $status_text = strtoupper($status);

            echo "<tr>";
            echo "<td class='ps-3 fw-semibold'>" . htmlspecialchars($row['nama_odalan']) . "</td>";
            echo "<td><small class='text-muted'>" . date('d M Y', strtotime($row['tanggal'])) . "</small></td>";
            echo "<td class='fw-bold text-dark'>Rp " . number_format($row['jumlah_hutang'], 0, ',', '.') . "</td>";
            echo "<td class='text-center'><span class='badge rounded-pill $badge_class' style='padding: 8px 12px; font-size: 0.75rem;'>$status_text</span></td>";
            echo "</tr>";
        }

        echo "</tbody></table>";
        echo "</div></div>";

        echo "<div class='alert alert-info border-0 shadow-sm rounded-3 small'>
                <i class='fas fa-info-circle me-2'></i> Silakan hubungi pengurus jika terdapat kekeliruan data pembayaran.
              </div>";
    } else {
        echo "<div class='text-center py-5'>
                <i class='fas fa-search-minus fa-3x text-muted opacity-25 mb-3'></i>
                <p class='text-muted'>Tidak ada catatan urunan ditemukan untuk ID: <strong>" . htmlspecialchars($user_id, ENT_QUOTES, 'UTF-8') . "</strong></p>
              </div>";
    }

    $stmt->close();
    $koneksi->close();
}
