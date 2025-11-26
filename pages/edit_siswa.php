<?php
// Pastikan koneksi dan session_start berada di atas
session_start();
// Menggunakan 'require' atau 'require_once' lebih baik untuk file penting
require_once '../includes/koneksi.php';

// --- Bagian Verifikasi dan Pengambilan Data ---

// Cek apakah pengguna sudah login
if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

// Cek apakah ada ID siswa yang dikirim melalui URL dan valid
// id harus ada, tidak kosong, dan berupa angka
if (!isset($_GET['id']) || empty($_GET['id']) || !ctype_digit($_GET['id'])) {
    header("Location: siswa.php?status=error&pesan=ID Siswa tidak valid.");
    exit();
}

// Gunakan intval() untuk memastikan nilai adalah integer, menghindari potensi masalah
$id_siswa = intval($_GET['id']);

// Ambil data siswa yang akan diedit menggunakan prepared statement
$stmt_siswa = $conn->prepare("SELECT * FROM siswa WHERE id_siswa = ?");
// Cek jika prepare gagal
if ($stmt_siswa === false) {
    die("Kesalahan SQL (Siswa): " . $conn->error);
}
$stmt_siswa->bind_param("i", $id_siswa);
$stmt_siswa->execute();
$result_siswa = $stmt_siswa->get_result();
$data_siswa = $result_siswa->fetch_assoc();
$stmt_siswa->close();

// Jika siswa tidak ditemukan, kembali ke halaman siswa
if (!$data_siswa) {
    header("Location: siswa.php?status=gagal&pesan=Siswa tidak ditemukan.");
    exit();
}

// Ambil data kelas dan tahun ajaran untuk dropdown
// Prepared statement sudah bagus, pertahankan.
$stmt_kelas = $conn->prepare("SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas ASC");
if ($stmt_kelas === false) {
    die("Kesalahan SQL (Kelas): " . $conn->error);
}
$stmt_kelas->execute();
$result_kelas = $stmt_kelas->get_result();
$stmt_kelas->close();

$stmt_tahun = $conn->prepare("SELECT id_tahun_ajaran, nama_tahun FROM tahun_ajaran ORDER BY nama_tahun DESC"); // Urutkan menurun agar tahun terbaru di atas
if ($stmt_tahun === false) {
    die("Kesalahan SQL (Tahun Ajaran): " . $conn->error);
}
$stmt_tahun->execute();
$result_tahun = $stmt_tahun->get_result();
$stmt_tahun->close();

// Tutup koneksi di bagian akhir file
// $conn->close();

// --- Tutup blok PHP untuk memulai HTML ---
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Siswa | Aplikasi Pembayaran Siswa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        /* Gaya khusus untuk form */
        .content-wrapper form {
            background-color: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            max-width: 600px;
            margin: 20px auto;
        }

        .content-wrapper form div {
            margin-bottom: 20px;
        }

        .content-wrapper label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }

        .content-wrapper input[type="text"],
        .content-wrapper select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 1em;
            transition: border-color 0.3s;
        }

        .content-wrapper input[type="text"]:focus,
        .content-wrapper select:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        .content-wrapper button[type="submit"] {
            background-color: #2ecc71; /* Warna hijau untuk simpan */
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: 600;
            transition: background-color 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .content-wrapper button[type="submit"]:hover {
            background-color: #27ae60;
        }
        
        /* Sidebar Icons - Diperbaiki untuk konsistensi */
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sidebar-header h2 {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Perbaikan: Tambahkan margin-top untuk tombol jika ada elemen lain di bawah form */
        .content-wrapper form div:last-child {
            margin-top: 30px; 
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
                <li><a href="siswa.php" class="active"><i class="fas fa-user-graduate"></i> Kelola Data Siswa</a></li>
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
                <h2><i class="fas fa-user-edit"></i> Edit Data Siswa</h2>
                <p>Ubah informasi detail untuk Siswa dengan NISN: **<?php echo htmlspecialchars($data_siswa['nisn']); ?>**</p>
            </div>
            
            <form action="../proses/proses_siswa.php" method="POST">
                <input type="hidden" name="id_siswa" value="<?php echo htmlspecialchars($data_siswa['id_siswa']); ?>">
                <input type="hidden" name="action" value="update"> 

                <div>
                    <label for="nama_lengkap"><i class="fas fa-user-tag"></i> Nama Lengkap:</label>
                    <input type="text" id="nama_lengkap" name="nama_lengkap" value="<?php echo htmlspecialchars($data_siswa['nama_lengkap']); ?>" required>
                </div>
                
                <div>
                    <label for="nisn"><i class="fas fa-id-card"></i> NISN:</label>
                    <input type="text" id="nisn" name="nisn" value="<?php echo htmlspecialchars($data_siswa['nisn']); ?>" required>
                </div>
                
                <div>
                    <label for="id_kelas"><i class="fas fa-school"></i> Kelas:</label>
                    <select id="id_kelas" name="id_kelas" required>
                        <?php 
                        // Jika ingin me-reset, bisa menggunakan $result_kelas->data_seek(0);
                        while($row = $result_kelas->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($row['id_kelas']); ?>" 
                                <?php echo ($row['id_kelas'] == $data_siswa['id_kelas']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row['nama_kelas']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div>
                    <label for="id_tahun_ajaran"><i class="fas fa-calendar-alt"></i> Tahun Ajaran:</label>
                    <select id="id_tahun_ajaran" name="id_tahun_ajaran" required>
                        <?php 
                        // Jika ingin me-reset, bisa menggunakan $result_tahun->data_seek(0);
                        while($row = $result_tahun->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($row['id_tahun_ajaran']); ?>" 
                                <?php echo ($row['id_tahun_ajaran'] == $data_siswa['id_tahun_ajaran']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row['nama_tahun']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div>
                    <button type="submit" name="ubah"><i class="fas fa-save"></i> Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
<?php
// Tutup koneksi di akhir file untuk memastikan semua operasi DB selesai
if (isset($conn)) {
    $conn->close();
}
?>