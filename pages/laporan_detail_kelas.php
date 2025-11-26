<?php
session_start();
include '../includes/koneksi.php';

// Cek apakah pengguna sudah login
if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

// --- 1. Fungsi Helper ---
function formatRupiah($angka) {
    // Pastikan input adalah numerik, jika null/kosong, anggap 0
    $angka = (float)($angka ?? 0);
    return "Rp " . number_format($angka, 0, ',', '.');
}

$nama_kelas = 'Tidak Ditemukan';
$nama_tahun = 'Tidak Ditemukan';
$transaksi_detail = [];

if (isset($_GET['id_kelas']) && isset($_GET['id_tahun_ajaran'])) {
    $id_kelas = mysqli_real_escape_string($conn, $_GET['id_kelas']);
    $id_tahun_ajaran = mysqli_real_escape_string($conn, $_GET['id_tahun_ajaran']);

    // Ambil detail kelas dan tahun
    $query_info = "SELECT k.nama_kelas, ta.nama_tahun 
                   FROM kelas k, tahun_ajaran ta 
                   WHERE k.id_kelas = '$id_kelas' AND ta.id_tahun_ajaran = '$id_tahun_ajaran'";
    $result_info = mysqli_query($conn, $query_info);
    if ($info = mysqli_fetch_assoc($result_info)) {
        $nama_kelas = $info['nama_kelas'];
        $nama_tahun = $info['nama_tahun'];
    }

    // Ambil detail transaksi
    $query_detail = "
        SELECT 
            t.tanggal_transaksi,
            t.jenis_pembayaran,
            t.jumlah,
            t.deskripsi,
            s.nama_lengkap AS nama_siswa,
            s.nisn,
            u.nama_pengguna AS dicatat_oleh
        FROM
            transaksi t
        JOIN
            siswa s ON t.id_siswa = s.id_siswa
        JOIN
            pengguna u ON t.dicatat_oleh_id_pengguna = u.id_pengguna
        WHERE
            t.jenis_transaksi = 'masuk' AND s.id_kelas = '$id_kelas' AND s.id_tahun_ajaran = '$id_tahun_ajaran'
        ORDER BY
            t.tanggal_transaksi DESC
    ";
    $result_detail = mysqli_query($conn, $query_detail);
    while ($row = mysqli_fetch_assoc($result_detail)) {
        $transaksi_detail[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Laporan Per Kelas | Aplikasi Pembayaran Siswa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        /* ====================================================================== */
        /* VARIABEL, GAYA DASAR & LAYOUT (DISALIN DARI DASHBOARD.PHP) */
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

        /* ------------------- Sidebar (DISALIN DARI DASHBOARD.PHP) ------------------- */
        .sidebar {
            width: 250px;
            background-color: var(--sidebar-bg);
            color: white;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            height: 100%;
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
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #ecf0f1;
            text-decoration: none;
            transition: background-color 0.3s, color 0.3s;
            font-size: 0.95rem;
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
            margin-right: 10px;
            font-size: 1.1rem;
        }

        /* ------------------- Content ------------------- */
        .content-wrapper {
            flex-grow: 1;
            margin-left: 250px; /* Jarak dari Sidebar */
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

        /* ====================================================================== */
        /* GAYA KHUSUS HALAMAN DETAIL (Menyesuaikan dengan gaya Dashboard) */
        /* ====================================================================== */
        
        .container {
            background-color: #ffffff;
            border-radius: 8px; 
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); 
            padding: 25px;
            margin-bottom: 20px; 
            border: 1px solid #e0e0e0;
        }
        
        .container h3 {
            font-size: 1.4rem;
            color: var(--dark-text);
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            margin-top: 0;
            margin-bottom: 20px;
            font-weight: 600;
        }

        /* Tombol Kembali/Link */
        .button-link {
            display: inline-block;
            padding: 10px 15px;
            background-color: var(--secondary-color);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: background-color 0.3s;
            margin-top: 5px;
        }
        .button-link:hover {
            background-color: #5a6268;
        }

        /* Table Styling (Menggunakan gaya Dashboard) */
        .table-container {
             /* Tambahkan container ini untuk membungkus tabel dengan shadow */
            background-color: #ffffff;
            border-radius: 8px; 
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); 
            overflow-x: auto;
            padding: 25px; 
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95em;
        }
        .data-table thead th {
            background-color: var(--primary-color);
            color: white;
            text-align: left;
            padding: 12px 15px;
            border-bottom: 1px solid #ddd;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
        }
        .data-table tbody tr:nth-child(even) {
            background-color: #f8f9fa; 
        }
        .data-table tbody tr:hover {
            background-color: #e9ecef; 
        }
        .data-table td {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
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
                <h2><i class="fas fa-info-circle"></i> Detail Laporan Pembayaran</h2>
            </div>
            
            <div class="container">
                <h3>Rangkuman Kelas: **<?php echo htmlspecialchars($nama_kelas); ?>** - Tahun Ajaran: **<?php echo htmlspecialchars($nama_tahun); ?>**</h3>
                <a href="laporan_per_kelas.php" class="button-link" style="margin-bottom: 20px;">
                    <i class="fas fa-arrow-left"></i> Kembali ke Laporan Per Kelas
                </a>
            </div>

            <div class="table-container">
                 <h3><i class="fas fa-receipt"></i> Riwayat Pembayaran Masuk</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="text-center">No</th>
                            <th>Tanggal</th>
                            <th>Nama Siswa</th>
                            <th>NISN</th>
                            <th>Jenis Pembayaran</th>
                            <th class="text-right">Jumlah</th>
                            <th>Deskripsi</th>
                            <th>Dicatat Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $no = 1;
                        if (!empty($transaksi_detail)):
                            foreach($transaksi_detail as $data):
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $no++; ?></td>
                            <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($data['tanggal_transaksi']))); ?></td>
                            <td><?php echo htmlspecialchars($data['nama_siswa']); ?></td>
                            <td><?php echo htmlspecialchars($data['nisn']); ?></td>
                            <td><?php echo htmlspecialchars($data['jenis_pembayaran']); ?></td>
                            <td class="text-right" style="color: var(--success-color); font-weight: 600;">
                                <?php echo formatRupiah($data['jumlah']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($data['deskripsi']); ?></td>
                            <td><?php echo htmlspecialchars($data['dicatat_oleh']); ?></td>
                        </tr>
                        <?php
                            endforeach;
                        else:
                        ?>
                        <tr>
                            <td colspan="8" class="text-center" style="color: var(--secondary-color); padding: 20px;">Tidak ada data transaksi ditemukan untuk kelas ini di tahun ajaran ini.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
<?php mysqli_close($conn); ?>