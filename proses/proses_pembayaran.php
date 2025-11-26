<?php
session_start();
include '../includes/koneksi.php';

if (!isset($_SESSION['id_pengguna'])) { // Cek ID pengguna, lebih aman dari nama
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_transaksi'])) {
    $id_siswa = $_POST['id_siswa'] ?? null;
    $tanggal_transaksi = $_POST['tanggal_transaksi'] ?? date('Y-m-d H:i:s'); // Gunakan H:i:s agar lebih akurat
    $dicatat_oleh = $_SESSION['id_pengguna'] ?? null;
    $jenis_transaksi = 'masuk';
    
    // Ambil data penting yang dikirim dari form (SESUAI DENGAN INPUT FORM HTML)
    $jenis_pembayaran_select_value = $_POST['jenis_pembayaran'] ?? '';
    $jumlah_dibayar = $_POST['jumlah'] ?? 0; // PENTING: Ambil jumlah yang diinput/dihitung JS
    $deskripsi = $_POST['deskripsi'] ?? ''; // PENTING: Ambil deskripsi yang dihitung/diedit JS

    // Pisahkan jenis pembayaran dan nominal default dari dropdown (untuk referensi saja)
    // Asumsi format: JENIS|NOMINAL_DEFAULT|KETERANGAN_OPSIONAL
    $parts = explode('|', $jenis_pembayaran_select_value);
    $jenis_pembayaran_murni = $parts[0] ?? 'Lain-lain';
    
    // Konversi jumlah ke float/integer dan pastikan valid
    $jumlah_dibayar = (float) str_replace(['.', ','], ['', '.'], $jumlah_dibayar);
    if ($jumlah_dibayar <= 0) {
        header("Location: ../pages/pembayaran.php?id_siswa=" . urlencode($id_siswa) . "&pesan=error&debug=Jumlah%20pembayaran%20tidak%20valid.");
        exit();
    }


    if ($jenis_pembayaran_murni === 'SPP') {
        // --- LOGIKA SPP: Buat SATU transaksi, dan simpan bulan yang dicentang ---
        $bulan_tahun_spp = $_POST['spp_bulan_tahun'] ?? []; // PERBAIKAN: Ambil dari nama input yang benar
        
        if (empty($bulan_tahun_spp)) {
            header("Location: ../pages/pembayaran.php?id_siswa=" . urlencode($id_siswa) . "&pesan=error&debug=SPP%20-%20Mohon%20pilih%20bulan.");
            exit();
        }

        // Lakukan INSERT SATU BARIS transaksi utama
        $stmt = $conn->prepare("INSERT INTO transaksi (id_siswa, jenis_pembayaran, jumlah, tanggal_transaksi, deskripsi, jenis_transaksi, dicatat_oleh_id_pengguna) VALUES (?, ?, ?, NOW(), ?, ?, ?)");
        // Menggunakan NOW() atau $tanggal_transaksi. Jika $tanggal_transaksi dari input date, harus disesuaikan. 
        // Saya asumsikan NOW() atau $tanggal_transaksi dari form sudah benar (di form anda gunakan input date)
        
        $tanggal_form = $_POST['tanggal_transaksi'] ?? date('Y-m-d');
        
        // Simpan data di tabel transaksi (SATU BARIS)
        if ($stmt->bind_param("isdsssi", $id_siswa, $jenis_pembayaran_murni, $jumlah_dibayar, $tanggal_form, $deskripsi, $jenis_transaksi, $dicatat_oleh)) {
            if ($stmt->execute()) {
                $id_transaksi_utama = $conn->insert_id;

                // --- OPTIONAL TAPI DISARANKAN: Simpan detail bulan di tabel terpisah (misal: transaksi_spp_detail) ---
                // Karena ini opsional dan tidak ada skema detailnya, kita lewati. 
                // CATATAN: PENTING UNTUK REKAP BAHWA BULAN YANG DICENTANG HARUS DISIMPAN DI DATABASE.
                // Jika tidak ada tabel detail, Anda HARUS mengandalkan parsing deskripsi, yang RENTAN.

                // Jika berhasil, redirect ke struk
                header("Location: ../pages/pembayaran.php?id_siswa=" . urlencode($id_siswa) . "&pesan=success"); // Arahkan ke halaman pembayaran dengan pesan sukses
                // Anda mungkin ingin mengarahkan ke halaman struk, tapi lebih aman dulu ke halaman pembayaran
                exit();
            } else {
                error_log("Gagal menambahkan transaksi SPP: " . $stmt->error);
                header("Location: ../pages/pembayaran.php?id_siswa=" . urlencode($id_siswa) . "&pesan=error&debug=Gagal%20eksekusi%20SQL%20SPP");
                exit();
            }
        } else {
             error_log("Gagal bind param SPP: " . $stmt->error);
             header("Location: ../pages/pembayaran.php?id_siswa=" . urlencode($id_siswa) . "&pesan=error&debug=Gagal%20bind%20SPP");
             exit();
        }


    } else { 
        // --- LOGIKA NON-SPP: Buat SATU transaksi non-SPP ---
        
        // Lakukan INSERT SATU BARIS transaksi
        $stmt = $conn->prepare("INSERT INTO transaksi (id_siswa, jenis_pembayaran, jumlah, tanggal_transaksi, deskripsi, jenis_transaksi, dicatat_oleh_id_pengguna) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $tanggal_form = $_POST['tanggal_transaksi'] ?? date('Y-m-d');

        if ($stmt->bind_param("isdsssi", $id_siswa, $jenis_pembayaran_murni, $jumlah_dibayar, $tanggal_form, $deskripsi, $jenis_transaksi, $dicatat_oleh)) {
            if ($stmt->execute()) {
                $id_transaksi_terakhir = $conn->insert_id;
                header("Location: ../pages/pembayaran.php?id_siswa=" . urlencode($id_siswa) . "&pesan=success");
            } else {
                error_log("Gagal menambahkan transaksi non-SPP: " . $stmt->error);
                header("Location: ../pages/pembayaran.php?id_siswa=" . urlencode($id_siswa) . "&pesan=error&debug=Gagal%20eksekusi%20SQL%20Non-SPP");
            }
        } else {
            error_log("Gagal bind param Non-SPP: " . $stmt->error);
            header("Location: ../pages/pembayaran.php?id_siswa=" . urlencode($id_siswa) . "&pesan=error&debug=Gagal%20bind%20Non-SPP");
        }
        $stmt->close();
        exit();
    }
}
mysqli_close($conn);
?>