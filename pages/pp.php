<?php
session_start();
include '../includes/koneksi.php';

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

$id_siswa = isset($_GET['id_siswa']) ? intval($_GET['id_siswa']) : 0;
// Gunakan tanggal hari ini untuk nilai default di form
$tanggal_default_form = date('Y-m-d'); 
$siswa = null;
$biaya_opsi = [];
$bulan_spp_dibayar = []; // Array ini akan berisi bulan-bulan yang sudah dibayar (misal: ['Juli 2025', 'Agustus 2025'])

if ($id_siswa > 0) {
    // Ambil info siswa
    $query_siswa = "
        SELECT 
            s.id_siswa, s.nama_lengkap, s.nisn, k.nama_kelas, ta.nama_tahun AS tahun_ajaran
        FROM 
            siswa s
        JOIN 
            kelas k ON s.id_kelas = k.id_kelas
        JOIN 
            tahun_ajaran ta ON s.id_tahun_ajaran = ta.id_tahun_ajaran
        WHERE s.id_siswa = ?
    ";
    $stmt = $conn->prepare($query_siswa);
    $stmt->bind_param("i", $id_siswa);
    $stmt->execute();
    $result_siswa = $stmt->get_result();
    $siswa = $result_siswa->fetch_assoc();
    $stmt->close();

    if ($siswa) {
        // Ambil data biaya berdasarkan tahun ajaran siswa
        $tahun_ajaran_siswa = $siswa['tahun_ajaran'];
        $query_biaya = "SELECT jenis_pembayaran, nominal, keterangan FROM set_biaya WHERE tahun_ajaran = ?";
        $stmt_biaya = $conn->prepare($query_biaya);
        $stmt_biaya->bind_param("s", $tahun_ajaran_siswa);
        $stmt_biaya->execute();
        $result_biaya = $stmt_biaya->get_result();
        while ($row = $result_biaya->fetch_assoc()) {
            $biaya_opsi[] = $row;
        }
        $stmt_biaya->close();
    
        // =========================================================================
        // PERBAIKAN LOGIKA PENGAMBILAN BULAN SPP DIBAYAR (PHP)
        // =========================================================================
        // Ambil deskripsi (bulan) dari semua transaksi SPP siswa
        $query_spp_dibayar_deskripsi = "
            SELECT deskripsi 
            FROM transaksi 
            WHERE id_siswa = ? AND jenis_pembayaran LIKE '%SPP%' AND jenis_transaksi = 'masuk'
        ";
        $stmt_spp = $conn->prepare($query_spp_dibayar_deskripsi);
        $stmt_spp->bind_param("i", $id_siswa);
        $stmt_spp->execute();
        $result_spp = $stmt_spp->get_result();

        $bulan_spp_raw_list = [];
        while ($row_spp = $result_spp->fetch_assoc()) {
            // Kumpulkan deskripsi mentah (misal: "Juli 2025, Agustus 2025")
            $bulan_spp_raw_list[] = $row_spp['deskripsi'];
        }
        $stmt_spp->close();

        // Pecah dan ratakan (flatten) semua bulan menjadi satu array tunggal
        $months_paid = [];
        foreach ($bulan_spp_raw_list as $deskripsi) {
            // Pastikan pemisah yang digunakan di database adalah koma dan spasi (", ")
            $parts = explode(', ', $deskripsi); 
            foreach ($parts as $part) {
                if (!empty(trim($part))) {
                    $months_paid[] = trim($part); // 'Juli 2025'
                }
            }
        }
        $bulan_spp_dibayar = $months_paid; // Array final yang berisi bulan-bulan yang sudah dibayar
    }
}

// =========================================================================
// PERBAIKAN UTAMA: PROSES FORM POST (SINKRONISASI TANGGAL)
// =========================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id_siswa_post = $_POST['id_siswa'];
    $jenis_pembayaran = $_POST['jenis_pembayaran'];
    $jumlah = $_POST['jumlah'];
    
    // AMBIL TANGGAL DARI INPUT FORM UNTUK SINKRONISASI
    // Tambahkan jam:menit:detik saat ini agar tipe data datetime di DB terisi lengkap
    $tanggal_transaksi = $_POST['tanggal_transaksi'] . ' ' . date('H:i:s'); 
    
    $id_pengguna_sesi = $_SESSION['id_pengguna'];
    $deskripsi = $_POST['deskripsi'];

    if (empty($jenis_pembayaran) || empty($jumlah) || empty($_POST['tanggal_transaksi']) || empty($id_siswa_post) || empty($deskripsi)) {
        header("Location: pembayaran.php?id_siswa=" . $id_siswa_post . "&pesan=error&debug=Data tidak lengkap");
        exit();
    }
    
    // Mulai transaksi
    $conn->begin_transaction();
    try {
        
        $query_insert = "
            INSERT INTO transaksi 
            (id_siswa, tanggal_transaksi, jumlah, deskripsi, jenis_pembayaran, jenis_transaksi, dicatat_oleh_id_pengguna) 
            VALUES (?, ?, ?, ?, ?, 'masuk', ?)
        ";
        $stmt_insert = $conn->prepare($query_insert);
        
        // Tipe data untuk bind_param: integer, string, double, string, string, integer
        $stmt_insert->bind_param("isdssi", $id_siswa_post, $tanggal_transaksi, $jumlah, $deskripsi, $jenis_pembayaran, $id_pengguna_sesi);
        
        if (!$stmt_insert->execute()) {
            throw new Exception("Gagal mengeksekusi statement: " . $stmt_insert->error);
        }
        $stmt_insert->close();
        
        $conn->commit();
        header("Location: pembayaran.php?id_siswa=" . $id_siswa_post . "&pesan=success");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: pembayaran.php?id_siswa=" . $id_siswa_post . "&pesan=error&debug=" . urlencode($e->getMessage()));
        exit();
    }
}

// Ambil riwayat transaksi siswa
$riwayat_transaksi = [];
if ($id_siswa > 0) {
    $query_riwayat = "
        SELECT 
            t.*, u.nama_pengguna AS dicatat_oleh 
        FROM 
            transaksi t
        LEFT JOIN
            pengguna u ON t.dicatat_oleh_id_pengguna = u.id_pengguna
        WHERE 
            t.id_siswa = ? 
        ORDER BY 
            t.tanggal_transaksi DESC, t.id_transaksi DESC"; 
    $stmt_riwayat = $conn->prepare($query_riwayat);
    $stmt_riwayat->bind_param("i", $id_siswa);
    $stmt_riwayat->execute();
    $result_riwayat = $stmt_riwayat->get_result();
    $riwayat_transaksi = $result_riwayat->fetch_all(MYSQLI_ASSOC);
    $stmt_riwayat->close();
}

// Ambil semua siswa untuk dropdown
$query_all_siswa = "SELECT id_siswa, nama_lengkap, nisn FROM siswa ORDER BY nama_lengkap ASC";
$result_all_siswa = mysqli_query($conn, $query_all_siswa);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kelola Pembayaran | Aplikasi Pembayaran Siswa</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .spp-options {
            display: none;
            margin-top: 10px;
            border: 1px solid #ccc;
            padding: 10px;
            border-radius: 5px;
            background-color: #f9f9f9;
        }
        .spp-options .bulan-item {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        .spp-options .bulan-item input[type="checkbox"] {
            margin-right: 10px;
        }
        .bulan-item label.paid {
            color: #888;
            font-style: italic;
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
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="pembayaran.php" class="active">Kelola Pembayaran</a></li>
                <li><a href="pengeluaran.php">Kelola Pengeluaran</a></li>
                <li><a href="siswa.php">Kelola Data Siswa</a></li>
                <li><a href="kelas.php">Kelola Data Kelas</a></li>
                <li><a href="tahun_ajaran.php">Kelola Tahun Ajaran</a></li>
                <li><a href="kelola_set_biaya.php">Kelola Biaya Pembayaran</a></li>
                <li><a href="laporan.php">Laporan Keuangan</a></li>
                <li><a href="laporan_per_kelas.php">Laporan Per Kelas</a></li>
                <li><a href="laporan_tunggakan.php">Laporan Tunggakan</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </div>
        <div class="content-wrapper">
            <div class="content-header">
                <h2>Kelola Pembayaran</h2>
            </div>
            
            <?php if (!$id_siswa || !$siswa): ?>
                <div class="form-container">
                    <h3>Pilih Siswa</h3>
                    <form action="" method="get">
                        <label for="id_siswa">Pilih Siswa:</label>
                        <select name="id_siswa" id="id_siswa" onchange="this.form.submit()">
                            <option value="">-- Pilih Siswa --</option>
                            <?php while ($row = mysqli_fetch_assoc($result_all_siswa)): ?>
                                <option value="<?php echo htmlspecialchars($row['id_siswa']); ?>" <?php echo ($id_siswa == $row['id_siswa']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_lengkap']); ?> (NISN: <?php echo htmlspecialchars($row['nisn']); ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </form>
                </div>
            <?php else: ?>
            
            <div class="form-container">
                <h3>Catat Pembayaran untuk <?php echo htmlspecialchars($siswa['nama_lengkap']); ?></h3>
                <?php if (isset($_GET['pesan']) && $_GET['pesan'] == 'success'): ?>
                    <p class="success-message">Pembayaran berhasil dicatat! ✅</p>
                <?php elseif (isset($_GET['pesan']) && $_GET['pesan'] == 'error'): ?>
                    <p class="error-message">Gagal mencatat pembayaran. Silakan coba lagi. ❌ <?php echo isset($_GET['debug']) ? "(" . htmlspecialchars($_GET['debug']) . ")" : ""; ?></p>
                <?php endif; ?>
                <form action="" method="post">
                    <input type="hidden" name="id_siswa" value="<?php echo htmlspecialchars($id_siswa); ?>">
                    
                    <label for="jenis_pembayaran">Jenis Pembayaran:</label>
                    <select id="jenis_pembayaran" name="jenis_pembayaran" required>
                        <option value="">--Pilih Jenis Pembayaran--</option>
                        <?php foreach ($biaya_opsi as $biaya): ?>
                            <option 
                                value="<?php echo htmlspecialchars($biaya['jenis_pembayaran']); ?>" 
                                data-nominal="<?php echo htmlspecialchars($biaya['nominal']); ?>"
                            >
                                <?php echo htmlspecialchars($biaya['jenis_pembayaran']); ?> (Rp <?php echo number_format($biaya['nominal'], 0, ',', '.'); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <div class="spp-options" id="spp-options">
                        <h4>Pilih Bulan SPP:</h4>
                        <?php
                            $bulan_spp = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];
                            
                            // Ambil tahun awal dan tahun akhir dari tahun ajaran siswa (Misal: 2024/2025)
                            $tahun_ajaran_parts = explode('/', $siswa['tahun_ajaran']);
                            $tahun_masuk = (int) $tahun_ajaran_parts[0]; // Misal: 2024
                            $total_tahun_ajaran = 3;
                        ?>
                        <?php 
                        // Loop untuk 3 Tahun Ajaran (misal 2024/2025, 2025/2026, 2026/2027)
                        for ($ta = 0; $ta < $total_tahun_ajaran; $ta++): 
                            $tahun_aj_awal = $tahun_masuk + $ta;
                            $tahun_aj_akhir = $tahun_masuk + $ta + 1;
                        ?>

                            <?php 
                            // 1. Semester Ganjil (Juli - Desember)
                            for ($i = array_search('Juli', $bulan_spp); $i <= array_search('Desember', $bulan_spp); $i++): 
                                $bulan = $bulan_spp[$i];
                                $tahun_spp_val = $tahun_aj_awal;
                                $bulan_lengkap = $bulan . " " . $tahun_spp_val;
                                
                                $is_paid = in_array($bulan_lengkap, $bulan_spp_dibayar);
                                $disabled = $is_paid ? 'disabled' : '';
                                $checked = $is_paid ? 'checked' : '';
                                $label_class = $is_paid ? 'paid' : '';
                            ?>
                            <div class="bulan-item">
                                <input 
                                    type="checkbox" 
                                    name="spp_bulan_tahun[]" 
                                    value="<?php echo htmlspecialchars($bulan_lengkap); ?>" 
                                    <?php echo $disabled; ?> 
                                    <?php echo $checked; ?>
                                >
                                <label class="<?php echo $label_class; ?>"><?php echo htmlspecialchars($bulan_lengkap); ?></label>
                            </div>
                            <?php endfor; ?>

                            <?php 
                            // 2. Semester Genap (Januari - Juni)
                            for ($i = array_search('Januari', $bulan_spp); $i <= array_search('Juni', $bulan_spp); $i++): 
                                $bulan = $bulan_spp[$i];
                                $tahun_spp_val = $tahun_aj_akhir;
                                $bulan_lengkap = $bulan . " " . $tahun_spp_val;
                                
                                $is_paid = in_array($bulan_lengkap, $bulan_spp_dibayar);
                                $disabled = $is_paid ? 'disabled' : '';
                                $checked = $is_paid ? 'checked' : '';
                                $label_class = $is_paid ? 'paid' : '';
                            ?>
                            <div class="bulan-item">
                                <input 
                                    type="checkbox" 
                                    name="spp_bulan_tahun[]" 
                                    value="<?php echo htmlspecialchars($bulan_lengkap); ?>" 
                                    <?php echo $disabled; ?> 
                                    <?php echo $checked; ?>
                                >
                                <label class="<?php echo $label_class; ?>"><?php echo htmlspecialchars($bulan_lengkap); ?></label>
                            </div>
                            <?php endfor; ?>
                        <?php endfor; ?>
                    </div>
                    
                    <label for="jumlah">Jumlah:</label>
                    <input type="number" id="jumlah" name="jumlah" placeholder="Masukkan jumlah" required readonly>
                    
                    <label for="deskripsi" id="deskripsi-label">Deskripsi:</label>
                    <input type="text" id="deskripsi" name="deskripsi" placeholder="Otomatis terisi..." required readonly>
                    
                    <label for="tanggal_transaksi">Tanggal Pembayaran (Aktual):</label>
                    <input type="date" id="tanggal_transaksi" name="tanggal_transaksi" value="<?php echo htmlspecialchars($tanggal_default_form); ?>" required>
                    
                    <button type="submit" class="btn">Catat Pembayaran</button>
                </form>
            </div>

            <div class="data-section">
                <h3>Riwayat Transaksi Siswa</h3>
                <p>Riwayat transaksi untuk **<?php echo htmlspecialchars($siswa['nama_lengkap']); ?>** (NISN: <?php echo htmlspecialchars($siswa['nisn']); ?>).</p>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tanggal Bayar</th> 
                            <th>Jenis Pembayaran</th>
                            <th>Jumlah</th>
                            <th>Deskripsi</th>
                            <th>Dicatat Oleh</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; ?>
                        <?php if (!empty($riwayat_transaksi)): ?>
                            <?php foreach ($riwayat_transaksi as $row): ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($row['tanggal_transaksi'])); ?></td> 
                                    <td><?php echo htmlspecialchars($row['jenis_pembayaran']); ?></td>
                                    <td>Rp <?php echo number_format($row['jumlah'], 0, ',', '.'); ?></td>
                                    <td><?php echo htmlspecialchars($row['deskripsi']); ?></td>
                                    <td><?php echo htmlspecialchars($row['dicatat_oleh']); ?></td>
                                    <td>
                                        <a href="struk_pembayaran.php?id_transaksi=<?php echo htmlspecialchars($row['id_transaksi']); ?>" target="_blank" class="btn btn-small">Cetak Struk</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">Belum ada riwayat transaksi.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const jenisPembayaranSelect = document.getElementById('jenis_pembayaran');
            const sppOptionsDiv = document.getElementById('spp-options');
            const jumlahInput = document.getElementById('jumlah');
            const deskripsiInput = document.getElementById('deskripsi');
            const deskripsiLabel = document.getElementById('deskripsi-label');
            
            // Logika saat jenis pembayaran berubah
            jenisPembayaranSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const selectedJenis = selectedOption.value;
                
                // Reset nilai dan tampilan
                jumlahInput.value = '';
                deskripsiInput.value = '';

                // Deteksi SPP (menggunakan case-insensitive check)
                if (selectedJenis && selectedJenis.toUpperCase().includes('SPP')) {
                    // Tampilkan opsi bulan SPP
                    sppOptionsDiv.style.display = 'block';
                    deskripsiInput.style.display = 'block';
                    deskripsiLabel.style.display = 'block';
                    jumlahInput.readOnly = true;
                    deskripsiInput.readOnly = true;
                    
                    // Reset semua checkbox SPP
                    sppOptionsDiv.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                        if (!checkbox.disabled) {
                            checkbox.checked = false;
                        }
                    });
                    updateTotalSPP();
                } else {
                    // Sembunyikan opsi bulan SPP (untuk Non-SPP)
                    sppOptionsDiv.style.display = 'none';
                    deskripsiInput.style.display = 'block';
                    deskripsiLabel.style.display = 'block';
                    jumlahInput.readOnly = false; // Boleh diedit jika non-SPP
                    deskripsiInput.readOnly = false; // Boleh diedit jika non-SPP
                    
                    // Mengisi Nominal dan Deskripsi untuk Non-SPP
                    const nominal = selectedOption.getAttribute('data-nominal');
                    if (nominal) {
                        jumlahInput.value = nominal;
                    } else {
                        jumlahInput.value = 0;
                    }
                    // Isi deskripsi awal dengan nama biaya yang dipilih (Non-SPP)
                    deskripsiInput.value = selectedJenis; 
                }
            });

            // Logika saat checkbox SPP diubah
            sppOptionsDiv.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                checkbox.addEventListener('change', updateTotalSPP);
            });

            function updateTotalSPP() {
                const selectedOption = jenisPembayaranSelect.options[jenisPembayaranSelect.selectedIndex];
                const nominalPerBulan = parseInt(selectedOption.getAttribute('data-nominal') || 0); 
                
                let total = 0;
                const selectedMonths = [];
                sppOptionsDiv.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                    // Hanya hitung yang dicentang DAN tidak disabled
                    if (checkbox.checked && !checkbox.disabled) { 
                        total += nominalPerBulan;
                        selectedMonths.push(checkbox.value);
                    }
                });
                jumlahInput.value = total;
                
                // Isi deskripsi dengan bulan-bulan yang dipilih
                deskripsiInput.value = selectedMonths.join(', ');
            }
            
            // Panggil logika inisial jika siswa sudah dipilih dan jenis pembayaran sudah ada
            if (jenisPembayaranSelect.value) {
                jenisPembayaranSelect.dispatchEvent(new Event('change'));
            }
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>