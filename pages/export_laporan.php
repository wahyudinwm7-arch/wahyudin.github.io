<?php
session_start();
include '../includes/koneksi.php';

if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

// Inisialisasi filter dari GET request
$tanggal_awal = isset($_GET['tanggal_awal']) ? $_GET['tanggal_awal'] : '';
$tanggal_akhir = isset($_GET['tanggal_akhir']) ? $_GET['tanggal_akhir'] : '';
$search_nama_siswa = isset($_GET['nama_siswa']) ? $_GET['nama_siswa'] : '';

// -------------------------------------------------------------------------
// 1. QUERY UNTUK MENGAMBIL DATA TRANSAKSI
// Query diperluas untuk JOIN dengan tabel siswa dan kelas
// -------------------------------------------------------------------------
$query_transaksi = "
    SELECT
        t.tanggal_transaksi,
        t.id_transaksi,
        s.nama_lengkap AS nama_siswa,
        s.nisn,
        k.nama_kelas, -- <<< KOLOM NAMA KELAS BARU DITAMBAHKAN
        t.jenis_pembayaran,
        t.jumlah,
        t.jenis_transaksi,
        t.deskripsi,
        u.nama_pengguna AS dicatat_oleh
    FROM
        transaksi t
    LEFT JOIN
        siswa s ON t.id_siswa = s.id_siswa
    LEFT JOIN
        kelas k ON s.id_kelas = k.id_kelas -- <<< JOIN KE TABEL KELAS
    LEFT JOIN
        pengguna u ON t.dicatat_oleh_id_pengguna = u.id_pengguna
";

$where_clauses = [];
$params = [];
$types = '';

// Filter Tanggal Awal
if (!empty($tanggal_awal)) {
    $where_clauses[] = "t.tanggal_transaksi >= ?";
    $params[] = $tanggal_awal;
    $types .= 's';
}

// Filter Tanggal Akhir
if (!empty($tanggal_akhir)) {
    $where_clauses[] = "t.tanggal_transaksi <= ?";
    $params[] = $tanggal_akhir;
    $types .= 's';
}

// Filter Nama Siswa (Hanya untuk transaksi 'masuk' yang terkait siswa)
if (!empty($search_nama_siswa)) {
    $where_clauses[] = "(s.nama_lengkap LIKE ? AND t.jenis_transaksi = 'masuk')"; 
    $params[] = '%' . $search_nama_siswa . '%';
    $types .= 's';
}

// Gabungkan semua klausa WHERE
if (!empty($where_clauses)) {
    $query_transaksi .= " WHERE " . implode(" AND ", $where_clauses);
}

// Tambahkan urutan
$query_transaksi .= " ORDER BY t.tanggal_transaksi DESC, t.id_transaksi DESC";

$stmt = $conn->prepare($query_transaksi);
$riwayat_transaksi = [];
$total_pemasukan = 0;
$total_pengeluaran = 0;

if ($stmt) {
    if (!empty($params)) {
        $bind_names[] = $types;
        for ($i=0; $i<count($params); $i++) {
            $bind_names[] = &$params[$i];
        }
        call_user_func_array(array($stmt,'bind_param'), $bind_names);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $riwayat_transaksi[] = $row;
        $jumlah = (float)$row['jumlah'];
        if ($row['jenis_transaksi'] == 'masuk') {
            $total_pemasukan += $jumlah;
        } elseif ($row['jenis_transaksi'] == 'keluar') {
            $total_pengeluaran += $jumlah;
        }
    }
    $stmt->close();
}

$saldo_akhir = $total_pemasukan - $total_pengeluaran;

// -------------------------------------------------------------------------
// 2. EXPORT KE FORMAT EXCEL
// -------------------------------------------------------------------------

// Header untuk file Excel
header("Content-type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Laporan_Keuangan_" . date('Ymd_His') . ".xls");

// Fungsi format rupiah
function formatRupiah($angka) {
    return "Rp " . number_format($angka, 0, ',', '.');
}

?>
<html>
<head>
    <title>Laporan Keuangan</title>
    <style>
        .num {
            mso-number-format: "General";
        }
        .text {
            mso-number-format: "\@";
        }
        .rupiah {
            mso-number-format: "\@";
        }
    </style>
</head>
<body>

    <h2>Laporan Keuangan</h2>
    <p>Periode: <?php echo date('d/m/Y', strtotime($tanggal_awal)) . ' sampai ' . date('d/m/Y', strtotime($tanggal_akhir)); ?></p>
    
    <table border="1">
        <thead>
            <tr>
                <th>No</th>
                <th>Tanggal</th>
                <th>Nama Siswa</th>
                <th>NISN</th>
                <th>Kelas</th> <!-- <<< HEADER KOLOM BARU -->
                <th>Jenis Pembayaran</th>
                <th>Jumlah</th>
                <th>Jenis Transaksi</th>
                <th>Deskripsi</th>
                <th>Dicatat Oleh</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($riwayat_transaksi)): ?>
                <?php $no = 1; ?>
                <?php foreach ($riwayat_transaksi as $transaksi): ?>
                    <tr>
                        <td class="num"><?php echo $no++; ?></td>
                        <td class="text"><?php echo date('d/m/Y', strtotime($transaksi['tanggal_transaksi'])); ?></td>
                        <td class="text">
                            <?php 
                                // Nama siswa hanya ditampilkan jika transaksinya 'masuk'
                                echo ($transaksi['jenis_transaksi'] == 'masuk' && !empty($transaksi['nama_siswa'])) ? $transaksi['nama_siswa'] : '-';
                            ?>
                        </td>
                        <td class="text"><?php echo !empty($transaksi['nisn']) ? $transaksi['nisn'] : '-'; ?></td>
                        <td class="text">
                            <?php 
                                // Nama kelas hanya ditampilkan jika transaksinya 'masuk' dan ada nama kelas
                                echo ($transaksi['jenis_transaksi'] == 'masuk' && !empty($transaksi['nama_kelas'])) ? $transaksi['nama_kelas'] : '-';
                            ?>
                        </td> <!-- <<< DATA KOLOM BARU -->
                        <td class="text"><?php echo $transaksi['jenis_pembayaran']; ?></td>
                        <td class="rupiah">
                            <?php 
                                // Tampilkan jumlah dengan tanda +/- dan format angka tanpa simbol 'Rp' di kolom ini
                                $sign = ($transaksi['jenis_transaksi'] == 'masuk') ? '' : '-';
                                echo $sign . number_format((float)$transaksi['jumlah'], 0, ',', '.');
                            ?>
                        </td>
                        <td class="text"><?php echo ucfirst($transaksi['jenis_transaksi']); ?></td>
                        <td class="text"><?php echo $transaksi['deskripsi']; ?></td>
                        <td class="text"><?php echo $transaksi['dicatat_oleh']; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" style="text-align: center;">Tidak ada data transaksi yang ditemukan.</td>
                </tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" style="text-align: right;"><strong>TOTAL PEMASUKAN:</strong></td>
                <td class="rupiah"><strong><?php echo number_format($total_pemasukan, 0, ',', '.'); ?></strong></td>
                <td colspan="3"></td>
            </tr>
            <tr>
                <td colspan="6" style="text-align: right;"><strong>TOTAL PENGELUARAN:</strong></td>
                <td class="rupiah"><strong><?php echo number_format($total_pengeluaran, 0, ',', '.'); ?></strong></td>
                <td colspan="3"></td>
            </tr>
            <tr>
                <td colspan="6" style="text-align: right;"><strong>SALDO AKHIR:</strong></td>
                <td class="rupiah"><strong><?php echo number_format($saldo_akhir, 0, ',', '.'); ?></strong></td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>

</body>
</html>
<?php mysqli_close($conn); ?>