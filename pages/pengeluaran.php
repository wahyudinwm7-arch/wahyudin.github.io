<?php
session_start();
// Pastikan path ke file koneksi.php sudah benar
include '../includes/koneksi.php';

// Cek apakah pengguna sudah login
if (!isset($_SESSION['id_pengguna'])) { 
    header("Location: ../login.php"); 
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

// Tentukan Tanggal Laporan (default hari ini)
$tanggal_laporan = date('Y-m-d'); 
$display_tanggal_laporan = date('d F Y');

// --- 2. Ambil Data Pengeluaran Hari Ini untuk Laporan ---
$query_laporan = "
    SELECT 
        tanggal_transaksi,
        jenis_pembayaran AS uraian,
        deskripsi AS keterangan,
        jumlah,
        dicatat_oleh_id_pengguna
    FROM 
        transaksi
    WHERE 
        DATE(tanggal_transaksi) = ? 
        AND jenis_transaksi = 'keluar' 
    ORDER BY 
        tanggal_transaksi ASC
";

$stmt_laporan = $conn->prepare($query_laporan);
$stmt_laporan->bind_param("s", $tanggal_laporan);
$stmt_laporan->execute();
$result_laporan = $stmt_laporan->get_result();

$data_pengeluaran = [];
$total_pengeluaran_harian = 0;

while ($row = $result_laporan->fetch_assoc()) {
    $data_pengeluaran[] = $row;
    $total_pengeluaran_harian += $row['jumlah'];
}

$stmt_laporan->close();


// Query untuk mengambil semua kategori dari tabel yang baru dibuat
$query_kategori = "SELECT nama_kategori FROM kategori_pengeluaran ORDER BY nama_kategori ASC";
$result_kategori = mysqli_query($conn, $query_kategori);

$kategori_pengeluaran = [];
while ($row = mysqli_fetch_assoc($result_kategori)) {
    $kategori_pengeluaran[] = $row['nama_kategori'];
}

// Tambahkan "Kas Tunai" secara manual ke daftar (jika digunakan untuk Setoran/Deposit)
if (!in_array('Kas Tunai', $kategori_pengeluaran)) {
    $kategori_pengeluaran[] = 'Kas Tunai'; 
}

// Gabungkan dengan kategori Kas yang digunakan untuk setoran
if (!in_array('Kas Tunai', $kategori_pengeluaran)) {
    $kategori_pengeluaran[] = 'Kas Tunai'; // Digunakan di proses_deposit, tapi tidak perlu di form ini
}


// --- 4. Cek Pesan dari Proses (Sukses/Error) ---
$pesan = $_GET['pesan'] ?? '';
$debug = $_GET['debug'] ?? '';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pengeluaran Kas - Sistem Pembayaran Sekolah</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        /* CSS DARI DASHBOARD.PHP UNTUK KONSISTENSI */
        :root {
            --primary-color: #007bff; /* Biru Cerah */
            --secondary-color: #6c757d;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #3498db;
            --dark-text: #343a40;
            --light-bg: #f8f9fa;
            --sidebar-bg: #2c3e50; 
            --sidebar-hover: #34495e;
        }
        
        body { font-family: 'Poppins', sans-serif; margin: 0; padding: 0; background-color: var(--light-bg); color: var(--dark-text); line-height: 1.6; }
        .main-container { display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background-color: var(--sidebar-bg); color: white; padding: 20px 0; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1); }
        .sidebar-header { text-align: center; padding: 10px 20px 30px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar-header h2 { font-size: 1.2rem; margin: 0; font-weight: 600; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li a { display: flex; align-items: center; padding: 12px 20px; color: #ecf0f1; text-decoration: none; transition: background-color 0.3s, color 0.3s; font-size: 0.95rem; font-weight: 400; }
        .sidebar-menu li a:hover { background-color: var(--sidebar-hover); color: white; }
        .sidebar-menu li a.active { background-color: var(--primary-color); color: white; border-left: 5px solid #3498db; }
        .sidebar-menu li a i { width: 20px; margin-right: 10px; text-align: center; font-size: 1.1rem; }
        .content-wrapper { flex-grow: 1; padding: 30px; }
        .content-header { margin-bottom: 30px; border-bottom: 2px solid #e9ecef; padding-bottom: 15px; }
        .content-header h2 { font-size: 1.8rem; color: var(--dark-text); margin: 0; font-weight: 600; }
        .content-header p { color: var(--secondary-color); margin-top: 5px; }
        .container { width: 100%; max-width: 1200px; margin: 0 auto; }
        
        /* Gaya Khusus Halaman */
        .card-form {
            background-color: #fff;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-family: inherit;
        }
        .btn-submit {
            background-color: var(--danger-color);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            transition: background-color 0.3s;
        }
        .btn-submit:hover {
            background-color: #c0392b;
        }

        /* Gaya Tabel Laporan */
        .report-table {
            margin-top: 30px;
            padding: 25px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        .report-table h3 {
            font-size: 1.4rem;
            color: var(--dark-text);
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            margin-top: 0;
            margin-bottom: 20px;
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
            font-size: 0.9rem;
        }
        .data-table th {
            background-color: var(--danger-color);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
        }
        .data-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .data-table tr:hover {
            background-color: #f1f1f1;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .total-row {
            font-weight: bold;
            background-color: #f8d7da !important; /* Warna merah muda */
            border-top: 3px solid var(--danger-color);
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-weight: 600;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
                <li><a href="pengeluaran.php" class="active"><i class="fas fa-money-bill-wave"></i> Kelola Pengeluaran</a></li>
                <li><a href="siswa.php"><i class="fas fa-user-graduate"></i> Kelola Data Siswa</a></li>
                <li><a href="kelas.php"><i class="fas fa-school"></i> Kelola Data Kelas</a></li>
                <li><a href="tahun_ajaran.php"><i class="fas fa-calendar-alt"></i> Kelola Tahun Ajaran</a></li>
                <li><a href="kelola_set_biaya.php"><i class="fas fa-cogs"></i> Kelola Biaya Pembayaran</a></li>
                <li><a href="laporan.php"><i class="fas fa-chart-line"></i> Lihat Laporan</a></li>
                <li><a href="laporan_per_kelas.php"><i class="fas fa-table"></i> Laporan Per Kelas</a></li>
                <li><a href="laporan_tunggakan.php"><i class="fas fa-exclamation-triangle"></i> Laporan Tunggakan</a></li>
                <li><a href="pengguna.php"><i class="fas fa-users"></i> Kelola Pengguna</a></li> 
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
        
        <div class="content-wrapper">
            <div class="content-header">
                <h2><i class="fas fa-money-bill-wave"></i> Pencatatan Pengeluaran Kas</h2>
                <p>Formulir ini digunakan untuk mencatat pengeluaran operasional dan setoran kas ke Bank.</p>
            </div>
            
            <div class="container">
                
                <?php if ($pesan === 'success'): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> Pengeluaran kas berhasil dicatat!</div>
                <?php elseif ($pesan === 'error'): ?>
                    <div class="alert alert-error"><i class="fas fa-times-circle"></i> Gagal mencatat pengeluaran. Debug: <?php echo htmlspecialchars($debug); ?></div>
                <?php endif; ?>

                <div class="card-form">
                    <h3><i class="fas fa-plus-circle"></i> Catat Pengeluaran Baru</h3>
                    <form action="../proses/proses_pengeluaran.php" method="POST">
                        <div class="form-group">
                            <label for="tanggal_transaksi">Tanggal Transaksi</label>
                            <input type="date" id="tanggal_transaksi" name="tanggal_transaksi" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="kategori_pengeluaran">Uraian / Kategori Pengeluaran</label>
                            <select id="kategori_pengeluaran" name="kategori_pengeluaran" required>
                                <option value="" disabled selected>-- Pilih Kategori --</option>
                                <?php foreach ($kategori_pengeluaran as $kategori): 
                                    // Hanya tampilkan kategori yang merupakan pengeluaran, bukan Kas Tunai (yang khusus untuk setoran)
                                    if ($kategori !== 'Kas Tunai'):
                                ?>
                                    <option value="<?php echo htmlspecialchars($kategori); ?>"><?php echo htmlspecialchars($kategori); ?></option>
                                <?php 
                                    endif;
                                endforeach; ?>
                            </select>
                            <small class="text-secondary">* Untuk Setoran/Deposit ke Bank, silakan gunakan menu terpisah (misal: deposit_transfer.php) agar tercatat sebagai transfer internal.</small>
                        </div>
                        <div class="form-group">
                            <label for="jumlah">Jumlah Pengeluaran (Rp)</label>
                            <input type="text" id="jumlah" name="jumlah" placeholder="Contoh: 150.000" required>
                        </div>
                        <div class="form-group">
                            <label for="deskripsi">Keterangan Rinci</label>
                            <textarea id="deskripsi" name="deskripsi" rows="3" placeholder="Contoh: Pembelian 5 rim kertas HVS dan pulpen"></textarea>
                        </div>
                        <button type="submit" name="tambah_pengeluaran" class="btn-submit">
                            <i class="fas fa-save"></i> Catat Pengeluaran
                        </button>
                    </form>
                </div>

                <div class="report-table">
                    <h3><i class="fas fa-list-alt"></i> Laporan Pengeluaran Kas Harian (<?php echo htmlspecialchars($display_tanggal_laporan); ?>)</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 5%;" class="text-center">No.</th>
                                <th style="width: 15%;">Tanggal</th>
                                <th style="width: 20%;">Uraian</th>
                                <th style="width: 45%;">Keterangan</th>
                                <th style="width: 15%;" class="text-right">Jumlah (Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            if (!empty($data_pengeluaran)): 
                                foreach ($data_pengeluaran as $data):
                                    
                                    // Cek apakah ini transaksi setoran (mengambil dari Kas Tunai)
                                    $is_deposit = ($data['uraian'] === 'Kas Tunai' && strpos($data['keterangan'], 'Setoran kas') !== false);
                                    
                                    $uraian_display = htmlspecialchars($data['uraian']); 
                                    $keterangan_display = htmlspecialchars($data['keterangan']);
                                    
                                    // Jika itu Setoran, tampilkan uraian yang lebih spesifik
                                    if ($is_deposit) {
                                        $uraian_display = "Setoran Ke Bank";
                                        $keterangan_display = "Pengurangan Kas Tunai untuk Disetor ke Bank";
                                    }
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $no++; ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($data['tanggal_transaksi'])); ?></td>
                                <td><?php echo $uraian_display; ?></td>
                                <td><?php echo $keterangan_display; ?></td>
                                <td class="text-right" style="color: var(--danger-color); font-weight: 600;">
                                    <?php echo formatRupiah($data['jumlah']); ?>
                                </td>
                            </tr>
                            <?php 
                                endforeach; 
                            else:
                            ?>
                            <tr>
                                <td colspan="5" class="text-center">Tidak ada pengeluaran kas yang tercatat pada tanggal ini.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="4" class="text-center">TOTAL PENGELUARAN KAS HARI INI</td>
                                <td class="text-right">
                                    <?php echo formatRupiah($total_pengeluaran_harian); ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const jumlahInput = document.getElementById('jumlah');
            
            // Fungsi format angka ke Rupiah
            function formatNumber(n) {
                // Konversi angka menjadi string tanpa karakter non-digit kecuali titik
                n = n.replace(/\D/g, ''); 
                return new Intl.NumberFormat('id-ID').format(n);
            }

            jumlahInput.addEventListener('keyup', function(e) {
                // Terapkan format saat pengguna mengetik
                this.value = formatNumber(this.value);
            });
            
            // Pastikan formatRupiah() ada di file proses_pengeluaran.php untuk membersihkan input sebelum INSERT
        });
    </script>
</body>
</html>
<?php 
    // Tutup koneksi database
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
?>