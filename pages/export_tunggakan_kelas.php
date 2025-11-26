<?php
session_start();
// Pastikan path ke koneksi benar
include '../includes/koneksi.php'; 

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['nama_pengguna'])) {
    // Jika tidak login, arahkan kembali
    header("Location: ../login.php");
    exit();
}

$id_kelas = $_GET['id_kelas'] ?? null;

if (empty($id_kelas)) {
    die("Kelas belum dipilih.");
}

// ------------------- FUNGSI HELPER DIPERLUKAN -------------------
// Fungsi ini HANYA digunakan untuk tujuan tampilan Rupiah di Keterangan Non-SPP.
function formatRupiahDisplay($angka) {
    return "Rp " . number_format((float)($angka ?? 0), 0, ',', '.');
}

function getNonSppBiaya($conn, $id_siswa, $tahun_ajaran_siswa) {
    // Menggunakan fungsi yang dimodifikasi dari kode Anda (laporan_per_kelas.php) 
    // untuk menghitung tunggakan Non-SPP dan kelebihan bayar.

    $biaya_standar = [];
    $detail_tunggakan_non_spp = [];
    $total_tunggakan_non_spp = 0;
    $total_kelebihan_bayar_siswa = 0;

    $query_biaya_non_spp = "
        SELECT jenis_pembayaran, MAX(nominal) as nominal
        FROM set_biaya
        WHERE jenis_pembayaran NOT LIKE 'SPP%'
        AND tahun_ajaran = ?
        GROUP BY jenis_pembayaran
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
            $biaya_standar[$row['jenis_pembayaran']] = (float)$row['nominal_biaya'];
        }
        $stmt_khusus->close();
    }
    
    if (!isset($biaya_standar['Deposit/Sisa Lebih Bayar'])) {
        $biaya_standar['Deposit/Sisa Lebih Bayar'] = 0;
    }

	foreach ($biaya_standar as $jenis => $nominal_seharusnya) {
        
        $query_masuk = "SELECT SUM(jumlah) AS total_masuk FROM transaksi WHERE id_siswa = ? AND jenis_pembayaran = ? AND jenis_transaksi = 'masuk'";
        
        $total_masuk = 0;
        if ($stmt_masuk = $conn->prepare($query_masuk)) {
            $stmt_masuk->bind_param("is", $id_siswa, $jenis);
            $stmt_masuk->execute();
            $total_masuk = $stmt_masuk->get_result()->fetch_assoc()['total_masuk'] ?? 0;
            $stmt_masuk->close();
        }
        
        $total_keluar = 0;
        if ($jenis === "Deposit/Sisa Lebih Bayar") {
            $query_keluar = "SELECT SUM(jumlah) AS total_keluar FROM transaksi WHERE id_siswa = ? AND jenis_pembayaran = ? AND jenis_transaksi = 'keluar'";
            
            if ($stmt_keluar = $conn->prepare($query_keluar)) {
                $stmt_keluar->bind_param("is", $id_siswa, $jenis);
                $stmt_keluar->execute();
                $total_keluar = $stmt_keluar->get_result()->fetch_assoc()['total_keluar'] ?? 0;
                $stmt_keluar->close();
            }
        }
        
        $total_dibayar = (float)$total_masuk - (float)$total_keluar;
        
        $sisa_tunggakan = (float)$nominal_seharusnya - $total_dibayar;

        if ($sisa_tunggakan > 0) {
            $total_tunggakan_non_spp += $sisa_tunggakan;
            $detail_tunggakan_non_spp[$jenis] = "Tunggakan " . formatRupiahDisplay($sisa_tunggakan);
        } elseif ($sisa_tunggakan < 0) {
            $kelebihan = abs($sisa_tunggakan);
            $total_kelebihan_bayar_siswa += $kelebihan;
            
            if ($jenis !== "Deposit/Sisa Lebih Bayar") {
                $detail_tunggakan_non_spp[$jenis] = "Lunas (Lebih: " . formatRupiahDisplay($kelebihan) . ")";
            } else {
                 $detail_tunggakan_non_spp[$jenis] = "Saldo Deposit: " . formatRupiahDisplay($kelebihan);
            }
        } else {
            $detail_tunggakan_non_spp[$jenis] = "Lunas (Pas)";
        }
	}
    
    // Hilangkan Deposit dari detail karena sudah dihitung terpisah
    unset($detail_tunggakan_non_spp['Deposit/Sisa Lebih Bayar']);
    
    // Ubah detail array ke string untuk CSV
    $keterangan_non_spp = array_map(function($jenis, $detail) {
        return "{$jenis}: {$detail}";
    }, array_keys($detail_tunggakan_non_spp), $detail_tunggakan_non_spp);
    
    if (empty($detail_tunggakan_non_spp) || (count($detail_tunggakan_non_spp) == 1 && strpos(reset($detail_tunggakan_non_spp), 'Lunas') !== false)) {
         $keterangan_non_spp = ['Lunas (Tidak Ada Tunggakan Non-SPP)'];
    }


    return [
        'tunggakan_non_spp' => max(0, $total_tunggakan_non_spp),
        'keterangan_non_spp' => implode(', ', $keterangan_non_spp),
        'kelebihan_bayar' => $total_kelebihan_bayar_siswa
    ];
}


// ------------------- LOGIKA PENENTUAN SPP -------------------

$bulan_semua = [
    'Juli' => 7, 'Agustus' => 8, 'September' => 9, 'Oktober' => 10,
    'November' => 11, 'Desember' => 12, 'Januari' => 1, 'Februari' => 2,
    'Maret' => 3, 'April' => 4, 'Mei' => 5, 'Juni' => 6
];

$tahun_sekarang = (int)date('Y');
$bulan_sekarang_idx = (int)date('n');

// ------------------- AMBIL DATA SISWA -------------------

$query_siswa = "
    SELECT s.id_siswa, s.nama_lengkap, s.nisn, k.nama_kelas, ta.nama_tahun as tahun_ajaran_siswa,
           s.status_spp, s.bulan_mulai_spp, s.tahun_mulai_spp
    FROM siswa s
    JOIN kelas k ON s.id_kelas = k.id_kelas
    JOIN tahun_ajaran ta ON s.id_tahun_ajaran = ta.id_tahun_ajaran
    WHERE s.id_kelas = ?
    ORDER BY s.nama_lengkap
";

$stmt_siswa = $conn->prepare($query_siswa);
$stmt_siswa->bind_param("i", $id_kelas);
$stmt_siswa->execute();
$daftar_siswa = $stmt_siswa->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_siswa->close();

$id_siswa_list = array_column($daftar_siswa, 'id_siswa');
$total_dibayar_per_bulan_semua = [];

if ($id_siswa_list) {
    $placeholders = implode(',', array_fill(0, count($id_siswa_list), '?'));
    $types_in = str_repeat('i', count($id_siswa_list));

    // Pastikan hanya transaksi masuk SPP yang diambil
    $q = "SELECT id_siswa, jumlah, deskripsi 
          FROM transaksi 
          WHERE id_siswa IN ($placeholders) 
          AND jenis_pembayaran LIKE 'SPP%' 
          AND jenis_transaksi = 'masuk'"; 

    $stmt = $conn->prepare($q);
    $bind_params = array_merge([$types_in], $id_siswa_list);
    $ref = [];
    foreach ($bind_params as $key => $value) {
        $ref[$key] = &$bind_params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $ref);

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

// ------------------- HITUNG TUNGGAKAN PER SISWA -------------------
$laporan = [];
$total_tunggakan_kelas = 0;
$nama_kelas_dipilih = '';

foreach ($daftar_siswa as $s) {
    $id = $s['id_siswa'];
    $tahun_ajaran = $s['tahun_ajaran_siswa'];
    $nama_kelas_dipilih = $s['nama_kelas'];

    $tahun_masuk_awal = (int)explode('/', $tahun_ajaran)[0];
    $tahun_mulai = (int)$s['tahun_mulai_spp'];
    $bulan_mulai_idx = (int)$s['bulan_mulai_spp'];

    // Nominal SPP
    $status_spp = strtolower($s['status_spp']);
    $ket_spp_db = (strpos($status_spp, 'diskon') !== false) ? 'Diskon' : 'Normal';

    $stmt = $conn->prepare("
        SELECT nominal 
        FROM set_biaya 
        WHERE jenis_pembayaran='SPP' 
        AND keterangan=? 
        AND tahun_ajaran=?
    ");
    $stmt->bind_param("ss", $ket_spp_db, $tahun_ajaran);
    $stmt->execute();
    $nominal_per_bulan = (float)($stmt->get_result()->fetch_assoc()['nominal'] ?? 0);
    $stmt->close();

    $dibayar_siswa = $total_dibayar_per_bulan_semua[$id] ?? [];
    $bulan_belum_lunas = [];
    $total_tunggakan_spp = 0;
    $semua_bulan_wajib_bayar = [];

    $tahun_sekarang_plus_satu = $tahun_sekarang + 1;
    
    if ($nominal_per_bulan > 0) {
        for ($th = $tahun_masuk_awal; $th <= $tahun_sekarang_plus_satu; $th++) {
            foreach ($bulan_semua as $bln => $angka) {
    
                $tahun_spp = ($angka >= 7) ? $th : $th + 1;
                $key = "$bln $tahun_spp";
    
                $is_before_now =
                    $tahun_spp < $tahun_sekarang ||
                    ($tahun_spp == $tahun_sekarang && $angka <= $bulan_sekarang_idx);
    
                $is_after_start =
                    $tahun_spp > $tahun_mulai ||
                    ($tahun_spp == $tahun_mulai && $angka >= $bulan_mulai_idx);
    
                if ($is_before_now && $is_after_start) {
                    $semua_bulan_wajib_bayar[] = $key;
                    
                    $dibayar = $dibayar_siswa[$key] ?? 0;
                    $sisa = $nominal_per_bulan - $dibayar;
    
                    if ($sisa > 0) {
                        $bulan_belum_lunas[] = $key;
                        $total_tunggakan_spp += $sisa;
                    }
                }
            }
        }
    }

    // Tentukan kolom Dibayar s.d. dan Keterangan SPP
    $jml_tunggak = count($bulan_belum_lunas);
    $ket_spp_final = '';
    $dibayar_sd = '';

    if ($jml_tunggak == 0) {
        $ket_spp_final = "Lunas";
        
        // 📌 PERBAIKAN: Cek jika array tidak kosong sebelum menggunakan end()
        if (!empty($semua_bulan_wajib_bayar)) {
            $dibayar_sd = end($semua_bulan_wajib_bayar); 
        } else {
             $dibayar_sd = "Belum Ada Kewajiban SPP";
        }
        
    } else {
        $bulan_pertama_tunggakan = reset($bulan_belum_lunas);
        $index_tunggakan = array_search($bulan_pertama_tunggakan, $semua_bulan_wajib_bayar);

        // 📌 PERBAIKAN: Cek jika array kosong atau index 0
        if ($index_tunggakan === false || $index_tunggakan === 0 || empty($semua_bulan_wajib_bayar)) {
            $dibayar_sd = "Belum Ada Pembayaran";
        } else {
            $dibayar_sd = $semua_bulan_wajib_bayar[$index_tunggakan - 1];
        }
        
        // Logika untuk Keterangan SPP
        if ($jml_tunggak < 6) {
            $ket_spp_final = implode(', ', $bulan_belum_lunas);
        } else {
            $first = array_slice($bulan_belum_lunas, 0, 3);
            $last = array_slice($bulan_belum_lunas, -3);

            $ket_spp_final =
                implode(', ', $first)
                . " ... ({$jml_tunggak} Bulan) ... "
                . implode(', ', $last);
        }
    }

    // Non-SPP
    $non_spp = getNonSppBiaya($conn, $id, $tahun_ajaran);

    $total_tunggakan_siswa = $total_tunggakan_spp + $non_spp['tunggakan_non_spp'];
    $total_tunggakan_kelas += $total_tunggakan_siswa;

    $laporan[] = [
        'nama_siswa'        => $s['nama_lengkap'],
        'nisn'              => $s['nisn'],
        'tahun_masuk_siswa' => $tahun_ajaran,
        'nominal_spp_per_bulan'=> $nominal_per_bulan,
        'tunggakan_spp'     => max(0, $total_tunggakan_spp),
        'jumlah_bulan_tunggakan'=> $jml_tunggak,
        'dibayar_s_d_spp'   => $dibayar_sd,
        'keterangan_spp'    => $ket_spp_final,
        'tunggakan_non_spp' => max(0, $non_spp['tunggakan_non_spp']),
        'keterangan_non_spp'=> $non_spp['keterangan_non_spp'],
        'kelebihan_bayar'   => $non_spp['kelebihan_bayar'],
        'total_tunggakan'   => max(0, $total_tunggakan_siswa)
    ];
}


// ------------------- CETAK CSV -------------------

$filename = "Rincian_Tunggakan_" . str_replace(' ', '_', $nama_kelas_dipilih) . "_" . date('Ymd_His') . ".csv";
header("Content-type: text/csv");
header("Content-Disposition: attachment; filename=$filename");
header("Pragma: no-cache");
header("Expires: 0");

// Menggunakan semicolon (;) sebagai delimiter CSV
$output = fopen('php://output', 'w');

// Header Info
fputcsv($output, ["RINCIAN LAPORAN TUNGGAKAN SISWA"], ';');
fputcsv($output, ["Kelas", $nama_kelas_dipilih], ';');
fputcsv($output, ["Tanggal Export", date('d-m-Y H:i:s')], ';');
fputcsv($output, [""], ';');

// Header Kolom Data
fputcsv($output, [
    "No","Nama Siswa","NISN","Tahun Masuk",
    "Nominal SPP (Per Bulan)","Tunggakan SPP (Nominal)",
    "Jumlah Bulan Tunggakan","Dibayar s.d.",
    "Keterangan Detail SPP",
    "Tunggakan Non-SPP (Nominal)",
    "Keterangan Detail Non-SPP",
    "Sisa Lebih Bayar (Deposit)",
    "TOTAL TUNGGAKAN SISWA"
], ';');

$no = 1;
$grand_total_tunggakan = 0;
$grand_total_kelebihan = 0;

foreach ($laporan as $d) {
    $grand_total_tunggakan += $d['total_tunggakan'];
    $grand_total_kelebihan += $d['kelebihan_bayar'];

    // Nominal dicetak tanpa format Rupiah agar bisa dijumlahkan di spreadsheet
    fputcsv($output, [
        $no++,
        $d['nama_siswa'],
        $d['nisn'],
        $d['tahun_masuk_siswa'],
        $d['nominal_spp_per_bulan'],
        $d['tunggakan_spp'],
        $d['jumlah_bulan_tunggakan'],
        $d['dibayar_s_d_spp'],
        $d['keterangan_spp'],
        $d['tunggakan_non_spp'],
        $d['keterangan_non_spp'], 
        $d['kelebihan_bayar'],
        $d['total_tunggakan']
    ], ';');
}

// Footer/Grand Total
fputcsv($output, [""], ';');
fputcsv($output, [
    "","","","","","","","","","","GRAND TOTAL TUNGGAKAN KELAS",
    $grand_total_tunggakan, // Nominal murni
    "TOTAL SISA LEBIH BAYAR KELAS",
    $grand_total_kelebihan // Nominal murni
], ';');

fclose($output);
mysqli_close($conn);
exit;
?>