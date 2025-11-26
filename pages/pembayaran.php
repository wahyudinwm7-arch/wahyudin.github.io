<?php
session_start();
// Pastikan path ke koneksi.php benar
include '../includes/koneksi.php';

date_default_timezone_set('Asia/Jakarta');

// =================================================================
// --- VARIABEL HELPER & FUNGSI UTILITAS ---
// =================================================================
$months_en = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$months_id = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// Map untuk konversi angka bulan ke nama ID
$num_to_bulan = array_combine(range(1, 12), $months_id);
// Map untuk konversi nama bulan ID ke angka
$bulan_to_num = array_combine($months_id, range(1, 12));

function formatRupiah($angka) {
    return number_format($angka, 0, ',', '.');
}

/**
 * Fungsi untuk membersihkan nama pembayaran (menghapus nominal Rp XXXXX)
 * @param string $full_name Nama pembayaran lengkap dari dropdown (e.g., "SPP (Rp 60.000)")
 * @return string Nama pembayaran yang bersih (e.g., "SPP")
 */
function extractCleanPaymentName($full_name) {
    // Menghapus string "(Rp XXXXXX)" di akhir
    return preg_replace('/\s*\(Rp\s*[\d\.,]+\)\s*$/', '', $full_name);
}

// =================================================================
// --- Pengecekan Sesi dan Autentikasi ---
// =================================================================
if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

$id_pengguna_sesi = $_SESSION['id_pengguna'] ?? 0;

if ($id_pengguna_sesi === 0 && isset($_SESSION['nama_pengguna'])) {
    $nama_pengguna_sesi = $_SESSION['nama_pengguna'];
    $query_user_id = "SELECT id_pengguna FROM pengguna WHERE nama_pengguna = ?";
    $stmt_user = $conn->prepare($query_user_id);
    if ($stmt_user) {
        $stmt_user->bind_param("s", $nama_pengguna_sesi);
        $stmt_user->execute();
        $result_user = $stmt_user->get_result();
        if ($row_user = $result_user->fetch_assoc()) {
            $_SESSION['id_pengguna'] = $row_user['id_pengguna'];
            $id_pengguna_sesi = $row_user['id_pengguna'];
        }
        $stmt_user->close();
    }
}
$dicatat_oleh_id = $id_pengguna_sesi;

// =================================================================
// --- Variabel Global Inisialisasi ---
// =================================================================
$id_siswa = isset($_GET['id_siswa']) ? intval($_GET['id_siswa']) : 0;
$tanggal_default_form = date('Y-m-d');
$siswa = null;
$biaya_opsi = [];
$spp_saldo_per_bulan = []; 
$rekap_tunggakan_non_spp = [];
$nominal_spp_per_bulan = 0; // Nominal wajib SPP YANG SUDAH DIDISKON/NORMAL yang akan digunakan untuk perhitungan
$display_nominal_spp = 0; // Nominal SPP yang ditampilkan di header (setelah diskon)
$display_nominal_spp_full = 0; // Nominal SPP FULL (Nominal Normal) dari set_biaya
$status_spp_siswa = 'normal';
$nominal_diskon_spp = 0; // Nominal potongan diskon (Rp 10.000 jika diskon)
$next_spp_month_text = "N/A";
$tahun_ajaran_siswa = '';
$bulan_wajib = 7;
$tahun_wajib = (int)date('Y');

// =================================================================
// --- Logika Pengambilan Data Siswa dan Biaya (GET/Initial Load) ---
// =================================================================
if ($id_siswa > 0) {
    // 1. Ambil info siswa
    $query_siswa = "
        SELECT 
            s.id_siswa, s.nama_lengkap, s.nisn, k.nama_kelas, ta.nama_tahun AS tahun_ajaran,
            s.bulan_mulai_spp, s.tahun_mulai_spp, 
            s.status_spp 
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
        $tahun_ajaran_siswa = $siswa['tahun_ajaran'];
        $bulan_wajib = (int) ($siswa['bulan_mulai_spp'] ?? 7);
        $tahun_wajib = (int) ($siswa['tahun_mulai_spp'] ?? $tahun_wajib);
        
        $status_spp_siswa = strtolower($siswa['status_spp'] ?? 'normal');
        $nominal_diskon_spp = 0; // Reset
        
        // 2. Ambil data biaya dan nominal SPP per bulan
        // Keterangan di set_biaya harus sesuai dengan status_spp (e.g., "Normal" atau "Diskon") 
        $query_biaya = "SELECT jenis_pembayaran, nominal, keterangan FROM set_biaya WHERE tahun_ajaran = ?";
        $stmt_biaya = $conn->prepare($query_biaya);
        $stmt_biaya->bind_param("s", $tahun_ajaran_siswa);
        $stmt_biaya->execute();
        $result_biaya = $stmt_biaya->get_result();
        
        // Tahap 1: Ambil Nominal SPP YANG TEPAT (sesuai status) dari set_biaya
        // dan kumpulkan semua opsi biaya
        while ($row = $result_biaya->fetch_assoc()) {
            $row['display_name'] = $row['jenis_pembayaran'] . " (Rp " . formatRupiah($row['nominal']) . ")";
            $biaya_opsi[] = $row;
            
            // Logika baru untuk menentukan nominal wajib SPP per bulan
            if (strpos(strtoupper($row['jenis_pembayaran']), 'SPP') !== false) {
                // Ambil nominal SPP FULL (Nominal Normal) untuk perbandingan
                if (strtolower($row['keterangan']) === 'normal') {
                     if ((int)$row['nominal'] > $display_nominal_spp_full) {
                        $display_nominal_spp_full = (int)$row['nominal'];
                     }
                }

                $keterangan_spp = strtolower(trim($row['keterangan']));
                
                // Jika status siswa 'diskon', cari nominal SPP dengan keterangan 'diskon'
                if ($status_spp_siswa === 'diskon' && $keterangan_spp === 'diskon') {
                    $nominal_spp_per_bulan = (int)$row['nominal']; 
                    $display_nominal_spp = (int)$row['nominal']; 
                } 
                // Jika status siswa 'normal', cari nominal SPP dengan keterangan 'normal'
                else if ($status_spp_siswa === 'normal' && $keterangan_spp === 'normal') {
                    // Nominal SPP per bulan ditetapkan ke nominal Normal
                    $nominal_spp_per_bulan = (int)$row['nominal']; 
                    $display_nominal_spp = (int)$row['nominal']; 
                }
            }
        }
        $stmt_biaya->close();
        
        // Cek jika nominal diskon berhasil diterapkan untuk menghitung potongan display
        if ($status_spp_siswa === 'diskon' && $display_nominal_spp_full > 0) {
            $nominal_diskon_spp = max(0, $display_nominal_spp_full - $nominal_spp_per_bulan);
        }
        
        // Fallback jika nominal SPP masih nol
        if ($nominal_spp_per_bulan == 0 && $display_nominal_spp_full > 0) {
            // Jika tidak ditemukan nominal 'diskon' atau 'normal' yang spesifik, 
            // gunakan saja nominal FULL (normal) sebagai fallback
            $nominal_spp_per_bulan = $display_nominal_spp_full;
            $display_nominal_spp = $display_nominal_spp_full;
        }

        
        // =============================================================================
        // *** LOGIKA PENTING: MENGHITUNG SALDO SPP BERDASARKAN DESKRIPSI TRANSAKSI ***
        // =============================================================================
        $bulan_list = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];
        $bulan_list_pattern = implode('|', $bulan_list);
        
        // Hanya hitung saldo jika nominal SPP terdeteksi
        if ($nominal_spp_per_bulan > 0) {
            
            $query_spp_transactions = "
                SELECT jumlah, deskripsi 
                FROM transaksi 
                WHERE id_siswa = ? AND jenis_pembayaran LIKE '%SPP%' AND jenis_transaksi = 'masuk'
            ";
            $stmt_spp = $conn->prepare($query_spp_transactions);
            $stmt_spp->bind_param("i", $id_siswa);
            $stmt_spp->execute();
            $result_spp = $stmt_spp->get_result();

            // Inisialisasi saldo bulanan
            $spp_saldo_per_bulan = []; 

            while ($row_spp = $result_spp->fetch_assoc()) {
                $deskripsi = trim($row_spp['deskripsi']);
                $jumlah_transaksi = (int)$row_spp['jumlah'];
                $months_covered = [];
                $alokasi_dibuat = false;
                
                // 1. Ekstraksi bulan dari deskripsi
                
                // Regex untuk menemukan range (Juli 2024 s.d. Desember 2024)
                $pattern_range = '/\b(' . $bulan_list_pattern . ')\s(\d{4})\s(s\.d\.|s\/d|sampai|to)\s(' . $bulan_list_pattern . ')\s(\d{4})\b/i';
                if (preg_match($pattern_range, $deskripsi, $matches_range)) {
                    $start_period = $matches_range[1] . " " . $matches_range[2];
                    $end_period = $matches_range[4] . " " . $matches_range[5];

                    // Konversi ke format Inggris untuk strtotime
                    $start_period_en = str_ireplace($months_id, $months_en, $start_period);
                    $end_period_en = str_ireplace($months_id, $months_en, $end_period);
                    
                    if (strtotime('1 ' . $start_period_en) !== false && strtotime('1 ' . $end_period_en) !== false) {
                         $start_ts = strtotime('1 ' . $start_period_en);
                         $end_ts = strtotime('1 ' . $end_period_en);
                         
                         $current_ts = $start_ts;
                         while ($current_ts <= $end_ts) {
                             $month_name_en = date('F', $current_ts);
                             $year_val = date('Y', $current_ts);
                             $month_name_id = str_ireplace($months_en, $months_id, $month_name_en);
                             $months_covered[] = $month_name_id . " " . $year_val;
                             $current_ts = strtotime('+1 month', $current_ts);
                         }
                         $alokasi_dibuat = true;
                    }
                }
                
                // Regex untuk menemukan bulan tunggal/list (Agustus 2024)
                $pattern_bulan_tahun = '/\b(' . $bulan_list_pattern . ')\s\d{4}\b/i';
                if (preg_match_all($pattern_bulan_tahun, $deskripsi, $matches_list)) {
                    $matches_found = array_unique($matches_list[0]);
                    $months_covered = array_merge($months_covered, $matches_found);
                    
                    if (!$alokasi_dibuat && !empty($matches_found)) {
                        $alokasi_dibuat = true;
                    }
                }
                
                // Regex untuk menemukan bulan di dalam deskripsi Angsuran Parsial (dari alokasi baru)
                $pattern_parsial = '/(Pelunasan|Angsuran Parsial):\s*([^;]+)/i';
                if (preg_match_all($pattern_parsial, $deskripsi, $matches_parts, PREG_SET_ORDER)) {
                    foreach ($matches_parts as $match) {
                        $month_list_str = $match[2];
                        if (preg_match_all($pattern_bulan_tahun, $month_list_str, $matches_list_parts)) {
                             $matches_found_parts = array_unique($matches_list_parts[0]);
                             $months_covered = array_merge($months_covered, $matches_found_parts);
                             if (!$alokasi_dibuat && !empty($matches_found_parts)) {
                                 $alokasi_dibuat = true;
                             }
                        }
                    }
                }


                $months_covered = array_unique($months_covered);

                // 2. Distribusikan jumlah pembayaran ke bulan-bulan yang terliput
                if (!empty($months_covered)) {
                    $jumlah_bulan_teridentifikasi = count($months_covered);
                    
                    // KOREKSI PENTING: Gunakan pendekatan alokasi proporsional
                    $alokasi_per_bulan = $jumlah_transaksi / $jumlah_bulan_teridentifikasi;
                    
                    foreach ($months_covered as $bulan_tahun_str) {
                        if (!isset($spp_saldo_per_bulan[$bulan_tahun_str])) {
                            $spp_saldo_per_bulan[$bulan_tahun_str] = 0;
                        }
                        // Pembulatan sangat penting di sini
                        $spp_saldo_per_bulan[$bulan_tahun_str] += round($alokasi_per_bulan);
                    }
                } else {
                     // Jika tidak ada bulan yang teridentifikasi, alokasikan ke tunggakan tertua
                     // (Logika ini hanya fallback dan diabaikan karena fokus pada centang bulan)
                }
            }
            $stmt_spp->close();

            // 3. Klasifikasi Status SPP (LUNAS, TUNGGAKAN, BELUM JATUH TEMPO)
            
            // Daftar semua bulan wajib bayar (asumsi 3 tahun ajaran)
            $all_wajib_months = [];
            $total_tahun_ajaran = 3; 
            $tahun_ajaran_parts = explode('/', $siswa['tahun_ajaran']);
            $tahun_awal_ta = (int) $tahun_ajaran_parts[0]; 
            
            // Logika untuk menentukan semua bulan wajib bayar
            for ($ta = 0; $ta < $total_tahun_ajaran; $ta++) {
                $tahun_aj_awal = $tahun_awal_ta + $ta;
                $tahun_aj_akhir = $tahun_awal_ta + $ta + 1;

                // Semester Ganjil (Juli - Desember)
                for ($i = array_search('Juli', $bulan_list); $i <= array_search('Desember', $bulan_list); $i++) {
                    $bulan = $bulan_list[$i];
                    $tahun_spp_val = $tahun_aj_awal;
                    $bulan_angka = $i + 7; 
                    $bulan_lengkap = $bulan . " " . $tahun_spp_val;
                    
                    $ts = strtotime("{$tahun_spp_val}-{$bulan_angka}-01");
                    // Filter berdasarkan bulan_mulai_spp siswa
                    $ts_mulai_wajib = strtotime("{$tahun_wajib}-{$bulan_wajib}-01");
                    $is_wajib = ($ts >= $ts_mulai_wajib); 
                    
                    if ($is_wajib) {
                         $all_wajib_months[$bulan_lengkap] = $ts;
                    }
                }

                // Semester Genap (Januari - Juni)
                for ($i = array_search('Januari', $bulan_list); $i <= array_search('Juni', $bulan_list); $i++) {
                    $bulan = $bulan_list[$i];
                    $tahun_spp_val = $tahun_aj_akhir;
                    $bulan_angka = $i - 5; 
                    $bulan_lengkap = $bulan . " " . $tahun_spp_val;

                    $ts = strtotime("{$tahun_spp_val}-{$bulan_angka}-01");
                    // Filter berdasarkan bulan_mulai_spp siswa
                    $ts_mulai_wajib = strtotime("{$tahun_wajib}-{$bulan_wajib}-01");
                    $is_wajib = ($ts >= $ts_mulai_wajib);
                    
                    if ($is_wajib) {
                         $all_wajib_months[$bulan_lengkap] = $ts;
                    }
                }
            }
            
            // Sortir bulan wajib berdasarkan waktu
            asort($all_wajib_months);
            
            $months_status = [];
            $tunggakan_tertua_ts = null;
            $batas_waktu_cek = new DateTime(date('Y-m-01')); 
            
            foreach ($all_wajib_months as $bulan_tahun_str => $ts) {
                $paid_amount = $spp_saldo_per_bulan[$bulan_tahun_str] ?? 0;
                
                // Sisa tunggakan dihitung berdasarkan $nominal_spp_per_bulan (yang sudah didiskon/normal)
                $sisa_tunggakan = max(0, $nominal_spp_per_bulan - $paid_amount);
                $date_obj = (new DateTime())->setTimestamp($ts);
                
                $status = 'BELUM JATUH TEMPO';
                
                if ($sisa_tunggakan > 0) {
                     // Bulan memiliki sisa tunggakan
                     if ($date_obj < $batas_waktu_cek) {
                         $status = 'TUNGGAKAN'; // Sudah lewat bulan ini
                         if ($tunggakan_tertua_ts === null) {
                             $tunggakan_tertua_ts = $ts;
                         }
                     } else {
                         // Bulan ini atau bulan depan (belum due)
                         // Jika ada sisa, berarti ada pembayaran parsial sebelumnya
                         if ($paid_amount > 0 && $sisa_tunggakan > 0) {
                             $status = 'TUNGGAKAN_PARSIAL';
                             // Jika ada parsial di bulan ini, perlakukan sebagai tunggakan tertua
                             if ($tunggakan_tertua_ts === null || $ts < $tunggakan_tertua_ts) {
                                 $tunggakan_tertua_ts = $ts;
                             }
                         } else {
                             // Bulan yang seharusnya penuh dan belum jatuh tempo
                             $status = 'BELUM JATUH TEMPO';
                         }
                     }
                } else {
                    // Saldo >= Nominal wajib (Lunas)
                    $status = 'LUNAS';
                }
                
                $months_status[$bulan_tahun_str] = [
                    'status' => $status,
                    'sisa' => (int) round($sisa_tunggakan),
                    'paid' => (int) round($paid_amount),
                    'nominal_wajib' => (int) $nominal_spp_per_bulan, // Nominal wajib setelah diskon/normal
                    'timestamp' => $ts,
                    'bulan_tahun_str' => $bulan_tahun_str
                ];
            }
            
            // --- LOGIKA PERHITUNGAN BULAN SPP SELANJUTNYA ---
            if ($tunggakan_tertua_ts !== null) {
                // Ada tunggakan parsial/penuh: Ambil bulan tunggakan/parsial tertua
                $next_spp_month_text_en = date('F Y', $tunggakan_tertua_ts);
            } else {
                // Semua lunas hingga bulan lalu: Cari bulan setelah bulan terakhir LUNAS
                $last_paid_full_ts = null;
                foreach ($months_status as $month_data) {
                    if ($month_data['status'] === 'LUNAS') {
                        $last_paid_full_ts = max($last_paid_full_ts, $month_data['timestamp']);
                    }
                }

                if ($last_paid_full_ts !== null) {
                    $next_spp_month_ts = strtotime('+1 month', $last_paid_full_ts);
                } else {
                    // Belum pernah bayar atau mulai wajib bayar
                    $next_spp_month_ts = strtotime("{$tahun_wajib}-{$bulan_wajib}-01");
                }
                
                // Pastikan bulan selanjutnya adalah bulan wajib terdekat
                $earliest_wajib_ts = min(array_values($all_wajib_months));
                if ($last_paid_full_ts === null && $next_spp_month_ts > $earliest_wajib_ts) {
                     $next_spp_month_ts = $earliest_wajib_ts;
                }


                $next_spp_month_text_en = date('F Y', $next_spp_month_ts);
            }
            $next_spp_month_text = str_ireplace($months_en, $months_id, $next_spp_month_text_en);
        }
        // =============================================================================
        
        // --- Logika Hitung Sisa Tunggakan NON-SPP (Tidak diubah) ---
        $jenis_non_spp_list = [];
        $rekap_tunggakan_non_spp = [];

        $query_non_spp_jenis = "SELECT DISTINCT jenis_pembayaran FROM set_biaya WHERE jenis_pembayaran NOT LIKE '%SPP%' AND tahun_ajaran = ?";
        $stmt_non_spp_jenis = $conn->prepare($query_non_spp_jenis);
        $stmt_non_spp_jenis->bind_param("s", $tahun_ajaran_siswa);
        $stmt_non_spp_jenis->execute();
        $result_non_spp_jenis = $stmt_non_spp_jenis->get_result();
        while ($row = $result_non_spp_jenis->fetch_assoc()) {
             $jenis_non_spp_list[] = $row['jenis_pembayaran'];
        }
        $stmt_non_spp_jenis->close();

        foreach ($jenis_non_spp_list as $jenis_biaya) {
             $nominal_seharusnya = 0;
             $query_khusus = "
                 SELECT nominal_biaya 
                 FROM nominal_biaya_siswa 
                 WHERE id_siswa = ? 
                   AND jenis_pembayaran = ? 
                   AND tahun_ajaran = ?
             ";
             $stmt_khusus = $conn->prepare($query_khusus);
             $stmt_khusus->bind_param("iss", $id_siswa, $jenis_biaya, $tahun_ajaran_siswa); 
             $stmt_khusus->execute();
             $result_khusus = $stmt_khusus->get_result();
             
             if ($result_khusus->num_rows > 0) {
                 $nominal_seharusnya = $result_khusus->fetch_assoc()['nominal_biaya'];
             }
             $stmt_khusus->close();
             
             if ($nominal_seharusnya == 0) {
                 $query_biaya = "SELECT nominal FROM set_biaya WHERE jenis_pembayaran = ? AND tahun_ajaran = ?";
                 $stmt_biaya_nominal = $conn->prepare($query_biaya);
                 $stmt_biaya_nominal->bind_param("ss", $jenis_biaya, $tahun_ajaran_siswa);
                 $stmt_biaya_nominal->execute();
                 $nominal_seharusnya = $stmt_biaya_nominal->get_result()->fetch_assoc()['nominal'] ?? 0;
                 $stmt_biaya_nominal->close();
             }
             
             $total_dibayar = 0;
             $query_dibayar = "SELECT SUM(jumlah) AS total_dibayar FROM transaksi WHERE id_siswa = ? AND jenis_pembayaran = ? AND jenis_transaksi = 'masuk'";
             $stmt_dibayar = $conn->prepare($query_dibayar);
             $stmt_dibayar->bind_param("is", $id_siswa, $jenis_biaya);
             $stmt_dibayar->execute();
             $total_dibayar = $stmt_dibayar->get_result()->fetch_assoc()['total_dibayar'] ?? 0;
             $stmt_dibayar->close();

             $sisa_tunggakan = max(0, $nominal_seharusnya - $total_dibayar); 

             if ($nominal_seharusnya > 0) {
                  $rekap_tunggakan_non_spp[$jenis_biaya] = [
                      'total_biaya' => (int)$nominal_seharusnya,
                      'total_dibayar' => (int)$total_dibayar,
                      'sisa' => (int)$sisa_tunggakan
                    ];
             }
        }
    }
}


// =================================================================
// --- Logika Pemrosesan Form POST ---
// =================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if ($dicatat_oleh_id === 0) {
        header("Location: ../login.php");
        exit();
    }

    $id_siswa_post = intval($_POST['id_siswa'] ?? 0);
    $jenis_pembayaran_form_value = $_POST['jenis_pembayaran'] ?? '';
    $jenis_pembayaran_db = extractCleanPaymentName($jenis_pembayaran_form_value); 
    
    $jumlah_str = str_replace(['Rp', '.', ','], '', $_POST['jumlah'] ?? '0');
    $jumlah = (float)$jumlah_str;
    $tanggal_transaksi = ($_POST['tanggal_transaksi'] ?? date('Y-m-d')) . ' ' . date('H:i:s');
    $deskripsi = $_POST['deskripsi'] ?? ''; 
    $spp_bulan_tahun = $_POST['spp_bulan_tahun'] ?? [];
    $spp_nominal_per_bulan_post = (int)($_POST['spp_nominal_per_bulan'] ?? 0);
    $spp_status_data_post = json_decode($_POST['spp_status_data_post'] ?? '[]', true); 

    if ($id_siswa_post <= 0 || $jumlah <= 0 || empty($jenis_pembayaran_db)) {
        header("Location: pembayaran.php?id_siswa=" . $id_siswa_post . "&pesan=error&debug=" . urlencode("Data transaksi atau Jenis Pembayaran invalid."));
        exit();
    }
    
    // --- Validasi & Pemrosesan SPP (LOGIKA ALOKASI BARU) ---
    if (strpos(strtoupper($jenis_pembayaran_db), 'SPP') !== false) {
        if ($spp_nominal_per_bulan_post == 0) {
            header("Location: pembayaran.php?id_siswa=" . $id_siswa_post . "&pesan=error&debug=" . urlencode("Gagal mengambil nominal SPP per bulan."));
            exit;
        }

        if (empty($spp_bulan_tahun)) {
            // KASUS 1: Angsuran Bebas / Sisa Tunggakan Manual
            if (empty($deskripsi)) {
                $deskripsi = "Pembayaran Angsuran SPP (Manual Input)";
            }
            // Lanjut proses dengan jumlah dan deskripsi manual
            
        } else {
            // KASUS 2: Pembayaran dengan Pilihan Bulan (Alokasi Fleksibel)
            
            $sisa_dana = $jumlah;
            
            // Urutkan bulan yang dipilih berdasarkan waktu (timestamp) untuk alokasi prioritas
            $bulan_data_sorted = [];
            foreach ($spp_bulan_tahun as $bulan_tahun_str) {
                 // Perlu parse data JSON status dari POST untuk mendapatkan status pembayaran saat ini
                 $data = $spp_status_data_post[$bulan_tahun_str] ?? null;
                 if ($data) {
                    $bulan_data_sorted[$data['timestamp']] = [
                         'str' => $bulan_tahun_str,
                         'sisa' => (int)$data['sisa'], // Sisa tunggakan yang belum dibayar
                         'paid' => (int)$data['paid'], // Jumlah yang sudah dibayar
                         'nominal_wajib' => (int)$data['nominal_wajib'], // Nominal wajib bulan tersebut (misal 100.000)
                         'status' => $data['status']
                    ];
                 }
            }
            ksort($bulan_data_sorted); // Urutkan berdasarkan timestamp (bulan tertua di awal)
            
            $bulan_tercover_penuh = [];
            $bulan_tercover_parsial = [];

            // --- LOGIKA ALOKASI BERDASARKAN KEKURANGAN KE NOMINAL WAJIB ---
            foreach ($bulan_data_sorted as $ts => $data) {
                if ($sisa_dana <= 0) break; // Dana habis

                $target_penuh = $data['nominal_wajib'];
                $sudah_dibayar = $data['paid'];
                
                // Berapa banyak lagi yang harus dibayar untuk LUNAS.
                $kekurangan_lunas = max(0, $target_penuh - $sudah_dibayar); 
                
                if ($kekurangan_lunas <= 0) {
                    // Bulan ini sudah lunas, lewati. (Seharusnya dicegah di client-side)
                    continue;
                }
                
                // Jumlah yang dapat dialokasikan ke bulan ini
                $jumlah_dialokasikan = min($sisa_dana, $kekurangan_lunas);
                
                if ($jumlah_dialokasikan > 0) {
                    $sisa_dana -= $jumlah_dialokasikan;
                    $sudah_dibayar_setelah_alokasi = $sudah_dibayar + $jumlah_dialokasikan;

                    if ($sudah_dibayar_setelah_alokasi >= $target_penuh) {
                        // Bulan ini menjadi lunas penuh
                        $bulan_tercover_penuh[] = $data['str'];
                    } else {
                        // Bulan ini hanya tercover parsial
                        $bulan_tercover_parsial[] = $data['str'];
                    }
                }
            }
            
            // Rekonstruksi Deskripsi:
            $deskripsi_parts = [];
            if (!empty($bulan_tercover_penuh)) {
                $deskripsi_parts[] = "Pelunasan: " . implode(', ', $bulan_tercover_penuh);
            }
            if (!empty($bulan_tercover_parsial)) {
                $deskripsi_parts[] = "Angsuran Parsial: " . implode(', ', $bulan_tercover_parsial);
            }
            
            if (!empty($deskripsi_parts)) {
                $deskripsi = "Pembayaran SPP: " . implode('; ', $deskripsi_parts);
            } else {
                 $deskripsi = "Pembayaran Angsuran SPP (" . formatRupiah($jumlah) . " Alokasi Otomatis)";
            }
        }
    }
    
    // --- Eksekusi Transaksi ---
    $conn->begin_transaction();
    try {
        $query_insert = "
            INSERT INTO transaksi 
            (id_siswa, tanggal_transaksi, jumlah, deskripsi, jenis_pembayaran, jenis_transaksi, dicatat_oleh_id_pengguna) 
            VALUES (?, ?, ?, ?, ?, 'masuk', ?)
        ";
        $stmt_insert = $conn->prepare($query_insert);
        
        $jumlah_double = (double)$jumlah; 
        $stmt_insert->bind_param("isdssi", $id_siswa_post, $tanggal_transaksi, $jumlah_double, $deskripsi, $jenis_pembayaran_db, $dicatat_oleh_id);
        
        if (!$stmt_insert->execute()) {
            throw new Exception("Gagal mengeksekusi statement: " . $stmt_insert->error);
        }
        
        $last_id_transaksi = $conn->insert_id; 
        $stmt_insert->close();
        
        $conn->commit();
        
        header("Location: pembayaran.php?id_siswa=" . $id_siswa_post . "&pesan=success&last_id=" . $last_id_transaksi);
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: pembayaran.php?id_siswa=" . $id_siswa_post . "&pesan=error&debug=" . urlencode("Transaksi gagal: " . $e->getMessage()));
        exit();
    }
}
// =================================================================
// --- Akhir Logika Pemrosesan Form POST ---
// =================================================================

// --- Pengambilan Riwayat Transaksi ---
$riwayat_transaksi = [];
if ($id_siswa > 0) {
    $query_riwayat = "
        SELECT 
            t.id_transaksi, t.tanggal_transaksi, t.jenis_pembayaran, t.jumlah, t.deskripsi, 
            u.nama_pengguna AS dicatat_oleh 
        FROM 
            transaksi t
        LEFT JOIN
            pengguna u ON t.dicatat_oleh_id_pengguna = u.id_pengguna
        WHERE 
            t.id_siswa = ? AND t.jenis_transaksi = 'masuk' 
        ORDER BY 
            t.tanggal_transaksi DESC, t.id_transaksi DESC"; 
    $stmt_riwayat = $conn->prepare($query_riwayat);
    $stmt_riwayat->bind_param("i", $id_siswa);
    $stmt_riwayat->execute();
    $result_riwayat = $stmt_riwayat->get_result();
    $riwayat_transaksi = $result_riwayat->fetch_all(MYSQLI_ASSOC);
    $stmt_riwayat->close();
}
// ------------------------------------------------------------------

// --- Ambil semua siswa untuk dropdown ---
$query_all_siswa = "SELECT id_siswa, nama_lengkap, nisn FROM siswa ORDER BY nama_lengkap ASC";
$result_all_siswa = mysqli_query($conn, $query_all_siswa);
// =================================================================
// --- TAMBAHAN LOGIKA KHUSUS DISPLAY NON-SPP ---
// =================================================================
$rekap_tunggakan_non_spp_display = [];
$total_tunggakan_non_spp_display = 0;

foreach ($rekap_tunggakan_non_spp as $jenis => $data) {
    $sisa_lebih_bayar = max(0, $data['total_dibayar'] - $data['total_biaya']);
    
    $total_dibayar_display = $data['total_dibayar'];
    
    // Jika ada kelebihan, di tampilan rekap kita set total_dibayar = nominal (agar sisa = 0)
    // dan sisa_lebih_bayar dicatat sebagai deposit.
    if ($sisa_lebih_bayar > 0) {
        $total_dibayar_display = $data['total_biaya']; // Mengoreksi display untuk Prakerin (550K)
        $data['sisa'] = 0; // Tunggakan dianggap nol karena lunas + lebih bayar
    }

    $rekap_tunggakan_non_spp_display[$jenis] = [
        'total_biaya' => $data['total_biaya'],
        'total_dibayar' => $total_dibayar_display,
        'sisa' => $data['sisa'],
        'sisa_lebih_bayar' => $sisa_lebih_bayar
    ];
    $total_tunggakan_non_spp_display += $data['sisa'];
}
?>


<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pembayaran | Aplikasi Pembayaran Siswa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    
    <style>
        /* ==================== VARIABLE CSS ==================== */
        :root {
            --primary-color: #007bff; 
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107; 
            --light-bg: #f8f9fa;
            --dark-text: #343a40;
            --sidebar-bg: #2c3e50; 
            --sidebar-hover: #34495e;
        }

        /* ==================== BASE STYLES & LAYOUT ==================== */
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

        /* --- SIDEBAR STYLES --- */
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

        /* --- CONTENT STYLES --- */
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
        .content-header i {
            margin-right: 10px;
        }

        /* --- GRID STYLES BARU (Diperbaiki) --- */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin-left: -15px;
            margin-right: -15px;
        }
        .col-md-6 {
            flex: 0 0 50%;
            max-width: 50%;
            padding-left: 15px;
            padding-right: 15px;
        }
        @media (max-width: 992px) {
            .col-md-6 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }
        
        /* --- BOXES & FORMS --- */
        .form-container, .data-section {
            background: white;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); 
            height: fit-content; /* Memastikan kolom menyesuaikan konten */
        }
        
        .form-container h3 {
            color: var(--dark-text);
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            margin-top: 15px;
            font-weight: 600;
            color: var(--dark-text);
        }

        input[type="text"], input[type="number"], input[type="date"], select, textarea {
            width: 100%;
            padding: 10px 15px;
            margin-bottom: 15px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
            transition: border-color 0.3s, box-shadow 0.3s;
            font-size: 1rem;
        }

        input[type="text"]:focus, input[type="number"]:focus, input[type="date"]:focus, select:focus, textarea:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
            outline: none;
        }

        /* --- BUTTONS --- */
        .btn {
            display: inline-block;
            background-color: var(--primary-color);
            color: white;
            padding: 10px 20px;
            margin-top: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.3s, transform 0.1s;
            font-weight: 600;
        }

        .btn:hover {
            background-color: #0056b3;
        }
        
        .btn:active {
            transform: translateY(1px);
        }

        .btn-small {
            padding: 5px 10px;
            font-size: 0.9rem;
        }
        .btn-danger {
            background-color: var(--danger-color);
        }
        .btn-danger:hover {
            background-color: #bd2130;
        }

        /* --- ALERTS --- */
        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #c3e6cb;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #f5c6cb;
            margin-bottom: 15px;
            font-weight: 600;
        }

        /* --- SPP OPTIONS --- */
        .spp-options {
            /* display: none; */ /* Hapus display: none di CSS agar dikontrol oleh JS */
            margin-top: 10px;
            border: 1px solid #ccc;
            padding: 15px;
            border-radius: 5px;
            background-color: var(--light-bg); 
        }
        .spp-options h4 {
            color: var(--primary-color);
            margin-top: 0;
            margin-bottom: 15px;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        /* Checkbox styling (gunakan yang lebih baik dari kode sebelumnya) */
        .bulan-item {
            display: inline-block;
            margin: 5px 10px 5px 0;
        }
        .bulan-item input[type="checkbox"] {
            display: none;
        }
        .bulan-item label {
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            border: 1px solid #ccc;
            font-size: 0.9em;
            transition: background-color 0.2s;
            user-select: none;
            margin-top: 0;
        }

        /* Status Warna */
        .bulan-item label.paid {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
            cursor: not-allowed;
            text-decoration: line-through;
            opacity: 0.7;
        }
        .bulan-item label.tunggakan {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        .bulan-item label.partial {
            background-color: #fff3cd; 
            color: #856404;
            border-color: #ffeeba;
        }
        .bulan-item label.not-due {
            background-color: #e9ecef;
            color: var(--secondary-color);
            border-color: #ced4da;
        }

        /* Checkbox Terpilih */
        .bulan-item input[type="checkbox"]:checked + label:not(.paid) {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.5);
        }

        /* --- INFO BOX --- */
        .info-box {
            background-color: #e3f2fd; 
            border: 1px solid #bbdefb;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            overflow: auto; /* Untuk menampung tombol float */
        }
        .info-box p {
            margin: 5px 0;
        }
        .next-month {
            font-weight: bold;
            color: #e67e22; 
        }

        /* --- TABLES --- */
        .rekap-table, .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: white;
            border-radius: 8px;
            overflow: hidden; 
        }
        .rekap-table th, .rekap-table td, .data-table th, .data-table td {
            border: 1px solid #e9ecef;
            padding: 12px 15px;
            text-align: left;
            font-size: 0.95rem;
        }
        .rekap-table th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
        }
        .rekap-table .total-row th {
            background-color: #f2f2f2;
            color: var(--dark-text);
            font-size: 1rem;
            font-weight: 700;
        }
        .data-table th {
            background-color: var(--sidebar-bg);
        }
        .rekap-table tr:nth-child(even), .data-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .rekap-table tr:hover, .data-table tr:hover {
            background-color: #e9ecef;
        }
        .badge-success {
            background-color: var(--success-color);
            color: white;
            padding: 3px 6px;
            border-radius: .5rem;
            font-size: 0.85em;
            font-weight: 600;
        }
        .data-section h3 {
             color: var(--dark-text);
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-size: 1.5rem;
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
                <li><a href="pembayaran.php" class="active"><i class="fas fa-cash-register"></i> Kelola Pembayaran</a></li>
                <li><a href="pengeluaran.php"><i class="fas fa-money-bill-wave"></i> Kelola Pengeluaran</a></li>
                <li><a href="siswa.php" ><i class="fas fa-user-graduate"></i> Kelola Data Siswa</a></li>
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
                <h2><i class="fas fa-cash-register"></i>Kelola Pembayaran</h2>
            </div>
            
            <?php 
            // Tampilkan pesan
            if (isset($_GET['pesan'])) {
                $pesan = $_GET['pesan'];
                $debug = isset($_GET['debug']) ? htmlspecialchars($_GET['debug']) : '';
                if ($pesan == 'success') {
                    echo "<div class='success-message'>Transaksi berhasil dicatat. ID Transaksi: " . htmlspecialchars($_GET['last_id'] ?? 'N/A') . "</div>";
                    
                    // --- KODE NOTIFIKASI SUARA DIMULAI ---
                    echo "<script>";
                    // PASTIKAN PATH AUDIO INI BENAR!
                    $audio_path = '../audio/2025-10-29-100500_132636.mp3';  
                    echo "var audio = new Audio('{$audio_path}');";
                    
                    echo "audio.play().catch(error => {";
                    echo "    console.warn('Pemutaran suara gagal (mungkin diblokir oleh browser):', error);";
                    echo "});";
                    echo "</script>";
                    // --- KODE NOTIFIKASI SUARA SELESAI ---
                    
                } elseif ($pesan == 'error') {
                    echo "<div class='error-message'>Transaksi gagal! " . $debug . "</div>";
                }
            }
            
            if (!isset($id_siswa) || !$id_siswa || !$siswa): // Jika siswa belum dipilih
            ?>
                <div class="form-container">
                    <h3>Pilih Siswa Terlebih Dahulu</h3>
                    <form action="" method="get">
                        <label for="id_siswa">Pilih Siswa:</label>
                        <select name="id_siswa" id="id_siswa" class="select2-siswa" onchange="this.form.submit()">
                            <option value="">-- Pilih Siswa --</option>
                            <?php 
                            if (isset($result_all_siswa) && $result_all_siswa):
                                // Asumsi $result_all_siswa adalah hasil query yang valid
                                while ($row = mysqli_fetch_assoc($result_all_siswa)): ?>
                                    <option value="<?php echo htmlspecialchars($row['id_siswa']); ?>" <?php echo (isset($id_siswa) && $id_siswa == $row['id_siswa']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($row['nama_lengkap']); echo " (NISN: " . htmlspecialchars($row['nisn']) . ")"; ?>
                                    </option>
                            <?php endwhile;
                            endif; ?>
                        </select>
                    </form>
                </div>
            <?php else: // Jika siswa sudah dipilih
                // =================================================================
                // --- TAMPILAN INFORMASI SISWA & FORM ---
                // =================================================================
            ?>
                <div class="info-box">
                    <p><strong>Nama Siswa:</strong> <?php echo htmlspecialchars($siswa['nama_lengkap']); ?></p>
                    <p><strong>NISN:</strong> <?php echo htmlspecialchars($siswa['nisn']); ?></p>
                    <p><strong>Kelas:</strong> <?php echo htmlspecialchars($siswa['nama_kelas']); ?></p>
                    <p><strong>Tahun Ajaran:</strong> <?php echo htmlspecialchars($siswa['tahun_ajaran']); ?></p>
                    <?php if (isset($display_nominal_spp) && $display_nominal_spp > 0): ?>
                        <p><strong>Status SPP:</strong> <span style="text-transform: capitalize; color: <?php echo $status_spp_siswa === 'diskon' ? '#27ae60' : '#2980b9'; ?>; font-weight: bold;"><?php echo htmlspecialchars($status_spp_siswa); ?></span></p>
                        <p><strong>Nominal SPP Wajib per Bulan:</strong> Rp <?php echo formatRupiah($display_nominal_spp); ?> 
                        <?php if (isset($nominal_diskon_spp) && $nominal_diskon_spp > 0): ?>
                            (Potongan Diskon: Rp <?php echo formatRupiah($nominal_diskon_spp); ?> dari Rp <?php echo formatRupiah($display_nominal_spp_full); ?>)
                        <?php endif; ?>
                        </p>
                        <p><strong>Bulan SPP Berikutnya yang Harus Dibayar:</strong> <span class="next-month"><?php echo htmlspecialchars($next_spp_month_text); ?></span></p>
                    <?php else: ?>
                        <p class="error-message">Nominal SPP belum terdefinisi untuk Tahun Ajaran ini atau status siswa Anda.</p>
                    <?php endif; ?>
                    <a href="pembayaran.php" class="btn btn-small btn-danger" style="float: right;">Pilih Siswa Lain</a>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="form-container">
                            <h3>Buat Transaksi Pembayaran</h3>
                            <form action="" method="post" id="formPembayaran">
                                <input type="hidden" name="id_siswa" value="<?php echo htmlspecialchars($id_siswa); ?>">
                                <input type="hidden" name="spp_nominal_per_bulan" value="<?php echo htmlspecialchars($nominal_spp_per_bulan ?? 0); ?>">
                                <input type="hidden" name="spp_status_data_post" id="sppStatusDataPost" value='<?php echo json_encode($months_status ?? []); ?>'>
                                <input type="hidden" name="jumlah" id="jumlah_hidden">

                                <label for="tanggal_transaksi">Tanggal Transaksi:</label>
                                <input type="date" name="tanggal_transaksi" id="tanggal_transaksi" value="<?php echo $tanggal_default_form ?? date('Y-m-d'); ?>" required>
                                
                                <label for="jenis_pembayaran">Jenis Pembayaran:</label>
                                <select name="jenis_pembayaran" id="jenis_pembayaran" onchange="updateForm()" required>
                                    <option value="">-- Pilih Jenis Pembayaran --</option>
                                    <?php foreach ($biaya_opsi as $biaya): ?>
                                        <option value="<?php echo htmlspecialchars($biaya['display_name']); ?>">
                                            <?php echo htmlspecialchars($biaya['display_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <div class="spp-options" id="sppOptions" style="display: none;"> <h4>Pilih Bulan SPP yang Dibayar:</h4>
                                    <?php if (!empty($months_status)): ?>
                                        <?php foreach ($months_status as $month_str => $data): 
                                            $status = $data['status'];
                                            $sisa = $data['sisa'];
                                            $nominal_wajib = $data['nominal_wajib'];
                                            $checkbox_id = "spp_month_" . str_replace([' ', '/'], '_', $month_str);
                                            
                                            $label_class = '';
                                            $status_text = '';
                                            $disabled = false;
                                            
                                            if ($status == 'LUNAS') {
                                                $label_class = 'paid';
                                                $status_text = "(Lunas)";
                                                $disabled = true;
                                            } elseif ($status == 'TUNGGAKAN') {
                                                $label_class = 'tunggakan';
                                                $status_text = "(Tunggakan: Rp " . formatRupiah($sisa) . ")";
                                            } elseif ($status == 'TUNGGAKAN_PARSIAL') {
                                                $label_class = 'partial';
                                                $status_text = "(Sisa Tunggakan: Rp " . formatRupiah($sisa) . ")";
                                            } elseif ($status == 'BELUM JATUH TEMPO') {
                                                $label_class = 'not-due';
                                                $status_text = "(Belum Jatuh Tempo: Rp " . formatRupiah($nominal_wajib) . ")";
                                            }
                                            
                                            // Nilai target yang akan ditambahkan ke total di JS
                                            $data_target_value = ($status == 'LUNAS') ? 0 : $sisa; 
                                            if ($status == 'BELUM JATUH TEMPO' || $sisa == $nominal_wajib) {
                                                $data_target_value = $nominal_wajib;
                                            }
                                        ?>
                                            <div class="bulan-item">
                                                <input 
                                                    type="checkbox" 
                                                    id="<?php echo $checkbox_id; ?>" 
                                                    name="spp_bulan_tahun[]" 
                                                    value="<?php echo htmlspecialchars($month_str); ?>"
                                                    data-target="<?php echo $data_target_value; ?>"
                                                    onchange="updateForm()"
                                                    <?php echo $disabled ? 'disabled' : ''; ?>
                                                >
                                                <label for="<?php echo $checkbox_id; ?>" class="<?php echo $label_class; ?>">
                                                    <?php echo htmlspecialchars($month_str); ?> <?php echo $status_text; ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>Tidak ada data bulan SPP yang terdeteksi atau Nominal SPP belum diatur.</p>
                                    <?php endif; ?>
                                </div>
                                
                                <label for="deskripsi">Deskripsi/Keterangan:</label>
                                <textarea name="deskripsi" id="deskripsi" rows="3" placeholder="Contoh: Pembayaran SPP bulan Agustus 2024 atau Angsuran Biaya Seragam"></textarea>
                                
                                <label for="jumlah_display">Jumlah Total Pembayaran (Rp):</label>
                                <input type="text" name="jumlah_display" id="jumlah_display" placeholder="Masukkan jumlah pembayaran" required oninput="formatRupiahInput(this)">
                                
                                <button type="submit" class="btn"><i class="fas fa-save"></i> Catat Pembayaran</button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="col-md-12">
                        <div class="data-section">
                            <h3>Rekap Tunggakan Non-SPP</h3>
                            <table class="rekap-table">
                                <thead>
                                    <tr>
                                        <th>Jenis Biaya</th>
                                        <th>Total Biaya</th>
                                        <th>Sudah Dibayar</th>
                                        <th>Sisa Tunggakan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rekap_tunggakan_non_spp)): ?>
                                        <?php 
                                        $total_tunggakan = 0;
                                        foreach ($rekap_tunggakan_non_spp as $jenis => $data): 
                                            $total_tunggakan += $data['sisa'];
                                            $sisa_display = ($data['sisa'] > 0) ? "Rp " . formatRupiah($data['sisa']) : "<span class='badge-success'>Lunas</span>";
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($jenis); ?></td>
                                                <td>Rp <?php echo formatRupiah($data['total_biaya']); ?></td>
                                                <td>Rp <?php echo formatRupiah($data['total_dibayar']); ?></td>
                                                <td><?php echo $sisa_display; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="total-row">
                                            <th colspan="3" style="text-align: right;">TOTAL TUNGGAKAN NON-SPP:</th>
                                            <th>Rp <?php echo formatRupiah($total_tunggakan); ?></th>
                                        </tr>
                                    <?php else: ?>
                                        <tr><td colspan="4">Tidak ada tunggakan Non-SPP.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            
                            <hr>
                            
                            <h3>Riwayat Transaksi Terakhir</h3>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Jenis</th>
                                            <th>Jumlah</th>
                                            <th>Deskripsi</th>
                                            <th>Dicatat Oleh</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($riwayat_transaksi)): ?>
                                            <?php foreach ($riwayat_transaksi as $transaksi): ?>
                                                <tr>
                                                    <td><?php echo date('d-m-Y H:i', strtotime($transaksi['tanggal_transaksi'])); ?></td>
                                                    <td><?php echo htmlspecialchars($transaksi['jenis_pembayaran']); ?></td>
                                                    <td>Rp <?php echo formatRupiah($transaksi['jumlah']); ?></td>
                                                    <td><?php echo htmlspecialchars($transaksi['deskripsi']); ?></td>
                                                    <td><?php echo htmlspecialchars($transaksi['dicatat_oleh'] ?? 'Admin'); ?></td>
                                                    <td>
                                                        <a href="struk_pembayaran.php?id_transaksi=<?php echo $transaksi['id_transaksi']; ?>" class="btn btn-small" target="_blank" style="margin-right: 5px;"><i class="fas fa-print"></i> Cetak Struk</a>
                                                        <a href="hapus_transaksi.php?id_transaksi=<?php echo $transaksi['id_transaksi']; ?>&id_siswa=<?php echo $id_siswa; ?>" class="btn btn-small btn-danger" onclick="return confirm('Apakah Anda yakin ingin menghapus transaksi ini? Tindakan ini tidak dapat dibatalkan.');"><i class="fas fa-trash"></i> Hapus</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="6">Belum ada riwayat pembayaran untuk siswa ini.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; // End Siswa Selected ?>

        </div>
    </div>
    
    <script>
        // Inisialisasi Select2
        $(document).ready(function() {
            $('.select2-siswa').select2({
                placeholder: "-- Pilih Siswa --",
                allowClear: true
            });
        });

        // =================================================================
        // --- Fungsi Format Rupiah ---
        // =================================================================
        function formatRupiahJs(angka, prefix) {
            var number_string = angka.toString().replace(/[^,\d]/g, ''),
                split = number_string.split(','),
                sisa = split[0].length % 3,
                rupiah = split[0].substr(0, sisa),
                ribuan = split[0].substr(sisa).match(/\d{3}/gi);

            if (ribuan) {
                separator = sisa ? '.' : '';
                rupiah += separator + ribuan.join('.');
            }

            rupiah = split[1] != undefined ? rupiah + ',' + split[1] : rupiah;
            return prefix == undefined ? rupiah : (rupiah ? 'Rp ' + rupiah : '');
        }

        function formatRupiahInput(input) {
            var value = input.value.replace(/[^0-9]/g, '');
            input.value = formatRupiahJs(value);
            
            // Perbarui nilai input tersembunyi
            var hiddenInput = document.getElementById('jumlah_hidden');
            if (hiddenInput) {
                hiddenInput.value = value;
            }
        }
        
        // =================================================================
        // --- Fungsi Rangkai Deskripsi Bulan SPP ---
        // =================================================================
        function getMonthRangeDescription(months) {
            if (months.length === 0) return "";
            
            const monthMap = {
                'Januari': 1, 'Februari': 2, 'Maret': 3, 'April': 4, 'Mei': 5, 'Juni': 6, 
                'Juli': 7, 'Agustus': 8, 'September': 9, 'Oktober': 10, 'November': 11, 'Desember': 12
            };

            // Parse dan Sortir Bulan: [ {ts: timestamp, name: 'Bulan Tahun'} ]
            const parsedMonths = months.map(m => {
                const parts = m.split(' ');
                const monthName = parts[0];
                const year = parseInt(parts[1]);
                const monthNum = monthMap[monthName];
                // Buat timestamp untuk sorting
                let ts = new Date(year, monthNum - 1, 1).getTime();
                return { ts, name: m, monthName, year, monthNum };
            }).sort((a, b) => a.ts - b.ts);
            
            if (parsedMonths.length === 0) return "";

            const ranges = [];
            let currentRange = [parsedMonths[0]];
            
            for (let i = 1; i < parsedMonths.length; i++) {
                const currentMonth = parsedMonths[i];
                const lastMonthInCurrentRange = currentRange[currentRange.length - 1];

                // Cek apakah bulan saat ini adalah bulan selanjutnya setelah bulan terakhir dalam rentang
                // Kita cek beda bulan dan tahun
                const dateA = new Date(lastMonthInCurrentRange.year, lastMonthInCurrentRange.monthNum - 1, 1);
                const dateB = new Date(currentMonth.year, currentMonth.monthNum - 1, 1);
                
                // Hitung beda bulan
                const diffMonths = (dateB.getFullYear() - dateA.getFullYear()) * 12 + (dateB.getMonth() - dateA.getMonth());

                if (diffMonths === 1) {
                    // Berurutan, tambahkan ke range yang sama
                    currentRange.push(currentMonth);
                } else {
                    // Tidak berurutan, simpan range saat ini dan mulai range baru
                    ranges.push(currentRange);
                    currentRange = [currentMonth];
                }
            }
            ranges.push(currentRange); // Tambahkan range terakhir

            // Format output
            const descriptionParts = ranges.map(range => {
                if (range.length === 1) {
                    return range[0].name;
                } else {
                    const start = range[0].name;
                    const end = range[range.length - 1].name;
                    // Format: Juli 2024 s.d. Desember 2024
                    return `${start} s.d. ${end}`;
                }
            });

            return descriptionParts.join(', ');
        }
        
        // =================================================================
        // --- Fungsi Utama Update Form ---
        // =================================================================
        function updateForm() {
            const jenisDropdown = document.getElementById('jenis_pembayaran');
            const sppOptionsDiv = document.getElementById('sppOptions');
            const deskripsiInput = document.getElementById('deskripsi');
            const jumlahDisplayInput = document.getElementById('jumlah_display');
            const jumlahHiddenInput = document.getElementById('jumlah_hidden');
            const sppCheckboxes = document.querySelectorAll('#sppOptions input[type="checkbox"]:checked');
            
            const selectedJenis = jenisDropdown.value;
            const cleanJenis = selectedJenis.replace(/\s*\(Rp\s*[\d\.,]+\)\s*$/, '');
            
            // Reset state
            // Kunci: jumlahDisplayInput.readOnly SELALU FALSE UNTUK FLEKSIBILITAS INPUT MANUAL
            jumlahDisplayInput.readOnly = false; 
            deskripsiInput.readOnly = false;
            sppOptionsDiv.style.display = 'none';
            
            // Jika tidak ada jenis yang dipilih
            if (!selectedJenis) {
                 jumlahDisplayInput.value = '';
                 jumlahHiddenInput.value = '';
                 deskripsiInput.value = ''; 
                 return;
            }

            if (cleanJenis.toUpperCase().includes('SPP')) {
                sppOptionsDiv.style.display = 'block';
                
                let totalTarget = 0;
                const selectedMonths = [];

                sppCheckboxes.forEach(checkbox => {
                    // Data-target di sini adalah nominal yang disarankan (sisa tunggakan atau nominal penuh)
                    totalTarget += parseInt(checkbox.getAttribute('data-target'));
                    selectedMonths.push(checkbox.value);
                });

                if (selectedMonths.length > 0) {
                    // Kasus 1: Bulan dicentang
                    
                    // Deskripsi akan diisi otomatis, tapi bisa diedit manual
                    // Kita buat deskripsiInput menjadi readonly HANYA saat ada bulan dicentang 
                    // agar sistem yang mengisi deskripsi alokasi yang detail
                    deskripsiInput.readOnly = true; 
                    
                    let rangeDescription = getMonthRangeDescription(selectedMonths);
                    let deskripsiText = `Pembayaran SPP untuk bulan: ${rangeDescription}`;
                    
                    // Tetapkan target sebagai nilai awal (sebelum input manual)
                    let finalTarget = Math.round(totalTarget); 
                    
                    jumlahDisplayInput.value = formatRupiahJs(finalTarget);
                    jumlahHiddenInput.value = finalTarget.toFixed(0); 
                    deskripsiInput.value = deskripsiText;
                    
                    // Menonaktifkan event oninput sementara agar fokus pada nilai yang dihitung
                    jumlahDisplayInput.oninput = function() {
                        formatRupiahInput(this);
                    };

                } else {
                    // Kasus 2: Angsuran Bebas (Bulan tidak dicentang)
                    deskripsiInput.readOnly = false; // Bisa diisi manual
                    jumlahDisplayInput.value = '';
                    jumlahHiddenInput.value = '';
                    deskripsiInput.value = ''; 
                    
                    // Aktifkan lagi event oninput untuk input manual murni
                    jumlahDisplayInput.oninput = function() {
                        formatRupiahInput(this);
                    };
                }

            } else {
                // JENIS NON-SPP
                sppOptionsDiv.style.display = 'none';
                
                // Ekstraksi nominal dari string (e.g., "Seragam (Rp 500.000)")
                const match = selectedJenis.match(/\(Rp\s*([\d\.]+)\)/);
                let nominal = 0;
                if (match && match[1]) {
                    nominal = parseInt(match[1].replace(/\./g, ''));
                }
                
                // Cari sisa tunggakan non-spp (mengambil dari rekap table DOM)
                let sisaTunggakan = 0;
                const rekapTable = document.querySelector('.rekap-table');
                let foundTunggakan = false;
                if (rekapTable) {
                    rekapTable.querySelectorAll('tbody tr').forEach(row => {
                        const jenisCell = row.cells[0];
                        if (jenisCell && jenisCell.textContent.trim() === cleanJenis) {
                            const sisaCell = row.cells[3];
                            // Clean text: "Rp 250.000" -> 250000 | "<span class='badge-success'>Lunas</span>" -> 0
                            const sisaText = sisaCell.textContent.replace(/[^\d]/g, '');
                            sisaTunggakan = parseInt(sisaText) || 0;
                            
                            // Cek jika sisaCell hanya berisi "Lunas" (untuk memastikan nominal seharusnya > 0)
                            if (sisaCell.querySelector('.badge-success') && sisaCell.textContent.trim() === 'Lunas') {
                                // Jika nominal > 0 tapi status Lunas, berarti tunggakan = 0
                                foundTunggakan = (nominal > 0); 
                                sisaTunggakan = 0;
                            } else if (sisaTunggakan > 0) {
                                foundTunggakan = true;
                            }
                        }
                    });
                }
                
               // Tentukan jumlah yang harus dibayar: Sisa tunggakan > 0, gunakan sisa. Jika tidak, gunakan nominal penuh
        // Ini adalah 'nominal yang dibutuhkan'
        let requiredNominal = (foundTunggakan && sisaTunggakan > 0) ? sisaTunggakan : nominal;

        // Terapkan nilai awal
        if (requiredNominal === 0 && !foundTunggakan) {
             jumlahDisplayInput.value = '';
             jumlahHiddenInput.value = '';
             deskripsiInput.value = ''; 
             deskripsiInput.readOnly = false;
        } else {
             jumlahDisplayInput.value = formatRupiahJs(requiredNominal);
             jumlahHiddenInput.value = requiredNominal.toFixed(0); 
             deskripsiInput.value = `Pembayaran ${cleanJenis}`;
             deskripsiInput.readOnly = false;
        }
        
        // Update hidden input dan deskripsi ketika display diubah
        jumlahDisplayInput.oninput = function() {
            formatRupiahInput(this);
            const enteredAmount = parseInt(this.value.replace(/[^\d]/g, '')) || 0;
            
            // LOGIKA KELEBIHAN BAYAR BARU:
            if (enteredAmount > requiredNominal && requiredNominal > 0) {
                // Kelebihan Bayar (Overpayment)
                const overage = enteredAmount - requiredNominal;
                deskripsiInput.value = `Pelunasan ${cleanJenis} dan Kelebihan Bayar Rp ${formatRupiahJs(overage)}`;
            } else if (enteredAmount < requiredNominal && requiredNominal > 0) {
                // Angsuran
                 deskripsiInput.value = `Angsuran ${cleanJenis}`;
            } else if (enteredAmount === requiredNominal && requiredNominal > 0) {
                // Pelunasan Pas
                deskripsiInput.value = `Pelunasan ${cleanJenis}`;
            } else {
                // Kasus lain (Misalnya: Pembayaran untuk jenis yang belum ada tunggakan dan nominalnya 0, atau Angsuran Murni)
                deskripsiInput.value = `Pembayaran ${cleanJenis}`;
            }
        };
            }
        }
        
        // Panggil updateForm saat dokumen dimuat
        document.addEventListener('DOMContentLoaded', updateForm);
        
        // Panggil updateForm saat jenis pembayaran diubah
        document.getElementById('jenis_pembayaran').addEventListener('change', updateForm);
        
        // Perbaiki pemformatan jumlah saat diketik
        document.getElementById('jumlah_display').addEventListener('input', function() {
            formatRupiahInput(this);
        });

        // Set nilai hidden input sebelum submit
        document.getElementById('formPembayaran').addEventListener('submit', function(e) {
            const jumlahDisplay = document.getElementById('jumlah_display');
            const jumlahHidden = document.getElementById('jumlah_hidden');
            // Pastikan nilai tersembunyi sudah bersih dari format Rp dan titik
            jumlahHidden.value = jumlahDisplay.value.replace(/[^\d]/g, '');
            
            if (parseInt(jumlahHidden.value) <= 0) {
                 alert("Jumlah pembayaran harus lebih dari nol.");
                 e.preventDefault();
            }
        });
    </script>
</body>
</html>