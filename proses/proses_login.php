<?php
session_start();
include '../includes/koneksi.php'; // Hubungkan ke file koneksi

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nama_pengguna = $_POST['nama_pengguna'];
    $kata_sandi = $_POST['kata_sandi'];

    // Hindari SQL Injection dengan prepared statements
    $stmt = $conn->prepare("SELECT * FROM pengguna WHERE nama_pengguna = ?");
    $stmt->bind_param("s", $nama_pengguna);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $hashed_password = $row['kata_sandi'];
        
        // Verifikasi kata sandi dengan password_verify
        if (password_verify($kata_sandi, $hashed_password)) {
            // Login berhasil
            $_SESSION['id_pengguna'] = $row['id_pengguna'];
            $_SESSION['nama_pengguna'] = $row['nama_pengguna'];
            $_SESSION['hak_akses'] = $row['hak_akses'];
            
            header("Location: dashboard.php"); // Arahkan ke dashboard
            exit();
        } else {
            // Kata sandi salah
            echo "Kata sandi salah.";
        }
    } else {
        // Nama pengguna tidak ditemukan
        echo "Nama pengguna tidak ditemukan.";
    }
    
    $stmt->close();
}
$conn->close();

?>