<?php
session_start();
include '../includes/koneksi.php';

// Cek apakah pengguna sudah login
if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: login.php");
    exit();
}

// Inisialisasi query dasar untuk daftar siswa menggunakan prepared statement
$sql = "
    SELECT 
        s.*, 
        k.nama_kelas, 
        ta.nama_tahun,
        s.status_spp /* <<< PERBAIKAN: Mengambil kolom status_spp */
    FROM 
        siswa s
    JOIN 
        kelas k ON s.id_kelas = k.id_kelas
    JOIN 
        tahun_ajaran ta ON s.id_tahun_ajaran = ta.id_tahun_ajaran
";

$params = [];
$types = '';

// Tambahkan logika pencarian jika ada input dari form
if (isset($_GET['cari']) && !empty($_GET['cari'])) {
    $keyword = '%' . $_GET['cari'] . '%';
    // Menambahkan pencarian berdasarkan NISN juga
    $sql .= " WHERE s.nama_lengkap LIKE ? OR s.nisn LIKE ?";
    $params[] = $keyword;
    $params[] = $keyword;
    $types .= 'ss';
}

// Tambahkan pengurutan
$sql .= " ORDER BY s.nama_lengkap ASC";

// Siapkan dan jalankan query
$stmt_siswa = $conn->prepare($sql);

if (!$stmt_siswa) {
    // Handle error jika prepared statement gagal
    error_log("Error preparing student query: " . $conn->error);
    $result_siswa = false;
} else {
    if (!empty($params)) {
        // Menggunakan reference untuk bind_param
        $bind_params = array_merge([$types], $params);
        $ref_params = [];
        foreach ($bind_params as $key => $value) {
            $ref_params[$key] = &$bind_params[$key];
        }
        call_user_func_array([$stmt_siswa, 'bind_param'], $ref_params);
    }
    $stmt_siswa->execute();
    $result_siswa = $stmt_siswa->get_result();
}


// Ambil data kelas dan tahun ajaran untuk dropdown
$query_kelas = "SELECT * FROM kelas ORDER BY nama_kelas ASC";
$result_kelas = $conn->query($query_kelas);

$query_tahun = "SELECT * FROM tahun_ajaran ORDER BY nama_tahun DESC";
$result_tahun = $conn->query($query_tahun);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Data Siswa | Aplikasi Pembayaran Siswa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ====================================================================== */
        /* Gaya Dasar & Layout (Konsisten) */
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
        
        .content-header p {
            color: var(--secondary-color);
            margin-top: 5px;
        }

        .content-header i {
            margin-right: 10px;
        }
        
        /* ------------------- Card & Form ------------------- */
        .card {
            background: white;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        h3 {
            color: var(--dark-text);
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        h3 i {
            margin-right: 8px;
            color: var(--primary-color);
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--dark-text);
        }

        input[type="text"], input[type="file"], select {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
            transition: border-color 0.3s, box-shadow 0.3s;
            font-size: 1rem;
        }

        input[type="text"]:focus, select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
            outline: none;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.3s, transform 0.1s;
            font-weight: 600;
            font-size: 1rem;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }

        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
        }

        /* Form Layout Tambah Siswa */
        .card form.form-input {
            display: grid; 
            grid-template-columns: repeat(2, 1fr); 
            gap: 15px;
        }
        .card form.form-input .form-group:last-child {
            grid-column: span 2;
            text-align: right;
        }
        /* Penyesuaian agar group terakhir sebelum tombol mencakup 2 kolom */
        .card form.form-input .form-group:nth-last-child(2) {
            grid-column: span 2;
        }

        /* Penyesuaian untuk import */
        .card form[enctype="multipart/form-data"] {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }
        .card form[enctype="multipart/form-data"] .form-group {
            flex-grow: 1;
            margin-bottom: 0;
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
        .status-message.warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        /* ------------------- Pencarian & Tabel ------------------- */
        .search-table-container {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            flex-wrap: wrap; 
        }

        .search-form-side {
            width: 280px; 
            flex-shrink: 0;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        }
        .search-form-side form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .search-form-side input[type="text"] {
            width: 100%;
        }

        .table-container-main {
            flex-grow: 1; 
            overflow-x: auto; 
        }

        /* Tabel Data */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
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

        /* Gaya Tombol Aksi */
        .aksi-btn {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin: 2px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9em;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .aksi-btn:hover {
            opacity: 0.8;
        }
        .aksi-btn.bayar {
            background-color: var(--success-color); /* Ubah ke hijau untuk Bayar */
            color: white;
        }
        .aksi-btn.edit {
            background-color: var(--warning-color);
            color: #333;
        }
        .aksi-btn.hapus {
            background-color: var(--danger-color);
            color: white;
        }
        .data-table td:last-child {
            white-space: nowrap; 
        }

        /* ------------------- Modal Kustom ------------------- */
        .modal {
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0;
            top: 0;
            width: 100%; 
            height: 100%; 
            background-color: rgba(0,0,0,0.6); 
            padding-top: 50px;
        }
        .modal-content {
            background-color: #fff;
            margin: 10% auto; 
            padding: 30px;
            border-radius: 10px;
            width: 90%; 
            max-width: 450px;
            text-align: center;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            animation: fadeIn 0.3s;
        }
        @keyframes fadeIn {
            from {opacity: 0; transform: translateY(-20px);}
            to {opacity: 1; transform: translateY(0);}
        }
        .modal-content h4 {
            color: var(--danger-color);
            margin-top: 0;
            font-weight: 700;
        }
        .modal-content p {
            margin-bottom: 25px;
        }
        .modal-buttons {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        .modal-buttons button, .modal-buttons a {
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            border-radius: 5px;
            text-decoration: none; 
            font-weight: 600;
            transition: background-color 0.2s;
        }
        .modal-buttons .btn-cancel {
            background-color: #f1f1f1;
            color: var(--dark-text);
        }
        .modal-buttons .btn-delete {
            background-color: var(--danger-color);
            color: white;
        }
        .modal-buttons .btn-delete:hover {
            background-color: #c82333;
        }

        /* Media Queries untuk responsivitas */
        @media (max-width: 992px) {
            .card form.form-input {
                grid-template-columns: 1fr;
            }
            .card form.form-input .form-group:last-child,
            .card form.form-input .form-group:nth-last-child(2) {
                grid-column: span 1;
                text-align: left;
            }
            .search-table-container {
                flex-direction: column;
            }
            .search-form-side {
                width: 100%;
                order: -1; /* Pindahkan form pencarian ke atas pada mobile */
            }
            .card form[enctype="multipart/form-data"] {
                flex-direction: column;
                align-items: stretch;
            }
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
                <h2><i class="fas fa-user-graduate"></i> Kelola Data Siswa</h2>
                <p>Tambah, cari, edit, dan hapus data siswa sekolah.</p>
            </div>

            <?php 
            if (isset($_GET['status']) || isset($_GET['import_sukses'])): 
                $status = isset($_GET['status']) ? $_GET['status'] : '';
                $import_sukses = isset($_GET['import_sukses']) ? $_GET['import_sukses'] : 0;
                $import_gagal = isset($_GET['import_gagal']) ? $_GET['import_gagal'] : 0;
            ?>
                <div class="status-message <?php echo ($status == 'gagal_hapus' || $status == 'gagal_upload' || $status == 'gagal_buka_file' || $status == 'error') ? 'danger' : (($import_gagal > 0) ? 'warning' : 'success'); ?>">
                    <?php if ($import_sukses > 0): ?>
                        <p><i class="fas fa-check-circle"></i> Import berhasil! **<?php echo $import_sukses; ?>** data siswa berhasil ditambahkan.</p>
                        <?php if ($import_gagal > 0): ?>
                            <p><i class="fas fa-exclamation-triangle"></i> Ada **<?php echo $import_gagal; ?>** data yang gagal diimpor (mungkin karena data duplikat atau ID tidak valid).</p>
                        <?php endif; ?>
                    <?php elseif ($status == 'sukses_tambah'): ?>
                        <p><i class="fas fa-check-circle"></i> Siswa berhasil ditambahkan!</p>
                    <?php elseif ($status == 'sukses_edit'): ?>
                        <p><i class="fas fa-check-circle"></i> Data Siswa berhasil diperbarui!</p>
                    <?php elseif ($status == 'sukses_hapus'): ?>
                        <p><i class="fas fa-check-circle"></i> Siswa berhasil dihapus!</p>
                    <?php elseif ($status == 'gagal_hapus'): ?>
                        <p><i class="fas fa-exclamation-circle"></i> GAGAL: Siswa tidak dapat dihapus karena sudah memiliki riwayat pembayaran.</p>
                    <?php elseif ($status == 'gagal_upload' || $status == 'gagal_buka_file' || $status == 'error'): ?>
                        <p><i class="fas fa-times-circle"></i> Terjadi kesalahan saat memproses data. Mohon coba lagi.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="card">
                <h3><i class="fas fa-user-plus"></i> Tambah Siswa Baru</h3>
                <form action="../proses/proses_siswa.php" method="POST" class="form-input">
                    <div class="form-group">
                        <label for="nama_lengkap">Nama Lengkap:</label>
                        <input type="text" id="nama_lengkap" name="nama_lengkap" required placeholder="Cth: Ahmad Fikri">
                    </div>
                    <div class="form-group">
                        <label for="nisn">NISN:</label>
                        <input type="text" id="nisn" name="nisn" required placeholder="Cth: 0012345678">
                    </div>
                    
                    <div class="form-group">
                        <label for="status_biaya">Status SPP (Biaya/Diskon):</label>
                        <select id="status_biaya" name="status_biaya" required>
                            <option value="Normal">Normal</option>
                            <option value="Diskon">Diskon (Umum)</option>
                            <option value="Diskon Yatim">Diskon Yatim</option> 
                        </select>
                        <small style="color: var(--secondary-color);">Pilih kategori SPP yang berlaku untuk siswa ini.</small>
                    </div>
                    <div class="form-group">
                        <label for="id_kelas">Kelas:</label>
                        <select id="id_kelas" name="id_kelas" required>
                            <option value="">-- Pilih Kelas --</option>
                            <?php 
                            if ($result_kelas && $result_kelas->num_rows > 0) {
                                $result_kelas->data_seek(0);
                                while ($row = $result_kelas->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['id_kelas']) . '">' . htmlspecialchars($row['nama_kelas']) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="id_tahun_ajaran">Tahun Ajaran:</label>
                        <select id="id_tahun_ajaran" name="id_tahun_ajaran" required>
                            <option value="">-- Pilih Tahun Ajaran --</option>
                            <?php 
                            if ($result_tahun && $result_tahun->num_rows > 0) {
                                $result_tahun->data_seek(0);
                                while ($row = $result_tahun->fetch_assoc()) {
                                    $selected = ($row['aktif'] == 1) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($row['id_tahun_ajaran']) . '" ' . $selected . '>' . htmlspecialchars($row['nama_tahun']) . (($row['aktif'] == 1) ? ' (Aktif)' : '') . '</option>';
                                }
                            } 
                            ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" name="tambah" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Siswa</button>
                    </div>
                </form>
            </div>
            
            <div class="card">
                <h3><i class="fas fa-file-excel"></i> Import Siswa dari File CSV</h3>
                <form action="../proses/proses_import.php" method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="file_import">Pilih File CSV:</label>
                        <input type="file" id="file_import" name="file_import" accept=".csv" required>
                    </div>
                    <button type="submit" name="import" class="btn btn-secondary"><i class="fas fa-upload"></i> Import Data</button>
                </form>
                <p style="margin-top: 15px; font-size: 0.9em; color: var(--secondary-color);">
                    <i class="fas fa-info-circle"></i> Pastikan format file CSV Anda memiliki kolom: **nama_lengkap, nisn, id_kelas, status_spp, id_tahun_ajaran** (sesuai ID/Value di database).
                </p>
            </div>
            
            <hr style="border: 0; border-top: 1px solid #ddd; margin: 30px 0;">

            <div class="search-table-container">
                <div class="search-form-side">
                    <h3><i class="fas fa-search"></i> Cari Siswa</h3>
                    <form action="siswa.php" method="GET">
                        <input type="text" name="cari" placeholder="Cari nama atau NISN siswa..." value="<?php echo isset($_GET['cari']) ? htmlspecialchars($_GET['cari']) : ''; ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Cari</button>
                        <a href="siswa.php" class="btn btn-secondary" style="text-align: center;"><i class="fas fa-sync-alt"></i> Reset Pencarian</a>
                    </form>
                </div>

                <div class="table-container-main">
                    <div class="card" style="padding: 0; box-shadow: none;">
                        <h3 style="padding: 20px; margin-bottom: 0;"><i class="fas fa-list"></i> Daftar Siswa <?php echo isset($_GET['cari']) && !empty($_GET['cari']) ? '(Hasil Pencarian)' : ''; ?></h3>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>NISN</th>
                                    <th>Nama Lengkap</th>
                                    <th>Kelas</th>
                                    <th>Tahun Ajaran</th>
                                    <th>Status SPP</th> <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                if ($result_siswa && $result_siswa->num_rows > 0) {
                                    while ($data_siswa = $result_siswa->fetch_assoc()):
                                ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo htmlspecialchars($data_siswa['nisn']); ?></td>
                                        <td><?php echo htmlspecialchars($data_siswa['nama_lengkap']); ?></td>
                                        <td><?php echo htmlspecialchars($data_siswa['nama_kelas']); ?></td>
                                        <td><?php echo htmlspecialchars($data_siswa['nama_tahun']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($data_siswa['status_spp'] ?? 'Normal'); ?></strong></td> <td>
                                            <a href="pembayaran.php?id_siswa=<?php echo htmlspecialchars($data_siswa['id_siswa']); ?>" class="aksi-btn bayar" title="Lihat Riwayat & Bayar">
                                                <i class="fas fa-receipt"></i> Bayar
                                            </a>
                                            <a href="edit_siswa.php?id=<?php echo htmlspecialchars($data_siswa['id_siswa']); ?>" class="aksi-btn edit" title="Edit Data Siswa">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <button type="button" class="aksi-btn hapus" title="Hapus Siswa" onclick="showDeleteModal(<?php echo htmlspecialchars($data_siswa['id_siswa']); ?>, '<?php echo htmlspecialchars($data_siswa['nama_lengkap']); ?>')">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </td>
                                    </tr>
                                <?php
                                    endwhile;
                                } else {
                                    echo "<tr><td colspan='7' style='text-align: center; padding: 20px;'>"; // UBAH colspan menjadi 7
                                    echo isset($_GET['cari']) && !empty($_GET['cari']) ? "Tidak ada siswa dengan nama/NISN **\"" . htmlspecialchars($_GET['cari']) . "\"** ditemukan." : "Tidak ada data siswa ditemukan.";
                                    echo "</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h4><i class="fas fa-trash-alt"></i> Konfirmasi Penghapusan Siswa</h4>
            <p>Anda yakin ingin menghapus siswa: <strong id="studentName"></strong>?</p>
            <p style="color: var(--danger-color); font-size: 0.9em; font-weight: 500;"><i class="fas fa-exclamation-triangle"></i> Penghapusan akan gagal jika siswa ini sudah memiliki riwayat pembayaran.</p>
            <div class="modal-buttons">
                <button class="btn-cancel" onclick="closeDeleteModal()">Batal</button>
                <a id="confirmDelete" href="#" class="btn-delete"><i class="fas fa-check"></i> Ya, Hapus</a>
            </div>
        </div>
    </div>
    <script>
        // Logika untuk menampilkan dan menyembunyikan modal kustom
        const modal = document.getElementById('deleteModal');
        const confirmDeleteLink = document.getElementById('confirmDelete');
        const studentNameDisplay = document.getElementById('studentName');

        function showDeleteModal(id_siswa, nama_siswa) {
            studentNameDisplay.textContent = nama_siswa;
            confirmDeleteLink.href = '../proses/proses_siswa.php?hapus=' + id_siswa;
            modal.style.display = 'flex'; // Menggunakan flex untuk centering yang lebih baik
        }

        function closeDeleteModal() {
            modal.style.display = 'none';
        }

        // Tutup modal jika user mengklik di luar area modal
        window.onclick = function(event) {
            if (event.target == modal) {
                closeDeleteModal();
            }
        }
    </script>
</body>
</html>
<?php
// Tutup statement dan koneksi database
if ($stmt_siswa) $stmt_siswa->close();
if ($result_kelas) $result_kelas->free();
if ($result_tahun) $result_tahun->free();
if ($conn) $conn->close();
?>