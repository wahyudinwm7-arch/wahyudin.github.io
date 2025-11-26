<?php
session_start();
include '../includes/koneksi.php';

// =========================================================================
// --- 1. LOGIKA INI DIGUNAKAN UNTUK MENENTUKAN TANGGAL DEFAULT (1 JULI TA) ---
// =========================================================================

$current_year = date('Y');
$current_month = date('n');

// Jika bulan saat ini (misal Okt/10) >= 7 (Juli), maka Tahun Ajaran dimulai di tahun ini.
if ($current_month >= 7) {
    $tahun_awal = $current_year;
} 
// Jika bulan saat ini (misal Jan/1) < 7 (Juli), maka Tahun Ajaran dimulai di tahun sebelumnya.
else {
    $tahun_awal = $current_year - 1;
}

// Tetapkan tanggal default: 1 Juli tahun ajaran berjalan
$default_tgl_awal = $tahun_awal . '-07-01';
$default_tgl_akhir = date('Y-m-d'); // Tanggal hari ini

// --- 2. TENTUKAN RENTANG TANGGAL DARI INPUT FILTER ATAU DEFAULT ---
$tanggal_awal = isset($_GET['tgl_awal']) && !empty($_GET['tgl_awal']) ? $_GET['tgl_awal'] : $default_tgl_awal;
$tanggal_akhir = isset($_GET['tgl_akhir']) && !empty($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : $default_tgl_akhir;

// =========================================================================
// --- 3. QUERY LAPORAN BERDASARKAN TAHUN AJARAN DAN JENIS BIAYA ---
// =========================================================================
$query_laporan = "
    SELECT
        -- Logika menentukan Tahun Ajaran (Juli-Juni)
        CASE
            WHEN MONTH(tanggal_transaksi) >= 7 THEN 
                CONCAT(YEAR(tanggal_transaksi), '/', YEAR(tanggal_transaksi) + 1)
            ELSE 
                CONCAT(YEAR(tanggal_transaksi) - 1, '/', YEAR(tanggal_transaksi))
        END AS tahun_ajaran,
        jenis_pembayaran,
        COUNT(id_transaksi) AS total_transaksi,
        SUM(jumlah) AS total_nominal
    FROM 
        transaksi
    WHERE 
        DATE(tanggal_transaksi) BETWEEN ? AND ?
    GROUP BY
        tahun_ajaran,
        jenis_pembayaran
    ORDER BY
        tahun_ajaran DESC,
        jenis_pembayaran ASC;
";

$stmt = $conn->prepare($query_laporan);
$stmt->bind_param("ss", $tanggal_awal, $tanggal_akhir);
$stmt->execute();
$result_laporan = $stmt->get_result();
$stmt->close();

$data_laporan = [];
$grand_total = 0;

// Mengelompokkan hasil query ke dalam array PHP untuk tampilan yang terstruktur
while ($row = $result_laporan->fetch_assoc()) {
    $ta = $row['tahun_ajaran'];
    if (!isset($data_laporan[$ta])) {
        $data_laporan[$ta] = [
            'total_ta' => 0,
            'biaya' => []
        ];
    }
    $data_laporan[$ta]['biaya'][] = $row;
    $data_laporan[$ta]['total_ta'] += $row['total_nominal'];
    $grand_total += $row['total_nominal'];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Per Jenis Biaya & Tahun Ajaran</title>
    <style>
        /* Gaya dasar untuk struktur tabel */
        .ta-header { background-color: #f2f2f2; font-weight: bold; }
        .grand-total { background-color: #007bff; color: white; font-weight: bold; }
        .text-right { text-align: right; }
        
        /* Gaya untuk form agar sejajar (jika tidak menggunakan Bootstrap) */
        .row { display: flex; flex-wrap: wrap; }
        .col-md-4, .col-md-3, .col-md-2 { padding: 5px 10px; }
        .d-flex { display: flex !important; }
        .align-items-end { align-items: flex-end !important; }
        .form-control { width: 100%; padding: 5px; box-sizing: border-box; }
        .btn { padding: 5px 10px; }
    </style>
</head>
<body>

<div class="container">
    ## 📈 Laporan Penerimaan Per Tahun Ajaran & Jenis Biaya
    <p>Periode Transaksi: **<?= htmlspecialchars($tanggal_awal) ?>** s.d. **<?= htmlspecialchars($tanggal_akhir) ?>**</p>
    
    <form method="GET" class="mb-4" id="filterForm">
        <div class="row">
            <div class="col-md-4">
                <label for="periode_cepat">Pilih Periode Cepat:</label>
                <select id="periode_cepat" class="form-control">
                    <option value="">-- Pilih Cepat --</option>
                    <option value="today">Hari Ini</option>
                    <option value="this_month">Bulan Ini</option>
                    <option value="last_month">Bulan Lalu</option>
                    <option value="this_year">Tahun Ini (Jan-Des)</option>
                    <option value="academic_year">Tahun Ajaran (Juli-Sekarang)</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label for="tgl_awal">Tanggal Awal:</label>
                <input type="date" name="tgl_awal" id="tgl_awal" class="form-control" value="<?= htmlspecialchars($tanggal_awal) ?>">
            </div>
            
            <div class="col-md-3">
                <label for="tgl_akhir">Tanggal Akhir:</label>
                <input type="date" name="tgl_akhir" id="tgl_akhir" class="form-control" value="<?= htmlspecialchars($tanggal_akhir) ?>">
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">Filter Laporan</button>
            </div>
        </div>
    </form>
    
    <hr>
    
    <table class="table table-bordered table-striped" style="width:100%;">
        <thead class="thead-dark">
            <tr>
                <th width="5%">No.</th>
                <th width="20%">Tahun Ajaran</th>
                <th>Jenis Pembayaran</th>
                <th width="15%">Jumlah Transaksi</th>
                <th width="20%" class="text-right">Total Nominal (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data_laporan as $tahun_ajaran => $data): ?>
                
                <tr class="ta-header">
                    <td colspan="5" class="text-left">**TAHUN AJARAN: <?= htmlspecialchars($tahun_ajaran) ?>**</td>
                </tr>

                <?php $sub_no = 1; ?>
                <?php foreach ($data['biaya'] as $row): ?>
                    <tr>
                        <td><?= $sub_no++ ?></td>
                        <td></td> 
                        <td><?= htmlspecialchars($row['jenis_pembayaran']) ?></td>
                        <td><?= number_format($row['total_transaksi']) ?></td>
                        <td class="text-right">
                            <?= number_format($row['total_nominal'], 0, ',', '.') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <tr class="bg-warning text-dark">
                    <td colspan="3">**SUB-TOTAL T.A. <?= htmlspecialchars($tahun_ajaran) ?>**</td> 
                    <td></td> 
                    <td class="text-right">**<?= number_format($data['total_ta'], 0, ',', '.') ?>**</td>
                </tr>

            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="grand-total">
                <td colspan="4">**GRAND TOTAL KESELURUHAN**</td>
                <td class="text-right">**<?= number_format($grand_total, 0, ',', '.') ?>**</td>
            </tr>
        </tfoot>
    </table>
    
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const selectElement = document.getElementById('periode_cepat');
        const tglAwalInput = document.getElementById('tgl_awal');
        const tglAkhirInput = document.getElementById('tgl_akhir');
        const filterForm = document.getElementById('filterForm');

        // Fungsi utilitas untuk memformat tanggal ke YYYY-MM-DD
        const formatDate = (date) => {
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        };

        selectElement.addEventListener('change', function() {
            const period = this.value;
            if (!period) return;

            const today = new Date();
            let startDate = null;
            let endDate = null;

            switch (period) {
                case 'today':
                    startDate = new Date(today);
                    endDate = new Date(today);
                    break;
                case 'this_month':
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    endDate = new Date(today);
                    break;
                case 'last_month':
                    startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    endDate = new Date(today.getFullYear(), today.getMonth(), 0);
                    break;
                case 'this_year':
                    startDate = new Date(today.getFullYear(), 0, 1); // 1 Januari
                    endDate = new Date(today);
                    break;
                case 'academic_year':
                    // Tahun Ajaran: 1 Juli hingga hari ini
                    const currentMonth = today.getMonth() + 1; // 1-12
                    let academicYearStart = today.getFullYear();

                    if (currentMonth < 7) { // Jika sebelum Juli, TA dimulai tahun sebelumnya
                        academicYearStart -= 1;
                    }
                    startDate = new Date(academicYearStart, 6, 1); // Bulan 6 adalah Juli
                    endDate = new Date(today);
                    break;
            }

            if (startDate && endDate) {
                tglAwalInput.value = formatDate(startDate);
                tglAkhirInput.value = formatDate(endDate);
                
                // Kirim form secara otomatis
                filterForm.submit();
            }
        });
    });
</script>

</body>
</html>
<?php // mysqli_close($conn); ?>