<?php
session_start();
// --- DEBUGGING: Tampilkan semua error untuk membantu kita mendiagnosa ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// -----------------------------------------------------------------------

// Asumsi: hapus_transaksi.php di folder pages/, koneksi.php di folder includes/
include '../includes/koneksi.php'; 

date_default_timezone_set('Asia/Jakarta');

// =================================================================
// --- Pengecekan Sesi dan Validasi Input ---
// =================================================================
if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

$id_transaksi = isset($_GET['id_transaksi']) ? intval($_GET['id_transaksi']) : 0;
$id_siswa_redirect = isset($_GET['id_siswa']) ? intval($_GET['id_siswa']) : 0; 

$redirect_url = "pembayaran.php?id_siswa=" . $id_siswa_redirect;

if ($id_transaksi === 0 || $id_siswa_redirect === 0) {
    $pesan_error = urlencode("Error: ID Transaksi atau ID Siswa tidak valid.");
    header("Location: " . $redirect_url . "&pesan=" . $pesan_error . "&status=error");
    exit();
}

// =================================================================
// --- Cek Koneksi (Jika gagal, redirect dengan pesan) ---
// =================================================================
if ($conn->connect_error) {
    $error_koneksi = urlencode("Koneksi Database Gagal: " . $conn->connect_error);
    header("Location: " . $redirect_url . "&pesan=" . $error_koneksi . "&status=error");
    exit();
}

// =================================================================
// --- Proses Penghapusan (Transaksi SQL) ---
// =================================================================
$conn->begin_transaction();
try {
    
    // Query DELETE
    $query_delete = "DELETE FROM transaksi WHERE id_transaksi = ?";
    $stmt_delete = $conn->prepare($query_delete);

    if (!$stmt_delete) {
        // Gagal menyiapkan statement (biasanya kesalahan SQL syntax)
        throw new Exception("SQL PREPARE GAGAL: " . $conn->error);
    }
    
    // Bind parameter (i = integer, untuk id_transaksi)
    $stmt_delete->bind_param("i", $id_transaksi);

    if (!$stmt_delete->execute()) {
        // Gagal eksekusi (bisa jadi Foreign Key Constraint)
        throw new Exception("SQL EXECUTE GAGAL: " . $stmt_delete->error);
    }
    
    $rows_affected = $stmt_delete->affected_rows;
    $stmt_delete->close();

    if ($rows_affected === 0) {
        // Data tidak ditemukan
        throw new Exception("Peringatan: Transaksi ID {$id_transaksi} tidak ditemukan di database.");
    }

    // Commit transaksi jika semua berhasil
    $conn->commit();
    
    // Redirect Sukses
    $pesan_sukses = urlencode("Transaksi ID {$id_transaksi} berhasil dihapus.");
    header("Location: " . $redirect_url . "&pesan=" . $pesan_sukses . "&status=success");
    exit();

} catch (Exception $e) {
    // Rollback jika terjadi kesalahan
    $conn->rollback();
    
    // Redirect dengan pesan error yang detail
    $error_message_detail = urlencode($e->getMessage());
    $error_display = urlencode("GAGAL HAPUS TRANSAKSI. Cek Debug URL untuk detail.");
    header("Location: " . $redirect_url . "&pesan=" . $error_display . "&status=error&debug=" . $error_message_detail);
    exit();
}

mysqli_close($conn);
?>