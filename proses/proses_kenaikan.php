<?php
session_start();
include '../includes/koneksi.php'; // Sesuaikan path koneksi Anda
date_default_timezone_set('Asia/Jakarta');

// Cek hak akses admin
if (!isset($_SESSION['nama_pengguna']) || $_SESSION['level'] != 'admin') {
    // Sesuaikan level hak akses jika perlu
    header("Location: ../login.php"); 
    exit();
}

// =========================================================================
// Logika Kenaikan Kelas
// =========================================================================

// Menggunakan transaksi database untuk memastikan semua query berhasil atau gagal semua (atomisitas)
mysqli_begin_transaction($conn);
$sukses = true;
$pesan = "Proses kenaikan kelas berhasil dilaksanakan pada " . date('d-m-Y H:i:s');
$errors = [];

try {
    // ---------------------------------------------------------------------
    // Query 1: Siswa Lulus (Kelas XII -> Alumni)
    // Update status siswa kelas XII menjadi 'alumni'
    // Perhatikan: Query ini menggunakan subquery untuk mencari ID kelas XII
    // ---------------------------------------------------------------------
    $query_lulus = "
        UPDATE siswa
        SET status_siswa = 'alumni', id_kelas = NULL
        WHERE id_kelas IN (
            SELECT id_kelas FROM kelas WHERE nama_kelas LIKE 'XII%'
        )
        AND status_siswa = 'aktif';
    ";
    if (!mysqli_query($conn, $query_lulus)) {
        throw new Exception("Gagal memproses kelulusan: " . mysqli_error($conn));
    }
    $rows_lulus = mysqli_affected_rows($conn);


    // ---------------------------------------------------------------------
    // Fungsi Pembantu untuk Kenaikan Kelas (Memerlukan Pengecekan Nama Kelas)
    // Fungsi ini mengasumsikan nama kelas konsisten (misal: 'X RPL 1' ke 'XI RPL 1')
    // ---------------------------------------------------------------------
    function updateKenaikanKelas($conn, $level_lama, $level_baru, &$errors) {
        // PENTING: Query ini mencari ID kelas baru berdasarkan nama kelas lama,
        // lalu mengupdate siswa ke ID kelas baru tersebut.
        $query = "
            UPDATE siswa s
            JOIN kelas k_lama ON s.id_kelas = k_lama.id_kelas
            JOIN kelas k_baru ON k_baru.nama_kelas = REPLACE(k_lama.nama_kelas, ?, ?)
            SET s.id_kelas = k_baru.id_kelas
            WHERE k_lama.nama_kelas LIKE '{$level_lama} %'
            AND s.status_siswa = 'aktif';
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $level_lama, $level_baru);

        if (!$stmt->execute()) {
            throw new Exception("Gagal memproses kenaikan {$level_lama} ke {$level_baru}: " . $stmt->error);
        }
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        return $affected_rows;
    }

    // ---------------------------------------------------------------------
    // Query 2: Kenaikan Kelas XI -> XII
    // ---------------------------------------------------------------------
    $rows_xi_ke_xii = updateKenaikanKelas($conn, 'XI', 'XII', $errors);
    
    // ---------------------------------------------------------------------
    // Query 3: Kenaikan Kelas X -> XI
    // ---------------------------------------------------------------------
    $rows_x_ke_xi = updateKenaikanKelas($conn, 'X', 'XI', $errors);


    // Jika semua query berhasil, lakukan COMMIT
    mysqli_commit($conn);
    
    $pesan .= ". Berhasil meluluskan **{$rows_lulus}** siswa, menaikkan **{$rows_xi_ke_xii}** siswa ke kelas XII, dan **{$rows_x_ke_xi}** siswa ke kelas XI.";

} catch (Exception $e) {
    // Jika ada query yang gagal, lakukan ROLLBACK dan catat error
    mysqli_rollback($conn);
    $sukses = false;
    $pesan = "PROSES GAGAL: " . $e->getMessage();
}


// Tutup koneksi
if (isset($conn) && $conn) {
    mysqli_close($conn);
}

// -------------------------------------------------------------------------
// Tampilan Hasil
// -------------------------------------------------------------------------
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Hasil Proses Kenaikan Kelas</title>
    </head>
<body>
    <div class="container">
        <div style="padding: 20px; border-radius: 8px; margin-top: 50px; 
            background-color: <?php echo $sukses ? '#d4edda' : '#f8d7da'; ?>;
            color: <?php echo $sukses ? '#155724' : '#721c24'; ?>;
            border: 1px solid <?php echo $sukses ? '#c3e6cb' : '#f5c6cb'; ?>;">
            
            <h2>Status Kenaikan Kelas</h2>
            <p><?php echo htmlspecialchars($pesan); ?></p>
            
            <?php if (!$sukses): ?>
                <p>Silakan periksa koneksi database atau skema nama kelas di tabel 'kelas'.</p>
            <?php endif; ?>
            
            <a href="dashboard.php" style="display: block; margin-top: 15px;">Kembali ke Dashboard</a>
        </div>
    </div>
</body>
</html>