<?php
session_start();
include '../includes/koneksi.php';

// Cek apakah pengguna sudah login
if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

if (isset($_POST['import'])) {
    // Cek apakah ada file yang diunggah
    if (empty($_FILES['file_import']['name'])) {
        header("Location: ../pages/siswa.php?status=gagal_upload&pesan=Tidak ada file yang diunggah.");
        exit();
    }

    $file_mimes = array(
        'text/x-comma-separated-values',
        'text/comma-separated-values',
        'application/octet-stream',
        'application/vnd.ms-excel',
        'application/x-csv',
        'text/x-csv',
        'text/csv',
        'application/csv',
        'application/excel',
        'application/vnd.msexcel'
    );

    // Cek tipe file yang diunggah
    if (!in_array($_FILES['file_import']['type'], $file_mimes)) {
        header("Location: ../pages/siswa.php?status=gagal_upload&pesan=Tipe file tidak valid. Harap unggah file CSV.");
        exit();
    }

    $uploaded_file = $_FILES['file_import']['tmp_name'];

    if (($handle = fopen($uploaded_file, "r")) === FALSE) {
        header("Location: ../pages/siswa.php?status=gagal_buka_file");
        exit();
    }

    // Ambil header kolom untuk validasi
    $header = fgetcsv($handle, 1000, ",");
    $valid_header = ["nama_lengkap", "nisn", "id_kelas", "id_tahun_ajaran"];
    
    // Konversi semua nama header menjadi huruf kecil untuk perbandingan
    $header = array_map('strtolower', $header);
    
    // Periksa apakah header file cocok dengan yang diharapkan
    if (array_diff($valid_header, $header) || array_diff($header, $valid_header)) {
        fclose($handle);
        header("Location: ../pages/siswa.php?status=gagal&pesan=" . urlencode("Format header file CSV tidak sesuai. Harap gunakan: " . implode(', ', $valid_header)));
        exit();
    }
    
    // Buat array mapping untuk mencocokkan kolom
    $col_map = array_flip($header);
    
    $imported_count = 0;
    $failed_count = 0;

    // Persiapkan statement SQL untuk memasukkan data
    $stmt = $conn->prepare("INSERT INTO siswa (nama_lengkap, nisn, id_kelas, id_tahun_ajaran) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssii", $nama_lengkap, $nisn, $id_kelas, $id_tahun_ajaran);

    // Mulai transaksi untuk performa yang lebih baik
    $conn->begin_transaction();

    // Loop melalui setiap baris data
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        // Skip baris kosong
        if (count(array_filter($data)) == 0) {
            continue;
        }

        // Ambil data sesuai urutan header
        $nama_lengkap = $data[$col_map['nama_lengkap']];
        $nisn = $data[$col_map['nisn']];
        $id_kelas = $data[$col_map['id_kelas']];
        $id_tahun_ajaran = $data[$col_map['id_tahun_ajaran']];

        // Jalankan eksekusi query
        if ($stmt->execute()) {
            $imported_count++;
        } else {
            $failed_count++;
            // Opsional: Anda bisa log error-nya ke file atau database
            // error_log("Gagal import NISN: $nisn. Error: " . $stmt->error);
        }
    }

    // Selesai transaksi
    $conn->commit();
    $stmt->close();
    fclose($handle);

    header("Location: ../pages/siswa.php?import_sukses=" . $imported_count . "&import_gagal=" . $failed_count);
    exit();
} else {
    header("Location: ../pages/siswa.php");
    exit();
}

mysqli_close($conn);
?>