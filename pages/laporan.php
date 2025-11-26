<?php
session_start();
// Harap pastikan file koneksi.php ada dan berisi koneksi database yang valid
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

// --- 2. Menangkap Input Filter ---
$tanggal_awal = isset($_GET['tanggal_awal']) ? trim($_GET['tanggal_awal']) : '';
$tanggal_akhir = isset($_GET['tanggal_akhir']) ? trim($_GET['tanggal_akhir']) : '';
$search_nama_siswa = isset($_GET['nama_siswa']) ? trim($_GET['nama_siswa']) : '';

// *** PERUBAHAN UTAMA UNTUK FILTER KELAS: Tangkap sebagai array ***
// Jika nama_kelas dikirimkan (bisa berupa array jika banyak checkbox tercentang)
$search_nama_kelas = isset($_GET['nama_kelas']) ? $_GET['nama_kelas'] : []; 
// Pastikan $search_nama_kelas adalah array
if (!is_array($search_nama_kelas)) {
    $search_nama_kelas = [$search_nama_kelas];
}
$search_nama_kelas = array_map('trim', $search_nama_kelas);
// *** AKHIR PERUBAHAN FILTER KELAS ***

$search_jenis_transaksi = isset($_GET['jenis_transaksi']) ? trim($_GET['jenis_transaksi']) : '';
$search_jenis_pembayaran = isset($_GET['jenis_pembayaran']) ? trim($_GET['jenis_pembayaran']) : '';

$riwayat_transaksi = [];
$total_pemasukan = 0;
$total_pengeluaran = 0;

// --- 3. Mengambil Daftar Kelas Tersedia (Untuk Checkbox) ---
$daftar_kelas = [];
$query_kelas = "SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas ASC";
$result_kelas = $conn->query($query_kelas);

if ($result_kelas) {
    while ($row = $result_kelas->fetch_assoc()) {
        $daftar_kelas[] = $row;
    }
}
// -----------------------------------------------------------------


// --- 4. Konstruksi Query SQL ---
$query_transaksi = "
    SELECT
        t.tanggal_transaksi,
        t.id_transaksi,
        s.nama_lengkap AS nama_siswa,
        s.nisn,
        k.nama_kelas,
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
        kelas k ON s.id_kelas = k.id_kelas
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

// Filter Nama Siswa (Kolom Filter)
if (!empty($search_nama_siswa)) {
    $where_clauses[] = "(s.nama_lengkap LIKE ? AND t.id_siswa IS NOT NULL)"; 
    $params[] = '%' . $search_nama_siswa . '%';
    $types .= 's';
}

// *** PERUBAHAN UNTUK KLAUSA WHERE KELAS (Menggunakan IN untuk multi-pilihan) ***
if (!empty($search_nama_kelas)) {
    // Buat placeholder untuk klausa IN (misalnya: ?, ?, ?)
    $placeholders = implode(',', array_fill(0, count($search_nama_kelas), '?'));
    
    // Klausa akan menjadi: k.nama_kelas IN ('Kelas XA', 'Kelas XB')
    // Memastikan hanya transaksi MASUK yang terikat dengan kelas
    $where_clauses[] = "(k.nama_kelas IN ($placeholders) AND t.id_siswa IS NOT NULL)";
    
    // Tambahkan setiap nama kelas ke array parameter
    foreach ($search_nama_kelas as $nama_kelas) {
        $params[] = $nama_kelas;
        $types .= 's';
    }
}
// *** AKHIR PERUBAHAN KLAUSA WHERE KELAS ***


// Filter Jenis Transaksi (Kolom Filter - Dropdown)
if (!empty($search_jenis_transaksi)) {
    $where_clauses[] = "t.jenis_transaksi = ?";
    $params[] = $search_jenis_transaksi;
    $types .= 's';
}

// Filter Jenis Pembayaran (Kolom Filter)
if (!empty($search_jenis_pembayaran)) {
    $where_clauses[] = "t.jenis_pembayaran LIKE ?";
    $params[] = '%' . $search_jenis_pembayaran . '%';
    $types .= 's';
}

// Gabungkan semua klausa WHERE
if (!empty($where_clauses)) {
    $query_transaksi .= " WHERE " . implode(" AND ", $where_clauses);
}

// Tambahkan urutan
$query_transaksi .= " ORDER BY t.tanggal_transaksi DESC, t.id_transaksi DESC";

// --- 5. Eksekusi Query ---
$stmt = $conn->prepare($query_transaksi);
if ($stmt) {
    if (!empty($params)) {
        // Dynamic binding for prepared statements
        $bind_names[] = $types;
        for ($i=0; $i<count($params); $i++) {
            $bind_names[] = &$params[$i];
        }
        // suppress warnings/errors if parameters don't match
        @call_user_func_array(array($stmt,'bind_param'), $bind_names); 
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
} else {
    if(isset($conn)) {
        die("Error dalam menyiapkan statement: " . $conn->error);
    } else {
        die("Koneksi database gagal atau tidak ditemukan.");
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Keuangan | Aplikasi Pembayaran Siswa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        /* ====================================================================== */
        /* VARIABEL, GAYA DASAR & LAYOUT (Disalin dari Dashboard.php) */
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
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            height: 100%;
        }
        /* ... (Gaya Sidebar lainnya tetap sama) ... */
        .sidebar-header {
            text-align: center;
            padding: 10px 20px 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .sidebar-header h2 { font-size: 1.2rem; margin: 0; font-weight: 600; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li a { 
            display: flex; align-items: center; padding: 12px 20px; 
            color: #ecf0f1; text-decoration: none; transition: background-color 0.3s, color 0.3s; 
            font-size: 0.95rem; 
        }
        .sidebar-menu li a:hover { background-color: var(--sidebar-hover); color: white; }
        .sidebar-menu li a.active { 
            background-color: var(--primary-color); color: white; 
            border-left: 5px solid #3498db; 
        }
        .sidebar-menu li a i { margin-right: 10px; font-size: 1.1rem; }


        /* ------------------- Content ------------------- */
        .content-wrapper {
            flex-grow: 1;
            margin-left: 250px;
            padding: 30px;
        }

        .content-header {
            margin-bottom: 30px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 15px;
        }
        .content-header h2 { font-size: 1.8rem; color: var(--dark-text); margin: 0; font-weight: 600; }

        /* ====================================================================== */
        /* GAYA KHUSUS LAPORAN */
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

        /* Filter & Form Styling (Filter Utama) */
        .filter-container {
            display: flex;
            flex-wrap: wrap; 
            align-items: flex-end;
            gap: 15px; 
            margin-bottom: 15px;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        .filter-group label {
            font-size: 0.9em;
            color: #555;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .filter-container input[type="date"] {
            padding: 8px 10px; 
            border: 1px solid #ccc;
            border-radius: 6px; 
            transition: border-color 0.3s;
            min-width: 160px; 
            height: 38px; 
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
        }
        .filter-container input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.2);
        }
        
        /* Button Styling */
        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            display: inline-block;
            transition: background-color 0.3s;
            font-weight: 600;
            height: 38px;
            box-sizing: border-box;
            line-height: 1.4;
            text-decoration: none;
            font-size: 0.95rem;
        }
        .btn-primary { background-color: var(--primary-color); color: white; }
        .btn-primary:hover { background-color: #0056b3; }
        .btn-reset { background-color: var(--secondary-color); color: white; }
        .btn-reset:hover { background-color: #5a6268; }
        .btn-export { background-color: var(--success-color); color: white; }
        .btn-export:hover { background-color: #21a65a; }
        .btn-small {
            padding: 5px 8px;
            font-size: 0.8em;
            border-radius: 4px;
            background-color: var(--info-color); 
            height: auto;
        }
        .btn-small:hover { background-color: #2980b9; }

        /* Table Styling */
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
        }
        .data-table tbody tr:nth-child(even) { background-color: #f8f9fa; } 
        .data-table tbody tr:hover { background-color: #e9ecef; } 
        .data-table td {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        /* Filter Kolom Styling */
        .filter-row td {
            padding: 5px 15px !important;
            vertical-align: middle !important;
            background-color: #f0f0f0; /* Latar belakang untuk membedakan baris filter */
        }
        .filter-row input[type="text"],
        .filter-row select {
            width: 100%;
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 0.85rem;
            height: 30px; /* Lebih kecil */
            font-family: 'Poppins', sans-serif;
        }
        
        /* Summary Styling */
        .finance-summary {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            padding: 25px; 
            gap: 20px;
            background-color: #fff;
        }
        .finance-summary h3 { width: 100%; }
        .summary-item {
            flex: 1 1 30%; 
            min-width: 250px;
            padding: 15px;
            border-radius: 8px;
            background-color: #f8f9fa;
            border: 1px solid #eee;
        }
        .summary-item .total-label { display: block; font-size: 0.9em; color: #777; margin-bottom: 5px; }
        .summary-item .total-value { font-size: 1.6em; font-weight: 700; line-height: 1.2; }
        .saldo-akhir {
            width: 100%;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px dashed #ccc; 
            text-align: right;
        }
        .saldo-akhir .total-label { font-size: 1.2em; font-weight: 600; color: var(--dark-text); }
        .saldo-akhir .total-value { font-size: 2em; font-weight: 800; color: var(--info-color); }


        /* GAYA KHUSUS CHECKBOX DROPDOWN KELAS */
        .checkbox-dropdown {
            position: relative;
        }
        .checkbox-dropdown-btn {
            background-color: #fff;
            border: 1px solid #ccc;
            border-radius: 6px;
            padding: 8px 10px;
            cursor: pointer;
            width: 100%;
            text-align: left;
            height: 38px;
            box-sizing: border-box;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.95rem;
            color: #333;
            font-family: 'Poppins', sans-serif;
        }
        .checkbox-dropdown-btn:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.2);
        }
        .checkbox-dropdown-content {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            z-index: 10;
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            padding: 10px;
            max-height: 200px;
            overflow-y: auto;
            min-width: 200px;
            margin-top: 2px;
        }
        .checkbox-item {
            margin-bottom: 5px;
            display: block;
            cursor: pointer;
            font-size: 0.9em;
        }
        .checkbox-item input {
            margin-right: 8px;
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
                
                <li><a href="laporan.php" class="active"><i class="fas fa-chart-line"></i> Lihat Laporan</a></li> 
                <li><a href="laporan_per_kelas.php"><i class="fas fa-table"></i> Laporan Per Kelas</a></li>
                <li><a href="laporan_tunggakan.php"><i class="fas fa-exclamation-triangle"></i> Laporan Tunggakan</a></li>
                <li><a href="pengguna.php"><i class="fas fa-users"></i> Kelola Pengguna</a></li>
                
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
        <div class="content-wrapper">
            <div class="content-header">
                <h2><i class="fas fa-chart-line"></i> Laporan Keuangan</h2>
            </div>
            
            <form action="laporan.php" method="GET" id="filterForm">
                <div class="container">
                    <h3>Filter Utama</h3>
                    <div class="filter-container">
                        <div class="filter-group">
                            <label for="tanggal_awal">Tanggal Awal:</label>
                            <input type="date" id="tanggal_awal" name="tanggal_awal" value="<?php echo htmlspecialchars($tanggal_awal); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="tanggal_akhir">Tanggal Akhir:</label>
                            <input type="date" id="tanggal_akhir" name="tanggal_akhir" value="<?php echo htmlspecialchars($tanggal_akhir); ?>">
                        </div>

                        <div class="filter-group" style="min-width: 200px;">
                            <label>Filter Kelas:</label>
                            <div class="checkbox-dropdown">
                                <button type="button" class="checkbox-dropdown-btn" onclick="toggleDropdown(this)">
                                    Pilih Kelas (<span id="kelas_count"><?php echo count(array_filter($search_nama_kelas)); ?></span> terpilih) <i class="fas fa-caret-down"></i>
                                </button>

                                <div class="checkbox-dropdown-content" id="kelasDropdown">
                                    <?php if (!empty($daftar_kelas)): ?>
                                        <?php foreach ($daftar_kelas as $kelas): ?>
                                            <label class="checkbox-item">
                                                <input 
                                                    type="checkbox" 
                                                    name="nama_kelas[]" 
                                                    value="<?php echo htmlspecialchars($kelas['nama_kelas']); ?>" 
                                                    <?php echo in_array($kelas['nama_kelas'], $search_nama_kelas) ? 'checked' : ''; ?>
                                                    onchange="updateKelasCount(); document.getElementById('filterForm').submit();"
                                                >
                                                <?php echo htmlspecialchars($kelas['nama_kelas']); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p style="margin: 0; color: #777;">Tidak ada data kelas.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary">Tampilkan</button>
                        </div>

                        <?php 
                        // Cek apakah ada filter yang aktif
                        $is_filtered = !empty($tanggal_awal) || !empty($tanggal_akhir) || !empty($search_nama_siswa) || !empty($search_nama_kelas) || !empty($search_jenis_transaksi) || !empty($search_jenis_pembayaran);
                        $is_submitted = isset($_GET['tanggal_awal']);

                        if ($is_filtered || $is_submitted):
                            // Query untuk Export Excel
                            // Menggunakan array untuk nama_kelas agar PHP dapat mengelolanya di export_laporan.php
                            $export_query = http_build_query([
                                'tanggal_awal' => $tanggal_awal,
                                'tanggal_akhir' => $tanggal_akhir,
                                'nama_siswa' => $search_nama_siswa,
                                'nama_kelas' => $search_nama_kelas, 
                                'jenis_transaksi' => $search_jenis_transaksi,
                                'jenis_pembayaran' => $search_jenis_pembayaran
                            ]);
                        ?>
                        <div class="filter-group">
                            <a href="laporan.php" class="btn btn-reset">Reset Filter</a>
                        </div>
                        
                        <div class="filter-group">
                            <a href="export_laporan.php?<?php echo $export_query; ?>" 
                               class="btn btn-export">
                                <i class="fas fa-file-excel"></i> Export ke Excel
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            
            
                <div class="finance-summary container">
                    <h3>Ringkasan Keuangan (Berdasarkan Filter)</h3>
                    
                    <div class="summary-item" style="border-left: 5px solid var(--success-color);">
                        <span class="total-label">Total Pemasukan:</span> 
                        <span class="total-value" style="color: var(--success-color);"><?php echo formatRupiah($total_pemasukan); ?></span>
                    </div>

                    <div class="summary-item" style="border-left: 5px solid var(--danger-color);">
                        <span class="total-label">Total Pengeluaran:</span> 
                        <span class="total-value" style="color: var(--danger-color);"><?php echo formatRupiah($total_pengeluaran); ?></span>
                    </div>

                    <div class="saldo-akhir">
                        <span class="total-label">SALDO BERSIH:</span> 
                        <span class="total-value"><?php echo formatRupiah($total_pemasukan - $total_pengeluaran); ?></span>
                    </div>
                </div>

                <div class="container">
                    <h3><i class="fas fa-table"></i> Tabel Riwayat Transaksi</h3>
                    <div class="table-container" style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Nama Siswa</th>
                                    <th>NISN</th>
                                    <th>Kelas</th> 
                                    <th>Jenis Pembayaran</th>
                                    <th class="text-right">Jumlah</th>
                                    <th>Jenis Transaksi</th>
                                    <th>Deskripsi</th>
                                    <th>Dicatat Oleh</th>
                                    <th>Aksi</th>
                                </tr>
                                
                                <tr class="filter-row">
                                    <td></td> <td></td> <td>
                                        <input type="text" name="nama_siswa" value="<?php echo htmlspecialchars($search_nama_siswa); ?>" placeholder="Siswa..." onchange="document.getElementById('filterForm').submit()">
                                    </td>
                                    <td></td> <td></td> 
                                    <td>
                                        <input type="text" name="jenis_pembayaran" value="<?php echo htmlspecialchars($search_jenis_pembayaran); ?>" placeholder="Jenis Bayar..." onchange="document.getElementById('filterForm').submit()">
                                    </td>
                                    <td></td> <td>
                                        <select name="jenis_transaksi" onchange="document.getElementById('filterForm').submit()">
                                            <option value="">Semua</option>
                                            <option value="masuk" <?php echo $search_jenis_transaksi == 'masuk' ? 'selected' : ''; ?>>Masuk</option>
                                            <option value="keluar" <?php echo $search_jenis_transaksi == 'keluar' ? 'selected' : ''; ?>>Keluar</option>
                                        </select>
                                    </td>
                                    <td></td> <td></td> <td></td> </tr>
                                </thead>
                            <tbody>
                                <?php if (!empty($riwayat_transaksi)): ?>
                                    <?php $no = 1; ?>
                                    <?php foreach ($riwayat_transaksi as $transaksi): ?>
                                        <tr>
                                            <td class="text-center"><?php echo $no++; ?></td>
                                            <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($transaksi['tanggal_transaksi']))); ?></td>
                                            <td>
                                                <?php  
                                                    if ($transaksi['jenis_transaksi'] == 'masuk' && !empty($transaksi['nama_siswa'])) {
                                                        echo htmlspecialchars($transaksi['nama_siswa']);
                                                    } else {
                                                        echo '-';
                                                    }
                                                ?>
                                            </td>
                                            <td><?php echo !empty($transaksi['nisn']) ? htmlspecialchars($transaksi['nisn']) : '-'; ?></td>
                                            <td>
                                                <?php  
                                                    if ($transaksi['jenis_transaksi'] == 'masuk' && !empty($transaksi['nama_kelas'])) {
                                                        echo htmlspecialchars($transaksi['nama_kelas']);
                                                    } else {
                                                        echo '-';
                                                    }
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($transaksi['jenis_pembayaran']); ?></td>
                                            <td class="text-right">
                                                <?php 
                                                    $amount = formatRupiah($transaksi['jumlah']);
                                                    if ($transaksi['jenis_transaksi'] == 'masuk') {
                                                        echo '<span style="color: var(--success-color); font-weight: 600;">' . $amount . '</span>'; 
                                                    } else {
                                                        echo '<span style="color: var(--danger-color); font-weight: 600;">-' . $amount . '</span>';
                                                    }
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(ucfirst($transaksi['jenis_transaksi'])); ?></td>
                                            <td><?php echo htmlspecialchars($transaksi['deskripsi']); ?></td>
                                            <td><?php echo htmlspecialchars($transaksi['dicatat_oleh']); ?></td>
                                            <td class="text-center">
                                                <?php if ($transaksi['jenis_transaksi'] == 'masuk' && !empty($transaksi['id_transaksi'])): ?>
                                                    <a href="struk_pembayaran.php?id_transaksi=<?php echo htmlspecialchars($transaksi['id_transaksi']); ?>" target="_blank" class="btn btn-small">Cetak Struk</a>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="11" class="text-center" style="padding: 20px; color: var(--secondary-color);">Tidak ada data transaksi yang ditemukan sesuai filter.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form> </div>
    </div>

<script>
    // Fungsi untuk membuka/menutup dropdown
    function toggleDropdown(button) {
        const content = button.nextElementSibling;
        content.style.display = content.style.display === 'block' ? 'none' : 'block';
    }

    // Fungsi untuk menghitung jumlah checkbox yang terpilih
    function updateKelasCount() {
        const checkboxes = document.querySelectorAll('#kelasDropdown input[type="checkbox"]');
        let checkedCount = 0;
        checkboxes.forEach(cb => {
            if (cb.checked) {
                checkedCount++;
            }
        });
        document.getElementById('kelas_count').textContent = checkedCount;
    }

    // Panggil saat halaman dimuat untuk menampilkan jumlah awal
    document.addEventListener('DOMContentLoaded', updateKelasCount);
</script>

</body>
</html>
<?php 
if (isset($conn) && $conn) {
    mysqli_close($conn); 
}
?>