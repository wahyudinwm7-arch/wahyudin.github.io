<?php
session_start();
// Pastikan path ke koneksi.php sudah benar
include '../includes/koneksi.php';

// Verifikasi: Pastikan pengguna terautentikasi dan memiliki hak akses admin
if (!isset($_SESSION['nama_pengguna']) || $_SESSION['hak_akses'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Ambil data pengguna dari database
$stmt_select = $conn->prepare("SELECT id_pengguna, nama_pengguna, hak_akses FROM pengguna");
$stmt_select->execute();
$result_select = $stmt_select->get_result();
$data_pengguna = [];
while ($row = $result_select->fetch_assoc()) {
    $data_pengguna[] = $row;
}
$stmt_select->close();

// Logika untuk menampilkan pesan
$pesan = isset($_GET['pesan']) ? htmlspecialchars($_GET['pesan']) : '';
$status = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';

// Data untuk Edit (jika ada)
$is_edit = false;
$edit_data = [
    'id_pengguna' => '',
    'nama_pengguna' => '',
    'hak_akses' => ''
];
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id_edit = htmlspecialchars($_GET['id']);
    $stmt_edit = $conn->prepare("SELECT id_pengguna, nama_pengguna, hak_akses FROM pengguna WHERE id_pengguna = ?");
    $stmt_edit->bind_param("i", $id_edit);
    $stmt_edit->execute();
    $result_edit = $stmt_edit->get_result();
    if ($result_edit->num_rows > 0) {
        $edit_data = $result_edit->fetch_assoc();
        $is_edit = true;
    }
    $stmt_edit->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pengguna - Aplikasi Pembayaran Sekolah</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root {
            --sidebar-width: 280px;
            --primary-color: #007bff;
            --sidebar-bg: #212529; /* Darker tone */
            --sidebar-link: #adb5bd;
            --sidebar-hover: #343a40;
        }
        body {
            background-color: #f8f9fa; /* Light gray background */
            font-family: 'Inter', sans-serif;
        }
        .sidebar {
            width: var(--sidebar-width);
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            background-color: var(--sidebar-bg);
            padding-top: 0;
            color: white;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 20px;
            background-color: #1a1d20; /* Slightly darker header */
            color: white;
            text-align: center;
        }
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar-menu li a {
            padding: 12px 20px;
            text-decoration: none;
            font-size: 16px;
            color: var(--sidebar-link);
            display: flex;
            align-items: center;
            transition: all 0.3s;
        }
        .sidebar-menu li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        .sidebar-menu li a:hover,
        .sidebar-menu li a.active {
            background-color: var(--sidebar-hover);
            color: white;
            border-left: 4px solid var(--primary-color);
        }
        .content {
            margin-left: var(--sidebar-width);
            padding: 30px;
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }
        .card-header {
            background-color: var(--primary-color);
            color: white;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
            font-weight: 600;
        }
        .table thead th {
            background-color: #e9ecef;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #0056b3;
        }
        /* Badge styling to match Bootstrap 5 */
        .badge-primary { background-color: var(--primary-color) !important; color: white; }
        .badge-secondary { background-color: #6c757d !important; color: white; }
    </style>
</head>
<body>

<div class="main-container d-flex">
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h3><i class="fas fa-graduation-cap"></i> Pembayaran Siswa</h3>
        </div>
        <ul class="sidebar-menu">
            <!-- Menyesuaikan dengan daftar menu yang Anda berikan -->
            <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="pembayaran.php"><i class="fas fa-cash-register"></i> Kelola Pembayaran</a></li>
            <li><a href="pengeluaran.php"><i class="fas fa-money-bill-wave"></i> Kelola Pengeluaran</a></li>
            <li><a href="siswa.php"><i class="fas fa-user-graduate"></i> Kelola Data Siswa</a></li>
            <li><a href="kelas.php"><i class="fas fa-school"></i> Kelola Data Kelas</a></li>
            <li><a href="tahun_ajaran.php"><i class="fas fa-calendar-alt"></i> Kelola Tahun Ajaran</a></li>
            <li><a href="kelola_set_biaya.php"><i class="fas fa-cogs"></i> Kelola Biaya Pembayaran</a></li>
            <li><a href="laporan.php"><i class="fas fa-chart-line"></i> Lihat Laporan</a></li>
            <li><a href="laporan_per_kelas.php"><i class="fas fa-table"></i> Laporan Per Kelas</a></li>
            <li><a href="laporan_tunggakan.php"><i class="fas fa-exclamation-triangle"></i> Laporan Tunggakan</a></li>
            <li><a href="pengguna.php" class="active"><i class="fas fa-users"></i> Kelola Pengguna</a></li>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
    <!-- End Sidebar -->

    <!-- Content Area -->
    <div class="content">
        <h1 class="mb-4 text-dark"><i class="fas fa-users me-2"></i> Kelola Pengguna</h1>

        <?php if ($pesan): ?>
        <!-- Alert menggunakan kelas Bootstrap 5 -->
        <div class="alert alert-<?php echo $status == 'sukses' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
            <?php echo $pesan; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Form Tambah/Edit Pengguna -->
            <div class="col-lg-4 col-md-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-user-plus me-2"></i>
                        <?php echo $is_edit ? 'Edit Pengguna: ' . $edit_data['nama_pengguna'] : 'Tambah Pengguna Baru'; ?>
                    </div>
                    <div class="card-body">
                        <!-- Menggunakan action yang benar: proses_pengguna.php, asumsi berada di direktori yang sama -->
                        <form action="../proses/proses_pengguna.php" method="POST">
                            <input type="hidden" name="action" value="<?php echo $is_edit ? 'edit' : 'tambah'; ?>">
                            <input type="hidden" name="id_pengguna" value="<?php echo $edit_data['id_pengguna']; ?>">

                            <div class="mb-3">
                                <label for="nama_pengguna" class="form-label">Nama Pengguna (Username)</label>
                                <input type="text" class="form-control" id="nama_pengguna" name="nama_pengguna" value="<?php echo $edit_data['nama_pengguna']; ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="kata_sandi" class="form-label">Kata Sandi (Password)</label>
                                <input type="password" class="form-control" id="kata_sandi" name="kata_sandi" <?php echo !$is_edit ? 'required' : ''; ?> placeholder="<?php echo $is_edit ? 'Kosongkan jika tidak ingin diubah' : 'Masukkan kata sandi'; ?>">
                            </div>

                            <div class="mb-3">
                                <label for="hak_akses" class="form-label">Hak Akses</label>
                                <select class="form-select" id="hak_akses" name="hak_akses" required>
                                    <option value="">-- Pilih Hak Akses --</option>
                                    <option value="admin" <?php echo $edit_data['hak_akses'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    <option value="petugas" <?php echo $edit_data['hak_akses'] == 'petugas' ? 'selected' : ''; ?>>Petugas</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-save me-1"></i> <?php echo $is_edit ? 'Simpan Perubahan' : 'Tambah Pengguna'; ?>
                            </button>
                            <?php if ($is_edit): ?>
                                <a href="pengguna.php" class="btn btn-outline-secondary w-100 mt-2">Batal Edit</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Tabel Daftar Pengguna -->
            <div class="col-lg-8 col-md-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-list me-2"></i> Daftar Semua Pengguna
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">ID</th>
                                        <th>Nama Pengguna</th>
                                        <th style="width: 120px;">Hak Akses</th>
                                        <th style="width: 150px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($data_pengguna)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center">Belum ada data pengguna.</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($data_pengguna as $pengguna): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($pengguna['id_pengguna']); ?></td>
                                            <td><?php echo htmlspecialchars($pengguna['nama_pengguna']); ?></td>
                                            <td>
                                                <span class="badge rounded-pill <?php echo $pengguna['hak_akses'] == 'admin' ? 'bg-primary' : 'bg-secondary'; ?> py-2 px-3">
                                                    <?php echo htmlspecialchars(ucfirst($pengguna['hak_akses'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="pengguna.php?action=edit&id=<?php echo $pengguna['id_pengguna']; ?>" class="btn btn-sm btn-warning me-2" title="Edit Pengguna">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <button 
                                                    class="btn btn-sm btn-danger" 
                                                    onclick="konfirmasiHapus(<?php echo $pengguna['id_pengguna']; ?>, '<?php echo htmlspecialchars($pengguna['nama_pengguna']); ?>')"
                                                    title="Hapus Pengguna">
                                                    <i class="fas fa-trash-alt"></i> Hapus
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- End Content Area -->
</div>

<!-- Modal Konfirmasi Hapus (Menggunakan Bootstrap 5) -->
<div class="modal fade" id="hapusModal" tabindex="-1" aria-labelledby="hapusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="hapusModalLabel"><i class="fas fa-exclamation-triangle me-2"></i> Konfirmasi Penghapusan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Apakah Anda yakin ingin menghapus pengguna **<span id="namaPenggunaHapus"></span>**?
                Tindakan ini tidak dapat dibatalkan.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <!-- Link Hapus akan diarahkan ke proses_pengguna.php -->
                <a id="linkHapus" class="btn btn-danger"><i class="fas fa-trash-alt me-1"></i> Hapus Permanen</a>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Fungsi JavaScript diperbarui untuk menggunakan Modal Bootstrap 5
    function konfirmasiHapus(id, nama) {
        document.getElementById('namaPenggunaHapus').textContent = nama;
        // Mengubah path action ke proses_pengguna.php, diasumsikan berada di folder yang sama (pages/)
        document.getElementById('linkHapus').href = 'proses_pengguna.php?action=hapus&id_pengguna=' + id;
        var hapusModal = new bootstrap.Modal(document.getElementById('hapusModal'));
        hapusModal.show();
    }
</script>

</body>
</html>