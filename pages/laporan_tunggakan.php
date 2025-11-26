<?php
session_start();
// Pastikan path ini benar!
include '../includes/koneksi.php'; 

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php"); 
    exit();
}

// --- FUNGSI HELPER ---

function formatRupiah($angka) {
    // Memastikan input adalah float atau 0
    $angka = (float)($angka ?? 0); 
    // Menggunakan fungsi bawaan number_format untuk format Rupiah
    return "Rp " . number_format($angka, 0, ',', '.');
}

// Fungsi untuk mendapatkan semua biaya Non-SPP dan Nominal Khusus Siswa
function getNonSppBiaya($conn, $id_siswa, $tahun_ajaran_siswa) {
    $biaya_standar = [];
    $total_tunggakan_non_spp = 0;
    $keterangan_non_spp = [];

    // 1. Ambil Biaya Non-SPP Standar dari set_biaya untuk TA Siswa
    $query_biaya_non_spp = "
        SELECT jenis_pembayaran, nominal 
        FROM set_biaya 
        WHERE jenis_pembayaran NOT LIKE 'SPP%' 
        AND tahun_ajaran = ?
    ";
    
    if ($stmt_set_biaya = $conn->prepare($query_biaya_non_spp)) {
        $stmt_set_biaya->bind_param("s", $tahun_ajaran_siswa);
        $stmt_set_biaya->execute();
        $result_set_biaya = $stmt_set_biaya->get_result();
        while ($row = $result_set_biaya->fetch_assoc()) {
            $biaya_standar[$row['jenis_pembayaran']] = (float)$row['nominal'];
        }
        $stmt_set_biaya->close();
    }

    // 2. Ambil Biaya Khusus dari nominal_biaya_siswa (akan menimpa yang standar)
    $query_biaya_khusus = "
        SELECT jenis_pembayaran, nominal_biaya 
        FROM nominal_biaya_siswa 
        WHERE id_siswa = ? AND tahun_ajaran = ?
    ";
    if ($stmt_khusus = $conn->prepare($query_biaya_khusus)) {
        $stmt_khusus->bind_param("is", $id_siswa, $tahun_ajaran_siswa);
        $stmt_khusus->execute();
        $result_khusus = $stmt_khusus->get_result();
        while ($row = $result_khusus->fetch_assoc()) {
            // Timpa nominal standar dengan nominal khusus
            $biaya_standar[$row['jenis_pembayaran']] = (float)$row['nominal_biaya']; 
        }
        $stmt_khusus->close();
    }

    // 3. Hitung Tunggakan untuk setiap jenis pembayaran Non-SPP
    foreach ($biaya_standar as $jenis => $nominal_seharusnya) {
        $query_dibayar = "SELECT SUM(jumlah) AS total_dibayar FROM transaksi WHERE id_siswa = ? AND jenis_pembayaran = ?";
        
        if ($stmt_dibayar = $conn->prepare($query_dibayar)) {
            $stmt_dibayar->bind_param("is", $id_siswa, $jenis);
            $stmt_dibayar->execute();
            $total_dibayar = $stmt_dibayar->get_result()->fetch_assoc()['total_dibayar'] ?? 0;
            $stmt_dibayar->close();
        } else {
            $total_dibayar = 0;
        }

        $sisa_tunggakan = $nominal_seharusnya - $total_dibayar;

        if ($sisa_tunggakan > 0) {
            $total_tunggakan_non_spp += $sisa_tunggakan;
            $keterangan_non_spp[] = "Tunggakan {$jenis}: " . formatRupiah($sisa_tunggakan);
        } else {
            $keterangan_non_spp[] = "{$jenis}: Lunas";
        }
    }
    
    // Jika tidak ada tunggakan Non-SPP, tampilkan keterangan lunas yang rapi
    if ($total_tunggakan_non_spp == 0 && !empty($keterangan_non_spp)) {
           $keterangan_non_spp = ['Lunas (Tidak Ada Tunggakan Non-SPP)'];
    } elseif (empty($keterangan_non_spp)) {
           $keterangan_non_spp = ['Tidak Ada Kewajiban Non-SPP Terdaftar'];
    }


    return [
        'tunggakan_non_spp' => $total_tunggakan_non_spp,
        'keterangan_non_spp' => implode('<br>', $keterangan_non_spp)
    ];
}


// --- DATA GLOBAL & FILTER ---

// PENGUBAHAN 1: Filter Kelas menjadi array (Multi-select)
$id_kelas_dipilih = $_GET['id_kelas'] ?? [];
if (!is_array($id_kelas_dipilih)) {
    $id_kelas_dipilih = [$id_kelas_dipilih]; // Pastikan selalu array
}

// PERUBAHAN 2: Menangkap input filter nama siswa
$search_nama_siswa = $_GET['nama_siswa'] ?? null;

// PERUBAHAN 3: Menangkap input filter jenis tunggakan
$filter_tunggakan_jenis = $_GET['filter_tunggakan'] ?? 'semua_tunggakan'; 


// Ambil semua data kelas untuk dropdown filter
$query_kelas = "SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas ASC";
$result_kelas = mysqli_query($conn, $query_kelas);

// Ambil Tahun Ajaran Aktif
$query_tahun_aktif = "SELECT nama_tahun FROM tahun_ajaran WHERE aktif = 1";
$result_tahun_aktif = mysqli_query($conn, $query_tahun_aktif);
$data_tahun_aktif = mysqli_fetch_assoc($result_tahun_aktif);
$tahun_aktif = $data_tahun_aktif['nama_tahun'] ?? 'Tidak Ada';


// --- PENGAMBILAN DATA SISWA ---

$daftar_siswa = [];
$query_siswa = "
    SELECT 
        s.id_siswa, s.nama_lengkap, k.nama_kelas, ta.nama_tahun as tahun_ajaran_siswa, s.status_spp,
        s.bulan_mulai_spp, s.tahun_mulai_spp
    FROM 
        siswa s 
    JOIN 
        kelas k ON s.id_kelas = k.id_kelas
    JOIN 
        tahun_ajaran ta ON s.id_tahun_ajaran = ta.id_tahun_ajaran
";

// LOGIKA PERBAIKAN: Membangun WHERE clause dengan prepared statements
$where_clauses = [];
$params = [];
$types = '';

// KLAUSA FILTER KELAS (Dukungan multiple selection)
if (!empty($id_kelas_dipilih) && in_array('', $id_kelas_dipilih) === false) {
    // Membuat placeholder untuk IN (?)
    $placeholders_kelas = implode(',', array_fill(0, count($id_kelas_dipilih), '?'));
    $where_clauses[] = "s.id_kelas IN ($placeholders_kelas)";
    foreach ($id_kelas_dipilih as $id) {
        $params[] = $id;
        $types .= 'i';
    }
}

// KLAUSA FILTER NAMA SISWA
if (!empty($search_nama_siswa)) {
    $where_clauses[] = "s.nama_lengkap LIKE ?";
    $params[] = '%' . $search_nama_siswa . '%';
    $types .= 's';
}

if (!empty($where_clauses)) {
    $query_siswa .= " WHERE " . implode(" AND ", $where_clauses);
}

$query_siswa .= " ORDER BY k.nama_kelas, s.nama_lengkap";

// Persiapan statement
$stmt_siswa = $conn->prepare($query_siswa);

if ($stmt_siswa === false) {
    die("Error preparing student statement: " . $conn->error);
}

if (!empty($params)) {
    // Dynamic binding for prepared statements
    $stmt_siswa->bind_param($types, ...$params);
}

$stmt_siswa->execute();
$result_siswa = $stmt_siswa->get_result();
$daftar_siswa = $result_siswa->fetch_all(MYSQLI_ASSOC);
$stmt_siswa->close();


// --- PENGOLAHAN LOGIKA TUNGGAKAN ---

$laporan_gabungan = [];
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

// 1. Ambil semua data transaksi SPP, termasuk JUMLAH, untuk SEMUA siswa yang difilter (satu query besar)
$id_siswa_list = array_column($daftar_siswa, 'id_siswa');
// Array untuk menyimpan total yang sudah dibayar per bulan/tahun untuk setiap siswa
$total_dibayar_per_bulan_semua = []; 
$latest_paid_date_ts_semua = []; 

if (!empty($id_siswa_list)) {
    $placeholders = implode(',', array_fill(0, count($id_siswa_list), '?'));
    $types_in = str_repeat('i', count($id_siswa_list));
    
    // Perbaikan: Ambil kolom 'jumlah' juga!
    $query_bayar_spp_all = "SELECT id_siswa, jumlah, deskripsi FROM transaksi WHERE id_siswa IN ($placeholders) AND jenis_pembayaran LIKE 'SPP%'";
    
    $stmt_spp_all = $conn->prepare($query_bayar_spp_all);
    
    if ($stmt_spp_all) {
        // Menggabungkan array $id_siswa_list ke dalam bind_param dengan operator splat (...)
        $stmt_spp_all->bind_param($types_in, ...$id_siswa_list); 
        $stmt_spp_all->execute();
        $result_bayar_spp_all = $stmt_spp_all->get_result();
        
        while ($row = $result_bayar_spp_all->fetch_assoc()) {
            $id_siswa = $row['id_siswa'];
            $jumlah = (float)$row['jumlah']; // Ambil jumlah pembayaran
            
            if (!isset($total_dibayar_per_bulan_semua[$id_siswa])) {
                $total_dibayar_per_bulan_semua[$id_siswa] = [];
                $latest_paid_date_ts_semua[$id_siswa] = 0;
            }
            
            // Parsing deskripsi untuk mendapatkan bulan/tahun yang dibayar
            if (preg_match_all('/(Juli|Agustus|September|Oktober|November|Desember|Januari|Februari|Maret|April|Mei|Juni)\s(\d{4})/', $row['deskripsi'], $matches, PREG_SET_ORDER)) {
                
                $jumlah_bulan_dicatat = count($matches);
                // Asumsi: Jika 2 bulan dibayar, jumlah dibagi 2. Jika 1 bulan, tidak dibagi.
                $jumlah_per_bulan = ($jumlah_bulan_dicatat > 0) ? $jumlah / $jumlah_bulan_dicatat : 0; 

                foreach ($matches as $match) {
                    $bulan = $match[1];
                    $tahun = $match[2];
                    $key = "{$bulan} {$tahun}"; // Contoh: "September 2025"
                    
                    // AKUMULASI: Tambahkan jumlah pembayaran ke key bulan/tahun
                    $total_dibayar_per_bulan_semua[$id_siswa][$key] = 
                        ($total_dibayar_per_bulan_semua[$id_siswa][$key] ?? 0) + $jumlah_per_bulan;
                    
                    // Update latest paid date (jika ini bulan terbaru yang terbayar/tercicil)
                    $timestamp = strtotime("{$tahun}-{$bulan_to_num[$bulan]}-01");
                    if ($timestamp > $latest_paid_date_ts_semua[$id_siswa]) {
                        $latest_paid_date_ts_semua[$id_siswa] = $timestamp;
                    }
                }
            }
        }
        $stmt_spp_all->close();
    }
}


// 2. Loop Utama untuk menghitung tunggakan per siswa
foreach ($daftar_siswa as $siswa) {
    $id_siswa = $siswa['id_siswa'];
    $tahun_ajaran_siswa = $siswa['tahun_ajaran_siswa'];
    
    $tahun_masuk_awal = (int)explode('/', $tahun_ajaran_siswa)[0];
    $tahun_mulai = (int)($siswa['tahun_mulai_spp']);
    $bulan_mulai_idx = (int)($siswa['bulan_mulai_spp']);
    $status_spp = $siswa['status_spp'];

    // --- Ambil Nominal SPP (Tetap harus diambil di dalam loop) ---
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
    
    // Jika nominal SPP 0, anggap tidak ada kewajiban
    if ($nominal_per_bulan <= 0) {
        $total_tunggakan_spp = 0;
        $dibayar_s_d_spp = 'Lunas (Nominal SPP Rp 0)';
        $bulan_belum_lunas_spp_keterangan = 'Lunas';
        goto skip_spp_calculation;
    }


    // --- Logika Tunggakan SPP (Menggunakan data akumulasi) ---
    
    $total_dibayar_siswa = $total_dibayar_per_bulan_semua[$id_siswa] ?? [];
    $bulan_belum_lunas_spp_keterangan = [];
    $total_tunggakan_spp = 0;
    $bulan_terakhir_lunas = 0; // Timestamp bulan terakhir yang lunas nominalnya 
    $tahun_sekarang_plus_satu = $tahun_sekarang + 1;
    $consecutive_paid_broken = false; // <-- FLAG BARU: Melacak putusnya urutan pembayaran lunas

    // Loop dari tahun masuk siswa hingga tahun depan (untuk mencakup Juni tahun ajaran ini)
    for ($tahun_loop = $tahun_masuk_awal; $tahun_loop <= $tahun_sekarang_plus_satu; $tahun_loop++) { 
        foreach ($bulan_semua as $bulan_nama => $bulan_angka) {
            
            // Penentuan Tahun SPP
            $tahun_spp = ($bulan_angka >= 7) ? $tahun_loop : $tahun_loop + 1;
            $bulan_tahun_string = "{$bulan_nama} {$tahun_spp}";
            
            // Batas waktu: Apakah bulan ini sudah wajib dibayar (sudah terlewati/bulan berjalan)?
            $is_before_or_current_month = ($tahun_spp < $tahun_sekarang) || ($tahun_spp == $tahun_sekarang && $bulan_angka <= $bulan_sekarang_idx);
            
            // Batas waktu mulai SPP siswa
            $is_after_or_at_start_month = ($tahun_spp > $tahun_mulai) || ($tahun_spp == $tahun_mulai && $bulan_angka >= $bulan_mulai_idx);

            // Saring bulan-bulan yang tidak relevan (siswa belum mencapai masa wajib bayar)
            if (!$is_after_or_at_start_month) {
                continue;
            }
            
            $nominal_dibayar = $total_dibayar_siswa[$bulan_tahun_string] ?? 0;
            
            // --- Menghitung Tunggakan (Hanya bulan yang sudah jatuh tempo) ---
            if ($is_before_or_current_month) {
                
                $sisa_tunggakan = $nominal_per_bulan - $nominal_dibayar;
                
                if ($sisa_tunggakan > 0) {
                    $total_tunggakan_spp += $sisa_tunggakan;
                    // Keterangan tunggakan menyertakan sisa nominalnya
                    $bulan_belum_lunas_spp_keterangan[] = "{$bulan_tahun_string} (Sisa: " . formatRupiah($sisa_tunggakan) . ")";
                } 
            }
            
            // --- LOGIKA LUNAS S.D. (Penentuan Bulan Lunas Terakhir Berurutan) ---
            
            if ($nominal_dibayar >= $nominal_per_bulan) {
                // Jika lunas penuh, perbarui bulan lunas terakhir HANYA JIKA urutan pembayaran belum terputus.
                if (!$consecutive_paid_broken) {
                    $current_ts = strtotime("{$tahun_spp}-{$bulan_to_num[$bulan_nama]}-01");
                    $bulan_terakhir_lunas = $current_ts;
                }
            } else {
                // Jika belum lunas penuh (tercicil atau nol), urutan pembayaran terputus.
                $consecutive_paid_broken = true; 
                // *** PENTING: break 2; DIHAPUS agar loop tetap berjalan untuk menghitung semua tunggakan. ***
            }
        }
    }
    
    // --- Penentuan Keterangan Bulan Terakhir Lunas ---
    
    $dibayar_s_d_spp = 'Belum Lunas';
    
    // Konversi Timestamp menjadi String Bulan/Tahun
    if ($bulan_terakhir_lunas > 0) {
        $nama_bulan_id = date('F', $bulan_terakhir_lunas); 
        $tahun_lunas = date('Y', $bulan_terakhir_lunas);
        
        $nama_bulan_id_map = [
            'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'April' => 'April', 
            'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus', 
            'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'
        ];
        $nama_bulan_indo = $nama_bulan_id_map[$nama_bulan_id] ?? $nama_bulan_id;
        $dibayar_s_d_spp = "{$nama_bulan_indo} {$tahun_lunas}";
    }

    // Logika Khusus untuk kasus belum ada kewajiban SPP ATAU sudah lunas penuh
    if (($total_tunggakan_spp ?? 0) <= 0) {
        if ($bulan_terakhir_lunas == 0) {
             $dibayar_s_d_spp = 'Lunas Penuh (Belum Ada Kewajiban)';
        } elseif (!empty($dibayar_s_d_spp)) {
             $bulan_belum_lunas_spp_keterangan = ['Lunas Penuh'];
        }
    }


    skip_spp_calculation: // Label untuk loncat (Tetap)

    // --- Logika Tunggakan Non-SPP ---
    $non_spp_data = getNonSppBiaya($conn, $id_siswa, $tahun_ajaran_siswa);

    // --- Gabungkan Hasil ---
    $laporan_gabungan[$id_siswa] = [
        'nama_siswa' => $siswa['nama_lengkap'],
        'nama_kelas' => $siswa['nama_kelas'],
        'tahun_masuk_siswa' => $siswa['tahun_ajaran_siswa'],
        
        // Data SPP
        'tunggakan_spp' => $total_tunggakan_spp ?? 0,
        'dibayar_s_d_spp' => $dibayar_s_d_spp,
        'keterangan_spp' => !empty($bulan_belum_lunas_spp_keterangan) ? implode(', ', $bulan_belum_lunas_spp_keterangan) : 'Lunas Penuh',
        
        // Data Non-SPP
        'tunggakan_non_spp' => $non_spp_data['tunggakan_non_spp'],
        'keterangan_non_spp' => $non_spp_data['keterangan_non_spp']
    ];
}


// --- PERUBAHAN 4: Post-Filter Berdasarkan Jenis Tunggakan ---
$laporan_terfilter = [];
foreach ($laporan_gabungan as $id_siswa => $data) {
    $tunggakan_spp = $data['tunggakan_spp'] > 0;
    $tunggakan_non_spp = $data['tunggakan_non_spp'] > 0;
    $total_tunggakan = $data['tunggakan_spp'] + $data['tunggakan_non_spp'];
    
    // Logika Post-Filter
    if ($filter_tunggakan_jenis == 'semua_tunggakan') {
        // Tampilkan semua siswa, termasuk yang lunas, untuk melihat status 'Dibayar S.D.'
        $laporan_terfilter[$id_siswa] = $data;
    } elseif ($total_tunggakan > 0) {
        if ($filter_tunggakan_jenis == 'spp_saja' && $tunggakan_spp && !$tunggakan_non_spp) {
            $laporan_terfilter[$id_siswa] = $data;
        } elseif ($filter_tunggakan_jenis == 'non_spp_saja' && !$tunggakan_spp && $tunggakan_non_spp) {
            $laporan_terfilter[$id_siswa] = $data;
        } elseif ($filter_tunggakan_jenis == 'keduanya' && $tunggakan_spp && $tunggakan_non_spp) {
            $laporan_terfilter[$id_siswa] = $data;
        }
    }
    
}
// Pindahkan data filter ke data gabungan untuk tampilan
$laporan_gabungan = $laporan_terfilter;


?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Tunggakan - Sistem Pembayaran Sekolah</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        /* [GAYA CSS TETAP SAMA] */
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
            position: fixed; /* Tambahkan ini agar sidebar tetap */
            height: 100%;
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
        .sidebar-header i {
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
            margin-left: 250px; /* Sesuaikan dengan lebar sidebar */
            padding: 30px;
        }

        .content-header {
            margin-bottom: 30px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 15px;
        }

        .content-header h2 {
            font-size: 1.8rem;
            color: var(--dark-text);
            margin: 0;
            font-weight: 600;
        }

        .content-header p {
            color: var(--secondary-color);
            margin-top: 5px;
        }
        
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Gaya Khusus Laporan */
        .filter-container {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            border-left: 5px solid var(--warning-color);
        }
        
        .filter-group {
             display: flex; 
             align-items: center; 
             gap: 10px; 
             margin-right: 20px;
        }
        
        .report-section {
            margin-top: 20px;
            padding: 25px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .report-section h3 {
            font-size: 1.4rem;
            color: var(--dark-text);
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            margin-top: 0;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th, .data-table td {
            vertical-align: top;
            padding: 10px 12px;
            border: 1px solid #e9ecef;
            text-align: left;
        }
        .data-table th {
            background-color: var(--danger-color); /* Merah untuk Tunggakan */
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            text-align: center;
        }
        .data-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .data-table tr:hover {
            background-color: #e9ecef;
        }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background-color 0.3s;
        }
        .btn[type="submit"] {
            background-color: var(--primary-color);
            color: white;
        }
        .btn[type="submit"]:hover {
            background-color: #0056b3;
        }
        .btn:not([type="submit"]) {
            background-color: var(--success-color);
            color: white;
        }
        .btn:not([type="submit"]):hover {
            background-color: #27ae60;
        }
        
        /* Tambahan untuk Total */
        .total-tunggakan-row td {
            background-color: #f8d7da !important;
            font-weight: bold;
            font-size: 1.1em;
        }

        /* Gaya Khusus Filter Kolom */
        .filter-row td {
            padding: 5px 12px !important;
            vertical-align: middle !important;
            background-color: #f0f0f0; 
        }
        .filter-row input[type="text"] {
            width: 100%;
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 0.85rem;
            height: 30px; 
            font-family: 'Poppins', sans-serif;
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
                <li><a href="laporan.php"><i class="fas fa-chart-line"></i> Lihat Laporan</a></li>
                <li><a href="laporan_per_kelas.php"><i class="fas fa-table"></i> Laporan Per Kelas</a></li>
                <li><a href="laporan_tunggakan.php" class="active"><i class="fas fa-exclamation-triangle"></i> Laporan Tunggakan</a></li>
                <li><a href="pengguna.php"><i class="fas fa-users"></i> Kelola Pengguna</a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
        
        <div class="content-wrapper">
            <div class="content-header">
                <h2><i class="fas fa-exclamation-triangle"></i> Laporan Tunggakan Siswa</h2>
                <p>Halaman ini menampilkan daftar siswa yang masih memiliki tunggakan pembayaran. Tahun Ajaran Aktif: **<?php echo htmlspecialchars($tahun_aktif); ?>**</p>
            </div>
            
            <div class="container">
                <form action="laporan_tunggakan.php" method="GET" id="filterForm">
                    <div class="filter-container">
                        <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: center;">
                            
                            <div class="filter-group">
                                <label for="id_kelas" style="font-weight: 600;">Filter Kelas (Bisa Pilih Banyak):</label>
                                <select name="id_kelas[]" id="id_kelas" multiple style="padding: 8px; border: 1px solid #ccc; border-radius: 5px; height: 120px;">
                                    <option value="" <?php echo empty($id_kelas_dipilih) || in_array('', $id_kelas_dipilih) ? 'selected' : ''; ?>>Semua Kelas</option>
                                    <?php mysqli_data_seek($result_kelas, 0); while ($row = mysqli_fetch_assoc($result_kelas)): ?>
                                        <option value="<?php echo htmlspecialchars($row['id_kelas']); ?>" 
                                            <?php echo in_array($row['id_kelas'], $id_kelas_dipilih) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($row['nama_kelas']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="filter_tunggakan" style="font-weight: 600;">Filter Jenis Tunggakan:</label>
                                <select name="filter_tunggakan" id="filter_tunggakan" style="padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                                    <option value="semua_tunggakan" <?php echo ($filter_tunggakan_jenis == 'semua_tunggakan') ? 'selected' : ''; ?>>Semua Tunggakan</option>
                                    <option value="spp_saja" <?php echo ($filter_tunggakan_jenis == 'spp_saja') ? 'selected' : ''; ?>>Hanya Tunggakan SPP</option>
                                    <option value="non_spp_saja" <?php echo ($filter_tunggakan_jenis == 'non_spp_saja') ? 'selected' : ''; ?>>Hanya Tunggakan Non-SPP</option>
                                    <option value="keduanya" <?php echo ($filter_tunggakan_jenis == 'keduanya') ? 'selected' : ''; ?>>Tunggakan SPP & Non-SPP</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn"><i class="fas fa-filter"></i> Tampilkan Laporan</button>
                            <?php if (!empty($laporan_gabungan)): ?>
                                <a href="export_tunggakan.php?id_kelas=<?php echo htmlspecialchars(implode(',', $id_kelas_dipilih)); ?>&nama_siswa=<?php echo htmlspecialchars($search_nama_siswa); ?>&filter_tunggakan=<?php echo htmlspecialchars($filter_tunggakan_jenis); ?>" class="btn">
                                    <i class="fas fa-file-excel"></i> Export ke Excel
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="report-section">
                        <h3><i class="fas fa-list-alt"></i> Daftar Tunggakan Siswa</h3>
                        
                        <?php if (count($laporan_gabungan) === 0): ?>
                            <div style="text-align: center; padding: 30px; background-color: #fff3cd; border: 1px solid #ffeeba; color: #856404; border-radius: 5px;">
                                <p><i class="fas fa-info-circle"></i> Tidak ada data siswa ditemukan untuk kriteria filter ini.</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Nama Siswa</th>
                                            <th>Kelas</th>
                                            <th>Tunggakan SPP</th>
                                            <th>Sudah LUNAS s.d.</th>
                                            <th>Keterangan SPP yang belum dibayar</th>
                                            <th>Tunggakan Non-SPP</th>
                                            <th>Keterangan Non-SPP</th>
                                            <th>Total Tunggakan</th>
                                        </tr>
                                        
                                        <tr class="filter-row">
                                            <td></td> 
                                            <td>
                                                <input 
                                                    type="text" 
                                                    name="nama_siswa" 
                                                    value="<?php echo htmlspecialchars($search_nama_siswa); ?>" 
                                                    placeholder="Cari Siswa..." 
                                                >
                                            </td>
                                            <td></td> <td></td> <td></td> <td></td> <td></td> <td></td> <td></td> 
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $no = 1; 
                                        $grand_total_tunggakan = 0;
                                        ?>
                                        <?php foreach ($laporan_gabungan as $data): ?>
                                            <?php
                                            $total_per_siswa = $data['tunggakan_spp'] + $data['tunggakan_non_spp'];
                                            $grand_total_tunggakan += $total_per_siswa;
                                            // Semua siswa di $laporan_gabungan harusnya sudah memiliki tunggakan > 0 karena sudah di post-filter.
                                            ?>
                                            <tr style="background-color: #fcebeb;">
                                                <td class="text-center"><?php echo htmlspecialchars($no++); ?></td>
                                                <td><?php echo htmlspecialchars($data['nama_siswa']); ?></td>
                                                <td class="text-center"><?php echo htmlspecialchars($data['nama_kelas']); ?></td>
                                                <td class="text-right" style="color: var(--danger-color);">
                                                    <?php echo formatRupiah($data['tunggakan_spp']); ?>
                                                </td>
                                                <td class="text-center"><strong><?php echo htmlspecialchars($data['dibayar_s_d_spp']); ?></strong></td>
                                                <td style="font-size: 0.9em; color: var(--danger-color);"><?php echo htmlspecialchars($data['keterangan_spp']); ?></td>
                                                <td class="text-right" style="color: var(--danger-color);">
                                                    <?php echo formatRupiah($data['tunggakan_non_spp']); ?>
                                                </td>
                                                <td style="font-size: 0.9em;"><?php echo $data['keterangan_non_spp']; ?></td>
                                                <td class="text-right" style="font-weight: 700; color: var(--danger-color);">
                                                    <?php echo formatRupiah($total_per_siswa); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <?php if ($no > 1): // Tampilkan grand total jika ada siswa tunggakan yang ditampilkan ?>
                                        <tr class="total-tunggakan-row">
                                            <td colspan="8" class="text-right">GRAND TOTAL TUNGGAKAN KESELURUHAN</td>
                                            <td class="text-right" style="color: var(--danger-color); font-size: 1.2em;">
                                                <?php echo formatRupiah($grand_total_tunggakan); ?>
                                            </td>
                                        </tr>
                                        <?php else: // Jika tidak ada siswa tunggakan setelah loop ?>
                                            <tr>
                                                <td colspan="9" class="text-center" style="padding: 20px; background-color: #d4edda; color: #155724;">
                                                    <i class="fas fa-check-circle"></i> Tidak ada siswa yang memiliki tunggakan berdasarkan filter yang dipilih.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                        
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </form> </div>
        </div>
    </div>
</body>
</html>
<?php 
if (isset($conn) && $conn) {
    mysqli_close($conn); 
}
?>