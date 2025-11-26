<?php
session_start();
include '../includes/koneksi.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

// Inisialisasi pesan
$pesan = '';

// --- FUNGSI HELPER ---
function redirect_with_status($status) {
    header("Location: kelola_set_biaya.php?status=" . urlencode($status));
    exit();
}
function formatRupiah($angka) {
    return "Rp " . number_format($angka, 0, ',', '.');
}

// --- LOGIKA TAMBAH/EDIT BIAYA (TERMASUK KETERANGAN TAMBAHAN) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_biaya'])) {
    // Sanitize dan trim input
    $jenis_pembayaran = trim($_POST['jenis_pembayaran']);
    
    // Mengambil nilai dari input yang diformat (nominal_display) dan membersihkannya
    $nominal_raw = str_replace('.', '', trim($_POST['nominal_display'])); 
    $nominal = intval(preg_replace('/[^\d]/', '', $nominal_raw)); 
    
    $tahun_ajaran = trim($_POST['tahun_ajaran']);
    $keterangan = trim($_POST['keterangan']); // Digunakan untuk Kategori Diskon (Normal, Diskon, Diskon Yatim)
    $keterangan_tambahan = trim($_POST['keterangan_tambahan']); // Kolom baru

    $id_set_biaya = isset($_POST['id_set_biaya']) ? intval($_POST['id_set_biaya']) : 0;

    if (empty($jenis_pembayaran) || empty($nominal) || empty($tahun_ajaran) || empty($keterangan)) {
         redirect_with_status('gagal_kosong');
    }

    if ($id_set_biaya > 0) {
        // Update data yang ada
        $query = "UPDATE set_biaya SET jenis_pembayaran = ?, nominal = ?, tahun_ajaran = ?, keterangan = ?, keterangan_tambahan = ? WHERE id_set_biaya = ?";
        $stmt = $conn->prepare($query);
        // Tipe parameter: s (string), i (integer), s (string), s (string), s (string), i (integer)
        $stmt->bind_param("sissii", $jenis_pembayaran, $nominal, $tahun_ajaran, $keterangan, $keterangan_tambahan, $id_set_biaya);
    } else {
        // Tambah data baru
        $query = "INSERT INTO set_biaya (jenis_pembayaran, nominal, tahun_ajaran, keterangan, keterangan_tambahan) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        // Tipe parameter: s, i, s, s, s
        $stmt->bind_param("sissi", $jenis_pembayaran, $nominal, $tahun_ajaran, $keterangan, $keterangan_tambahan);
    }
    
    if (!$stmt) {
        error_log("Error preparing statement: " . $conn->error);
        redirect_with_status('gagal_simpan');
    }
    
    if ($stmt->execute()) {
        redirect_with_status($id_set_biaya > 0 ? 'sukses_edit' : 'sukses_tambah');
    } else {
        error_log("Gagal menyimpan/mengedit biaya: " . $stmt->error);
        redirect_with_status('gagal_simpan');
    }
    $stmt->close();
}

// --- LOGIKA HAPUS BIAYA ---
if (isset($_GET['hapus'])) {
    $id_set_biaya = intval($_GET['hapus']);
    
    $query_cek_transaksi = "SELECT COUNT(*) AS total FROM transaksi WHERE id_set_biaya = ?";
    $stmt_cek = $conn->prepare($query_cek_transaksi);
    $stmt_cek->bind_param("i", $id_set_biaya);
    $stmt_cek->execute();
    $result_cek = $stmt_cek->get_result();
    $data_cek = $result_cek->fetch_assoc();
    $stmt_cek->close();

    if ($data_cek['total'] > 0) {
        redirect_with_status('gagal_hapus_terhubung');
    }
    
    $query_hapus = "DELETE FROM set_biaya WHERE id_set_biaya = ?";
    $stmt_hapus = $conn->prepare($query_hapus);
    $stmt_hapus->bind_param("i", $id_set_biaya);
    
    if ($stmt_hapus->execute()) {
        redirect_with_status('sukses_hapus');
    } else {
        error_log("Gagal menghapus biaya: " . $stmt_hapus->error);
        redirect_with_status('gagal_hapus');
    }
    $stmt_hapus->close();
}

// Ambil semua daftar tahun ajaran untuk dropdown
$daftar_tahun_ajaran = [];
$query_tahun = "SELECT nama_tahun FROM tahun_ajaran ORDER BY nama_tahun DESC";
$result_tahun = $conn->query($query_tahun);
while ($row = $result_tahun->fetch_assoc()) {
    $daftar_tahun_ajaran[] = $row['nama_tahun'];
}

// Ambil data biaya untuk ditampilkan (MEMASTIKAN kolom keterangan_tambahan diambil)
$daftar_biaya = [];
$query_biaya = "SELECT * FROM set_biaya ORDER BY tahun_ajaran DESC, jenis_pembayaran ASC, keterangan ASC";
$result_biaya = $conn->query($query_biaya);
while ($row = $result_biaya->fetch_assoc()) {
    $daftar_biaya[] = $row;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Biaya Pembayaran | Aplikasi Pembayaran Siswa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        :root {
            --primary-color: #007bff; 
            --success-color: #28a745;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
            --dark-text: #343a40;
            --light-bg: #f8f9fa;
            --card-bg: #ffffff;
            --sidebar-bg: #2c3e50;
        }

        body { font-family: 'Poppins', sans-serif; background-color: var(--light-bg); }
        .main-container { display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background-color: var(--sidebar-bg); color: white; padding: 20px 0; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1); }
        .sidebar-header { text-align: center; padding: 10px 20px 30px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar-header h2 { font-size: 1.2rem; margin: 0; font-weight: 600; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li a { display: flex; align-items: center; gap: 10px; padding: 12px 20px; color: #ecf0f1; text-decoration: none; transition: background-color 0.3s; font-size: 0.95rem; }
        .sidebar-menu li a:hover, .sidebar-menu li a.active { background-color: #34495e; }
        .content-wrapper { flex-grow: 1; padding: 30px; }
        .content-header h2, .content-header p { color: var(--dark-text); }
        .card {
            background-color: var(--card-bg);
            padding: 25px;
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
        .form-input label {
            display: block;
            margin-top: 10px;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark-text);
        }
        .form-input input[type="text"],
        .form-input input[type="number"],
        .form-input select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            box-sizing: border-box;
            margin-bottom: 15px;
        }
        .form-input {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        .form-input > div {
             margin-bottom: 0 !important; /* Hilangkan margin-bottom default */
        }
        .form-input .full-width {
            grid-column: span 2;
        }
        .form-input .button-group {
            grid-column: span 2;
            justify-self: end;
            text-align: right;
            margin-top: 10px; 
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-right: 10px;
        }
        .btn-primary { background-color: var(--primary-color); color: white; }
        .btn-primary:hover { background-color: #0056b3; }
        .btn-reset { background-color: #6c757d; color: white; }
        .btn-reset:hover { background-color: #5a6268; }
        .btn-edit { background-color: var(--info-color); color: white; }
        .btn-edit:hover { background-color: #117a8b; }
        .btn-delete { background-color: var(--danger-color); color: white; font-size: 0.9rem;}
        .btn-delete:hover { background-color: #c82333; }
        .notifikasi-box {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        .notifikasi-box.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .notifikasi-box.danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .notifikasi-box p { margin: 0; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
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
        .data-table tr:nth-child(even) { background-color: #f8f9fa; }
        .data-table tr:hover { background-color: #e9ecef; }
        .text-right { text-align: right; }
        .data-table td:nth-child(4) { text-align: right; } /* Kolom Nominal */
        .data-table td:nth-child(7) { min-width: 150px; } /* Kolom Aksi */
        .data-table .aksi a { margin: 2px; }
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
                <li><a href="kelola_set_biaya.php" class="active"><i class="fas fa-cogs"></i> Kelola Biaya Pembayaran</a></li>
                <li><a href="laporan.php"><i class="fas fa-chart-line"></i> Lihat Laporan</a></li>
                <li><a href="laporan_per_kelas.php"><i class="fas fa-table"></i> Laporan Per Kelas</a></li>
                <li><a href="laporan_tunggakan.php"><i class="fas fa-exclamation-triangle"></i> Laporan Tunggakan</a></li>
				<li><a href="pengguna.php"><i class="fas fa-users"></i> Kelola Pengguna</a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
        <div class="content-wrapper">
            <div class="content-header">
                <h2><i class="fas fa-cogs"></i> Kelola Biaya Pembayaran</h2>
                <p>Atur jenis pembayaran, nominal, kategori (Normal/Diskon), dan tahun ajaran.</p>
            </div>
            
            <?php 
            $status_msg = '';
            $status_class = '';

            if (isset($_GET['status'])) {
                switch ($_GET['status']) {
                    case 'sukses_tambah':
                        $status_msg = 'SUKSES: Biaya pembayaran baru berhasil ditambahkan!';
                        $status_class = 'success';
                        break;
                    case 'sukses_edit':
                        $status_msg = 'SUKSES: Data biaya pembayaran berhasil diperbarui!';
                        $status_class = 'success';
                        break;
                    case 'sukses_hapus':
                        $status_msg = 'SUKSES: Data biaya pembayaran berhasil dihapus!';
                        $status_class = 'success';
                        break;
                    case 'gagal_kosong':
                        $status_msg = 'GAGAL: Jenis Pembayaran, Nominal, Tahun Ajaran, dan Kategori wajib diisi.';
                        $status_class = 'danger';
                        break;
                    case 'gagal_hapus_terhubung':
                        $status_msg = 'GAGAL: Tidak bisa menghapus biaya ini karena sudah ada **transaksi** yang terhubung dengannya.';
                        $status_class = 'danger';
                        break;
                    case 'gagal_simpan':
                    case 'gagal_hapus':
                        $status_msg = 'Terjadi kesalahan saat menyimpan/menghapus data. Silakan coba lagi. (Cek log error jika kolom keterangan_tambahan belum ditambahkan di DB).';
                        $status_class = 'danger';
                        break;
                }
            }

            if ($status_msg):
            ?>
                <div class="notifikasi-box <?php echo $status_class; ?>">
                    <i class="fas fa-<?php echo ($status_class == 'success') ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <p><?php echo $status_msg; ?></p>
                </div>
            <?php endif; ?>
            
            <div class="card form-container">
                <h3><span id="formTitle">Tambah Biaya Baru</span></h3>
                <form action="" method="post" class="form-input">
                    <input type="hidden" id="id_set_biaya" name="id_set_biaya">
                    
                    <div>
                        <label for="jenis_pembayaran">Jenis Pembayaran (Contoh: SPP, Atribut, Ujikom):</label>
                        <input type="text" id="jenis_pembayaran" name="jenis_pembayaran" placeholder="Contoh: SPP" required>
                    </div>
                    
                    <div>
                        <label for="tahun_ajaran">Tahun Ajaran:</label>
                        <select id="tahun_ajaran" name="tahun_ajaran" required>
                            <?php if (empty($daftar_tahun_ajaran)): ?>
                                <option value="">-- Atur Tahun Ajaran di menu sebelah kiri! --</option>
                            <?php else: ?>
                                <option value="">-- Pilih Tahun Ajaran --</option>
                                <?php foreach ($daftar_tahun_ajaran as $ta): ?>
                                    <option value="<?php echo htmlspecialchars($ta); ?>"><?php echo htmlspecialchars($ta); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <label for="nominal_display">Nominal (Rp):</label>
                        <input type="text" id="nominal_display" name="nominal_display" placeholder="Masukkan Nominal" required onkeyup="formatCurrency(this)">
                        <small style="color:#6c757d;">Masukkan angka tanpa pemisah ribuan (e.g., 150000)</small>
                    </div>
                    
                    <div>
                         <label for="keterangan">Kategori Biaya (Normal/Diskon):</label>
                         <select id="keterangan" name="keterangan" required>
                             <option value="">-- Pilih Kategori/Diskon --</option>
                             <option value="Normal">Normal</option>
                             <option value="Diskon">Diskon (Umum)</option>
                             <option value="Diskon Yatim">Diskon Yatim</option> <option value="Lain-Lain">Lain-Lain</option>
                             <option value="Lengkap">Lengkap (Untuk Biaya Non-SPP)</option>
                         </select>
                         <small style="color:#6c757d;">Wajib diisi. Ini adalah nominal default.</small>
                    </div>
                    
                    <div class="full-width">
                        <label for="keterangan_tambahan">Keterangan Tambahan (Opsional, Jelaskan lebih detail):</label>
                        <input type="text" id="keterangan_tambahan" name="keterangan_tambahan" placeholder="Contoh: SPP Diskon Yatim 50%">
                    </div>

                    <div class="button-group">
                         <button type="button" class="btn btn-reset" onclick="resetForm()"><i class="fas fa-undo"></i> Reset</button>
                         <button type="submit" name="submit_biaya" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Biaya</button>
                    </div>
                </form>
            </div>

            <div class="card data-section">
                <h3><i class="fas fa-list-alt"></i> Daftar Biaya Pembayaran</h3>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Jenis Pembayaran</th>
                                <th>Kategori</th>
                                <th class="text-right">Nominal</th>
                                <th>Tahun Ajaran</th>
                                <th>Keterangan Tambahan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($daftar_biaya)): ?>
                                <tr>
                                    <td colspan="7" class="text-center" style="color: #6c757d; padding: 20px;">Belum ada biaya pembayaran yang terdaftar.</td>
                                </tr>
                            <?php else: ?>
                                <?php $no = 1; ?>
                                <?php foreach ($daftar_biaya as $biaya): ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo htmlspecialchars($biaya['jenis_pembayaran']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($biaya['keterangan']); ?></strong></td>
                                        <td class="text-right"><strong><?php echo formatRupiah($biaya['nominal']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($biaya['tahun_ajaran']); ?></td>
                                        <td><?php echo htmlspecialchars($biaya['keterangan_tambahan'] ?? ''); ?></td> 
                                        <td>
                                            <button onclick="editBiaya(<?php echo htmlspecialchars(json_encode($biaya)); ?>)" class="btn btn-edit btn-sm"><i class="fas fa-edit"></i> Edit</button>
                                            <a href="kelola_set_biaya.php?hapus=<?php echo htmlspecialchars($biaya['id_set_biaya']); ?>" class="btn btn-delete btn-sm" onclick="return confirm('Apakah Anda yakin ingin menghapus data biaya <?php echo htmlspecialchars($biaya['jenis_pembayaran']); ?> (<?php echo htmlspecialchars($biaya['keterangan']); ?>) - TA <?php echo htmlspecialchars($biaya['tahun_ajaran']); ?>? Tindakan ini tidak dapat dibatalkan.');"><i class="fas fa-trash"></i> Hapus</a>
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
    <script>
        function formatCurrency(input) {
            let value = input.value.replace(/\D/g, ''); 
            let number = parseInt(value, 10);
            
            if (isNaN(number)) {
                input.value = "";
                return;
            }
            
            input.value = number.toLocaleString('id-ID');
        }

        function editBiaya(biaya) {
            document.getElementById('id_set_biaya').value = biaya.id_set_biaya;
            document.getElementById('jenis_pembayaran').value = biaya.jenis_pembayaran;
            
            document.getElementById('nominal_display').value = parseInt(biaya.nominal).toLocaleString('id-ID');
            
            document.getElementById('tahun_ajaran').value = biaya.tahun_ajaran;
            document.getElementById('keterangan').value = biaya.keterangan; 
            document.getElementById('keterangan_tambahan').value = biaya.keterangan_tambahan || ''; 
            document.getElementById('formTitle').textContent = 'Edit Biaya: ' + biaya.jenis_pembayaran + ' (' + biaya.keterangan + ') - ' + biaya.tahun_ajaran;
            
            const submitBtn = document.querySelector('button[name="submit_biaya"]');
            submitBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Perbarui Biaya';
            submitBtn.classList.add('btn-info');
            submitBtn.classList.remove('btn-primary');

            document.getElementById('jenis_pembayaran').focus();
        }

        function resetForm() {
            document.getElementById('id_set_biaya').value = '';
            document.getElementById('jenis_pembayaran').value = '';
            document.getElementById('nominal_display').value = '';
            document.getElementById('tahun_ajaran').value = '';
            document.getElementById('keterangan').value = ''; 
            document.getElementById('keterangan_tambahan').value = '';
            document.getElementById('formTitle').textContent = 'Tambah Biaya Baru';
            
            const submitBtn = document.querySelector('button[name="submit_biaya"]');
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Simpan Biaya';
            submitBtn.classList.add('btn-primary');
            submitBtn.classList.remove('btn-info');
        }

        window.onload = resetForm;
    </script>
</body>
</html>
<?php
$conn->close();
?>