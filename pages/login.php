<?php
session_start();

// Cek jika pengguna sudah login, alihkan ke dashboard
if (isset($_SESSION['nama_pengguna'])) {
    header("Location: dashboard.php");
    exit();
}

include '../includes/koneksi.php';

$error_message = "";

// Proses form login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nama_pengguna = $_POST['nama_pengguna'];
    $kata_sandi = $_POST['kata_sandi'];

    $stmt = $conn->prepare("SELECT id_pengguna, nama_pengguna, kata_sandi, hak_akses FROM pengguna WHERE nama_pengguna = ?");
    $stmt->bind_param("s", $nama_pengguna);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // Verifikasi kata sandi
        if (password_verify($kata_sandi, $row['kata_sandi'])) {
            $_SESSION['id_pengguna'] = $row['id_pengguna'];
            $_SESSION['nama_pengguna'] = $row['nama_pengguna'];
            $_SESSION['hak_akses'] = $row['hak_akses'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error_message = "Nama pengguna atau kata sandi salah.";
        }
    } else {
        $error_message = "Nama pengguna atau kata sandi salah.";
    }

    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Login | Aplikasi Pembayaran Siswa</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* CSS khusus untuk halaman login */
        body {
            display: flex; /* Menggunakan flexbox untuk memusatkan secara vertikal */
            justify-content: center; /* Memusatkan secara horizontal */
            align-items: center; /* Memusatkan secara vertikal */
            min-height: 100vh; /* Memastikan body mengisi tinggi viewport */
            margin: 0;
            background-color: #f0f2f5; /* Sesuaikan dengan background di style.css */
        }
        .login-container {
            max-width: 350px; /* Sedikit dikecilkan untuk form login */
            width: 100%; /* Agar responsif dalam max-width */
            padding: 40px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
            text-align: center;
            box-sizing: border-box; /* Agar padding tidak menambah lebar */
        }
        .login-container h2 {
            color: #2c3e50; /* Warna judul */
            border-bottom: none;
            padding-bottom: 0;
            margin-bottom: 25px; /* Sedikit lebih banyak ruang di bawah judul */
        }
        .error-message {
            color: #e74c3c; /* Warna merah yang lebih elegan */
            margin-bottom: 15px;
            font-weight: 500;
        }
        .login-container form div {
            margin-bottom: 20px; /* Tambah jarak antar input */
        }
        .login-container label {
            text-align: left; /* Rata kiri label */
            margin-bottom: 8px;
            color: #555;
            font-weight: 600;
        }
        .login-container input[type="text"],
        .login-container input[type="password"] {
            width: calc(100% - 24px); /* Mengurangi lebar input agar padding tidak melewati batas */
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 16px;
        }
        .login-container button[type="submit"] {
            width: 100%; /* Tombol mengisi penuh lebar */
            padding: 12px;
            background-color: #3498db;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }
        .login-container button[type="submit"]:hover {
            background-color: #2980b9;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Kedah Login Hela</h2>
        <?php if (!empty($error_message)): ?>
            <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
        <?php endif; ?>
        <form action="login.php" method="POST">
            <div>
                <label for="nama_pengguna">Nama Pengguna:</label>
                <input type="text" id="nama_pengguna" name="nama_pengguna" required>
            </div>
            <div>
                <label for="kata_sandi">Kata Sandi:</label>
                <input type="password" id="kata_sandi" name="kata_sandi" required>
            </div>
            <div>
                <button type="submit">Masuk</button>
            </div>
        </form>
    </div>
</body>
</html>