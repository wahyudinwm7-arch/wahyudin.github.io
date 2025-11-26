<?php
session_start();
include '../includes/koneksi.php'; 

if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

function formatRupiah($angka) {
    $angka = (float)($angka ?? 0);
    if ($angka < 0) $angka = 0; 
    return "Rp " . number_format($angka, 0, ',', '.');
}

function getNonSppBiaya($conn, $id_siswa, $tahun_ajaran_siswa) {
    $biaya_standar = [];
    $detail_tunggakan_non_spp = []; 
    $total_tunggakan_non_spp = 0;
    $total_kelebihan_bayar_siswa = 0; 

   $query_biaya_non_spp = "
        SELECT jenis_pembayaran, MAX(nominal) as nominal
        FROM set_biaya
        WHERE jenis_pembayaran NOT LIKE 'SPP%'
        AND tahun_ajaran = ?
        GROUP BY jenis_pembayaran
    ";
    
    if ($stmt_set_biaya = $conn->prepare($query_biaya_non_spp)) {
        $stmt_set_biaya->bind_param("s", $tahun_ajaran_siswa);
        $stmt_set_biaya->execute();
        $result_set_biaya = $stmt_set_biaya->get_result();
        while ($row = $result_set_biaya->fetch_assoc()) {
            $biaya_standar[$row['jenis_pembayaran']] = (float)$row['nominal'];
        }
        $stmt_set_biaya->close();
    }

    $query_biaya_khusus = "
        SELECT jenis_pembayaran, nominal_biaya 
        FROM nominal_biaya_siswa 
        WHERE id_siswa = ? AND tahun_ajaran = ?
    ";
    if ($stmt_khusus = $conn->prepare($query_biaya_khusus)) {
        $stmt_khusus->bind_param("is", $id_siswa, $tahun_ajaran_siswa);
        $stmt_khusus->execute();
        $result_khusus = $stmt_khusus->get_result();
        while ($row = $result_khusus->fetch_assoc()) {
            $biaya_standar[$row['jenis_pembayaran']] = (float)$row['nominal_biaya']; 
        }
        $stmt_khusus->close();
    }
    
    if (!isset($biaya_standar['Deposit/Sisa Lebih Bayar'])) {
        $biaya_standar['Deposit/Sisa Lebih Bayar'] = 0;
    }
	foreach ($biaya_standar as $jenis => $nominal_seharusnya) {
        
        $query_masuk = "SELECT SUM(jumlah) AS total_masuk FROM transaksi WHERE id_siswa = ? AND jenis_pembayaran = ? AND jenis_transaksi = 'masuk'";
        
        $total_masuk = 0;
        if ($stmt_masuk = $conn->prepare($query_masuk)) {
            $stmt_masuk->bind_param("is", $id_siswa, $jenis);
            $stmt_masuk->execute();
            $total_masuk = $stmt_masuk->get_result()->fetch_assoc()['total_masuk'] ?? 0;
            $stmt_masuk->close();
        }
        
        $total_keluar = 0;
        if ($jenis === "Deposit/Sisa Lebih Bayar") { 
            $query_keluar = "SELECT SUM(jumlah) AS total_keluar FROM transaksi WHERE id_siswa = ? AND jenis_pembayaran = ? AND jenis_transaksi = 'keluar'";
            
            if ($stmt_keluar = $conn->prepare($query_keluar)) {
                $stmt_keluar->bind_param("is", $id_siswa, $jenis);
                $stmt_keluar->execute();
                $total_keluar = $stmt_keluar->get_result()->fetch_assoc()['total_keluar'] ?? 0;
                $stmt_keluar->close();
            }
        }
        
        $total_dibayar = (float)$total_masuk - (float)$total_keluar;
        
        $sisa_tunggakan = (float)$nominal_seharusnya - $total_dibayar;

        if ($sisa_tunggakan > 0) {
            $total_tunggakan_non_spp += $sisa_tunggakan;
            $detail_tunggakan_non_spp[$jenis] = $sisa_tunggakan;
        } elseif ($sisa_tunggakan < 0) {
            $kelebihan = abs($sisa_tunggakan);
            $total_kelebihan_bayar_siswa += $kelebihan; 
            
            if ($jenis !== "Deposit/Sisa Lebih Bayar") {
                $detail_tunggakan_non_spp[$jenis] = "Lunas (Lebih: " . formatRupiah($kelebihan) . ")";
            } else {
                 $detail_tunggakan_non_spp[$jenis] = "Saldo Deposit: " . formatRupiah($kelebihan);
            }
        } else {
            $detail_tunggakan_non_spp[$jenis] = "Lunas (Pas)";
        }
    }
    
    unset($detail_tunggakan_non_spp['Deposit/Sisa Lebih Bayar']);
    
    return [
        'detail_tunggakan' => $detail_tunggakan_non_spp, 
        'tunggakan_non_spp' => max(0, $total_tunggakan_non_spp),
        'total_kelebihan_bayar' => $total_kelebihan_bayar_siswa 
    ];
}


$bulan_semua = [
    'Juli' => 7, 'Agustus' => 8, 'September' => 9, 'Oktober' => 10, 'November' => 11, 'Desember' => 12,
    'Januari' => 1, 'Februari' => 2, 'Maret' => 3, 'April' => 4, 'Mei' => 5, 'Juni' => 6
];
$bulan_to_num = [
     'Januari' => 1, 'Februari' => 2, 'Maret' => 3, 'April' => 4, 'Mei' => 5, 'Juni' => 6,
     'Juli' => 7, 'Agustus' => 8, 'September' => 9, 'Oktober' => 10, 'November' => 11, 'Desember' => 12
];
$tahun_sekarang = (int)date('Y');
$bulan_sekarang_idx = (int)date('n');


$kelas = null;
$riwayat_transaksi_kelas = [];
$data_total_pembayaran = []; 
$data_tunggakan_siswa = []; 

$grand_total_tunggakan_kelas = 0;
$total_tunggakan_spp_kelas = 0;
$total_tunggakan_non_spp_kelas = 0;
$total_kelebihan_bayar_kelas = 0; 

$rincian_tunggakan_non_spp_kelas = []; 


$query_kelas = "SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas ASC";
$result_kelas = mysqli_query($conn, $query_kelas);

if (isset($_GET['id_kelas']) && !empty($_GET['id_kelas'])) {
    $id_kelas = intval($_GET['id_kelas']);

    $query_kelas_terpilih = "SELECT nama_kelas FROM kelas WHERE id_kelas = ?";
    $stmt_kelas = $conn->prepare($query_kelas_terpilih);
    $stmt_kelas->bind_param("i", $id_kelas);
    $stmt_kelas->execute();
    $result_kelas_terpilih = $stmt_kelas->get_result();
    $kelas = $result_kelas_terpilih->fetch_assoc();
    $stmt_kelas->close();


    if ($kelas) {
        $query_transaksi_detail = "
            SELECT t.tanggal_transaksi, s.nama_lengkap AS nama_siswa, s.nisn, t.jenis_pembayaran, t.jumlah, t.jenis_transaksi, t.deskripsi, u.nama_pengguna AS dicatat_oleh
            FROM transaksi t
            JOIN siswa s ON t.id_siswa = s.id_siswa
            LEFT JOIN pengguna u ON t.dicatat_oleh_id_pengguna = u.id_pengguna
            WHERE s.id_kelas = ? AND t.jenis_transaksi = 'masuk'
            ORDER BY t.tanggal_transaksi DESC, s.nama_lengkap ASC
        ";
        $stmt_detail = $conn->prepare($query_transaksi_detail);
        $stmt_detail->bind_param("i", $id_kelas);
        $stmt_detail->execute();
        $result_transaksi = $stmt_detail->get_result();
        while ($row = $result_transaksi->fetch_assoc()) {
            $riwayat_transaksi_kelas[] = $row;
        }
        $stmt_detail->close();


        $query_transaksi_ringkasan = "
            SELECT t.jenis_pembayaran, SUM(t.jumlah) AS total_dibayar
            FROM transaksi t
            JOIN siswa s ON t.id_siswa = s.id_siswa
            WHERE s.id_kelas = ? AND t.jenis_transaksi = 'masuk'
            GROUP BY t.jenis_pembayaran
            ORDER BY t.jenis_pembayaran ASC
        ";
        $stmt_ringkasan = $conn->prepare($query_transaksi_ringkasan);
        $stmt_ringkasan->bind_param("i", $id_kelas);
        $stmt_ringkasan->execute();
        $result_ringkasan = $stmt_ringkasan->get_result();
        while ($row = $result_ringkasan->fetch_assoc()) {
            $data_total_pembayaran[$row['jenis_pembayaran']] = $row['total_dibayar'];
        }
        $stmt_ringkasan->close();
        
        
        $query_data_siswa = "
            SELECT 
                s.id_siswa, s.nama_lengkap, s.nisn, s.status_spp,
                s.bulan_mulai_spp, s.tahun_mulai_spp, ta.nama_tahun as tahun_ajaran_siswa
            FROM 
                siswa s
            JOIN 
                tahun_ajaran ta ON s.id_tahun_ajaran = ta.id_tahun_ajaran
            WHERE 
                s.id_kelas = ?
            ORDER BY 
                s.nama_lengkap ASC
        ";
        $stmt_siswa = $conn->prepare($query_data_siswa);
        $stmt_siswa->bind_param("i", $id_kelas);
        $stmt_siswa->execute();
        $daftar_siswa_kelas = $stmt_siswa->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_siswa->close();

        
        $id_siswa_list = array_column($daftar_siswa_kelas, 'id_siswa');
        $total_dibayar_per_bulan_semua = []; 
        
        if (!empty($id_siswa_list)) {
            $placeholders = implode(',', array_fill(0, count($id_siswa_list), '?'));
            $types_in = str_repeat('i', count($id_siswa_list));
            
            $query_bayar_spp_all = "SELECT id_siswa, jumlah, deskripsi FROM transaksi WHERE id_siswa IN ($placeholders) AND jenis_pembayaran LIKE 'SPP%' AND jenis_transaksi = 'masuk'"; // Pastikan hanya transaksi masuk
            
            $stmt_spp_all = $conn->prepare($query_bayar_spp_all);
            
            if ($stmt_spp_all) {
                $bind_params = array_merge([$types_in], $id_siswa_list);
                $ref = [];
                foreach ($bind_params as $key => $value) {
                    $ref[$key] = &$bind_params[$key];
                }

                call_user_func_array([$stmt_spp_all, 'bind_param'], $ref); 
                
                $stmt_spp_all->execute();
                $result_bayar_spp_all = $stmt_spp_all->get_result();
                
                while ($row = $result_bayar_spp_all->fetch_assoc()) {
                    $id_siswa = $row['id_siswa'];
                    $jumlah = (float)$row['jumlah']; 
                    
                    if (!isset($total_dibayar_per_bulan_semua[$id_siswa])) {
                        $total_dibayar_per_bulan_semua[$id_siswa] = [];
                    }
                    
                    if (preg_match_all('/(Juli|Agustus|September|Oktober|November|Desember|Januari|Februari|Maret|April|Mei|Juni)\s(\d{4})/', $row['deskripsi'], $matches, PREG_SET_ORDER)) {
                        
                        $jumlah_bulan_dicatat = count($matches);
                        $jumlah_per_bulan = ($jumlah_bulan_dicatat > 0) ? $jumlah / $jumlah_bulan_dicatat : 0; 

                        foreach ($matches as $match) {
                            $bulan = $match[1];
                            $tahun = $match[2];
                            $key = "{$bulan} {$tahun}";
                            
                            $total_dibayar_per_bulan_semua[$id_siswa][$key] = 
                                ($total_dibayar_per_bulan_semua[$id_siswa][$key] ?? 0) + $jumlah_per_bulan;
                        }
                    }
                }
                $stmt_spp_all->close();
            }
        }
        
        foreach ($daftar_siswa_kelas as $siswa) {
            $id_siswa = $siswa['id_siswa'];
            $tahun_ajaran_siswa = $siswa['tahun_ajaran_siswa'];
            
           $tahun_masuk_awal = (int)explode('/', $tahun_ajaran_siswa)[0];
            $tahun_mulai = (int)($siswa['tahun_mulai_spp']);
            $bulan_mulai_idx = (int)($siswa['bulan_mulai_spp']);
            $status_spp = strtolower($siswa['status_spp']); // Gunakan strtolower untuk konsistensi

            $jenis_spp_db = 'SPP';
            
            if (strpos($status_spp, 'diskon') !== false || $status_spp === 'diskon') {
                $keterangan_spp_db = 'Diskon';
            } else {
                $keterangan_spp_db = 'Normal';
            }
           $query_nominal_spp = "SELECT nominal FROM set_biaya WHERE jenis_pembayaran = ? AND keterangan = ? AND tahun_ajaran= ?";
            $stmt_nominal_spp = $conn->prepare($query_nominal_spp);
            
            if ($stmt_nominal_spp) {
                $stmt_nominal_spp->bind_param("sss", $jenis_spp_db, $keterangan_spp_db, $tahun_ajaran_siswa);
                $stmt_nominal_spp->execute();
                $nominal_per_bulan = (float)($stmt_nominal_spp->get_result()->fetch_assoc()['nominal'] ?? 0);
                $stmt_nominal_spp->close();
            } else {
                $nominal_per_bulan = 0;
            }
            
            $total_tunggakan_spp = 0;
            if ($nominal_per_bulan > 0) {
                $total_dibayar_siswa = $total_dibayar_per_bulan_semua[$id_siswa] ?? [];
                
                $tahun_sekarang_plus_satu = $tahun_sekarang + 1;
                
               for ($tahun_loop = $tahun_masuk_awal; $tahun_loop <= $tahun_sekarang_plus_satu; $tahun_loop++) { 
                    foreach ($bulan_semua as $bulan_nama => $bulan_angka) {
                        
                        $tahun_spp = ($bulan_angka >= 7) ? $tahun_loop : $tahun_loop + 1;
                        $bulan_tahun_string = "{$bulan_nama} {$tahun_spp}";
                        
                        $is_before_or_current_month = ($tahun_spp < $tahun_sekarang) || ($tahun_spp == $tahun_sekarang && $bulan_angka <= $bulan_sekarang_idx);
                        
                        $is_after_or_at_start_month = ($tahun_spp > $tahun_mulai) || ($tahun_spp == $tahun_mulai && $bulan_angka >= $bulan_mulai_idx);

                        if (!$is_after_or_at_start_month) {
                            continue;
                        }
                        
                        if ($is_before_or_current_month) {
                            $nominal_dibayar = $total_dibayar_siswa[$bulan_tahun_string] ?? 0;
                            $sisa_tunggakan = $nominal_per_bulan - $nominal_dibayar;
                            
                            if ($sisa_tunggakan > 0) {
                                $total_tunggakan_spp += $sisa_tunggakan;
                            } 
                        }
                    }
                }
            }

            $non_spp_data = getNonSppBiaya($conn, $id_siswa, $tahun_ajaran_siswa);
            $total_tunggakan_non_spp_siswa = $non_spp_data['tunggakan_non_spp']; 
            $detail_non_spp_siswa = $non_spp_data['detail_tunggakan']; 
            $total_kelebihan_bayar_siswa = $non_spp_data['total_kelebihan_bayar']; 

            foreach($detail_non_spp_siswa as $jenis_bayar => $nominal_tunggak_status) {
                if (is_numeric($nominal_tunggak_status)) {
                    $rincian_tunggakan_non_spp_kelas[$jenis_bayar] = 
                        ($rincian_tunggakan_non_spp_kelas[$jenis_bayar] ?? 0) + $nominal_tunggak_status;
                }
            }

            $total_kelebihan_bayar_kelas += $total_kelebihan_bayar_siswa;


            $total_tunggakan_siswa = $total_tunggakan_spp + $total_tunggakan_non_spp_siswa;
            
            $data_tunggakan_siswa[] = [
                 'id_siswa' => $id_siswa,
                 'nama_lengkap' => $siswa['nama_lengkap'],
                 'nisn' => $siswa['nisn'],
                 'tunggakan_spp' => max(0, $total_tunggakan_spp),
                 'tunggakan_non_spp' => max(0, $total_tunggakan_non_spp_siswa),
                 'total_tunggakan' => max(0, $total_tunggakan_siswa),
                 'kelebihan_bayar' => $total_kelebihan_bayar_siswa, // Ini adalah saldo deposit bersih
            ];

            $total_tunggakan_spp_kelas += max(0, $total_tunggakan_spp);
            $total_tunggakan_non_spp_kelas += max(0, $total_tunggakan_non_spp_siswa);
            $grand_total_tunggakan_kelas += max(0, $total_tunggakan_siswa);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Per Kelas | Aplikasi Pembayaran Siswa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #007bff; 
            --secondary-color: #6c757d;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --dark-text: #343a40;
            --light-bg: #f8f9fa;
            --sidebar-bg: #2c3e50; 
        }
        
        body { font-family: 'Poppins', sans-serif; margin: 0; padding: 0; background-color: var(--light-bg); color: var(--dark-text); line-height: 1.6; }
        .main-container { display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background-color: var(--sidebar-bg); color: white; padding: 22px 0; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1); position: fixed; top: 0; left: 0; z-index: 1000; height: 100%; }
        .sidebar-header { text-align: center; padding: 10px 20px 30px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar-header h2 { font-size: 1.2rem; margin: 0; font-weight: 600; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li a { display: flex; align-items: center; padding: 12px 20px; color: #ecf0f1; text-decoration: none; transition: background-color 0.3s, color 0.3s; font-size: 0.95rem; }
        .sidebar-menu li a:hover { background-color: #34495e; color: white; }
        .sidebar-menu li a.active { background-color: var(--primary-color); color: white; border-left: 5px solid #3498db; }
        .sidebar-menu li a i { margin-right: 10px; font-size: 1.1rem; }
        .content-wrapper { flex-grow: 1; margin-left: 250px; padding: 30px; }
        .content-header { margin-bottom: 30px; border-bottom: 2px solid #e9ecef; padding-bottom: 15px; }
        .content-header h2 { font-size: 1.8rem; color: var(--dark-text); margin: 0; font-weight: 600; }
        .container { background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); padding: 25px; margin-bottom: 20px; border: 1px solid #e0e0e0; }
        .container h3 { font-size: 1.4rem; color: var(--dark-text); padding-bottom: 10px; margin-top: 0; margin-bottom: 20px; font-weight: 600; }
        
        .form-filter { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-weight: 600; margin-bottom: 5px; font-size: 0.9rem; color: var(--secondary-color); }
        .form-group select, .form-group input[type="text"] { padding: 10px 15px; border-radius: 6px; border: 1px solid #ced4da; font-size: 1rem; width: 250px; box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.075); background-color: white; }
        .form-group button { padding: 10px 20px; border: none; border-radius: 6px; background-color: var(--primary-color); color: white; font-weight: 600; cursor: pointer; transition: background-color 0.3s; }
        .form-group button:hover { background-color: #0056b3; }

        .data-table, .summary-table { width: 100%; border-collapse: collapse; font-size: 0.95em; margin-top: 15px; }
        .data-table thead th, .summary-table thead th { background-color: var(--primary-color); color: white; text-align: left; padding: 12px 15px; border-bottom: 1px solid #ddd; font-weight: 600; text-transform: uppercase; font-size: 0.85rem; }
        .data-table tbody tr:nth-child(even) { background-color: #f8f9fa; }
        .data-table tbody tr:hover { background-color: #e9ecef; }
        .data-table td, .summary-table td { padding: 10px 15px; border-bottom: 1px solid #eee; vertical-align: top; }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* Gaya untuk tabel ringkasan */
        .summary-table { margin-bottom: 20px; }
        .summary-table tfoot td { font-weight: 700; border-top: 2px solid #ced4da; }
        .tunggakan-total-row td { background-color: #fffaf0 !important; font-weight: 700 !important; }
        .grand-total-keseluruhan-row td { 
            background-color: #e9ecef !important; 
            border-top: 3px solid var(--primary-color) !important; 
            font-weight: 700;
        }
        
        /* Gaya untuk tabel tunggakan siswa */
        .tunggakan-footer td { 
            background-color: #fcece9 !important;
            border-top: 3px solid var(--danger-color);
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--danger-color);
        }
        .tunggakan-footer td:nth-child(5) { /* Target the Sisa Lebih Bayar column in footer */
            color: var(--info-color) !important; 
        }

        /* Gaya untuk ringkasan tunggakan Non-SPP */
        .non-spp-tunggakan-table th {
            background-color: #f7a39d !important; /* Warna merah muda */
            color: white !important;
        }
        .non-spp-tunggakan-table tfoot td {
            background-color: #e74c3c !important;
            color: white !important;
            font-weight: 700;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-graduation-cap"></i> Pembayaran Siswa</h2>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="pembayaran.php"><i class="fas fa-cash-register"></i> Kelola Pembayaran</a></li>
                <li><a href="pengeluaran.php"><i class="fas fa-money-bill-wave"></i> Kelola Pengeluaran</a></li>
                <li><a href="siswa.php"><i class="fas fa-user-graduate"></i> Kelola Data Siswa</a></li>
                <li><a href="kelas.php"><i class="fas fa-school"></i> Kelola Data Kelas</a></li>
                <li><a href="tahun_ajaran.php"><i class="fas fa-calendar-alt"></i> Kelola Tahun Ajaran</a></li>
                <li><a href="kelola_set_biaya.php"><i class="fas fa-cogs"></i> Kelola Biaya Pembayaran</a></li>
                <li><a href="laporan.php"><i class="fas fa-chart-line"></i> Lihat Laporan</a></li>
                <li><a href="laporan_per_kelas.php" class="active"><i class="fas fa-table"></i> Laporan Per Kelas</a></li>
                <li><a href="laporan_tunggakan.php"><i class="fas fa-exclamation-triangle"></i> Laporan Tunggakan</a></li>
				<li><a href="pengguna.php"><i class="fas fa-users"></i> Kelola Pengguna</a></li> 
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>

        <div class="content-wrapper">
            <div class="content-header">
                <h2><i class="fas fa-table"></i> Laporan Keuangan Per Kelas</h2>
            </div>

            <div class="container">
                <h3><i class="fas fa-filter"></i> Filter Kelas</h3>
                <form method="GET" class="form-filter">
                    <div class="form-group">
                        <label for="id_kelas">Pilih Kelas:</label>
                        <select name="id_kelas" id="id_kelas" required>
                            <option value="">-- Pilih Kelas --</option>
                            <?php 
                            if (isset($result_kelas) && method_exists($result_kelas, 'data_seek')) {
                                mysqli_data_seek($result_kelas, 0); // Reset pointer
                                while ($row = mysqli_fetch_assoc($result_kelas)) { 
                            ?>
                                    <option value="<?php echo $row['id_kelas']; ?>" 
                                        <?php echo (isset($id_kelas) && $id_kelas == $row['id_kelas']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($row['nama_kelas']); ?>
                                    </option>
                            <?php 
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit"><i class="fas fa-search"></i> Tampilkan Laporan</button>
                    </div>
                </form>
            </div>

            <?php if (isset($kelas) && $kelas): ?>
            
            <hr>

            <div class="container summary-box">
                <h3><i class="fas fa-chart-bar"></i> Ringkasan Kas Masuk Kelas <?php echo htmlspecialchars($kelas['nama_kelas']); ?></strong></h3>
                
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th style="text-align: left;">Jenis Pembayaran</th>
                            <th class="text-right">Total Kas Masuk (Sudah Dibayar)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_total_kas_masuk = 0;
                        
                        $jenis_pembayaran_kas = [];
                        if (isset($data_total_pembayaran)) {
                            $jenis_pembayaran_kas = array_keys($data_total_pembayaran);
                        }
                        
                        if (isset($rincian_tunggakan_non_spp_kelas)) {
                            $jenis_pembayaran_kas = array_unique(array_merge($jenis_pembayaran_kas, array_keys($rincian_tunggakan_non_spp_kelas)));
                        }

                        usort($jenis_pembayaran_kas, function($a, $b) {
                            if ($a === 'SPP') return -1;
                            if ($b === 'SPP') return 1;
                            return $a <=> $b;
                        });

                        foreach ($jenis_pembayaran_kas as $jenis) {
                            $total = $data_total_pembayaran[$jenis] ?? 0;
                            if ($total > 0 || (isset($rincian_tunggakan_non_spp_kelas) && in_array($jenis, array_keys($rincian_tunggakan_non_spp_kelas))) || $jenis === 'SPP') {
                                $grand_total_kas_masuk += $total;
                                echo "<tr>
                                        <td>" . htmlspecialchars($jenis) . "</td>
                                        <td class='text-right' style='color: var(--success-color); font-weight: 600;'>" . formatRupiah($total) . "</td>
                                    </tr>";
                            }
                        }
                        ?>
                    </tbody>
                    <tfoot>
                            <tr class="grand-total-keseluruhan-row">
                                <td style="text-align: left;"><strong>GRAND TOTAL KAS MASUK KELAS</strong></td>
                                <td class="text-right" style="color: var(--dark-text);">
                                    <strong><?php echo formatRupiah($grand_total_kas_masuk ?? 0); ?></strong>
                                </td>
                            </tr>
                    </tfoot>
                </table>
            </div>

            <div class="container summary-box" style="margin-top: -5px; border-top: none;">
                <h3><i class="fas fa-list-alt"></i> Rincian Total Tunggakan Non-SPP Kelas <?php echo htmlspecialchars($kelas['nama_kelas']); ?></strong></h3>
                <table class="summary-table non-spp-tunggakan-table">
                    <thead>
                        <tr>
                            <th style="text-align: left;">Jenis Pembayaran Non-SPP</th>
                            <th class="text-right">Total Tunggakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (isset($rincian_tunggakan_non_spp_kelas) && !empty($rincian_tunggakan_non_spp_kelas)): ?>
                            <?php 
                            ksort($rincian_tunggakan_non_spp_kelas);
                            foreach ($rincian_tunggakan_non_spp_kelas as $jenis => $total_tunggakan): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($jenis); ?></td>
                                    <td class="text-right" style="color: var(--danger-color); font-weight: 600;">
                                        <?php echo formatRupiah($total_tunggakan); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="2" class="text-center" style="color: var(--success-color); font-weight: 600;">Semua tunggakan Non-SPP telah lunas di semua siswa.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td style="text-align: left;"><strong>TOTAL AKUMULASI TUNGGAKAN NON-SPP KELAS</strong></td>
                            <td class="text-right">
                                <strong><?php echo formatRupiah($total_tunggakan_non_spp_kelas ?? 0); ?></strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="container summary-box" style="margin-top: -5px; border-top: none;">
                <h3><i class="fas fa-exclamation-triangle"></i> Ringkasan Total Keuangan Tunggakan Kelas <?php echo htmlspecialchars($kelas['nama_kelas']); ?></strong></h3>
                <table class="summary-table">
                    <thead>
                        <tr style="background-color: var(--danger-color);">
                            <th style="text-align: left;">Kategori Keuangan</th>
                            <th class="text-right">Jumlah Nominal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="tunggakan-total-row">
                            <td style="text-align: left; background-color: #fffaf0;"><strong>TOTAL TUNGGAKAN SPP KELAS</strong></td>
                            <td class="text-right" style="color: var(--danger-color); font-weight: 700; background-color: #fffaf0;">
                                <strong><?php echo formatRupiah($total_tunggakan_spp_kelas ?? 0); ?></strong>
                            </td>
                        </tr>
                        <tr class="tunggakan-total-row">
                            <td style="text-align: left; background-color: #fffaf0;"><strong>TOTAL TUNGGAKAN NON-SPP KELAS</strong></td>
                            <td class="text-right" style="color: var(--danger-color); font-weight: 700; background-color: #fffaf0;">
                                <strong><?php echo formatRupiah($total_tunggakan_non_spp_kelas ?? 0); ?></strong>
                            </td>
                        </tr>
                            <tr style="background-color: #e6f7ff; font-weight: 700;">
                                <td style="text-align: left; color: var(--info-color);"><strong>TOTAL SISA LEBIH BAYAR (DEPOSIT) KELAS</strong></td>
                                <td class="text-right" style="color: var(--info-color); font-weight: 700;">
                                    <strong><?php echo formatRupiah($total_kelebihan_bayar_kelas ?? 0); ?></strong>
                                </td>
                            </tr>
                    </tbody>
                    <tfoot>
                        <tr style="background-color: #fcece9;">
                            <td style="text-align: left;"><strong>GRAND TOTAL TUNGGAKAN KESELURUHAN KELAS</strong></td>
                            <td class="text-right" style="font-size: 1.2rem; font-weight: 700; color: var(--danger-color);">
                                <strong><?php echo formatRupiah($grand_total_tunggakan_kelas ?? 0); ?></strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>


            <div class="container">
                <h3><i class="fas fa-user-graduate"></i> Rincian Tunggakan Per Siswa Kelas <?php echo htmlspecialchars($kelas['nama_kelas']); ?></strong></h3>
                <a href="export_tunggakan_kelas.php?id_kelas=<?php echo $id_kelas; ?>" 
                   style="margin-bottom: 20px; display: inline-block; padding: 10px 15px; border-radius: 6px; background-color: var(--success-color); color: white; text-decoration: none; font-weight: 600;">
                    <i class="fas fa-file-excel"></i> Export Rincian Tunggakan ke CSV
                </a>
                                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Siswa (NISN)</th>
                            <th class="text-right">Tunggakan SPP</th>
                            <th class="text-right">Tunggakan Non-SPP</th>
                            <th class="text-right">Sisa Lebih Bayar (Deposit)</th>
                            <th class="text-right">TOTAL TUNGGAKAN (Net)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; 
                        if (isset($data_tunggakan_siswa) && is_array($data_tunggakan_siswa)) {
                            foreach ($data_tunggakan_siswa as $data): ?>
                        <tr>
                            <td class="text-center"><?php echo $no++; ?></td>
                            <td><?php echo htmlspecialchars($data['nama_lengkap'] . ' (' . $data['nisn'] . ')'); ?></td>
                            <td class="text-right" style="color: <?php echo (($data['tunggakan_spp'] ?? 0) > 0) ? 'var(--danger-color)' : 'var(--success-color)'; ?>; font-weight: 600;"><?php echo formatRupiah($data['tunggakan_spp'] ?? 0); ?></td>
                            <td class="text-right" style="color: <?php echo (($data['tunggakan_non_spp'] ?? 0) > 0) ? 'var(--danger-color)' : 'var(--success-color)'; ?>; font-weight: 600;"><?php echo formatRupiah($data['tunggakan_non_spp'] ?? 0); ?></td>
                            <td class="text-right" style="color: <?php echo (($data['kelebihan_bayar'] ?? 0) > 0) ? 'var(--info-color)' : 'var(--secondary-color)'; ?>; font-weight: 600;"><?php echo formatRupiah($data['kelebihan_bayar'] ?? 0); ?></td>
                            <td class="text-right" style="color: var(--danger-color); font-weight: 700;"><?php echo formatRupiah($data['total_tunggakan'] ?? 0); ?></td>
                        </tr>
                            <?php endforeach; 
                        } ?>
                    </tbody>
                    <tfoot>
                        <tr class="tunggakan-footer">
                            <td colspan="2" style="text-align: right;"><strong>TOTAL KELAS</strong></td>
                            <td class="text-right"><?php echo formatRupiah($total_tunggakan_spp_kelas ?? 0); ?></td>
                            <td class="text-right"><?php echo formatRupiah($total_tunggakan_non_spp_kelas ?? 0); ?></td>
                            <td class="text-right"><?php echo formatRupiah($total_kelebihan_bayar_kelas ?? 0); ?></td>
                            <td class="text-right"><?php echo formatRupiah($grand_total_tunggakan_kelas ?? 0); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="container">
                <h3><i class="fas fa-file-invoice"></i> Riwayat Transaksi Kas Masuk Detail Kelas <?php echo htmlspecialchars($kelas['nama_kelas']); ?></strong></h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Tgl. Transaksi</th>
                            <th>Nama Siswa (NISN)</th>
                            <th>Jenis Pembayaran</th>
                            <th class="text-right">Jumlah (Kas Masuk)</th>
                            <th>Deskripsi</th>
                            <th>Dicatat Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (isset($riwayat_transaksi_kelas) && !empty($riwayat_transaksi_kelas)): ?>
                            <?php foreach ($riwayat_transaksi_kelas as $transaksi): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($transaksi['tanggal_transaksi']); ?></td>
                                <td><?php echo htmlspecialchars($transaksi['nama_siswa'] . ' (' . $transaksi['nisn'] . ')'); ?></td>
                                <td><?php echo htmlspecialchars($transaksi['jenis_pembayaran']); ?></td>
                                <td class="text-right" style="color: var(--success-color); font-weight: 600;"><?php echo formatRupiah($transaksi['jumlah']); ?></td>
                                <td><?php echo htmlspecialchars($transaksi['deskripsi']); ?></td>
                                <td><?php echo htmlspecialchars($transaksi['dicatat_oleh']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center">Belum ada riwayat transaksi kas masuk untuk kelas ini.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>


            <?php else: ?>
                <div class="container">
                    <p class="text-center" style="font-size: 1.1rem; color: var(--secondary-color);">Silakan pilih kelas dari dropdown di atas untuk menampilkan laporan keuangan dan tunggakan.</p>
                </div>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>