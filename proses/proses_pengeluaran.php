<?php
session_start();
// Pastikan path ke file koneksi.php sudah benar
include '../includes/koneksi.php';

// Cek apakah pengguna sudah login menggunakan ID
if (!isset($_SESSION['id_pengguna'])) {
    header("Location: ../login.php"); 
    exit();
}

// Logika hanya berjalan jika ada data POST yang dikirim dan tombol 'tambah_pengeluaran' diklik
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_pengeluaran'])) {
    
    // --- 1. Ambil dan Bersihkan Data Input ---
    
    // Transaksi ini BUKAN untuk siswa, jadi id_siswa diisi NULL
    $id_siswa = NULL; 
    
    // Pastikan jenis transaksi adalah 'keluar'
    $jenis_transaksi = 'keluar';
    
    // Ambil data tanggal. Gunakan tanggal saat ini jika tidak diset dari form.
    $tanggal_transaksi = $_POST['tanggal_transaksi'] ?? date('Y-m-d H:i:s'); 
    
    // Ambil jumlah dan konversi ke format numerik yang benar (float)
    $jumlah_input = $_POST['jumlah'] ?? 0;
    // Menghapus titik (ribuan) dan mengganti koma (desimal) menjadi titik, lalu konversi ke float
    $jumlah = (float) str_replace(['.', ','], ['', '.'], $jumlah_input); 
    
    // Ambil kategori/jenis pengeluaran
    $kategori_pengeluaran = $_POST['kategori_pengeluaran'] ?? 'Pengeluaran Lain-Lain';
    
    // Ambil keterangan/deskripsi pengeluaran
    $deskripsi = $_POST['deskripsi'] ?? 'Pengeluaran Tanpa Keterangan Detail';
    
    // Ambil ID pengguna yang mencatat
    $dicatat_oleh = $_SESSION['id_pengguna'] ?? null;
    
    
    // --- 2. Validasi Data ---
    if ($jumlah <= 0) {
        // Jika jumlah tidak valid, kembalikan dengan pesan error
        header("Location: ../pages/pengeluaran.php?pesan=error&debug=Jumlah%20pengeluaran%20tidak%20valid.");
        exit();
    }

    // --- 3. Proses Insert Data ke Database ---

    // Query untuk memasukkan data ke tabel 'transaksi'
    $query = "INSERT INTO transaksi (id_siswa, jenis_pembayaran, jumlah, tanggal_transaksi, deskripsi, jenis_transaksi, dicatat_oleh_id_pengguna) 
              VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);

    // Binding parameter
    // Tipe data: i(integer/null), s(string), d(double), s(string), s(string), s(string), i(integer)
    // Untuk id_siswa yang NULL, kita gunakan 'i' (integer) dan PHP/MySQLi akan menanganinya
    if ($stmt->bind_param("isdsssi", $id_siswa, $kategori_pengeluaran, $jumlah, $tanggal_transaksi, $deskripsi, $jenis_transaksi, $dicatat_oleh)) {
        
        if ($stmt->execute()) {
            // Berhasil: Redirect kembali ke halaman pengeluaran dengan pesan sukses
            header("Location: ../pages/pengeluaran.php?pesan=success");
        } else {
            // Gagal eksekusi SQL
            error_log("SQL Error (Pengeluaran): " . $stmt->error);
            header("Location: ../pages/pengeluaran.php?pesan=error&debug=Gagal%20eksekusi%20SQL.");
        }
        
        $stmt->close();
        
    } else {
        // Gagal bind parameter
        error_log("Bind Param Error (Pengeluaran): " . $conn->error);
        header("Location: ../pages/pengeluaran.php?pesan=error&debug=Gagal%20memproses%20data%20input.");
    }
    
    exit();
}

// Jika diakses tanpa metode POST, redirect saja
header("Location: ../pages/pengeluaran.php");
exit();

// Tutup koneksi database
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>