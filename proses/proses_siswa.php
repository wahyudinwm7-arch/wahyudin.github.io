<?php
session_start();
// Gunakan require_once untuk file koneksi krusial
require_once '../includes/koneksi.php';

// 1. Cek Login
if (!isset($_SESSION['nama_pengguna'])) {
    header("Location: ../login.php");
    exit();
}

// 2. Cek dan validasi data yang masuk
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ---------------------------------
    // TAMBAH SISWA
    // ---------------------------------
    if (isset($_POST['tambah'])) {
        
        // --- 2.1 Sanitasi dan Ambil Data ---
        // Data wajib harus ada dan divalidasi sebagai integer/string
        $nama_lengkap       = trim($_POST['nama_lengkap'] ?? '');
        $nisn               = trim($_POST['nisn'] ?? '');
        // Pastikan ID adalah integer
        $id_kelas           = filter_var($_POST['id_kelas'] ?? '', FILTER_VALIDATE_INT);
        $id_tahun_ajaran    = filter_var($_POST['id_tahun_ajaran'] ?? '', FILTER_VALIDATE_INT);
        
        // Data opsional
        // Menggunakan parameter yang terpisah (tanggal_dibuat dan alamat)
        $tanggal_dibuat     = trim($_POST['tanggal_dibuat'] ?? date('Y-m-d')); // Default ke tanggal hari ini jika kosong
        $alamat             = trim($_POST['alamat'] ?? '');

        // --- 2.2 Validasi Data Kritis ---
        if (empty($nama_lengkap) || empty($nisn) || $id_kelas === false || $id_tahun_ajaran === false) {
            header("Location: ../pages/siswa.php?status=gagal&pesan=Data wajib (Nama, NISN, Kelas, Tahun Ajaran) tidak valid atau kosong.");
            exit();
        }

        // --- 2.3 Prepared Statement (INSERT) ---
        $sql = "INSERT INTO siswa (nama_lengkap, nisn, id_kelas, id_tahun_ajaran, tanggal_dibuat, alamat) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            error_log("Kesalahan Prepare SQL (Tambah): " . $conn->error);
            header("Location: ../pages/siswa.php?status=gagal&pesan=Kesalahan sistem saat menyiapkan INSERT.");
            $conn->close();
            exit();
        }

        // Bind parameter: s=string, i=integer
        // Urutan: nama_lengkap(s), nisn(s), id_kelas(i), id_tahun_ajaran(i), tanggal_dibuat(s), alamat(s)
        $stmt->bind_param("ssiiss", $nama_lengkap, $nisn, $id_kelas, $id_tahun_ajaran, $tanggal_dibuat, $alamat);

        if ($stmt->execute()) {
            header("Location: ../pages/siswa.php?status=sukses&pesan=Siswa **" . urlencode($nama_lengkap) . "** berhasil ditambahkan.");
        } else {
            error_log("Kesalahan Execute SQL (Tambah): " . $stmt->error);
            // Tambahkan pengecekan jika errornya adalah duplicate entry (misalnya NISN sudah ada)
            if ($conn->errno == 1062) { 
                header("Location: ../pages/siswa.php?status=gagal&pesan=NISN sudah terdaftar. " . urlencode($nisn));
            } else {
                header("Location: ../pages/siswa.php?status=gagal&pesan=Gagal menambah siswa: " . urlencode($stmt->error));
            }
        }
        $stmt->close();

    // ---------------------------------
    // UBAH SISWA (Diperbaiki agar konsisten dengan id_tahun_ajaran)
    // ---------------------------------
    } elseif (isset($_POST['ubah'])) {
        
        // --- 3.1 Sanitasi dan Ambil Data ---
        // Data wajib harus ada dan divalidasi
        $id_siswa           = filter_var($_POST['id_siswa'] ?? '', FILTER_VALIDATE_INT);
        $nisn               = trim($_POST['nisn'] ?? '');
        $nama_lengkap       = trim($_POST['nama_lengkap'] ?? '');
        $id_kelas           = filter_var($_POST['id_kelas'] ?? '', FILTER_VALIDATE_INT);
        // Menggunakan id_tahun_ajaran (sesuai form edit_siswa.php)
        $id_tahun_ajaran    = filter_var($_POST['id_tahun_ajaran'] ?? '', FILTER_VALIDATE_INT); 

        // Data opsional (asumsi kolom ini ada di database)
        $tanggal_lahir      = $_POST['tanggal_lahir'] ?? null; // Dibiarkan null jika tidak ada
        $alamat             = trim($_POST['alamat'] ?? '');

        // --- 3.2 Validasi Data Kritis ---
        if ($id_siswa === false || empty($nisn) || empty($nama_lengkap) || $id_kelas === false || $id_tahun_ajaran === false) {
            header("Location: ../pages/siswa.php?status=gagal&pesan=Data wajib (ID Siswa, NISN, Nama, Kelas, Tahun Ajaran) tidak valid atau kosong.");
            exit();
        }

        // --- 3.3 Prepared Statement (UPDATE) ---
        // Mengubah kolom 'tahun_masuk' menjadi 'id_tahun_ajaran' agar konsisten
        $sql = "UPDATE siswa SET 
                    nisn = ?, 
                    nama_lengkap = ?, 
                    id_kelas = ?, 
                    id_tahun_ajaran = ?, 
                    alamat = ? 
                WHERE id_siswa = ?";
        
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            error_log("Kesalahan Prepare SQL (Ubah): " . $conn->error);
            header("Location: ../pages/siswa.php?status=gagal&pesan=Kesalahan sistem saat menyiapkan UPDATE.");
            $conn->close();
            exit();
        }

        // Bind parameter: s=string, i=integer. Urutan harus sesuai dengan SQL di atas.
        // Urutan: nisn(s), nama_lengkap(s), id_kelas(i), id_tahun_ajaran(i), alamat(s), id_siswa(i)
        $stmt->bind_param("ssiisi", $nisn, $nama_lengkap, $id_kelas, $id_tahun_ajaran, $alamat, $id_siswa);

        if ($stmt->execute()) {
            header("Location: ../pages/siswa.php?status=sukses&pesan=Data siswa **" . urlencode($nama_lengkap) . "** berhasil diubah!");
        } else {
            error_log("Kesalahan Execute SQL (Ubah): " . $stmt->error);
            header("Location: ../pages/siswa.php?status=gagal&pesan=Gagal mengubah data siswa: " . urlencode($stmt->error));
        }
        $stmt->close();
    }
}

// ---------------------------------
// HAPUS SISWA (Menggunakan GET Request)
// ---------------------------------
elseif (isset($_GET['hapus'])) {
    
    // --- 4.1 Sanitasi dan Validasi ID ---
    $id_siswa = filter_var($_GET['hapus'], FILTER_VALIDATE_INT);
    
    if ($id_siswa === false) {
        header("Location: ../pages/siswa.php?status=gagal&pesan=ID siswa tidak valid.");
        exit();
    }

    // --- 4.2 Prepared Statement (DELETE) ---
    $sql = "DELETE FROM siswa WHERE id_siswa = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        error_log("Kesalahan Prepare SQL (Hapus): " . $conn->error);
        header("Location: ../pages/siswa.php?status=gagal&pesan=Kesalahan sistem saat menyiapkan DELETE.");
        $conn->close();
        exit();
    }
    
    $stmt->bind_param("i", $id_siswa);

    if ($stmt->execute()) {
        header("Location: ../pages/siswa.php?status=sukses&pesan=Data siswa dengan ID " . $id_siswa . " berhasil dihapus.");
    } else {
        error_log("Kesalahan Execute SQL (Hapus): " . $stmt->error);
        // Pengecekan Constraint/Foreign Key: Jika siswa memiliki data pembayaran, hapus akan gagal
        if ($conn->errno == 1451) { 
            header("Location: ../pages/siswa.php?status=gagal&pesan=Gagal menghapus! Siswa memiliki data pembayaran terkait.");
        } else {
            header("Location: ../pages/siswa.php?status=gagal&pesan=Gagal menghapus data siswa: " . urlencode($stmt->error));
        }
    }
    $stmt->close();

} else {
    // Jika diakses tanpa metode POST atau GET aksi
    header("Location: ../pages/siswa.php?status=error&pesan=Aksi tidak dikenali.");
}

// Tutup koneksi di akhir script
if (isset($conn)) {
    mysqli_close($conn);
}
?>