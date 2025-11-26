<?php
session_start();
include '../includes/koneksi.php';

// Cek apakah pengguna sudah login
if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

$message = "";

// Logika untuk CRUD Jenis Pembayaran
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_jenis'])) {
    $nama_pembayaran = $_POST['nama_pembayaran'];
    $id_jenis_pembayaran = $_POST['id_jenis_pembayaran'] ?? null;

    if ($id_jenis_pembayaran) {
        $query = "UPDATE jenis_pembayaran SET nama_pembayaran=? WHERE id_jenis_pembayaran=?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "si", $nama_pembayaran, $id_jenis_pembayaran);
    } else {
        $query = "INSERT INTO jenis_pembayaran (nama_pembayaran) VALUES (?)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "s", $nama_pembayaran);
    }

    if (mysqli_stmt_execute($stmt)) {
        $message = "Data jenis pembayaran berhasil disimpan.";
    } else {
        $message = "Error: " . mysqli_error($conn);
    }
    mysqli_stmt_close($stmt);
    header("Location: kelola_jenis_pembayaran.php?message=" . urlencode($message));
    exit();
}

// Logika untuk CRUD Nominal Pembayaran
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_nominal'])) {
    $id_jenis_pembayaran = $_POST['id_jenis_pembayaran'];
    $nominal = $_POST['nominal'];
    $deskripsi = $_POST['deskripsi'];
    $id_nominal = $_POST['id_nominal'] ?? null;

    if ($id_nominal) {
        $query = "UPDATE nominal_jenis_pembayaran SET nominal=?, deskripsi=? WHERE id=?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "isi", $nominal, $deskripsi, $id_nominal);
    } else {
        $query = "INSERT INTO nominal_jenis_pembayaran (id_jenis_pembayaran, nominal, deskripsi) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ids", $id_jenis_pembayaran, $nominal, $deskripsi);
    }

    if (mysqli_stmt_execute($stmt)) {
        $message = "Data nominal berhasil disimpan.";
    } else {
        $message = "Error: " . mysqli_error($conn);
    }
    mysqli_stmt_close($stmt);
    header("Location: kelola_jenis_pembayaran.php?message=" . urlencode($message));
    exit();
}

// Logika untuk mengambil data yang akan diedit
$edit_jenis_data = null;
if (isset($_GET['edit_jenis'])) {
    $id = $_GET['edit_jenis'];
    $query = "SELECT * FROM jenis_pembayaran WHERE id_jenis_pembayaran = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $edit_jenis_data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

$edit_nominal_data = null;
if (isset($_GET['edit_nominal'])) {
    $id = $_GET['edit_nominal'];
    $query = "SELECT * FROM nominal_jenis_pembayaran WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $edit_nominal_data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

// Logika untuk penghapusan
if (isset($_GET['hapus_jenis'])) {
    $id = $_GET['hapus_jenis'];
    $query = "DELETE FROM jenis_pembayaran WHERE id_jenis_pembayaran=?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    if (mysqli_stmt_execute($stmt)) {
        $message = "Data jenis pembayaran berhasil dihapus.";
    } else {
        $message = "Error: " . mysqli_error($conn);
    }
    mysqli_stmt_close($stmt);
    header("Location: kelola_jenis_pembayaran.php?message=" . urlencode($message));
    exit();
}

if (isset($_GET['hapus_nominal'])) {
    $id = $_GET['hapus_nominal'];
    $query = "DELETE FROM nominal_jenis_pembayaran WHERE id=?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    if (mysqli_stmt_execute($stmt)) {
        $message = "Data nominal berhasil dihapus.";
    } else {
        $message = "Error: " . mysqli_error($conn);
    }
    mysqli_stmt_close($stmt);
    header("Location: kelola_jenis_pembayaran.php?message=" . urlencode($message));
    exit();
}


// Ambil daftar jenis pembayaran
$query_jenis = "SELECT * FROM jenis_pembayaran ORDER BY nama_pembayaran ASC";
$result_jenis = mysqli_query($conn, $query_jenis);
$daftar_jenis_pembayaran = mysqli_fetch_all($result_jenis, MYSQLI_ASSOC);

// Ambil daftar nominal pembayaran dengan nama jenis
$query_nominal = "SELECT n.*, j.nama_pembayaran FROM nominal_jenis_pembayaran n JOIN jenis_pembayaran j ON n.id_jenis_pembayaran = j.id_jenis_pembayaran ORDER BY j.nama_pembayaran, n.nominal";
$result_nominal = mysqli_query($conn, $query_nominal);
$daftar_nominal_pembayaran = mysqli_fetch_all($result_nominal, MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kelola Jenis Pembayaran | Aplikasi Pembayaran Siswa</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-section, .table-section {
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        .form-section h3 {
            margin-top: 0;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Pembayaran Siswa</h2>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php" class= <i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="pembayaran.php"><i class="fas fa-cash-register"></i> Kelola Pembayaran</a></li>
                <li><a href="pengeluaran.php"><i class="fas fa-money-bill-wave"></i> Kelola Pengeluaran</a></li>
                <li><a href="siswa.php"><i class="fas fa-user-graduate"></i> Kelola Data Siswa</a></li>
                <li><a href="kelas.php"><i class="fas fa-school"></i> Kelola Data Kelas</a></li>
                <li><a href="tahun_ajaran.php"><i class="fas fa-calendar-alt"></i> Kelola Tahun Ajaran</a></li>
                <li><a href="kelola_set_biaya.php"><i class="fas fa-cogs"></i> Kelola Biaya Pembayaran</a></li>
                <li><a href="laporan.php"><i class="fas fa-chart-line"></i> Lihat Laporan</a></li>
                <li><a href="laporan_per_kelas.php"><i class="fas fa-table"></i> Laporan Per Kelas</a></li>
                <li><a href="laporan_tunggakan.php"><i class="fas fa-exclamation-triangle"></i> Laporan Tunggakan</a></li>
				<li><a href="pengguna.php" class="fas fa-users"></i> Kelola Pengguna</a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
        <div class="content-wrapper">
            <div class="content-header">
                <h2>Kelola Jenis Pembayaran</h2>
            </div>
            <div class="container" style="max-width: 100%; margin: 0; padding: 20px;">
                <?php if (isset($_GET['message'])): ?>
                    <div class="alert"><?php echo htmlspecialchars($_GET['message']); ?></div>
                <?php endif; ?>
                
                <div class="form-grid">
                    <div class="form-section">
                        <h3><?php echo $edit_jenis_data ? 'Edit' : 'Tambah'; ?> Jenis Pembayaran</h3>
                        <form method="POST" action="kelola_jenis_pembayaran.php">
                            <label for="nama_pembayaran">Nama Pembayaran:</label>
                            <input type="text" id="nama_pembayaran" name="nama_pembayaran" value="<?php echo htmlspecialchars($edit_jenis_data['nama_pembayaran'] ?? ''); ?>" required>
                            
                            <input type="hidden" name="id_jenis_pembayaran" value="<?php echo htmlspecialchars($edit_jenis_data['id_jenis_pembayaran'] ?? ''); ?>">
                            
                            <div style="display: flex; gap: 10px; margin-top: 15px;">
                                <button type="submit" name="submit_jenis">Simpan</button>
                                <a href="kelola_jenis_pembayaran.php" class="btn-secondary">Batal</a>
                            </div>
                        </form>
                    </div>

                    <div class="form-section">
                        <h3><?php echo $edit_nominal_data ? 'Edit' : 'Tambah'; ?> Nominal Pembayaran</h3>
                        <form method="POST" action="kelola_jenis_pembayaran.php">
                            <label for="jenis_pembayaran_nominal">Jenis Pembayaran:</label>
                            <select id="jenis_pembayaran_nominal" name="id_jenis_pembayaran" required>
                                <option value="">-- Pilih Jenis --</option>
                                <?php foreach ($daftar_jenis_pembayaran as $jenis): ?>
                                    <option value="<?php echo htmlspecialchars($jenis['id_jenis_pembayaran']); ?>" <?php echo ($edit_nominal_data && $edit_nominal_data['id_jenis_pembayaran'] == $jenis['id_jenis_pembayaran']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($jenis['nama_pembayaran']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label for="nominal">Nominal:</label>
                            <input type="number" id="nominal" name="nominal" value="<?php echo htmlspecialchars($edit_nominal_data['nominal'] ?? ''); ?>" required>

                            <label for="deskripsi">Deskripsi:</label>
                            <input type="text" id="deskripsi" name="deskripsi" value="<?php echo htmlspecialchars($edit_nominal_data['deskripsi'] ?? ''); ?>" placeholder="e.g. Tahun 2024/2025" required>
                            
                            <input type="hidden" name="id_nominal" value="<?php echo htmlspecialchars($edit_nominal_data['id'] ?? ''); ?>">
                            
                            <div style="display: flex; gap: 10px; margin-top: 15px;">
                                <button type="submit" name="submit_nominal">Simpan</button>
                                <a href="kelola_jenis_pembayaran.php" class="btn-secondary">Batal</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <br>
                
                <div class="table-section">
                    <h3>Daftar Jenis Pembayaran</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nama Pembayaran</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daftar_jenis_pembayaran as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['id_jenis_pembayaran']); ?></td>
                                <td><?php echo htmlspecialchars($item['nama_pembayaran']); ?></td>
                                <td>
                                    <a href="kelola_jenis_pembayaran.php?edit_jenis=<?php echo $item['id_jenis_pembayaran']; ?>">Edit</a> | 
                                    <a href="kelola_jenis_pembayaran.php?hapus_jenis=<?php echo $item['id_jenis_pembayaran']; ?>" onclick="return confirm('Apakah Anda yakin ingin menghapus jenis pembayaran ini? Menghapus jenis pembayaran juga akan menghapus semua nominal yang terkait.')">Hapus</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <br>

                <div class="table-section">
                    <h3>Daftar Nominal Pembayaran</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID Nominal</th>
                                <th>Jenis Pembayaran</th>
                                <th>Nominal</th>
                                <th>Deskripsi</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daftar_nominal_pembayaran as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['id']); ?></td>
                                <td><?php echo htmlspecialchars($item['nama_pembayaran']); ?></td>
                                <td>Rp <?php echo number_format($item['nominal'], 0, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($item['deskripsi']); ?></td>
                                <td>
                                    <a href="kelola_jenis_pembayaran.php?edit_nominal=<?php echo $item['id']; ?>">Edit</a> | 
                                    <a href="kelola_jenis_pembayaran.php?hapus_nominal=<?php echo $item['id']; ?>" onclick="return confirm('Apakah Anda yakin ingin menghapus nominal ini?')">Hapus</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php mysqli_close($conn); ?>