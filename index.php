<?php
session_start(); // MUST be the very first thing

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0"); // Expire immediately

require_once "db.php";

// --- LOGIN ATTEMPT CONSTANTS AND HELPERS ---
define('MAX_LOGIN_ATTEMPTS', 3);
define('LOCKOUT_DURATION', 900); // 15 minutes (900 seconds)

/**
 * Records a failed login attempt for a user.
 * If attempts exceed max, sets a lockout time.
 */
function record_failed_attempt($username) {
    if (!isset($_SESSION['login_attempts'][$username])) {
        $_SESSION['login_attempts'][$username] = 0;
    }
    
    $_SESSION['login_attempts'][$username]++;
    
    if ($_SESSION['login_attempts'][$username] >= MAX_LOGIN_ATTEMPTS) {
        // Set lockout time
        $_SESSION['lockout_until'][$username] = time() + LOCKOUT_DURATION;
        // Clear the attempt counter now that they are locked out
        unset($_SESSION['login_attempts'][$username]);
    }
}

/**
 * Gets the appropriate error message for a failed login.
 * Includes timer logic.
 */
function get_failure_message($username) {
    // Check if user is currently locked out
    if (isset($_SESSION['lockout_until'][$username]) && $_SESSION['lockout_until'][$username] > time()) {
        $remaining = $_SESSION['lockout_until'][$username] - time();
        $minutes = ceil($remaining / 60); // Round up to the nearest minute
        return "Too many failed attempts. Please try again in $minutes minute(s).";
    }
    
    // Otherwise, show attempts remaining
    $attempts = $_SESSION['login_attempts'][$username] ?? 0;
    $attempts_left = MAX_LOGIN_ATTEMPTS - $attempts;
    return "Invalid username or password. $attempts_left attempt(s) remaining.";
}

// --- Initial GET Request or Non-AJAX POST (Fallback/Security) ---
// If user is already logged in, redirect away from login page
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: dashboard.php");
    } else {
        header("Location: transactions/collections.php");
    }
    exit();
}

// --- AJAX Login Handling ---
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // We assume POST requests are AJAX for this implementation
    header('Content-Type: application/json'); // Set response type to JSON
    $response = ['status' => 'error', 'message' => 'An unexpected error occurred.']; // Default error

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $response['message'] = "Please enter both username and password.";
    } else {
        // --- NEW: CHECK FOR EXISTING LOCKOUT FIRST ---
        if (isset($_SESSION['lockout_until'][$username]) && $_SESSION['lockout_until'][$username] > time()) {
            
            $remaining = $_SESSION['lockout_until'][$username] - time();
            $minutes = ceil($remaining / 60);
            $response['message'] = "Too many failed attempts. Please try again in $minutes minute(s).";
            
        } else {
            // --- User is not locked out, proceed with login ---
            $sql = "SELECT user_id, username, password, role, status, name FROM users WHERE username=?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if ($row = mysqli_fetch_assoc($result)) {
                if (password_verify($password, $row['password'])) {
                    if ($row['status'] === 'active') {
                        
                        // --- NEW: CLEAR ATTEMPTS ON SUCCESS ---
                        unset($_SESSION['login_attempts'][$username]);
                        unset($_SESSION['lockout_until'][$username]);
                        // --- END NEW ---

                        // Regenerate session ID for security upon login
                        session_regenerate_id(true);

                        // Set session variables
                        $_SESSION['user_id'] = $row['user_id'];
                        $_SESSION['username'] = $row['username'];
                        $_SESSION['role'] = $row['role'];
                        $_SESSION['name'] = (!empty($row['name'])) ? trim($row['name']) : $row['username'];

                        // Determine redirect URL
                        $redirect_url = ($row['role'] === 'admin') ? 'dashboard.php' : 'transactions/collections.php';

                        // Prepare success response
                        $response = [
                            'status' => 'success',
                            'redirect' => $redirect_url
                        ];
                    } else {
                        $response['message'] = "Your account is inactive. Please contact an administrator.";
                    }
                } else {
                    // --- NEW: HANDLE FAILED PASSWORD ---
                    record_failed_attempt($username);
                    $response['message'] = get_failure_message($username);
                }
            } else {
                // --- NEW: HANDLE FAILED USERNAME (prevents username enumeration) ---
                record_failed_attempt($username);
                $response['message'] = get_failure_message($username);
            }
            mysqli_stmt_close($stmt);
        } // End of the lockout check 'else'
    }

    // Send JSON response and stop script execution
    echo json_encode($response);
    exit();
}

// --- If it's a GET request, display the HTML login form ---
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Login — RCRAO Accounting</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    /* --- Your CSS Styles Remain the Same --- */
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
    .floating-shape {
      position: absolute;
      width: 120px;
      height: 120px;
      background: rgba(216, 76, 115, 0.08);
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
    .login-container {
      background: var(--card);
      border-radius: 16px;
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
    .login-container h1 {
      text-align: center;
      color: var(--accent-dark);
      font-weight: 700;
      font-size: 24px;
      margin-bottom: 10px;
    }
    .login-container p {
      text-align: center;
      color: var(--text-muted);
      margin-bottom: 30px;
      font-size: 15px;
    }
    .form-group { margin-bottom: 20px; position: relative; }
    .form-group input {
      width: 100%; padding: 12px 45px 12px 15px; border: 1px solid #ddd;
      border-radius: 8px; background: #f9f9f9; font-size: 15px;
      transition: all 0.2s ease; font-family:"Poppins",sans-serif;
    }
    .form-group input:focus {
      outline: none; border-color: var(--accent); background: #fff;
      box-shadow: 0 0 0 3px rgba(216, 76, 115, 0.15);
    }
    .form-group i {
      position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
      color: var(--accent-light); transition: color 0.3s ease;
    }
    .form-group input:focus + i { color: var(--accent); }
    .btn-login {
      border: none; border-radius: 8px; padding: 12px 18px; font-weight: 600;
      cursor: pointer; transition: all 0.25s ease; background: var(--accent);
      color: #fff; font-size: 16px; width: 100%; font-family:"Poppins",sans-serif;
    }
    .btn-login:hover {
      background: var(--accent-dark); transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(216,76,115,0.3);
    }
    .btn-login:disabled { /* Style for disabled button during processing */
        background-color: var(--accent-light);
        cursor: not-allowed;
        opacity: 0.7;
    }
    .error {
      display: none; /* Hide by default, shown by JS */
      background: rgba(220, 53, 69, 0.1); color: var(--danger); padding: 12px 15px;
      border-radius: 8px; text-align: center; margin-bottom: 20px;
      font-size: 14px; font-weight: 500; border: 1px solid rgba(220, 53, 69, 0.2);
    }
    .error i { margin-right: 8px; }
  </style>
</head>
<body>
  <div class="floating-shape"></div>
  <div class="floating-shape"></div>

  <div class="login-container">
    <h1>RCRAO Accounting</h1> <p>Please log in to continue</p>

    <div id="errorMessage" class="error"></div>

    <form id="loginForm" method="POST" action="index.php">
      <div class="form-group">
        <input type="text" name="username" id="username" placeholder="Username" required>
        <i class="fa fa-user"></i>
      </div>
      <div class="form-group">
        <input type="password" name="password" id="password" placeholder="Password" required>
        <i class="fa fa-lock"></i>
      </div>
      <button type="submit" id="loginButton" class="btn-login">Login</button>
    </form>
  </div>

<script>
    const loginForm = document.getElementById('loginForm');
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const errorMessageDiv = document.getElementById('errorMessage');
    const loginButton = document.getElementById('loginButton');

    loginForm.addEventListener('submit', function(event) {
        event.preventDefault(); // Stop the default form submission

        // Clear previous errors and disable button
        errorMessageDiv.textContent = '';
        errorMessageDiv.style.display = 'none';
        loginButton.disabled = true;
        loginButton.textContent = 'Logging in...';

        const formData = new FormData(loginForm);

        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                // Handle HTTP errors (like 404, 500)
                throw new Error('Network response was not ok: ' + response.statusText);
            }
            return response.json(); // Parse the JSON response from PHP
        })
        .then(data => {
            if (data.status === 'success') {
                // Redirect on success
                window.location.href = data.redirect;
                // No need to re-enable button as we are leaving the page
            } else {
                // Show error message from PHP
                errorMessageDiv.innerHTML = `<i class="fa fa-exclamation-circle"></i> ${data.message || 'Login failed. Please try again.'}`;
                errorMessageDiv.style.display = 'block';
                loginButton.disabled = false; // Re-enable button on error
                loginButton.textContent = 'Login';
            }
        })
        .catch(error => {
            // Handle network errors or JSON parsing errors
            console.error('Login error:', error);
            errorMessageDiv.innerHTML = `<i class="fa fa-exclamation-circle"></i> An error occurred during login. Please check your connection and try again.`;
            errorMessageDiv.style.display = 'block';
            loginButton.disabled = false; // Re-enable button on error
            loginButton.textContent = 'Login';
        });
    });
</script>

</body>
</html>