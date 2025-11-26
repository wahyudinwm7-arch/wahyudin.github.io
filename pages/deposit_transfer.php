<?php
session_start();
include '../includes/koneksi.php'; 

if (!isset($_SESSION['nama_pengguna']) || !isset($_SESSION['id_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

// Data Bulan untuk perhitungan SPP
$bulan_semua = [
    'Juli' => 7, 'Agustus' => 8, 'September' => 9, 'Oktober' => 10, 'November' => 11, 'Desember' => 12,
    'Januari' => 1, 'Februari' => 2, 'Maret' => 3, 'April' => 4, 'Mei' => 5, 'Juni' => 6
];
$bulan_to_num = [
     'Januari' => 1, 'Februari' => 2, 'Maret' => 3, 'April' => 4, 'Mei' => 5, 'Juni' => 6,
     'Juli' => 7, 'Agustus' => 8, 'September' => 9, 'Oktober' => 10, 'November' => 11, 'Desember' => 12
];
$tahun_sekarang = (int)date('Y');
$bulan_sekarang_idx = (int)date('n');


function formatRupiah($angka) {
    return "Rp " . number_format((float)$angka, 0, ',', '.');
}

$id_siswa = isset($_GET['id_siswa']) ? intval($_GET['id_siswa']) : 0;
$max_deposit = isset($_GET['deposit']) ? floatval($_GET['deposit']) : 0;
$pesan = "";
$tipe_pesan = "";
$data_spp_tunggakan = []; 

// 1. Ambil data siswa
$siswa_data = null;
if ($id_siswa > 0) {
    $query_siswa = "SELECT s.nama_lengkap, s.nisn, k.nama_kelas, ta.nama_tahun,
                           s.status_spp, s.bulan_mulai_spp, s.tahun_mulai_spp, ta.nama_tahun as tahun_ajaran_siswa
                    FROM siswa s
                    JOIN kelas k ON s.id_kelas = k.id_kelas
                    JOIN tahun_ajaran ta ON s.id_tahun_ajaran = ta.id_tahun_ajaran
                    WHERE s.id_siswa = ?";
    if ($stmt = $conn->prepare($query_siswa)) {
        $stmt->bind_param("i", $id_siswa);
        $stmt->execute();
        $siswa_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

// ======================================================================
// FUNGSI PENGHITUNGAN TUNGGAKAN SPP SISWA (TIDAK BERUBAH DARI SEBELUMNYA)
// ======================================================================

if ($siswa_data) {
    $tahun_ajaran_siswa = $siswa_data['tahun_ajaran_siswa'];
    $tahun_masuk_awal = (int)explode('/', $tahun_ajaran_siswa)[0];
    $tahun_mulai = (int)($siswa_data['tahun_mulai_spp']);
    $bulan_mulai_idx = (int)($siswa_data['bulan_mulai_spp']);
    $status_spp = $siswa_data['status_spp'];
    $id_siswa_target = $id_siswa;

    // 1. Ambil Nominal SPP Per Bulan
    $jenis_spp_db = 'SPP';
    $keterangan_spp_db = ($status_spp === 'diskon') ? 'Diskon' : 'Normal';

    $query_nominal_spp = "SELECT nominal FROM set_biaya WHERE jenis_pembayaran = ? AND keterangan = ? AND tahun_ajaran= ?";
    $stmt_nominal_spp = $conn->prepare($query_nominal_spp);
    
    if ($stmt_nominal_spp) {
        $stmt_nominal_spp->bind_param("sss", $jenis_spp_db, $keterangan_spp_db, $tahun_ajaran_siswa);
        $stmt_nominal_spp->execute();
        $nominal_per_bulan = (float)($stmt_nominal_spp->get_result()->fetch_assoc()['nominal'] ?? 0);
        $stmt_nominal_spp->close();
    } else {
        $nominal_per_bulan = 0;
    }

    // 2. Pre-Query Transaksi SPP untuk siswa ini
    $total_dibayar_per_bulan = []; 
    if ($nominal_per_bulan > 0) {
        $query_bayar_spp = "SELECT jumlah, deskripsi FROM transaksi WHERE id_siswa = ? AND jenis_pembayaran LIKE 'SPP%'";
        
        $stmt_spp = $conn->prepare($query_bayar_spp);
        if ($stmt_spp) {
            $stmt_spp->bind_param("i", $id_siswa_target); 
            $stmt_spp->execute();
            $result_bayar_spp = $stmt_spp->get_result();
            
            while ($row = $result_bayar_spp->fetch_assoc()) {
                $jumlah = (float)$row['jumlah']; 
                
                if (preg_match_all('/(Juli|Agustus|September|Oktober|November|Desember|Januari|Februari|Maret|April|Mei|Juni)\s(\d{4})/', $row['deskripsi'], $matches, PREG_SET_ORDER)) {
                    
                    $jumlah_bulan_dicatat = count($matches);
                    $jumlah_per_bulan = ($jumlah_bulan_dicatat > 0) ? $jumlah / $jumlah_bulan_dicatat : 0; 

                    foreach ($matches as $match) {
                        $bulan = $match[1];
                        $tahun = $match[2];
                        $key = "{$bulan} {$tahun}";
                        
                        $total_dibayar_per_bulan[$key] = 
                            ($total_dibayar_per_bulan[$key] ?? 0) + $jumlah_per_bulan;
                    }
                }
            }
            $stmt_spp->close();
        }
    }

    // 3. Loop Perhitungan Tunggakan Per Bulan
    if ($nominal_per_bulan > 0) {
        $tahun_sekarang_plus_satu = $tahun_sekarang + 1;
        
        for ($tahun_loop = $tahun_masuk_awal; $tahun_loop <= $tahun_sekarang_plus_satu; $tahun_loop++) { 
            foreach ($bulan_semua as $bulan_nama => $bulan_angka) {
                
                $tahun_spp = ($bulan_angka >= 7) ? $tahun_loop : $tahun_loop + 1;
                $bulan_tahun_string = "{$bulan_nama} {$tahun_spp}";
                
                $is_before_or_current_month = ($tahun_spp < $tahun_sekarang) || ($tahun_spp == $tahun_sekarang && $bulan_angka <= $bulan_sekarang_idx);
                $is_after_or_at_start_month = ($tahun_spp > $tahun_mulai) || ($tahun_spp == $tahun_mulai && $bulan_angka >= $bulan_mulai_idx);

                if (!$is_after_or_at_start_month) {
                    continue;
                }
                
                if ($is_before_or_current_month) {
                    $nominal_dibayar = $total_dibayar_per_bulan[$bulan_tahun_string] ?? 0;
                    $sisa_tunggakan = $nominal_per_bulan - $nominal_dibayar;
                    
                    if ($sisa_tunggakan > 0) {
                        $data_spp_tunggakan[] = [
                            'bulan_tahun' => $bulan_tahun_string,
                            'nominal' => $sisa_tunggakan,
                        ];
                    } 
                }
            }
        }
    }
}
// ======================================================================
// END FUNGSI PENGHITUNGAN TUNGGAKAN SPP SISWA
// ======================================================================


// 2. LOGIKA TRANSFER DEPOSIT (Logika PHP ini tetap sama, karena JS mengatur input nominal)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['transfer'])) {
    
    $nominal_transfer = floatval($_POST['nominal_transfer']);
    $jenis_pembayaran_tujuan = $_POST['jenis_pembayaran_tujuan'];
    $deskripsi_tambahan = trim($_POST['deskripsi_tambahan']);
    $max_deposit_post = floatval($_POST['max_deposit']); 

    if ($nominal_transfer <= 0 || $nominal_transfer > $max_deposit_post) {
        $pesan = "Nominal transfer tidak valid atau melebihi sisa deposit (" . formatRupiah($max_deposit_post) . ").";
        $tipe_pesan = "danger";
    } else {
        $conn->begin_transaction();
        $success = true;

        // A. Transaksi 1: Mencatat PENGURANGAN Deposit (Dicatat sebagai 'keluar')
        $deskripsi_keluar = "Pengalihan deposit untuk pembayaran " . $jenis_pembayaran_tujuan . ". " . ($deskripsi_tambahan ? "($deskripsi_tambahan)" : "");
        $dicatat_oleh = $_SESSION['id_pengguna'];
        
        $query_keluar = "INSERT INTO transaksi (id_siswa, tanggal_transaksi, jenis_pembayaran, jumlah, jenis_transaksi, deskripsi, dicatat_oleh_id_pengguna) 
                          VALUES (?, NOW(), ?, ?, 'keluar', ?, ?)";
        
        if ($stmt_keluar = $conn->prepare($query_keluar)) {
            $jenis_deposit_keluar = "Deposit/Sisa Lebih Bayar"; 
            $stmt_keluar->bind_param("isdsi", $id_siswa, $jenis_deposit_keluar, $nominal_transfer, $deskripsi_keluar, $dicatat_oleh);
            if (!$stmt_keluar->execute()) {
                $success = false;
                $pesan = "Gagal mencatat pengurangan deposit: " . $stmt_keluar->error;
            }
            $stmt_keluar->close();
        } else {
            $success = false;
            $pesan = "Prepare query pengurangan deposit gagal: " . $conn->error;
        }

        // B. Transaksi 2: Mencatat PENERIMAAN Pembayaran (Dicatat sebagai 'masuk')
        if ($success) {
            
            // >>> LOGIKA DESKRIPSI UNTUK SPP DAN NON-SPP <<<
            $deskripsi_tambahan_detail = "";
            
            if ($jenis_pembayaran_tujuan === 'SPP' && isset($_POST['spp_months']) && is_array($_POST['spp_months'])) {
                // Logika SPP: Ambil bulan yang dicentang
                $bulan_dibayar = implode(", ", array_map('htmlspecialchars', $_POST['spp_months']));
                $deskripsi_tambahan_detail = " (Membayar SPP bulan: " . $bulan_dibayar . ")";
            }
            // Jika Non-SPP, rinciannya sudah ada di $deskripsi_tambahan yang diisi user
            
            $deskripsi_masuk = "Pembayaran via pengalihan saldo deposit, untuk " 
                             . htmlspecialchars($jenis_pembayaran_tujuan) 
                             . $deskripsi_tambahan_detail 
                             . ". Dari tagihan " . $siswa_data['nama_lengkap'] 
                             . ". Tambahan: " . ($deskripsi_tambahan ? "($deskripsi_tambahan)" : "N/A");
            // >>> END LOGIKA DESKRIPSI <<<


            $query_masuk = "INSERT INTO transaksi (id_siswa, tanggal_transaksi, jenis_pembayaran, jumlah, jenis_transaksi, deskripsi, dicatat_oleh_id_pengguna) 
                            VALUES (?, NOW(), ?, ?, 'masuk', ?, ?)";
            
            if ($stmt_masuk = $conn->prepare($query_masuk)) {
                $stmt_masuk->bind_param("isdsi", $id_siswa, $jenis_pembayaran_tujuan, $nominal_transfer, $deskripsi_masuk, $dicatat_oleh);
                if (!$stmt_masuk->execute()) {
                    $success = false;
                    $pesan = "Gagal mencatat pembayaran masuk: " . $stmt_masuk->error;
                }
                $stmt_masuk->close();
            } else {
                $success = false;
                $pesan = "Prepare query pembayaran masuk gagal: " . $conn->error;
            }
        }
        
        // C. Commit/Rollback
        if ($success) {
            $conn->commit();
            $sisa_akhir = $max_deposit_post - $nominal_transfer;
            $pesan = "Transfer deposit sebesar **" . formatRupiah($nominal_transfer) . "** berhasil dicatat untuk jenis pembayaran **" . htmlspecialchars($jenis_pembayaran_tujuan) . "**. Sisa deposit: **" . formatRupiah($sisa_akhir) . "**.";
            $tipe_pesan = "success";
            
            header("Location: deposit_transfer.php?id_siswa=" . $id_siswa . "&deposit=" . $sisa_akhir . "&status=" . $tipe_pesan . "&msg=" . urlencode($pesan));
            exit();

        } else {
            $conn->rollback();
            $tipe_pesan = "danger";
            $pesan = "Transaksi dibatalkan. " . $pesan;
        }
    }
}

// Ambil pesan dari redirect jika ada
if (isset($_GET['status']) && isset($_GET['msg'])) {
    $tipe_pesan = $_GET['status'];
    $pesan = urldecode($_GET['msg']);
}

// 3. Ambil daftar jenis pembayaran Non-SPP untuk dropdown
$jenis_pembayaran_options = [];
$query_jenis = "SELECT DISTINCT jenis_pembayaran FROM set_biaya WHERE jenis_pembayaran NOT LIKE 'SPP%' ORDER BY jenis_pembayaran ASC";
$result_jenis = mysqli_query($conn, $query_jenis);
while ($row = mysqli_fetch_assoc($result_jenis)) {
    $jenis_pembayaran_options[] = $row['jenis_pembayaran'];
}
// Tambahkan SPP secara manual di awal
array_unshift($jenis_pembayaran_options, 'SPP');

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Deposit Siswa | Aplikasi Pembayaran Siswa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        /* CSS umum (tidak ada perubahan signifikan) */
        :root {
            --primary-color: #007bff; 
            --secondary-color: #6c757d;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --dark-text: #343a40;
            --light-bg: #f8f9fa;
            --sidebar-bg: #2c3e50; 
        }
        
        body { font-family: 'Poppins', sans-serif; margin: 0; padding: 0; background-color: var(--light-bg); color: var(--dark-text); line-height: 1.6; }
        .main-container { display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background-color: var(--sidebar-bg); color: white; padding: 22px 0; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1); position: fixed; top: 0; left: 0; z-index: 1000; height: 100%; }
        .sidebar-header { text-align: center; padding: 10px 20px 30px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar-header h2 { font-size: 1.2rem; margin: 0; font-weight: 600; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li a { display: flex; align-items: center; padding: 12px 20px; color: #ecf0f1; text-decoration: none; transition: background-color 0.3s, color 0.3s; font-size: 0.95rem; }
        .sidebar-menu li a:hover { background-color: #34495e; color: white; }
        .sidebar-menu li a.active { background-color: var(--primary-color); color: white; border-left: 5px solid #3498db; }
        .sidebar-menu li a i { margin-right: 10px; font-size: 1.1rem; }
        
        .content-wrapper { flex-grow: 1; margin-left: 250px; padding: 30px; }
        .content-header { margin-bottom: 30px; border-bottom: 2px solid #e9ecef; padding-bottom: 15px; }
        .content-header h2 { font-size: 1.8rem; color: var(--dark-text); margin: 0; font-weight: 600; }
        .container { background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); padding: 25px; margin-bottom: 20px; border: 1px solid #e0e0e0; }
        .container h3 { font-size: 1.4rem; color: var(--dark-text); padding-bottom: 10px; margin-top: 0; margin-bottom: 20px; font-weight: 600; }
        
        .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
        .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
        .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
        .alert-info { color: #0c5460; background-color: #d1ecf1; border-color: #bee5eb; }

        .info-box { background-color: #e6f7ff; border: 1px solid #91d5ff; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .info-box p { margin: 5px 0; }
        .info-box strong { font-size: 1.1rem; color: var(--info-color); }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
        .form-group input[type="number"], .form-group select, .form-group textarea { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #ced4da; 
            border-radius: 4px; 
            box-sizing: border-box;
        }
        .btn-submit { 
            background-color: var(--primary-color); 
            color: white; 
            padding: 10px 15px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            font-weight: 600;
        }
        .btn-submit:hover { background-color: #0056b3; }
        .highlight { font-weight: 700; color: var(--danger-color); }

        #spp_months_container table { width: 100%; border-collapse: collapse; }
        #spp_months_container table td { padding: 5px 0; border-bottom: 1px dotted #e9ecef; }
        #spp_months_container table tr:last-child td { border-bottom: none; }
        #spp_months_container input[type="checkbox"] { margin-right: 10px; transform: scale(1.2); }
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
                <li><a href="laporan.php"><i class="fas fa-chart-line"></i> Lihat Laporan</a></li>
                <li><a href="laporan_per_kelas.php" class="active"><i class="fas fa-table"></i> Laporan Per Kelas</a></li> 
                <li><a href="laporan_tunggakan.php"><i class="fas fa-exclamation-triangle"></i> Laporan Tunggakan</a></li>
				<li><a href="pengguna.php"><i class="fas fa-users"></i> Kelola Pengguna</a></li> 
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>

        <div class="content-wrapper">
            <div class="content-header">
                <h2><i class="fas fa-exchange-alt"></i> Transfer Sisa Lebih Bayar (Deposit)</h2>
            </div>

            <?php if (!$siswa_data): ?>
                <div class="alert alert-danger">Data siswa tidak ditemukan atau ID tidak valid.</div>
            <?php else: ?>
                
                <?php if ($pesan): ?>
                    <div class="alert alert-<?php echo $tipe_pesan; ?>">
                        <?php echo $pesan; ?>
                    </div>
                <?php endif; ?>

                <div class="container">
                    <h3>Detail Siswa Penerima Deposit</h3>
                    <div class="info-box">
                        <p>Nama Siswa: **<?php echo htmlspecialchars($siswa_data['nama_lengkap']); ?>**</p>
                        <p>NISN: **<?php echo htmlspecialchars($siswa_data['nisn']); ?>**</p>
                        <p>Kelas: **<?php echo htmlspecialchars($siswa_data['nama_kelas']); ?>**</p>
                        <p>Tahun Ajaran: **<?php echo htmlspecialchars($siswa_data['nama_tahun']); ?>**</p>
                        <p>Sisa Deposit Saat Ini: <strong style="color: var(--primary-color);"><?php echo formatRupiah($max_deposit); ?></strong></p>
                    </div>

                    <?php if ($max_deposit > 0): ?>
                        <form method="POST">
                            <input type="hidden" name="id_siswa" value="<?php echo $id_siswa; ?>">
                            <input type="hidden" name="max_deposit" id="max_deposit" value="<?php echo $max_deposit; ?>"> 

                            <div class="form-group">
                                <label for="jenis_pembayaran_tujuan">Dialihkan untuk Pembayaran:</label>
                                <select name="jenis_pembayaran_tujuan" id="jenis_pembayaran_tujuan" required>
                                    <option value="">-- Pilih Jenis Pembayaran --</option>
                                    <?php foreach ($jenis_pembayaran_options as $jenis): ?>
                                        <option value="<?php echo htmlspecialchars($jenis); ?>"><?php echo htmlspecialchars($jenis); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div id="dynamic_form_container">
                                </div>
                            
                            <div class="form-group">
                                <label for="nominal_transfer">Nominal Transfer:</label>
                                <input type="number" name="nominal_transfer" id="nominal_transfer" min="1" max="<?php echo $max_deposit; ?>" step="1" required placeholder="Masukkan nominal transfer">
                                <small id="nominal_help_text">Masukkan nominal transfer. **Akan dihitung otomatis jika SPP dipilih.**</small>
                            </div>

                             <div class="form-group">
                                <label for="deskripsi_tambahan">Deskripsi Tambahan (Wajib untuk Non-SPP):</label>
                                <textarea name="deskripsi_tambahan" id="deskripsi_tambahan" rows="2" placeholder="Contoh: Pelunasan biaya Uji Kompetensi 2025"></textarea>
                            </div>

                            <button type="submit" name="transfer" class="btn-submit"><i class="fas fa-check-circle"></i> Catat Pengalihan Deposit</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-success">Siswa ini tidak memiliki sisa lebih bayar (deposit) saat ini.</div>
                    <?php endif; ?>

                </div>
            <?php endif; ?>
        </div>
    </div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const selectJenis = document.getElementById('jenis_pembayaran_tujuan');
        const dynamicContainer = document.getElementById('dynamic_form_container');
        const nominalInput = document.getElementById('nominal_transfer');
        const deskripsiInput = document.getElementById('deskripsi_tambahan');
        const nominalHelpText = document.getElementById('nominal_help_text');
        const maxDeposit = parseFloat(document.getElementById('max_deposit').value);
        
        // Data SPP Tunggakan dari PHP disuntikkan ke JavaScript
        const SPP_TUNGGAKAN = <?php echo json_encode($data_spp_tunggakan); ?>;

        // Helper Rupiah (untuk tampilan di JS)
        function formatRupiah(angka) {
             const number_string = Math.floor(angka).toString();
             let sisa    = number_string.length % 3;
             let rupiah  = number_string.substr(0, sisa);
             const ribuan = number_string.substr(sisa).match(/\d{3}/g);
                 
             if (ribuan) {
                 separator = sisa ? '.' : '';
                 rupiah += separator + ribuan.join('.');
             }
             return 'Rp ' + rupiah;
        }

        // --- FUNGSI SPP ---
        function loadSppMonths() {
            if (SPP_TUNGGAKAN.length === 0) {
                dynamicContainer.innerHTML = '<div class="alert alert-success">Semua tunggakan SPP telah lunas.</div>';
                nominalInput.value = 0;
                return;
            }

            let html = `
                <div class="form-group" style="border: 1px dashed var(--info-color); padding: 15px; border-radius: 4px; margin-bottom: 15px;">
                    <h4>Pilih Bulan SPP yang Dibayar:</h4>
                    <table>
            `;
            let currentTotal = 0; 
            
            SPP_TUNGGAKAN.forEach((item, index) => {
                const nominal = parseFloat(item.nominal);
                let checked = false;

                if (currentTotal + nominal <= maxDeposit) {
                    checked = true;
                    currentTotal += nominal;
                }

                html += `
                    <tr>
                        <td>
                            <input type="checkbox" name="spp_months[]" 
                                   data-nominal="${nominal}" 
                                   value="${item.bulan_tahun}" 
                                   id="spp_${index}" ${checked ? 'checked' : ''}
                                   >
                        </td>
                        <td>
                            <label for="spp_${index}">${item.bulan_tahun}</label>
                        </td>
                        <td style="text-align: right; color: var(--danger-color);">${formatRupiah(nominal)}</td>
                    </tr>
                `;
            });
            html += '</table></div>';
            
            dynamicContainer.innerHTML = html;
            
            document.querySelectorAll('input[name="spp_months[]"]').forEach(checkbox => {
                checkbox.addEventListener('change', updateNominalAndCheckLimit);
            });
            
            updateNominalAndCheckLimit(); // Hitung nominal awal
        }

        function updateNominalAndCheckLimit() {
            let totalNominal = 0;
            const checkedMonths = document.querySelectorAll('input[name="spp_months[]"]:checked');
            
            checkedMonths.forEach(checkbox => {
                totalNominal += parseFloat(checkbox.dataset.nominal);
            });

            if (totalNominal > maxDeposit) {
                 // Hanya alert, user harus uncheck sendiri
                 alert("Total pembayaran melebihi sisa deposit (" + formatRupiah(maxDeposit) + "). Harap periksa kembali centangan Anda.");
            }
            
            const finalNominal = Math.min(totalNominal, maxDeposit);
            nominalInput.value = finalNominal;
        }
        
        // --- FUNGSI NON-SPP ---
        function loadNonSppForm() {
             // Tidak ada form khusus, kita hanya mengandalkan input nominal dan deskripsi_tambahan
             dynamicContainer.innerHTML = '';
             deskripsiInput.placeholder = "Contoh: Pelunasan biaya Uji Kompetensi 2025 (Wajib diisi)";
        }
        
        // --- EVENT LISTENER UTAMA ---
        selectJenis.addEventListener('change', function() {
            const selectedJenis = this.value;
            
            // Reset state
            dynamicContainer.innerHTML = '';
            nominalInput.readOnly = false;
            nominalInput.required = true;
            deskripsiInput.required = false;
            nominalInput.value = '';
            nominalHelpText.textContent = 'Masukkan nominal transfer. **Akan dihitung otomatis jika SPP dipilih.**';


            if (selectedJenis === 'SPP') {
                nominalInput.readOnly = true;
                nominalInput.required = false; // Karena nilai 0 mungkin valid jika sudah lunas
                deskripsiInput.placeholder = "Deskripsi opsional tambahan untuk pembayaran SPP.";
                loadSppMonths();
            } else if (selectedJenis && selectedJenis !== '') {
                // Logika untuk NON-SPP
                nominalHelpText.textContent = 'Masukkan nominal transfer. Nominal harus <= ' + formatRupiah(maxDeposit) + '.';
                deskripsiInput.required = true;
                loadNonSppForm();
            } else {
                 // Jika tidak memilih apa-apa
                 deskripsiInput.placeholder = "Contoh: Pelunasan biaya Uji Kompetensi 2025";
            }
        });
        
    });
</script>
</body>
</html>