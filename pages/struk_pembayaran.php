<?php
session_start();
// Pastikan path ke koneksi.php sudah benar
include '../includes/koneksi.php';

// Pastikan pengguna sudah login
if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

// Ambil ID transaksi
$id_transaksi = isset($_GET['id_transaksi']) ? intval($_GET['id_transaksi']) : 0;
if ($id_transaksi == 0) {
    die("ID Transaksi tidak valid.");
}

// Query transaksi: Mengambil data siswa, kelas, dan pengguna yang mencatat
$query_transaksi = "
    SELECT 
        t.*, 
        t.jenis_pembayaran AS nama_jenis_transaksi,
        s.nama_lengkap AS nama_siswa, 
        s.nisn, 
        k.nama_kelas,
        u.nama_pengguna AS dicatat_oleh
    FROM transaksi t
    JOIN siswa s ON t.id_siswa = s.id_siswa
    LEFT JOIN kelas k ON s.id_kelas = k.id_kelas
    LEFT JOIN pengguna u ON t.dicatat_oleh_id_pengguna = u.id_pengguna
    WHERE t.id_transaksi = ?
";

$stmt = $conn->prepare($query_transaksi);
$stmt->bind_param("i", $id_transaksi);
$stmt->execute();
$result = $stmt->get_result();
$transaksi = $result->fetch_assoc();
$stmt->close();

if (!$transaksi) {
    die("Data transaksi tidak ditemukan.");
}

// =========================================================================
// --- FUNGSI UNTUK MENYEDERHANAKAN DESKRIPSI BULAN (MAKSIMAL RINGKAS) ---
// =========================================================================
function sederhanakan_deskripsi_bulan($deskripsi_raw) {
    // 1. Cek apakah ini pembayaran SPP menggunakan pola yang lebih fleksibel
    if (!preg_match('/Pembayaran SPP:(.*)/is', $deskripsi_raw, $matches)) {
        return $deskripsi_raw; // Jika bukan SPP, kembalikan deskripsi asli
    }

    // Ambil seluruh string setelah "Pembayaran SPP:"
    $bulan_tahun_str = $matches[1] ?? '';
    
    // Pembersihan String untuk Ekstraksi (ganti semua pemisah dengan koma)
    $bulan_tahun_str = preg_replace('/\s+(dan|AND|:)\s+/i', ',', $bulan_tahun_str);
    $bulan_tahun_str = str_replace(PHP_EOL, ',', $bulan_tahun_str);
    
    // Mengambil pola BULAN TAHUN
    preg_match_all('/\b(Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)\s+\d{4}\b/i', $bulan_tahun_str, $bulan_matches);
    $bulan_array_mentah = array_map('trim', $bulan_matches[0]);
    $bulan_array_mentah = array_filter(array_unique($bulan_array_mentah));

    if (empty($bulan_array_mentah)) {
        return $deskripsi_raw;
    }

    $urutan_bulan = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
        'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12
    ];

    $singkatan_bulan = [
        1 => 'JAN', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MEI', 6 => 'JUN',
        7 => 'JUL', 8 => 'AGU', 9 => 'SEP', 10 => 'OKT', 11 => 'NOV', 12 => 'DES'
    ];

    $timestamps = [];

    // 2. Konversi dan Sortir Bulan (Menggunakan Timestamp untuk Urutan Pasti)
    foreach ($bulan_array_mentah as $item) {
        $item_lower = strtolower($item);
        if (preg_match('/(\w+)\s+(\d{4})/', $item_lower, $parts)) {
            $bulan_str = $parts[1];
            $tahun = intval($parts[2]);
            $bulan_num = $urutan_bulan[$bulan_str] ?? 0;
            
            $timestamp = strtotime("1 {$bulan_str} {$tahun}");
            if ($bulan_num !== 0 && $timestamp !== false) {
                $timestamps[$timestamp] = [
                    'tahun' => $tahun,
                    'urutan' => $bulan_num,
                    'singkatan_tampil' => "{$singkatan_bulan[$bulan_num]} {$tahun}"
                ];
            }
        }
    }
    
    ksort($timestamps); // Sortir berdasarkan tanggal (timestamp)
    $data_bulan_sorted = array_values($timestamps);

    // 3. LOGIKA PENGELOMPOKAN
    $kelompok_bulan = [];
    $current_group = [];

    foreach ($data_bulan_sorted as $current_bulan) {
        if (empty($current_group)) {
            $current_group[] = $current_bulan;
            continue;
        }

        $last_bulan = end($current_group);
        
        $next_urutan = ($last_bulan['urutan'] == 12) ? 1 : $last_bulan['urutan'] + 1;
        $next_tahun = ($last_bulan['urutan'] == 12) ? $last_bulan['tahun'] + 1 : $last_bulan['tahun'];
        
        $is_consecutive = ($current_bulan['urutan'] === $next_urutan && $current_bulan['tahun'] === $next_tahun);

        if ($is_consecutive) {
            $current_group[] = $current_bulan;
        } else {
            $kelompok_bulan[] = $current_group;
            $current_group = [$current_bulan];
        }
    }
    
    if (!empty($current_group)) {
        $kelompok_bulan[] = $current_group;
    }

    // 4. Format kelompok-kelompok menjadi string ringkasan
    $ringkasan = [];
    foreach ($kelompok_bulan as $group) {
        $count = count($group);
        $awal = $group[0]['singkatan_tampil'];
        $akhir = end($group)['singkatan_tampil'];
        
        if ($count > 2) {
            // RENTANG (3 bulan atau lebih)
            $ringkasan[] = "{$awal} S.D. {$akhir}";
        } elseif ($count == 2) {
            // Dua bulan
            $ringkasan[] = "{$awal} DAN {$akhir}";
        } else {
            // Satu bulan
            $ringkasan[] = $awal;
        }
    }

    $ringkasan_final = implode(', ', $ringkasan);

    // 5. Gabungkan kembali dengan prefix standar
    return "PEMBAYARAN SPP UNTUK BULAN: {$ringkasan_final}";
}
// =========================================================================
// --- FUNGSI TERBILANG (tetap) ---
// =========================================================================
function terbilang($x) {
    $abil = array("", "satu","dua","tiga","empat","lima","enam","tujuh","delapan","sembilan","sepuluh","sebelas");
    $x = abs(intval($x));
    if ($x < 12) return " " . $abil[$x];
    elseif ($x < 20) return terbilang($x - 10) . " belas";
    elseif ($x < 100) return terbilang($x / 10) . " puluh" . terbilang($x % 10);
    elseif ($x < 200) return " seratus" . terbilang($x - 100);
    elseif ($x < 1000) return terbilang($x / 100) . " ratus" . terbilang($x % 100);
    elseif ($x < 2000) return " seribu" . terbilang($x - 1000);
    elseif ($x < 1000000) return terbilang($x / 1000) . " ribu" . terbilang($x % 1000);
    elseif ($x < 1000000000) return terbilang($x / 1000000) . " juta" . terbilang($x % 1000000);
    return "";
}

// =========================================================================
// --- PERSIAPAN DATA ---
// =========================================================================

$nomor_kwitansi = "KW-" . date('dmY', strtotime($transaksi['tanggal_transaksi'])) . "-" . $transaksi['id_transaksi'];
$total_angka = number_format($transaksi['jumlah'], 0, ',', '.');
$total_terbilang = ucwords(trim(terbilang($transaksi['jumlah']))) . " Rupiah"; 
$tanggal = date('d F Y', strtotime($transaksi['tanggal_transaksi']));

// Panggil fungsi penyederhanaan di sini!
$deskripsi_final = sederhanakan_deskripsi_bulan($transaksi['deskripsi'] ?? '-');

// --- PENGGABUNGAN NAMA DAN KELAS ---
$nama_kelas_siswa = strtoupper($transaksi['nama_kelas']) ?? '-'; 
$nama_siswa_dan_kelas = strtoupper($transaksi['nama_siswa']) . " / " . $nama_kelas_siswa;
// ---------------------------------
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Kwitansi Pembayaran</title>
<style>
    body {
        font-family: "Courier New", monospace;
        font-size: 11pt;
    }
    .page {
        /* Ukuran Kertas: 28cm x 9cm (Untuk 2 kolom kwitansi) */
        width: 28cm; height: 9cm;
        margin: 0;
        position: relative;
    }

    .field { 
        position: absolute;
        line-height: 1.2em;
    }
    
    /* --- Gaya untuk Teks Terbilang (Huruf Sambung Miring) --- */
    .terbilang_style {
        font-family: 'Times New Roman', Times, serif, cursive; 
        font-style: italic;
        font-size: 12pt; 
    }
    /* -------------------------------------- */

    /* Bagian kiri (Untuk Siswa) - Area yang sangat sempit */
    .left_no 	 	 { top: 10mm; left: 9mm; }
    
    /* FIELD NAMA DAN KELAS */
    .left_nama 	 	 { top: 20mm; left: 5mm; width: 80mm; white-space: normal; font-weight: bold; } 
    .left_kelas 	{ top: 25mm; left: 5mm; width: 80mm; white-space: normal; font-weight: bold; } 
    
    /* Deskripsi Pembayaran (KRITIS: Dibuat lebih kecil agar muat) */
    .left_bayar 	{ top: 38mm; left: 5mm; width: 80mm; white-space: normal; font-size: 10pt; } 
    .left_nominal 	{ top: 50mm; left: 14mm; font-weight: bold; font-size: 12pt; }

    /* Bagian kanan (salinan/arsip) - Mulai dari 11.5cm */
    .right_no 	 	 { top: 2mm; left: 115mm; }
    .right_nama_kelas { top: 13mm; left: 135mm; width: 11cm; white-space: normal; font-weight: bold; }
    .right_terbilang { top: 25mm; left: 128mm; width: 11cm; white-space: normal; }
    .right_bayar 	{ top: 35mm; left: 135mm; width: 11cm; white-space: normal; font-size: 11pt; } 
    .right_nominal 	{ top: 65mm; left: 110mm; font-weight: bold; font-size: 13pt; }
    .right_tanggal 	{ bottom: 35mm; right: 25mm; }
    .right_ttd 	 	 { bottom: 10mm; right: 55mm; }
/* ========================================================================= */
    /* --- PENGATURAN CETAK (@media print) --- */
    /* ========================================================================= */
    @media print {
        @page {
            size: 280mm 90mm landscape; 
            margin: 0; 
        }

        body {
            font-size: 12pt !important; 
        }

        .left_nominal {
            font-size: 14pt !important;
        }

        .right_nominal {
            font-size: 15pt !important;
        }
        
        .terbilang_style {
            font-size: 13pt !important; 
        }

        /* Perubahan di @media print juga! */
        .left_bayar {
            font-size: 11pt !important; /* Tingkatkan di mode cetak, tapi tetap lebih kecil dari default body */
        }

        .page-break { page-break-after: always; }
    }
</style>
</head>
<body onload="window.print()">

<div class="page">
    <!-- KWITANSI BAGIAN KIRI (UNTUK SISWA) -->
    <div class="field left_no"><?= $nomor_kwitansi ?></div>
    
    <div class="field left_nama"><?= strtoupper($transaksi['nama_siswa']) ?></div>
    <div class="field left_kelas"><?= strtoupper($transaksi['nama_kelas']) ?></div>
    
    <div class="field left_bayar"><?= $deskripsi_final ?></div>
    
    <div class="field left_nominal"><?= $total_angka ?></div>

    <!-- KWITANSI BAGIAN KANAN (UNTUK ARSIP) -->
    <div class="field right_no"><?= $nomor_kwitansi ?></div>
    
    <div class="field right_nama_kelas"><?= $nama_siswa_dan_kelas ?></div>
    
    <div class="field right_terbilang"><span class="terbilang_style"><?= $total_terbilang ?></span></div>
    
    <div class="field right_bayar"><?= $deskripsi_final ?></div>
    
    <div class="field right_nominal"><?= $total_angka ?></div>
    <div class="field right_tanggal">Darmaraja, <?= $tanggal ?></div>
    <div class="field right_ttd"><?= htmlspecialchars($transaksi['dicatat_oleh']) ?></div>
</div>

</body>
</html>
<?php mysqli_close($conn); ?>