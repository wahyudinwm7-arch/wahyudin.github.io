<?php
session_start();
include '../includes/koneksi.php';

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

$filename = "Laporan_Tunggakan_" . date('Ymd_His') . ".csv";
// Menggunakan koma (,) sebagai pemisah kolom untuk kompatibilitas Excel
header("Content-type: text/csv");
header("Content-Disposition: attachment; filename=$filename");
header("Pragma: no-cache");
header("Expires: 0");

$output = fopen('php://output', 'w');

// ------------------- FUNGSI HELPER -------------------
function formatRupiah($angka) {
    return number_format((float)($angka ?? 0), 0, ',', '.');
}

function getNonSppBiaya($conn, $id_siswa, $tahun_ajaran_siswa) {
    $biaya_standar = [];
    $total_tunggakan_non_spp = 0;
    $keterangan_non_spp = [];

    $query_biaya_non_spp = "
        SELECT jenis_pembayaran, nominal 
        FROM set_biaya 
        WHERE jenis_pembayaran NOT LIKE 'SPP%' 
        AND tahun_ajaran = ?
    ";

    if ($stmt_set = $conn->prepare($query_biaya_non_spp)) {
        $stmt_set->bind_param("s", $tahun_ajaran_siswa);
        $stmt_set->execute();
        $r = $stmt_set->get_result();
        while ($row = $r->fetch_assoc()) {
            $biaya_standar[$row['jenis_pembayaran']] = (float)$row['nominal'];
        }
        $stmt_set->close();
    }

    $query_khusus = "
        SELECT jenis_pembayaran, nominal_biaya 
        FROM nominal_biaya_siswa 
        WHERE id_siswa = ? AND tahun_ajaran = ?
    ";

    if ($stmt2 = $conn->prepare($query_khusus)) {
        $stmt2->bind_param("is", $id_siswa, $tahun_ajaran_siswa);
        $stmt2->execute();
        $r2 = $stmt2->get_result();
        while ($row = $r2->fetch_assoc()) {
            $biaya_standar[$row['jenis_pembayaran']] = (float)$row['nominal_biaya'];
        }
        $stmt2->close();
    }

    foreach ($biaya_standar as $jenis => $nominal_seharusnya) {
        $query_dibayar = "SELECT SUM(jumlah) AS total FROM transaksi WHERE id_siswa = ? AND jenis_pembayaran = ?";
        $stmt3 = $conn->prepare($query_dibayar);
        $stmt3->bind_param("is", $id_siswa, $jenis);
        $stmt3->execute();
        $dibayar = $stmt3->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt3->close();

        $sisa = $nominal_seharusnya - $dibayar;

        if ($sisa > 0) {
            $total_tunggakan_non_spp += $sisa;
            $keterangan_non_spp[] = "Tunggakan {$jenis}: " . formatRupiah($sisa); 
        } else {
            $keterangan_non_spp[] = "{$jenis}: Lunas";
        }
    }

    if ($total_tunggakan_non_spp == 0 && !empty($biaya_standar)) {
        $keterangan_non_spp = ['Lunas (Tidak Ada Tunggakan Non-SPP)'];
    } elseif (empty($biaya_standar)) {
        $keterangan_non_spp = ['Tidak Ada Kewajiban Non-SPP'];
    }

    return [
        'tunggakan_non_spp' => $total_tunggakan_non_spp,
        'keterangan_non_spp' => implode(', ', $keterangan_non_spp)
    ];
}

// ------------------- FILTER DATA (PENANGANAN MULTI-SELECT ROBUST) -------------------
$id_kelas_input = $_GET['id_kelas'] ?? null;
$id_kelas_dipilih = [];

if (!empty($id_kelas_input)) {
    if (is_array($id_kelas_input)) {
        // Case 1: Data datang sebagai array (Form HTML sudah benar: name="id_kelas[]")
        $id_kelas_dipilih = $id_kelas_input;
    } elseif (is_string($id_kelas_input)) {
        // Case 2 & 3: Data datang sebagai string. Kita coba pisahkan dengan koma.
        if (strpos($id_kelas_input, ',') !== false) {
            $id_kelas_dipilih = array_map('trim', explode(',', $id_kelas_input));
        } else {
            // Hanya satu nilai string
            $id_kelas_dipilih = [$id_kelas_input];
        }
    }
}
// Bersihkan array dari nilai kosong atau 0
$id_kelas_dipilih = array_filter($id_kelas_dipilih, function($v) { return $v > 0; });

$q_ta = mysqli_query($conn, "SELECT nama_tahun FROM tahun_ajaran WHERE aktif = 1");
$tahun_aktif = mysqli_fetch_assoc($q_ta)['nama_tahun'] ?? 'Tidak Ada';

$nama_kelas_dipilih = "Semua Kelas";

$query_siswa = "
    SELECT s.id_siswa, s.nama_lengkap, k.nama_kelas, ta.nama_tahun as tahun_ajaran_siswa,
            s.status_spp, s.bulan_mulai_spp, s.tahun_mulai_spp
    FROM siswa s
    JOIN kelas k ON s.id_kelas = k.id_kelas
    JOIN tahun_ajaran ta ON s.id_tahun_ajaran = ta.id_tahun_ajaran
";

$where = [];
$params = [];
$types = '';

if (!empty($id_kelas_dipilih)) {
    
    $placeholders = implode(',', array_fill(0, count($id_kelas_dipilih), '?'));
    $where[] = "s.id_kelas IN ($placeholders)";
    
    // Gabungkan parameter dan jenis data
    $params = array_merge($params, $id_kelas_dipilih);
    $types .= str_repeat('i', count($id_kelas_dipilih));

    // Ambil nama kelas untuk header CSV
    $stmt_kelas = $conn->prepare("SELECT GROUP_CONCAT(nama_kelas SEPARATOR ', ') as nama_kelas FROM kelas WHERE id_kelas IN ($placeholders)");
    $stmt_kelas->bind_param($types, ...$id_kelas_dipilih);
    $stmt_kelas->execute();
    $nama_kelas_dipilih = $stmt_kelas->get_result()->fetch_assoc()['nama_kelas'] ?? 'N/A';
    $stmt_kelas->close();
}

if ($where) {
    $query_siswa .= " WHERE " . implode(" AND ", $where);
}

$query_siswa .= " ORDER BY k.nama_kelas, s.nama_lengkap";

$stmt_siswa = $conn->prepare($query_siswa);
if (!empty($params)) {
    $stmt_siswa->bind_param($types, ...$params);
}

$stmt_siswa->execute();
$daftar_siswa = $stmt_siswa->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_siswa->close();

// ------------------- PROSES TUNGGAKAN -------------------
$bulan_semua = [
    'Juli' => 7, 'Agustus' => 8, 'September' => 9, 'Oktober' => 10,
    'November' => 11, 'Desember' => 12, 'Januari' => 1, 'Februari' => 2,
    'Maret' => 3, 'April' => 4, 'Mei' => 5, 'Juni' => 6
];

$tahun_sekarang = (int)date('Y');
$bulan_sekarang_idx = (int)date('n');

$id_siswa_list = array_column($daftar_siswa, 'id_siswa');
$total_dibayar_per_bulan_semua = [];

if ($id_siswa_list) {
    $placeholders = implode(',', array_fill(0, count($id_siswa_list), '?'));
    $types_in = str_repeat('i', count($id_siswa_list));

    $q = "SELECT id_siswa, jumlah, deskripsi 
          FROM transaksi 
          WHERE id_siswa IN ($placeholders) 
          AND jenis_pembayaran LIKE 'SPP%'";

    $stmt = $conn->prepare($q);
    $stmt->bind_param($types_in, ...$id_siswa_list);
    $stmt->execute();
    $r = $stmt->get_result();

    while ($row = $r->fetch_assoc()) {
        $id_siswa = $row['id_siswa'];
        $jumlah = (float)$row['jumlah'];

        if (!isset($total_dibayar_per_bulan_semua[$id_siswa])) {
            $total_dibayar_per_bulan_semua[$id_siswa] = [];
        }

        if (preg_match_all('/(Juli|Agustus|September|Oktober|November|Desember|Januari|Februari|Maret|April|Mei|Juni)\s(\d{4})/', $row['deskripsi'], $matches, PREG_SET_ORDER)) {

            $jumlah_bulan = count($matches);
            $per_bulan = $jumlah_bulan > 0 ? $jumlah / $jumlah_bulan : 0;

            foreach ($matches as $m) {
                $bln = $m[1];
                $th = $m[2];
                $key = "$bln $th";

                $total_dibayar_per_bulan_semua[$id_siswa][$key] =
                    ($total_dibayar_per_bulan_semua[$id_siswa][$key] ?? 0) + $per_bulan;
            }
        }
    }
    $stmt->close();
}

$laporan = [];

foreach ($daftar_siswa as $s) {
    $id = $s['id_siswa'];
    $tahun_ajaran = $s['tahun_ajaran_siswa'];

    $tahun_masuk_awal = (int)explode('/', $tahun_ajaran)[0];
    $tahun_mulai = (int)$s['tahun_mulai_spp'];
    $bulan_mulai_idx = (int)$s['bulan_mulai_spp'];

    // Nominal SPP
    $status_spp = $s['status_spp'];
    $ket_spp = ($status_spp === 'diskon') ? 'Diskon' : 'Normal';

    $stmt = $conn->prepare("
        SELECT nominal 
        FROM set_biaya 
        WHERE jenis_pembayaran='SPP' 
        AND keterangan=? 
        AND tahun_ajaran=?
    ");
    $stmt->bind_param("ss", $ket_spp, $tahun_ajaran);
    $stmt->execute();
    $nominal_per_bulan = (float)($stmt->get_result()->fetch_assoc()['nominal'] ?? 0);
    $stmt->close();

    if ($nominal_per_bulan <= 0) {
        $laporan[$id] = [
            'nama_siswa'=> $s['nama_lengkap'],
            'nama_kelas'=> $s['nama_kelas'],
            'tahun_masuk_siswa'=> $tahun_ajaran,
            'nominal_spp_per_bulan'=> 0,
            'tunggakan_spp'=> 0,
            'jumlah_bulan_tunggakan'=> 0,
            'dibayar_s_d_spp'=> 'Lunas (Nominal 0)',
            'keterangan_spp'=> 'Lunas',
            'tunggakan_non_spp'=> 0,
            'keterangan_non_spp'=> 'Lunas'
        ];
        continue;
    }

    $dibayar_siswa = $total_dibayar_per_bulan_semua[$id] ?? [];
    $total_tunggakan_spp = 0;
    
    $semua_bulan_wajib_bayar = [];
    $bulan_belum_lunas_total = [];
    $bulan_belum_lunas_due = []; 

    // Tentukan batas akhir masa studi (36 bulan)
    try {
        $start_date = new DateTime("{$tahun_mulai}-{$bulan_mulai_idx}-01");
        // Tambahkan 35 bulan (bulan pertama sudah terhitung)
        $end_date = clone $start_date;
        $end_date->modify('+35 months'); 
        
        $tahun_akhir_wajib_bayar = (int)$end_date->format('Y');
        $bulan_akhir_wajib_bayar_idx = (int)$end_date->format('n');
    } catch (Exception $e) {
        $tahun_akhir_wajib_bayar = $tahun_masuk_awal + 3; 
        $bulan_akhir_wajib_bayar_idx = 7;
    }
    
    // Tentukan batas loop: hingga tahun akhir studi ATAU tahun sekarang (mana yang lebih besar)
    $batas_tahun_minimal = max($tahun_sekarang, $tahun_akhir_wajib_bayar); 

    for ($th = $tahun_masuk_awal; $th <= $batas_tahun_minimal; $th++) { 
        foreach ($bulan_semua as $bln => $angka) {

            $tahun_spp = ($angka >= 7) ? $th : $th + 1;
            $key = "$bln $tahun_spp";

            // Kriteria 1: Mulai dari bulan masuk siswa
            $is_after_start =
                $tahun_spp > $tahun_mulai ||
                ($tahun_spp == $tahun_mulai && $angka >= $bulan_mulai_idx);
            
            // Kriteria 2: Sampai batas akhir masa studi (36 bulan)
            $is_before_end =
                $tahun_spp < $tahun_akhir_wajib_bayar ||
                ($tahun_spp == $tahun_akhir_wajib_bayar && $angka <= $bulan_akhir_wajib_bayar_idx);
            
            // Kriteria 3: Jatuh tempo sampai bulan ini
            $is_due_now =
                $tahun_spp < $tahun_sekarang ||
                ($tahun_spp == $tahun_sekarang && $angka <= $bulan_sekarang_idx);
                

            if ($is_after_start && $is_before_end) { 
                $semua_bulan_wajib_bayar[] = $key;
                
                $dibayar = $dibayar_siswa[$key] ?? 0;
                $sisa = $nominal_per_bulan - $dibayar;

                if ($sisa > 0) {
                    $bulan_belum_lunas_total[] = $key;
                    
                    if ($is_due_now) {
                        $bulan_belum_lunas_due[] = $key;
                        $total_tunggakan_spp += $sisa;
                    }  
                }
            }
        }
    }

    // ------------------- LOGIKA 'Dibayar s.d.' & KETERANGAN (FIXED ITERATIF) -------------------
    
    $jml_tunggak_total = count($bulan_belum_lunas_total);
    $ket_spp_final = '';
    $dibayar_sd = "Belum Ada Pembayaran";

    // FIX: LOGIKA ITERATIF UNTUK MENENTUKAN BULAN TERAKHIR YANG LUNAS
    $is_lunas_ditemukan = false;

    // Iterasi melalui semua bulan wajib bayar (sesuai urutan)
    foreach ($semua_bulan_wajib_bayar as $key_bulan) {
        $dibayar = $total_dibayar_per_bulan_semua[$id][$key_bulan] ?? 0;
        $sisa = $nominal_per_bulan - $dibayar;

        if ($sisa <= 0) {
            $dibayar_sd = $key_bulan; 
            $is_lunas_ditemukan = true;
        } else {
            break; 
        }
    }

    // --- Penentuan KETERANGAN SPP
    $jml_tunggak_due = count($bulan_belum_lunas_due);

    if ($jml_tunggak_total == 0) {
        $ket_spp_final = "Lunas Penuh";
        if (!empty($semua_bulan_wajib_bayar)) {
            $dibayar_sd = end($semua_bulan_wajib_bayar);
        }
    } else {
        if ($jml_tunggak_due == 0 && $jml_tunggak_total > 0) {
            $ket_spp_final = "Lunas Sampai Bulan Ini (Ada Prabayarmenyicil tunggakan)";
        } elseif ($jml_tunggak_due < 6) {
            $ket_spp_final = implode(', ', $bulan_belum_lunas_due);
        } else {
            $first = array_slice($bulan_belum_lunas_due, 0, 3);
            $last = array_slice($bulan_belum_lunas_due, -3);

            $ket_spp_final =
                implode(', ', $first)
                . " ... ({$jml_tunggak_due} Bulan) ... "
                . implode(', ', $last);
        }
    }
    // ------------------- AKHIR LOGIKA 'Dibayar s.d.' & KETERANGAN -------------------

    // Non-SPP
    $non_spp = getNonSppBiaya($conn, $id, $tahun_ajaran);

    $laporan[$id] = [
        'nama_siswa'=> $s['nama_lengkap'],
        'nama_kelas'=> $s['nama_kelas'],
        'tahun_masuk_siswa'=> $tahun_ajaran,
        'nominal_spp_per_bulan'=> $nominal_per_bulan,
        'tunggakan_spp'=> $total_tunggakan_spp,
        'jumlah_bulan_tunggakan'=> count($bulan_belum_lunas_due), 
        'dibayar_s_d_spp'=> $dibayar_sd,
        'keterangan_spp'=> $ket_spp_final,
        'tunggakan_non_spp'=> $non_spp['tunggakan_non_spp'],
        'keterangan_non_spp'=> $non_spp['keterangan_non_spp']
    ];
}

// ------------------- CETAK CSV (Menggunakan Koma) -------------------

fputcsv($output, ["LAPORAN TUNGGAKAN SISWA"], ',');
fputcsv($output, ["Periode", date('d-m-Y H:i:s')], ',');
fputcsv($output, ["Tahun Ajaran Aktif", $tahun_aktif], ',');
fputcsv($output, ["Kelas", $nama_kelas_dipilih], ',');
fputcsv($output, [""], ',');

// Header disamakan dengan aplikasi
fputcsv($output, [
    "NO",
    "NAMA SISWA",
    "KELAS",
    "TUNGGAKAN SPP",
    "SUDAH LUNAS S.D.",
    "KETERANGAN SPP YANG BELUM DIBAYAR",
    "TUNGGAKAN NON-SPP",
    "KETERANGAN NON-SPP",
    "TOTAL TUNGGAKAN"
], ',');

$no = 1;
$grand_total = 0;

foreach ($laporan as $d) {
    $total = $d['tunggakan_spp'] + $d['tunggakan_non_spp'];
    $grand_total += $total;

    fputcsv($output, [
        $no++,
        $d['nama_siswa'],
        $d['nama_kelas'],
        "'" . (string)$d['tunggakan_spp'],           
        $d['dibayar_s_d_spp'],
        $d['keterangan_spp'],
        "'" . (string)$d['tunggakan_non_spp'],        
        $d['keterangan_non_spp'],
        "'" . (string)$total                      
    ], ',');
}

fputcsv($output, [""], ',');

fputcsv($output, [
    "","","","","","","","GRAND TOTAL:",
    formatRupiah($grand_total)
], ',');

fclose($output);
mysqli_close($conn);
exit;
?>