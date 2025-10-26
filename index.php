<?php
session_start();
require_once "db.php";

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Check for empty fields
    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        $sql = "SELECT * FROM users WHERE username=?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($row = mysqli_fetch_assoc($result)) {
            // Verify password
            if (password_verify($password, $row['password'])) {
                
                // *** FIX: Check if user account is active ***
                if ($row['status'] === 'active') {
                    // Set session variables and redirect
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['username'] = $row['username'];
                    $_SESSION['role'] = $row['role'];
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $error = "Your account is inactive. Please contact an administrator.";
                }
            } else {
                // *** FIX: Generic error for security ***
                $error = "Invalid username or password.";
            }
        } else {
            // *** FIX: Generic error for security ***
            $error = "Invalid username or password.";
        }
        mysqli_stmt_close($stmt);
    }
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Login — RCRAO Accounting</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    :root {
      --accent:#d84c73;
      --accent-light:#ffb6c1;
      --accent-dark: #b83b5e;
      --bg1:#fff0f6;
      --bg2:#ffe6ee;
      --card:#ffffff;
      --text-dark:#3d1a2a;
      --text-muted:#6b4a57;
      --shadow:0 8px 25px rgba(216,76,115,0.1);
      --danger: #dc3545;
    }
    *{
        box-sizing: border-box;
        font-family: 'Poppins', sans-serif;
        margin: 0;
        padding: 0;
    }
    body {
      background: linear-gradient(135deg, var(--bg1), var(--bg2));
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      overflow: hidden;
    }

    /* Floating shapes for decoration */
    .floating-shape {
      position: absolute;
      width: 120px;
      height: 120px;
      background: rgba(216, 76, 115, 0.08); /* Use theme color */
      border-radius: 50%;
      animation: float 8s ease-in-out infinite;
      z-index: 0;
    }
    .floating-shape:nth-child(1) { top: -40px; left: -50px; animation-delay: 0s; }
    .floating-shape:nth-child(2) { bottom: -60px; right: -40px; animation-delay: 3s; width: 150px; height: 150px;}
    @keyframes float {
      0%, 100% { transform: translateY(0) rotate(0); }
      50% { transform: translateY(-25px) rotate(30deg); }
    }


    /* Login Box */
    .login-container {
      background: var(--card);
      border-radius: 16px; /* Matched card style */
      box-shadow: var(--shadow);
      width: 400px;
      max-width: 95%;
      padding: 40px;
      position: relative;
      z-index: 1;
      animation: popIn 0.8s ease-out;
    }

    @keyframes popIn {
      from { opacity: 0; transform: scale(0.9) translateY(20px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }

    /* Title */
    .login-container h1 {
      text-align: center;
      color: var(--accent-dark);
      font-weight: 700;
      font-size: 24px;
      margin-bottom: 10px;
    }

    /* Subtitle */
    .login-container p {
      text-align: center;
      color: var(--text-muted);
      margin-bottom: 30px;
      font-size: 15px;
    }

    /* Form Fields */
    .form-group {
      margin-bottom: 20px;
      position: relative;
    }

    .form-group input {
      width: 100%;
      padding: 12px 45px 12px 15px; /* Padding for icon */
      border: 1px solid #ddd;
      border-radius: 8px;
      background: #f9f9f9;
      font-size: 15px;
      transition: all 0.2s ease;
      font-family:"Poppins",sans-serif;
    }

    .form-group input:focus {
      outline: none;
      border-color: var(--accent);
      background: #fff;
      box-shadow: 0 0 0 3px rgba(216, 76, 115, 0.15);
    }

    .form-group i {
      position: absolute;
      right: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--accent-light);
      transition: color 0.3s ease;
    }
    
    .form-group input:focus + i {
        color: var(--accent);
    }

    /* Button */
    .btn-login {
      border: none;
      border-radius: 8px;
      padding: 12px 18px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.25s ease;
      background: var(--accent);
      color: #fff;
      font-size: 16px;
      width: 100%;
      font-family:"Poppins",sans-serif;
    }

    .btn-login:hover {
      background: var(--accent-dark);
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(216,76,115,0.3);
    }

    /* Error Message */
    .error {
      background: rgba(220, 53, 69, 0.1);
      color: var(--danger);
      padding: 12px 15px;
      border-radius: 8px;
      text-align: center;
      margin-bottom: 20px;
      font-size: 14px;
      font-weight: 500;
      border: 1px solid rgba(220, 53, 69, 0.2);
    }
    .error i {
        margin-right: 8px;
    }
  </style>
</head>
<body>
  <div class="floating-shape"></div>
  <div class="floating-shape"></div>

  <div class="login-container">
    <h1>RCRAO Accounting</h1> <p>Please log in to continue</p>

    <?php if(!empty($error)): ?>
      <div class="error"><i class="fa fa-exclamation-circle"></i> <?= htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="index.php"> <div class="form-group">
        <input type="text" name="username" placeholder="Username" required>
        <i class="fa fa-user"></i>
      </div>
      <div class="form-group">
        <input type="password" name="password" placeholder="Password" required>
        <i class="fa fa-lock"></i>
      </div>
      <button type="submit" class="btn-login">Login</button>
    </form>
  </div>
</body>
</html>