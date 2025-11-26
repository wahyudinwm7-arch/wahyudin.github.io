<?php
session_start();
// ... (Kode PHP di atas ini tetap sama, hanya bagian HTML/CSS yang diubah)
// START KODE PHP ASLI
include '../includes/koneksi.php';

// Cek apakah pengguna sudah login
if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: login.php");
    exit();
}

// Proses Tambah Tahun Ajaran
if (isset($_POST['tambah'])) {
    $nama_tahun = mysqli_real_escape_string($conn, $_POST['nama_tahun']);
    
    // Nonaktifkan semua tahun ajaran yang ada
    $query_nonaktif = "UPDATE tahun_ajaran SET aktif = 0";
    mysqli_query($conn, $query_nonaktif);

    // Tambahkan tahun ajaran baru dan set sebagai aktif
    $stmt = $conn->prepare("INSERT INTO tahun_ajaran (nama_tahun, aktif) VALUES (?, 1)");
    $stmt->bind_param("s", $nama_tahun);
    $stmt->execute();
    
    header("Location: tahun_ajaran.php?status=tambah_sukses");
    exit();
}

// Proses Hapus Tahun Ajaran
if (isset($_GET['hapus'])) {
    $id_tahun_ajaran = (int)$_GET['hapus'];
    
    // Periksa apakah tahun ajaran sudah digunakan oleh siswa
    $stmt_cek = $conn->prepare("SELECT COUNT(*) AS total FROM siswa WHERE id_tahun_ajaran = ?");
    $stmt_cek->bind_param("i", $id_tahun_ajaran);
    $stmt_cek->execute();
    $result_cek = $stmt_cek->get_result();
    $data_cek = $result_cek->fetch_assoc();

    if ($data_cek['total'] > 0) {
        // Jika ada siswa yang terhubung, beri pesan error
        header("Location: tahun_ajaran.php?status=gagal_hapus");
        exit();
    } else {
        // Jika tidak ada siswa, hapus tahun ajaran
        $stmt_hapus = $conn->prepare("DELETE FROM tahun_ajaran WHERE id_tahun_ajaran = ?");
        $stmt_hapus->bind_param("i", $id_tahun_ajaran);
        $stmt_hapus->execute();
        
        header("Location: tahun_ajaran.php?status=sukses_hapus");
        exit();
    }
}

// Proses Aktifkan Tahun Ajaran
if (isset($_GET['aktifkan'])) {
    $id_tahun_ajaran = (int)$_GET['aktifkan'];

    // Nonaktifkan semua tahun ajaran yang ada
    $query_nonaktif = "UPDATE tahun_ajaran SET aktif = 0";
    mysqli_query($conn, $query_nonaktif);
    
    // Aktifkan tahun ajaran yang dipilih
    $stmt_aktifkan = $conn->prepare("UPDATE tahun_ajaran SET aktif = 1 WHERE id_tahun_ajaran = ?");
    $stmt_aktifkan->bind_param("i", $id_tahun_ajaran);
    $stmt_aktifkan->execute();

    header("Location: tahun_ajaran.php?status=aktifkan_sukses");
    exit();
}

// Ambil data tahun ajaran untuk ditampilkan
$query = "SELECT * FROM tahun_ajaran ORDER BY nama_tahun DESC";
$result = mysqli_query($conn, $query);
// END KODE PHP ASLI
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Tahun Ajaran | Pembayaran Siswa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ====================================================================== */
        /* Gaya Dasar & Layout (Konsisten dengan file sebelumnya) */
        /* ====================================================================== */
        :root {
            --primary-color: #007bff; /* Biru Cerah */
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --light-bg: #f8f9fa;
            --dark-text: #343a40;
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
            transition: width 0.3s;
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
        
        /* Font Awesome */
        .sidebar-header h2 i {
            margin-right: 5px;
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
            padding: 30px;
        }

        .content-header {
            margin-bottom: 30px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 15px;
        }

        .content-header h2 {
            font-size: 1.8rem;
            color: var(--primary-color);
            margin: 0;
            font-weight: 600;
        }

        .content-header i {
            margin-right: 10px;
        }
        
        /* ------------------- Form & Input ------------------- */
        .form-container, .data-section {
            background: white;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); /* Shadow yang lebih halus */
        }
        
        h3 {
            color: var(--dark-text);
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            margin-top: 15px;
            font-weight: 600;
            color: var(--dark-text);
        }
        
        /* Form Tambah Tahun Ajaran */
        .tambah-form div {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .tambah-form div label {
            margin: 0;
        }

        .tambah-form #nama_tahun {
            flex-grow: 1;
        }

        input[type="text"], input[type="number"], input[type="date"], select {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
            transition: border-color 0.3s, box-shadow 0.3s;
            font-size: 1rem;
        }

        input[type="text"]:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
            outline: none;
        }

        .btn {
            display: inline-block;
            background-color: var(--primary-color);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.3s, transform 0.1s;
            font-weight: 600;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        .btn:hover {
            background-color: #0056b3;
        }
        
        .btn:active {
            transform: translateY(1px);
        }

        /* ------------------- Pesan Status ------------------- */
        .status-message {
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: 600;
        }

        .status-message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-message.danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* ------------------- Tabel ------------------- */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
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
        
        .status-aktif {
            color: var(--success-color);
            font-weight: 600;
        }
        
        .status-nonaktif {
            color: var(--secondary-color);
            font-style: italic;
        }
        
        .action-link {
            margin-right: 10px;
            text-decoration: none;
            color: var(--primary-color);
            transition: color 0.2s;
        }

        .action-link:hover {
            color: #0056b3;
            text-decoration: underline;
        }

        .action-hapus {
            color: var(--danger-color);
        }
        .action-hapus:hover {
            color: #b00020;
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
                <li><a href="tahun_ajaran.php" class="active"><i class="fas fa-calendar-alt"></i> Kelola Tahun Ajaran</a></li>
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
                <h2><i class="fas fa-calendar-alt"></i> Kelola Tahun Ajaran</h2>
            </div>

            <?php if (isset($_GET['status'])): ?>
                <?php if ($_GET['status'] == 'gagal_hapus'): ?>
                    <div class="status-message danger">
                        <i class="fas fa-times-circle"></i> Tidak bisa menghapus tahun ajaran karena masih ada siswa yang terdaftar di tahun ajaran ini.
                    </div>
                <?php elseif ($_GET['status'] == 'tambah_sukses'): ?>
                    <div class="status-message success">
                        <i class="fas fa-check-circle"></i> Tahun ajaran berhasil ditambahkan dan diaktifkan.
                    </div>
                <?php elseif ($_GET['status'] == 'sukses_hapus'): ?>
                    <div class="status-message success">
                        <i class="fas fa-check-circle"></i> Tahun ajaran berhasil dihapus.
                    </div>
                <?php elseif ($_GET['status'] == 'aktifkan_sukses'): ?>
                    <div class="status-message success">
                        <i class="fas fa-check-circle"></i> Tahun ajaran berhasil diaktifkan.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="form-container tambah-form">
                <h3><i class="fas fa-plus-circle"></i> Tambah Tahun Ajaran Baru</h3>
                <form action="" method="POST">
                    <div>
                        <label for="nama_tahun">Tahun Ajaran:</label>
                        <input type="text" id="nama_tahun" name="nama_tahun" placeholder="Contoh: 2023/2024" required>
                        <button type="submit" name="tambah" class="btn"><i class="fas fa-check"></i> Tambah & Aktifkan</button>
                    </div>
                </form>
            </div>
            
            <div class="data-section">
                <h3><i class="fas fa-list-ul"></i> Daftar Tahun Ajaran</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Tahun Ajaran</th>
                            <th>Status Aktif</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1; 
                        if (mysqli_num_rows($result) > 0) {
                            while($row = mysqli_fetch_assoc($result)): 
                        ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><?php echo htmlspecialchars($row['nama_tahun']); ?></td>
                            <td>
                                <?php if ($row['aktif'] == 1): ?>
                                    <span class="status-aktif"><i class="fas fa-circle-check"></i> (Aktif)</span>
                                <?php else: ?>
                                    <span class="status-nonaktif"><i class="fas fa-circle-xmark"></i> Tidak Aktif</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['aktif'] == 0): ?>
                                    <a href="?aktifkan=<?php echo $row['id_tahun_ajaran']; ?>" 
                                       onclick="return confirm('Yakin ingin mengaktifkan tahun ajaran ini? Ini akan menonaktifkan tahun ajaran lainnya.');"
                                       class="action-link btn-sm"
                                    >
                                        <i class="fas fa-toggle-on"></i> Aktifkan
                                    </a> 
                                <?php endif; ?>
                                
                                <a href="?hapus=<?php echo $row['id_tahun_ajaran']; ?>" 
                                   onclick="return confirm('Yakin ingin menghapus tahun ajaran <?php echo htmlspecialchars($row['nama_tahun']); ?>?');"
                                   class="action-link action-hapus btn-sm"
                                >
                                    <i class="fas fa-trash-alt"></i> Hapus
                                </a>
                            </td>
                        </tr>
                        <?php 
                            endwhile; 
                        } else {
                            echo "<tr><td colspan='4' style='text-align: center;'>Belum ada data tahun ajaran.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
<?php mysqli_close($conn); ?>