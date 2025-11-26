<?php
$host = 'localhost';
$username = 'root'; // Ganti dengan username database Anda
$password = ''; // Ganti dengan password database Anda (jika ada)
$database = 'db_pembayaran_sekolah';

// Buat koneksi ke database
$conn = mysqli_connect($host, $username, $password, $database);

// Cek apakah koneksi berhasil
if (!$conn) {
    die("Koneksi gagal: " . mysqli_connect_error());
}