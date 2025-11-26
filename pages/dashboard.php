<?php
session_start();
// Pastikan path ke file koneksi.php sudah benar
include '../includes/koneksi.php';

// Cek apakah pengguna sudah login
if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php"); // Pastikan path ke login benar
    exit();
}

// Set zona waktu agar tanggal harian akurat
date_default_timezone_set('Asia/Jakarta');

// --- 1. Fungsi Helper ---
function formatRupiah($angka) {
    // Pastikan input adalah numerik, jika null/kosong, anggap 0
    $angka = (float)($angka ?? 0);
    return "Rp " . number_format($angka, 0, ',', '.');
}

// Tentukan Tanggal Hari Ini (untuk filter transaksi)
$tanggal_hari_ini = date('Y-m-d'); // Format YYYY-MM-DD
$display_tanggal_hari = date('d M Y');

// --- 2. Ambil Data Statistik Utama (Global) ---
$query_masuk = "SELECT SUM(jumlah) AS total_masuk FROM transaksi WHERE jenis_transaksi = 'masuk'";
$result_masuk = mysqli_query($conn, $query_masuk);
$data_masuk = mysqli_fetch_assoc($result_masuk);
$total_masuk = $data_masuk['total_masuk'] ?: 0;

$query_keluar = "SELECT SUM(jumlah) AS total_keluar FROM transaksi WHERE jenis_transaksi = 'keluar'";
$result_keluar = mysqli_query($conn, $query_keluar);
$data_keluar = mysqli_fetch_assoc($result_keluar);
$total_keluar = $data_keluar['total_keluar'] ?: 0;

$saldo_akhir = $total_masuk - $total_keluar;

// Ambil jumlah siswa
$query_siswa = "SELECT COUNT(*) AS total_siswa FROM siswa";
$result_siswa = mysqli_query($conn, $query_siswa);
$data_siswa = mysqli_fetch_assoc($result_siswa);
$total_siswa = $data_siswa['total_siswa'] ?? 0;

// Ambil jumlah kelas
$query_kelas = "SELECT COUNT(*) AS total_kelas FROM kelas";
$result_kelas = mysqli_query($conn, $query_kelas);
$data_kelas = mysqli_fetch_assoc($result_kelas);
$total_kelas = $data_kelas['total_kelas'] ?? 0;

// Ambil tahun ajaran aktif
$query_tahun_aktif = "SELECT nama_tahun FROM tahun_ajaran WHERE aktif = 1";
$result_tahun_aktif = mysqli_query($conn, $query_tahun_aktif);
$data_tahun_aktif = mysqli_fetch_assoc($result_tahun_aktif);
$tahun_aktif = $data_tahun_aktif['nama_tahun'] ?? 'Tidak Ada';


// --- 3. LOGIKA LAPORAN PEMASUKAN HARIAN BERDASARKAN JENIS SET_BIAYA ---

// 1. Ambil semua Jenis Pembayaran dari set_biaya (Master List) dan juga kategori 'Lain-Lain' dari transaksi
$master_jenis = [];
$master_jenis_query = "SELECT DISTINCT jenis_pembayaran FROM set_biaya 
                             UNION 
                             SELECT DISTINCT jenis_pembayaran FROM transaksi WHERE jenis_transaksi = 'masuk'
                             ORDER BY jenis_pembayaran ASC";

$master_jenis_result = mysqli_query($conn, $master_jenis_query);

while ($row = mysqli_fetch_assoc($master_jenis_result)) {
    // Hanya masukkan yang bukan placeholder pengeluaran, karena kita hanya ingin master list Pemasukan
    $master_jenis[$row['jenis_pembayaran']] = [
        'total_pemasukan' => 0,
        'jenis_pembayaran' => $row['jenis_pembayaran']
    ];
}

// 2. Ambil Transaksi Pemasukan Hari Ini
$query_pemasukan_harian = "
    SELECT 
        jenis_pembayaran,
        SUM(jumlah) AS total_pemasukan
    FROM 
        transaksi
    WHERE 
        DATE(tanggal_transaksi) = ? AND jenis_transaksi = 'masuk'
    GROUP BY 
        jenis_pembayaran
";
$stmt_pemasukan = $conn->prepare($query_pemasukan_harian);
$total_pemasukan_display = 0;

if ($stmt_pemasukan) {
    $stmt_pemasukan->bind_param("s", $tanggal_hari_ini);
    $stmt_pemasukan->execute();
    $result_pemasukan = $stmt_pemasukan->get_result();

    // 3. Gabungkan Transaksi Harian ke dalam Master List
    while ($transaksi = $result_pemasukan->fetch_assoc()) {
        $jenis = $transaksi['jenis_pembayaran'];
        $total = $transaksi['total_pemasukan'];

        if (isset($master_jenis[$jenis])) {
            $master_jenis[$jenis]['total_pemasukan'] = $total;
        } else {
            // Jika ada jenis pembayaran 'masuk' yang tidak terdaftar di set_biaya, tambahkan
            $master_jenis[$jenis] = [
                'total_pemasukan' => $total,
                'jenis_pembayaran' => $jenis
            ];
        }
        $total_pemasukan_display += $total;
    }
    $stmt_pemasukan->close();
}


// Query untuk mendapatkan total pengeluaran harian
$query_pengeluaran_harian = "
    SELECT 
        SUM(jumlah) AS total_keluar
    FROM 
        transaksi
    WHERE 
        DATE(tanggal_transaksi) = ? AND jenis_transaksi = 'keluar'
";
$stmt_pengeluaran = $conn->prepare($query_pengeluaran_harian);
$total_keluar_harian = 0;
if ($stmt_pengeluaran) {
    $stmt_pengeluaran->bind_param("s", $tanggal_hari_ini);
    $stmt_pengeluaran->execute();
    $result_pengeluaran = $stmt_pengeluaran->get_result();
    $data_pengeluaran_harian = $result_pengeluaran->fetch_assoc();
    $total_keluar_harian = $data_pengeluaran_harian['total_keluar'] ?: 0;
    $stmt_pengeluaran->close();
}

$saldo_harian_bersih = $total_pemasukan_display - $total_keluar_harian;


// --- 4. Ambil Detail Pengeluaran Harian (Tambahan untuk menampilkan detail) ---
$query_detail_pengeluaran = "
    SELECT 
        deskripsi, 
        jumlah, 
        jenis_transaksi 
    FROM 
        transaksi 
    WHERE 
        DATE(tanggal_transaksi) = ? AND jenis_transaksi = 'keluar'
    ORDER BY 
        tanggal_transaksi DESC
";
$stmt_detail_pengeluaran = $conn->prepare($query_detail_pengeluaran);
$detail_pengeluaran_harian = [];

if ($stmt_detail_pengeluaran) {
    $stmt_detail_pengeluaran->bind_param("s", $tanggal_hari_ini);
    $stmt_detail_pengeluaran->execute();
    $result_detail_pengeluaran = $stmt_detail_pengeluaran->get_result();

    while ($detail = $result_detail_pengeluaran->fetch_assoc()) {
        $detail_pengeluaran_harian[] = $detail;
    }
    $stmt_detail_pengeluaran->close();
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistem Pembayaran Sekolah</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ====================================================================== */
        /* Gaya Dasar & Layout (Konsisten) */
        /* ====================================================================== */
        :root {
            --primary-color: #007bff; /* Biru Cerah */
            --secondary-color: #6c757d;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #3498db;
            --dark-text: #343a40;
            --light-bg: #f8f9fa;
            --sidebar-bg: #2c3e50; /* Darker, modern blue-gray */
            --sidebar-hover: #34495e;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--light-bg);
            color: var(--dark-text);
            line-height: 1.6;
        }

        .main-container {
            display: flex;
            min-height: 100vh;
        }

        /* ------------------- Sidebar ------------------- */
        .sidebar {
            width: 250px;
            background-color: var(--sidebar-bg);
            color: white;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
        }

        .sidebar-header {
            text-align: center;
            padding: 10px 20px 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h2 {
            font-size: 1.2rem;
            margin: 0;
            font-weight: 600;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li a {
            /* Gaya Seragam untuk Semua Menu Item */
            display: flex; /* Aktifkan flexbox */
            align-items: center; /* Sejajarkan ikon dan teks di tengah vertikal */
            padding: 12px 20px;
            color: #ecf0f1;
            text-decoration: none;
            transition: background-color 0.3s, color 0.3s;
            font-size: 0.95rem; /* UKURAN FONT SERAGAM */
            font-weight: 400; /* KETEBALAN FONT SERAGAM */
        }

        .sidebar-menu li a:hover {
            background-color: var(--sidebar-hover);
            color: white;
        }

        .sidebar-menu li a.active {
            background-color: var(--primary-color);
            color: white;
            border-left: 5px solid #3498db;
        }

        .sidebar-menu li a i {
            /* Penataan Ikon Seragam */
            width: 20px; 
            margin-right: 10px; 
            text-align: center; 
            font-size: 1.1rem;
        }
        /* ------------------- Akhir Sidebar ------------------- */


        /* ------------------- Content ------------------- */
        .content-wrapper {
            flex-grow: 1;
            padding: 30px;
        }

        .content-header {
            margin-bottom: 30px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 15px;
        }

        .content-header h2 {
            font-size: 1.8rem;
            color: var(--dark-text);
            margin: 0;
            font-weight: 600;
        }

        .content-header p {
            color: var(--secondary-color);
            margin-top: 5px;
        }
        
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* ====================================================================== */
        /* Gaya Khusus Dashboard */
        /* ====================================================================== */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background-color: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-left: 5px solid; 
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 14px rgba(0, 0, 0, 0.12);
        }

        .card-content h3 {
            margin-top: 0;
            font-size: 0.95em;
            color: #666;
            font-weight: 500;
            margin-bottom: 0;
        }

        .card-content .amount {
            font-size: 1.8em;
            font-weight: 700;
            margin: 5px 0 0 0;
            line-height: 1.2;
        }

        .card-icon {
            font-size: 3em;
            opacity: 0.3;
        }

        /* Warna Card */
        .card.masuk { border-color: var(--success-color); } 
        .card.masuk .amount { color: var(--success-color); }
        .card.masuk .card-icon { color: var(--success-color); }

        .card.keluar { border-color: var(--danger-color); } 
        .card.keluar .amount { color: var(--danger-color); }
        .card.keluar .card-icon { color: var(--danger-color); }

        .card.saldo { border-color: var(--info-color); } 
        .card.saldo .amount { color: var(--info-color); }
        .card.saldo .card-icon { color: var(--info-color); }

        .card.siswa { border-color: var(--warning-color); } 
        .card.siswa .amount { color: var(--warning-color); }
        .card.siswa .card-icon { color: var(--warning-color); }

        .card.kelas { border-color: #9b59b6; } /* Ungu */
        .card.kelas .amount { color: #9b59b6; }
        .card.kelas .card-icon { color: #9b59b6; }

        /* Gaya Tabel Laporan Harian */
        .daily-report {
            margin-top: 30px;
            padding: 25px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        .daily-report h3 {
            font-size: 1.4rem;
            color: var(--dark-text);
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            margin-top: 0;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th, .data-table td {
            border: 1px solid #e9ecef;
            padding: 12px 15px;
            text-align: left;
        }
        .data-table th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
        }
        .data-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .data-table tr:hover {
            background-color: #e9ecef;
        }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .total-row {
            font-weight: bold;
            background-color: #c9e8f8 !important;
        }
        .balance-row {
            font-weight: bold;
            background-color: #d0f0d0 !important; /* Hijau muda */
            border-top: 3px solid var(--success-color);
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
                <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="pembayaran.php"><i class="fas fa-cash-register"></i> Kelola Pembayaran</a></li>
                <li><a href="pengeluaran.php"><i class="fas fa-money-bill-wave"></i> Kelola Pengeluaran</a></li>
                <li><a href="siswa.php"><i class="fas fa-user-graduate"></i> Kelola Data Siswa</a></li>
                <li><a href="kelas.php"><i class="fas fa-school"></i> Kelola Data Kelas</a></li>
                <li><a href="tahun_ajaran.php"><i class="fas fa-calendar-alt"></i> Kelola Tahun Ajaran</a></li>
                <li><a href="kelola_set_biaya.php"><i class="fas fa-cogs"></i> Kelola Biaya Pembayaran</a></li>
                <li><a href="laporan.php"><i class="fas fa-chart-line"></i> Lihat Laporan</a></li>
                <li><a href="laporan_per_kelas.php"><i class="fas fa-table"></i> Laporan Per Kelas</a></li>
                <li><a href="laporan_tunggakan.php"><i class="fas fa-exclamation-triangle"></i> Laporan Tunggakan</a></li>
                <li><a href="pengguna.php"><i class="fas fa-users"></i> Kelola Pengguna</a></li> 
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
        <div class="content-wrapper">
            <div class="content-header">
                <h2><i class="fas fa-chart-pie"></i> Ringkasan Sistem</h2>
                <p>Selamat datang, **<?php echo htmlspecialchars($_SESSION['nama_pengguna']); ?>**! Tahun Ajaran Aktif: **<?php echo htmlspecialchars($tahun_aktif); ?>**</p>
            </div>
            
            <div class="container">
                <div class="dashboard-cards">
                    
                    <div class="card masuk">
                        <div class="card-content">
                            <h3>Total Pemasukan (Global)</h3>
                            <p class="amount"><?php echo formatRupiah($total_masuk); ?></p>
                        </div>
                        <i class="fas fa-hand-holding-usd card-icon"></i>
                    </div>

                    <div class="card keluar">
                        <div class="card-content">
                            <h3>Total Pengeluaran (Global)</h3>
                            <p class="amount"><?php echo formatRupiah($total_keluar); ?></p>
                        </div>
                        <i class="fas fa-shopping-cart card-icon"></i>
                    </div>

                    <div class="card saldo">
                        <div class="card-content">
                            <h3>Saldo Kas Sekolah</h3>
                            <p class="amount"><?php echo formatRupiah($saldo_akhir); ?></p>
                        </div>
                        <i class="fas fa-wallet card-icon"></i>
                    </div>
                    
                    <div class="card siswa">
                        <div class="card-content">
                            <h3>Jumlah Siswa Aktif</h3>
                            <p class="amount"><?php echo number_format($total_siswa); ?> Siswa</p>
                        </div>
                        <i class="fas fa-user-graduate card-icon"></i>
                    </div>

                    <div class="card kelas">
                        <div class="card-content">
                            <h3>Jumlah Kelas</h3>
                            <p class="amount"><?php echo number_format($total_kelas); ?> Kelas</p>
                        </div>
                        <i class="fas fa-school card-icon"></i>
                    </div>
                </div>
                
                <div class="daily-report">
                    <h3><i class="fas fa-calendar-day"></i> Laporan Kas Harian (<?php echo htmlspecialchars($display_tanggal_hari); ?>)</h3>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Uraian Transaksi</th>
                                    <th class="text-right">Jumlah (Rp)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr style="background-color: #e6f7e6;">
                                    <td colspan="2" style="font-weight: 700; color: var(--success-color); text-align: center; border-bottom: 2px solid var(--success-color);">
                                        DETAIL PEMASUKAN
                                    </td>
                                </tr>

                                <?php 
                                $data_pemasukan_harian_ada = false;
                                foreach ($master_jenis as $data) {
                                    if ($data['total_pemasukan'] > 0) {
                                        $data_pemasukan_harian_ada = true;
                                ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($data['jenis_pembayaran']); ?></td>
                                            <td class="text-right" style="color: var(--success-color);">
                                                <?php echo formatRupiah($data['total_pemasukan']); ?>
                                            </td>
                                        </tr>
                                <?php 
                                    }
                                }
                                
                                if (!$data_pemasukan_harian_ada && $total_pemasukan_display == 0): 
                                ?>
                                    <tr>
                                        <td colspan="2" class="text-center" style="color: var(--secondary-color);">Belum ada pemasukan yang tercatat hari ini.</td>
                                    </tr>
                                <?php 
                                endif; 
                                ?>
                                
                                <tr class="total-row">
                                    <td>TOTAL KOTOR PEMASUKAN HARI INI</td>
                                    <td class="text-right" style="color: var(--success-color);">
                                        <?php echo formatRupiah($total_pemasukan_display); ?>
                                    </td>
                                </tr>

                                <tr style="background-color: #fce4e4;">
                                    <td colspan="2" style="font-weight: 700; color: var(--danger-color); text-align: center; border-bottom: 2px solid var(--danger-color); border-top: 2px solid #ddd;">
                                        DETAIL PENGELUARAN
                                    </td>
                                </tr>

                                <?php if (!empty($detail_pengeluaran_harian)): ?>
                                    <?php foreach ($detail_pengeluaran_harian as $detail_keluar): ?>
                                        <tr>
                                            <td style="padding-left: 30px; font-style: italic; color: #7f8c8d;">
                                                <i class="fas fa-arrow-right" style="margin-right: 5px;"></i>
                                                <?php echo htmlspecialchars($detail_keluar['deskripsi']); ?>
                                                </td>
                                            <td class="text-right" style="color: var(--danger-color);">
                                                -<?php echo formatRupiah($detail_keluar['jumlah']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2" class="text-center" style="color: var(--secondary-color); background-color: #fef0f0;">Belum ada pengeluaran yang tercatat hari ini.</td>
                                    </tr>
                                <?php endif; ?>

                                <tr style="font-weight: bold; background-color: #f8e9e9;">
                                    <td>TOTAL AKUMULASI PENGELUARAN HARI INI</td>
                                    <td class="text-right" style="color: var(--danger-color);">
                                        (<?php echo formatRupiah($total_keluar_harian); ?>)
                                    </td>
                                </tr>

                                <tr class="balance-row">
                                    <td>SALDO BERSIH HARI INI</td>
                                    <td class="text-right" style="color: <?php echo $saldo_harian_bersih >= 0 ? 'darkgreen' : 'darkred'; ?>;">
                                        <?php echo formatRupiah($saldo_harian_bersih); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php 
    // Tutup koneksi database
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
?>