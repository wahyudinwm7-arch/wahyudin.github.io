<?php
session_start();
include '../includes/koneksi.php';

// Cek apakah pengguna sudah login
if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php"); // Pastikan path ke login.php benar
    exit();
}

// Proses Tambah Kelas
if (isset($_POST['tambah'])) {
    $nama_kelas = trim($_POST['nama_kelas']);
    
    // Validasi input
    if (empty($nama_kelas)) {
        header("Location: kelas.php?status=gagal_tambah_kosong");
        exit();
    }

    // Menggunakan Prepared Statement untuk INSERT
    $stmt = $conn->prepare("INSERT INTO kelas (nama_kelas) VALUES (?)");
    
    // Penanganan error jika prepare gagal
    if (!$stmt) {
        error_log("Error preparing statement for adding class: " . $conn->error);
        header("Location: kelas.php?status=error_internal");
        exit();
    }
    
    $stmt->bind_param("s", $nama_kelas);
    
    if ($stmt->execute()) {
        header("Location: kelas.php?status=sukses_tambah");
        exit();
    } else {
        // Error_log untuk debugging database (misal: duplikasi nama)
        error_log("Error executing statement for adding class: " . $stmt->error);
        header("Location: kelas.php?status=gagal_tambah");
        exit();
    }
}

// Proses Hapus Kelas
if (isset($_GET['hapus'])) {
    $id_kelas = (int)$_GET['hapus']; // Pastikan ID adalah integer
    
    // 1. Periksa apakah kelas sudah digunakan oleh siswa
    $stmt_cek = $conn->prepare("SELECT COUNT(*) AS total FROM siswa WHERE id_kelas = ?");
    $stmt_cek->bind_param("i", $id_kelas);
    $stmt_cek->execute();
    $result_cek = $stmt_cek->get_result();
    $data_cek = $result_cek->fetch_assoc();
    $stmt_cek->close();

    if ($data_cek['total'] > 0) {
        // Jika ada siswa yang terhubung, beri pesan error
        header("Location: kelas.php?status=gagal_hapus_terhubung");
        exit();
    } else {
        // 2. Jika tidak ada siswa, hapus kelas
        $stmt_hapus = $conn->prepare("DELETE FROM kelas WHERE id_kelas = ?");
        $stmt_hapus->bind_param("i", $id_kelas);
        
        if ($stmt_hapus->execute()) {
            header("Location: kelas.php?status=sukses_hapus");
            exit();
        } else {
            error_log("Error executing statement for deleting class: " . $stmt_hapus->error);
            header("Location: kelas.php?status=gagal_hapus_db");
            exit();
        }
    }
}

// Ambil data kelas untuk ditampilkan
$query = "SELECT * FROM kelas ORDER BY nama_kelas ASC";
$result = mysqli_query($conn, $query);

// Mendapatkan data tahun ajaran aktif untuk sidebar
$query_tahun_aktif = "SELECT nama_tahun FROM tahun_ajaran WHERE aktif = 1";
$result_tahun_aktif = mysqli_query($conn, $query_tahun_aktif);
$data_tahun_aktif = mysqli_fetch_assoc($result_tahun_aktif);
$tahun_aktif = $data_tahun_aktif['nama_tahun'] ?? 'Tidak Ada';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Kelas | Aplikasi Pembayaran Siswa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* Mendefinisikan Warna Dasar */
        :root {
            --primary-color: #007bff; 
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --dark-text: #343a40;
            --light-bg: #f8f9fa;
            --card-bg: #ffffff;
            --sidebar-bg: #2c3e50;
        }

        body {
            font-family: 'Poppins', sans-serif;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* CARD STYLE (Jika belum ada di style.css) */
        .card {
            background-color: var(--card-bg);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }
        .card h3 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.4rem;
            color: var(--primary-color);
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            font-weight: 600;
        }
        
        /* Form Styling */
        .form-input .form-group {
            margin-bottom: 15px;
        }
        .form-input label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark-text);
        }
        .form-input input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            box-sizing: border-box;
        }
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }

        /* Notifikasi Box */
        .notifikasi-box {
            padding: 10px 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-size: 0.95rem;
        }
        .notifikasi-box p {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .notifikasi-box.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .notifikasi-box.danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Modal Kustom */
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0;
            width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe; margin: 10% auto; padding: 30px; border: 1px solid #888;
            width: 90%; max-width: 400px; border-radius: 10px; text-align: center;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2); animation: fadeIn 0.3s;
        }
        @keyframes fadeIn {
            from {opacity: 0;} to {opacity: 1;}
        }
        .modal-buttons {
            margin-top: 25px;
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        .modal-buttons button, .modal-buttons a {
            padding: 10px 25px;
            border: none;
            cursor: pointer;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.3s;
        }
        .modal-buttons .btn-cancel {
            background-color: #6c757d;
            color: white;
        }
        .modal-buttons .btn-cancel:hover {
            background-color: #5a6268;
        }
        .modal-buttons .btn-delete {
            background-color: var(--danger-color);
            color: white;
        }
        .modal-buttons .btn-delete:hover {
            background-color: #c82333;
        }
        /* Tabel Aksi */
        .data-table .aksi button {
            background-color: var(--danger-color);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: background-color 0.3s;
        }
        .data-table .aksi button:hover {
            background-color: #c82333;
        }
        hr {
            border: 0;
            border-top: 1px solid #e9ecef;
            margin: 25px 0;
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
                <li><a href="kelas.php" class="active"><i class="fas fa-school"></i> Kelola Data Kelas</a></li>
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
                <h2><i class="fas fa-school"></i> Kelola Data Kelas</h2>
                <p>Tahun Ajaran Aktif: **<?php echo htmlspecialchars($tahun_aktif); ?>**. Tambah, lihat, dan hapus data kelas sekolah.</p>
            </div>

            <?php 
            $status_msg = '';
            $status_class = '';

            if (isset($_GET['status'])) {
                switch ($_GET['status']) {
                    case 'gagal_hapus_terhubung':
                        $status_msg = 'GAGAL: Tidak bisa menghapus kelas karena masih ada **siswa** yang terdaftar di kelas ini. Hapus atau pindahkan siswa terlebih dahulu.';
                        $status_class = 'danger';
                        break;
                    case 'sukses_hapus':
                        $status_msg = 'SUKSES: Kelas berhasil dihapus!';
                        $status_class = 'success';
                        break;
                    case 'sukses_tambah':
                        $status_msg = 'SUKSES: Kelas berhasil ditambahkan!';
                        $status_class = 'success';
                        break;
                    case 'gagal_tambah_kosong':
                        $status_msg = 'GAGAL: Nama kelas tidak boleh kosong.';
                        $status_class = 'danger';
                        break;
                    case 'gagal_tambah':
                    case 'gagal_hapus_db':
                    case 'error_internal':
                        $status_msg = 'Terjadi kesalahan saat memproses data. Silakan coba lagi atau hubungi administrator.';
                        $status_class = 'danger';
                        break;
                }
            }

            if ($status_msg):
            ?>
                <div class="notifikasi-box <?php echo $status_class; ?>">
                    <p>
                        <i class="fas fa-<?php echo ($status_class == 'success') ? 'check-circle' : 'exclamation-circle'; ?>"></i> 
                        <?php echo $status_msg; ?>
                    </p>
                </div>
            <?php endif; ?>
            <div class="card">
                <h3><i class="fas fa-plus-circle"></i> Tambah Kelas Baru</h3>
                <form action="" method="POST" class="form-input">
                    <div class="form-group">
                        <label for="nama_kelas">Nama Kelas:</label>
                        <input type="text" id="nama_kelas" name="nama_kelas" required placeholder="Contoh: X IPA 1, XI IPS 2">
                    </div>
                    <button type="submit" name="tambah" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah Kelas</button>
                </form>
            </div>
            
            <hr>
            <div class="card">
    <h3>Proses Kenaikan Kelas Massal</h3>
    <p>Gunakan fitur ini hanya di akhir Tahun Ajaran (sebelum Tahun Ajaran Baru dimulai).</p>
    <a href="proses_kenaikan.php" onclick="return confirm('APAKAH ANDA YAKIN INGIN MEMPROSES KENAIKAN KELAS? Tindakan ini tidak dapat dibatalkan.');" class="btn btn-warning">
        <i class="fas fa-arrow-up"></i> Proses Kenaikan Kelas
    </a>
</div>

<style>
/* Style sederhana untuk tombol, sesuaikan dengan tema Anda */
.btn-warning {
    background-color: #f39c12;
    color: white;
    padding: 10px 20px;
    border-radius: 5px;
    text-decoration: none;
    display: inline-block;
}
</style>
            <div class="card">
                <h3><i class="fas fa-list-alt"></i> Daftar Kelas</h3>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Kelas</th>
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
                                <td><?php echo htmlspecialchars($row['nama_kelas']); ?></td>
                                <td class="aksi">
                                    <button class="btn btn-danger btn-sm" onclick="showDeleteModal(<?php echo $row['id_kelas']; ?>, '<?php echo htmlspecialchars($row['nama_kelas']); ?>')">
                                        <i class="fas fa-trash"></i> Hapus
                                    </button>
                                </td>
                            </tr>
                            <?php 
                                endwhile; 
                            } else {
                                echo "<tr><td colspan='3' style='text-align: center; color: #6c757d; padding: 20px;'>Belum ada data kelas yang terdaftar. Silakan tambahkan kelas baru di atas.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="deleteModal" class="modal">
                <div class="modal-content">
                    <h4><i class="fas fa-exclamation-triangle" style="color: var(--danger-color); margin-right: 5px;"></i> Konfirmasi Penghapusan</h4>
                    <p>Apakah Anda yakin ingin menghapus kelas: <strong id="className"></strong>?</p>
                    <p style="color: var(--danger-color); font-size: 0.9em; margin-top: 15px;">**PERINGATAN:** Penghapusan hanya bisa dilakukan jika **tidak ada siswa** yang terhubung dengan kelas ini.</p>
                    <div class="modal-buttons">
                        <button class="btn-cancel" onclick="closeDeleteModal()"><i class="fas fa-times"></i> Batal</button>
                        <a id="confirmDelete" href="#" class="btn-delete"><i class="fas fa-trash-alt"></i> Ya, Hapus</a>
                    </div>
                </div>
            </div>
            </div>
    </div>

    <script>
        // Logika untuk menampilkan dan menyembunyikan modal kustom
        const modal = document.getElementById('deleteModal');
        const confirmDeleteLink = document.getElementById('confirmDelete');
        const classNameDisplay = document.getElementById('className');

        function showDeleteModal(id_kelas, nama_kelas) {
            classNameDisplay.textContent = nama_kelas;
            confirmDeleteLink.href = 'kelas.php?hapus=' + id_kelas;
            modal.style.display = 'flex'; // Menggunakan flex untuk centering yang lebih baik
        }

        function closeDeleteModal() {
            modal.style.display = 'none';
        }

        // Tutup modal jika user mengklik di luar area modal
        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
<?php 
// Tutup koneksi setelah semua proses selesai
if (isset($conn)) {
    mysqli_close($conn);
}
?>