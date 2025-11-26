<?php
include '../includes/koneksi.php';

// Pastikan parameter id_siswa ada di URL
if (isset($_GET['id_siswa'])) {
    $id_siswa = $_GET['id_siswa'];

    // Siapkan query untuk mengambil transaksi berdasarkan id_siswa
    $stmt = $conn->prepare("SELECT tanggal_transaksi, jenis_pembayaran, jumlah, deskripsi FROM transaksi WHERE id_siswa = ? ORDER BY tanggal_transaksi DESC");
    $stmt->bind_param("i", $id_siswa);
    $stmt->execute();
    $result = $stmt->get_result();

    $transaksi = [];
    while ($row = $result->fetch_assoc()) {
        $transaksi[] = $row;
    }

    $stmt->close();
    $conn->close();

    // Set header agar browser tahu bahwa ini adalah data JSON
    header('Content-Type: application/json');
    echo json_encode($transaksi); // Kirim data dalam format JSON
} else {
    echo json_encode([]); // Kirim array kosong jika id_siswa tidak ada
}
?>