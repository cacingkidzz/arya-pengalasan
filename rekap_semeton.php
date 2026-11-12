<?php
include 'admin/koneksi.php'; // Pastikan koneksi ke database sudah benar
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Semeton | Arya Pengalasan Pusat</title>
    <link rel="icon" type="image/png" href="admin/dist/assets/img/bg-logo.png">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --primary-color: #1e3c72;
            --secondary-color: #2a5298;
        }

        body {
            background-color: #f8fafc;
            font-family: 'Inter', sans-serif;
            color: #334155;
        }

        .header-rekap {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 50px 0 90px 0;
            border-radius: 0 0 30px 30px;
        }

        .rekap-card {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            padding: 25px;
            margin-top: -60px;
        }

        .back-btn {
            color: white;
            text-decoration: none;
            font-size: 0.9rem;
            opacity: 0.8;
            transition: 0.3s;
        }

        .back-btn:hover {
            opacity: 1;
            color: white;
        }

        .table thead th {
            background-color: #f1f5f9;
            color: #475569;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            border: none;
            padding: 15px;
        }

        .wilayah-row {
            background-color: #f8fafc;
            font-weight: 700;
            color: var(--primary-color);
        }

        .sub-wilayah-text {
            padding-left: 20px;
            color: #64748b;
        }

        .total-badge {
            background-color: #e0f2fe;
            color: #0369a1;
            padding: 5px 12px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.85rem;
        }

        .total-wilayah-row {
            background-color: #1e3c72 !important;
            color: white !important;
            font-weight: 600;
        }

        .footer-grand-total {
            background-color: #dcfce7 !important;
            color: #15803d !important;
            font-size: 1.1rem;
        }
    </style>
</head>

<body>
    <header class="header-rekap text-center">
        <div class="container px-4">
            <a href="index.php" class="back-btn mb-3 d-inline-block"><i class="fas fa-arrow-left me-2"></i>Kembali ke Dashboard</a>
            <h2 class="fw-bold text-uppercase">Rekapitulasi Semeton</h2>
            <p class="opacity-75 small mb-0">Ringkasan jumlah persebaran Semeton Arya Pengalasan Pusat per Wilayah.</p>
        </div>
    </header>

    <main class="container px-4 pb-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="rekap-card">
                    <?php
                    $query = "
                        SELECT w.nama_wilayah, s.nama_sub, COUNT(sm.id) AS total_semeton
                        FROM (
                            SELECT 'semeton' AS sumber, id, wilayah, sub_wilayah FROM semeton
                            UNION ALL
                            SELECT 'semeton_baru' AS sumber, id, wilayah, sub_wilayah FROM semeton_baru
                        ) sm
                        LEFT JOIN wilayah w ON sm.wilayah = w.id_wilayah
                        LEFT JOIN sub_wilayah s ON sm.sub_wilayah = s.id_sub
                        GROUP BY w.nama_wilayah, s.nama_sub WITH ROLLUP
                    ";

                    $result = $koneksi->query($query);
                    if (!$result) {
                        die("Query Error: " . $koneksi->error);
                    }

                    $total_keseluruhan = 0;
                    $data = [];
                    $rowspan_data = [];

                    while ($row = $result->fetch_assoc()) {
                        $data[] = $row;
                        if ($row['nama_sub'] != NULL && $row['nama_wilayah'] != NULL) {
                            if (!isset($rowspan_data[$row['nama_wilayah']])) {
                                $rowspan_data[$row['nama_wilayah']] = 0;
                            }
                            $rowspan_data[$row['nama_wilayah']]++;
                        }
                    }
                    ?>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th width="40%">Wilayah</th>
                                    <th width="40%">Sub Wilayah</th>
                                    <th width="20%" class="text-center">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $last_wilayah = "";
                                foreach ($data as $row) {
                                    // Melewati baris terakhir rollup total keseluruhan untuk dihandle di tfoot
                                    if ($row['nama_wilayah'] == NULL) {
                                        $total_keseluruhan = $row['total_semeton'];
                                        continue;
                                    }

                                    echo "<tr>";

                                    // Kolom Wilayah dengan Grouping
                                    if ($last_wilayah != $row['nama_wilayah']) {
                                        $span = isset($rowspan_data[$row['nama_wilayah']]) ? $rowspan_data[$row['nama_wilayah']] + 1 : 1;
                                        echo "<td rowspan='$span' class='wilayah-row border-end'>" .
                                            "<i class='fas fa-map-marker-alt me-2 text-primary'></i>" .
                                            strtoupper($row['nama_wilayah']) . "</td>";
                                        $last_wilayah = $row['nama_wilayah'];
                                    }

                                    // Baris Sub-Wilayah atau Total Per Wilayah
                                    if ($row['nama_sub'] == NULL) {
                                        echo "<td colspan='2' class='total-wilayah-row text-center'>" .
                                            "TOTAL DI " . strtoupper($row['nama_wilayah']) . " : " . $row['total_semeton'] . " Semeton</td>";
                                    } else {
                                        echo "<td class='sub-wilayah-text'>" . $row['nama_sub'] . "</td>";
                                        echo "<td class='text-center'><span class='total-badge'>" . $row['total_semeton'] . "</span></td>";
                                    }
                                    echo "</tr>";
                                }
                                ?>
                            </tbody>
                            <tfoot>
                                <tr class="footer-grand-total">
                                    <td colspan="2" class="text-center fw-bold py-3 text-uppercase">Total Keseluruhan Semeton</td>
                                    <td class="text-center fw-bold py-3"><?php echo $total_keseluruhan; ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="mt-4 p-3 bg-white rounded-3 shadow-sm border-start border-4 border-info">
                    <small class="text-muted d-block">
                        <i class="fas fa-info-circle me-1 text-info"></i>
                        Data rekapitulasi ini mencakup gabungan dari data Semeton lama dan data pendaftaran Semeton baru.
                    </small>
                </div>

                <footer class="text-center mt-5">
                    <p class="text-muted small">&copy; <?php echo date('Y'); ?> Arya Pengalasan Pusat Bedulu</p>
                </footer>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>