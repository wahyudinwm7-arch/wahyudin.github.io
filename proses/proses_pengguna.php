<?php
session_start();
// Pastikan path ke koneksi.php sudah benar
include('../includes/koneksi.php');

// Verifikasi: Pastikan pengguna terautentikasi dan memiliki hak akses admin
if (!isset($_SESSION['nama_pengguna']) || $_SESSION['hak_akses'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Default redirect jika proses tidak berhasil
$default_redirect = "pengguna.php";

// Ambil dan Sanitasi Input
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$id_pengguna = isset($_POST['id_pengguna']) ? htmlspecialchars($_POST['id_pengguna']) : (isset($_GET['id_pengguna']) ? htmlspecialchars($_GET['id_pengguna']) : null);
$nama_pengguna = isset($_POST['nama_pengguna']) ? htmlspecialchars($_POST['nama_pengguna']) : null;
$kata_sandi = isset($_POST['kata_sandi']) ? $_POST['kata_sandi'] : null; // Tidak perlu htmlspecialchars untuk password
$hak_akses = isset($_POST['hak_akses']) ? htmlspecialchars($_POST['hak_akses']) : null;

$pesan = "";
$status = "error";

try {
    if ($action == 'tambah') {
        if (empty($nama_pengguna) || empty($kata_sandi) || empty($hak_akses)) {
            throw new Exception("Semua field harus diisi untuk menambah pengguna.");
        }

        // Hashing Kata Sandi
        // PENTING: Selalu hash password sebelum disimpan ke database
        // Gunakan PASSWORD_DEFAULT (BCrypt, kuat dan adaptif)
        $hashed_password = password_hash($kata_sandi, PASSWORD_DEFAULT);

        // Query INSERT
        $query = "INSERT INTO pengguna (nama_pengguna, kata_sandi, hak_akses) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sss", $nama_pengguna, $hashed_password, $hak_akses);

        if ($stmt->execute()) {
            $pesan = "Pengguna baru '" . $nama_pengguna . "' berhasil ditambahkan.";
            $status = "sukses";
        } else {
            // Tangani error jika terjadi duplikasi nama pengguna (UNIQUE constraint)
            if ($conn->errno == 1062) { 
                 $pesan = "Gagal menambah pengguna. Nama pengguna sudah ada.";
            } else {
                 $pesan = "Gagal menambah pengguna: " . $stmt->error;
            }
        }
        $stmt->close();

    } elseif ($action == 'edit') {
        if (empty($id_pengguna) || empty($nama_pengguna) || empty($hak_akses)) {
            throw new Exception("Field ID, Nama Pengguna, dan Hak Akses harus diisi untuk mengedit.");
        }
        
        $params = [$nama_pengguna, $hak_akses];
        $types = "ss";
        $set_clause = "nama_pengguna = ?, hak_akses = ?";
        
        // Jika kata sandi diisi, update juga kata sandi
        if (!empty($kata_sandi)) {
            $hashed_password = password_hash($kata_sandi, PASSWORD_DEFAULT);
            array_unshift($params, $hashed_password); // Tambahkan password ke awal array
            $types = "s" . $types; // Tambahkan 's' ke jenis tipe

            $set_clause = "kata_sandi = ?, " . $set_clause;
            $pesan_tambahan = " (Kata sandi diubah)";
        } else {
             $pesan_tambahan = " (Kata sandi tidak diubah)";
        }

        // Query UPDATE
        $query = "UPDATE pengguna SET $set_clause WHERE id_pengguna = ?";
        $params[] = $id_pengguna; // Tambahkan ID ke akhir array
        $types .= "i"; // Tambahkan 'i' untuk integer ID

        $stmt = $conn->prepare($query);
        // Panggil bind_param secara dinamis
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            $pesan = "Pengguna '" . $nama_pengguna . "' berhasil diubah." . $pesan_tambahan;
            $status = "sukses";
        } else {
            $pesan = "Gagal mengubah pengguna: " . $stmt->error;
        }
        $stmt->close();

    } elseif ($action == 'hapus') {
        if (empty($id_pengguna)) {
            throw new Exception("ID Pengguna tidak ditemukan untuk penghapusan.");
        }
        
        // Cek agar tidak menghapus pengguna yang sedang login
        if ($id_pengguna == $_SESSION['id_pengguna']) {
             throw new Exception("Anda tidak bisa menghapus akun Anda sendiri saat sedang login.");
        }
        
        // Query DELETE
        $query = "DELETE FROM pengguna WHERE id_pengguna = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $id_pengguna);

        if ($stmt->execute()) {
            $pesan = "Pengguna dengan ID " . $id_pengguna . " berhasil dihapus.";
            $status = "sukses";
        } else {
            $pesan = "Gagal menghapus pengguna: " . $stmt->error;
        }
        $stmt->close();

    } else {
        $pesan = "Aksi tidak valid atau tidak ditentukan.";
    }

} catch (Exception $e) {
    $pesan = "Terjadi kesalahan: " . $e->getMessage();
    $status = "error";
}

// Redirect kembali ke halaman utama pengguna dengan pesan
header("Location: $default_redirect?pesan=" . urlencode($pesan) . "&status=" . $status);
exit();
?>
