<?php
session_start();
include '../includes/koneksi.php'; // Pastikan path ke koneksi benar

date_default_timezone_set('Asia/Jakarta');

// --- Fungsi Konversi Angka ke Terbilang ---
function terbilang($angka) {
    // FUNGSI LENGKAP TERBILANG DITEMPATKAN DI SINI
    $angka = abs($angka);
    $bilangan = array(
        '', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'
    );
    $temp = '';
    if ($angka < 12) {
        $temp = ' ' . $bilangan[$angka];
    } elseif ($angka < 20) {
        $temp = terbilang($angka - 10) . ' belas';
    } elseif ($angka < 100) {
        $temp = terbilang($angka / 10) . ' puluh' . terbilang($angka % 10);
    } elseif ($angka < 200) {
        $temp = ' seratus' . terbilang($angka - 100);
    } elseif ($angka < 1000) {
        $temp = terbilang($angka / 100) . ' ratus' . terbilang($angka % 100);
    } elseif ($angka < 2000) {
        $temp = ' seribu' . terbilang($angka - 1000);
    } elseif ($angka < 1000000) {
        $temp = terbilang($angka / 1000) . ' ribu' . terbilang($angka % 1000);
    } elseif ($angka < 1000000000) {
        $temp = terbilang($angka / 1000000) . ' juta' . terbilang($angka % 1000000);
    } elseif ($angka < 1000000000000) {
        $temp = terbilang($angka / 1000000000) . ' milyar' . terbilang($angka % 1000000000);
    }
    return $temp;
}

function sebut_terbilang($x) {
    if ($x < 0) {
        return 'minus ' . trim(terbilang($x));
    }
    $hasil = trim(terbilang($x));
    return ucwords($hasil); 
}
// ---------------------------------------------

if (!isset($_GET['id_transaksi'])) {
    die("ID Transaksi tidak ditemukan.");
}

$id_transaksi = intval($_GET['id_transaksi']);

// Ambil data transaksi
$query = "
    SELECT 
        t.tanggal_transaksi, t.jumlah, t.deskripsi, t.jenis_pembayaran, t.id_siswa,
        s.nama_lengkap AS nama_siswa, s.nisn,
        k.nama_kelas,
        u.nama_pengguna AS dicatat_oleh
    FROM 
        transaksi t
    LEFT JOIN 
        siswa s ON t.id_siswa = s.id_siswa
    LEFT JOIN
        kelas k ON s.id_kelas = k.id_kelas
    LEFT JOIN
        pengguna u ON t.dicatat_oleh_id_pengguna = u.id_pengguna
    WHERE 
        t.id_transaksi = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id_transaksi);
$stmt->execute();
$result = $stmt->get_result();
$data_transaksi = $result->fetch_assoc();
$stmt->close();
mysqli_close($conn);

if (!$data_transaksi) {
    die("Data transaksi tidak ditemukan.");
}

$jumlah_terbilang = sebut_terbilang($data_transaksi['jumlah']);
$nama_bendahara = $data_transaksi['dicatat_oleh'] ?? 'Bendahara Sekolah'; 
$tempat_sekolah = "Darmajaya"; // Mengambil dari contoh Excel
$tanggal_kwitansi = date('d F Y', strtotime($data_transaksi['tanggal_transaksi']));

// Menarik data untuk kuitansi
$siswa_nama = htmlspecialchars($data_transaksi['nama_siswa']);
$siswa_kelas = htmlspecialchars($data_transaksi['nama_kelas']);
$jenis_bayar = htmlspecialchars($data_transaksi['jenis_pembayaran']);
$nominal_rp = number_format($data_transaksi['jumlah'], 0, ',', '.');
$tanggal_kwitansi_short = date('d/m/Y', strtotime($data_transaksi['tanggal_transaksi']));

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kwitansi #<?php echo $id_transaksi; ?></></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
        }

        /* Container utama dengan border tipis */
        .kwitansi-container {
            width: 250mm; 
            height: 150mm; 
            border: 1px solid #000; 
            box-sizing: border-box;
            position: relative;
            background: #fff;
            padding: 10px; /* Padding untuk jarak dari border luar */
        }
        
        /* Tabel Utama untuk Meniru Struktur Sel Excel */
        .excel-table {
            width: 100%;
            height: 100%;
            border-collapse: collapse;
            table-layout: fixed; /* Memastikan lebar kolom tetap */
        }

        /* Mendefinisikan Kolom Palsu (Meniru Kolom Excel A-P) */
        .col-a { width: 4%; }
        .col-b { width: 4%; }
        .col-c { width: 4%; }
        .col-d { width: 10%; } /* GIAN, Nama Siswa di Kiri */
        .col-e { width: 10%; } /* Nominal Kiri */
        .col-f { width: 5%; }
        .col-g { width: 5%; }
        .col-h { width: 10%; } /* Awal Terbilang / Nama Siswa Kanan */
        .col-i { width: 10%; } 
        .col-j { width: 10%; } /* Tanggal Kanan */
        .col-k { width: 10%; }
        .col-l { width: 10%; } /* TTD Kanan */
        .col-m { width: 4%; }
        .col-n { width: 4%; }
        .col-o { width: 2%; } /* Kolom Kosong Penutup */
        
        /* Style Sel */
        .excel-table td {
            height: 10px; /* Tinggi baris kecil */
            padding: 2px 5px;
            vertical-align: top;
            font-size: 11pt;
            /* border: 1px dotted #ccc; /* Aktifkan ini untuk melihat grid sel */
        }

        /* Style untuk Font Kaligrafi/Handwriting */
        .handwriting {
            font-family: 'Times New Roman', Times, serif; 
            font-size: 14pt;
            font-style: italic;
            font-weight: bold;
        }

        /* Garis Bawah Nominal Kiri */
        .nominal-line {
            border-bottom: 1px solid #000; 
            text-align: right;
            font-weight: bold;
        }
        
        /* Box Nominal Kanan */
        .nominal-box {
            border: 1px solid #000;
            text-align: center;
            font-weight: bold;
        }

        /* Box No. Kwitansi Kanan Atas */
        .no-kwitansi-box {
            border: 1px solid #000;
            text-align: center;
            font-size: 10pt;
        }
        
        /* Garis Bawah Tanda Tangan */
        .ttd-line {
            border-bottom: 1px solid #000;
            padding-bottom: 2px;
            text-align: center;
        }
        
        @media print {
            .kwitansi-container {
                border: 1px solid #000; 
                box-shadow: none;
                margin: 0;
                padding: 10px;
                page-break-after: avoid; 
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="kwitansi-container">
        
        <table class="excel-table">
            <colgroup>
                <col class="col-a"><col class="col-b"><col class="col-c"><col class="col-d"><col class="col-e"><col class="col-f">
                <col class="col-g"><col class="col-h"><col class="col-i"><col class="col-j"><col class="col-k"><col class="col-l">
                <col class="col-m"><col class="col-n"><col class="col-o">
            </colgroup>
            <tbody>
                
                <tr><td colspan="15"></td></tr>
                <tr><td colspan="15"></td></tr>
                
                <tr>
                    <td colspan="3"></td> 
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td colspan="2" class="no-kwitansi-box">
                        <?php echo $jenis_bayar; ?>.<?php echo date('d.m', strtotime($data_transaksi['tanggal_transaksi'])); ?>
                    </td>
                    <td colspan="4" class="no-kwitansi-box">
                         <?php echo $jenis_bayar; ?>.<?php echo date('d.m', strtotime($data_transaksi['tanggal_transaksi'])); ?>
                    </td>
                    <td colspan="2"></td>
                </tr>
                
                <tr>
                    <td colspan="3"></td>
                    <td style="font-weight: bold;">GIAN</td>
                    <td colspan="3"></td>
                    <td colspan="7" class="handwriting" style="text-align: center;">
                        <?php echo $jumlah_terbilang; ?>
                    </td>
                    <td colspan="2"></td>
                </tr>
                
                <tr>
                    <td colspan="3"></td>
                    <td style="font-weight: bold;"><?php echo $siswa_nama; ?></td>
                    <td colspan="4"></td>
                    <td colspan="3" style="font-weight: bold;"><?php echo $siswa_nama; ?></td>
                    <td colspan="4"><?php echo $siswa_kelas; ?></td>
                </tr>
                
                <tr>
                    <td colspan="3"></td>
                    <td style="font-weight: bold;"><?php echo $siswa_kelas; ?></td>
                    <td colspan="3"></td>
                    <td colspan="3" style="text-align: center; font-size: 12pt;"><?php echo $jenis_bayar; ?></td>
                    <td colspan="5"></td>
                </tr>
                
                <tr>
                    <td colspan="3"></td>
                    <td style="font-weight: bold;"><?php echo $jenis_bayar; ?></td>
                    <td colspan="3"></td>
                    <td colspan="3" style="text-align: center; font-size: 12pt;">PELUNASAN</td>
                    <td colspan="5"></td>
                </tr>
                
                <tr>
                    <td colspan="3"></td>
                    <td style="font-weight: bold;">PELUNASAN</td>
                    <td colspan="11"></td>
                </tr>
                
                <tr><td colspan="15"></td></tr>
                
                <tr>
                    <td colspan="4"></td>
                    <td class="nominal-line" style="text-align: right;"><?php echo $nominal_rp; ?></td>
                    <td colspan="10"></td>
                </tr>
                
                <tr><td colspan="15"></td></tr>

                <tr>
                    <td colspan="3"></td>
                    <td style="text-align: left;"><?php echo $tanggal_kwitansi; ?></td>
                    <td colspan="2"></td>
                    <td colspan="4" class="nominal-box">
                        <?php echo $nominal_rp; ?>
                    </td>
                    <td colspan="2" style="text-align: right;"><?php echo $tempat_sekolah; ?>,</td>
                    <td colspan="3" style="text-align: right;"><?php echo $tanggal_kwitansi; ?></td>
                </tr>
                
                <tr><td colspan="15"></td></tr>
                <tr><td colspan="15"></td></tr>
                
                <tr>
                    <td colspan="10"></td>
                    <td colspan="5" style="text-align: right; font-weight: bold;">
                        <?php echo $nama_bendahara; ?>
                    </td>
                </tr>
                
            </tbody>
        </table>

        <div style="clear: both; margin-top: 10px; font-size: 8pt; text-align: right; padding-right: 10px;">
             Dicetak: <?php echo date('d/m/Y H:i:s'); ?>.
        </div>
        
    </div>

    <div class="no-print" style="position: fixed; top: 10px; right: 10px;">
        <button onclick="window.close()" style="padding: 10px; font-size: 14px;">Tutup & Kembali</button>
    </div>
</body>
</html>