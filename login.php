<?php
require_once "config.php";


// Handle AJAX login submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (!$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'Please fill all fields.']);
        exit;
    }

    // Fetch user by email
    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Password correct - login success
        // Start session and set user info if needed
        session_start();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];

        echo json_encode(['success' => true, 'message' => 'Login successful. Redirecting...']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Login - Social Network</title>
<style>
  body { font-family: Arial, sans-serif; background: #f5f6fa; display: flex; justify-content:center; align-items:center; height: 100vh; margin: 0; }
  .login-container { background: #fff; padding: 30px 35px; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.1); max-width: 400px; width: 100%; }
  h2 { margin-bottom: 25px; text-align: center; }
  input[type=email], input[type=password] {
    width: 100%; padding: 12px 10px; margin: 8px 0 20px 0; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;
  }
  button {
    width: 100%; padding: 12px; background-color: #28a745; color: white; border: none; border-radius: 4px; font-size: 16px;
  }
  button:hover {background-color: #218838; cursor: pointer;}
  #responseMsg { text-align: center; margin-top: 20px; font-weight: bold; }
</style>
</head>
<body>
<div class="login-container">
  <h2>Login to Your Account</h2>
  <form id="loginForm">
    <input type="email" id="email" name="email" placeholder="Email Address" required maxlength="255" />
    <input type="password" id="password" name="password" placeholder="Password" required minlength="6" />
    <button type="submit">Log In</button>
  </form>
  <div id="responseMsg"></div>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', async function(e) {
  e.preventDefault();

  const email = this.email.value.trim();
  const password = this.password.value;

  if (!email || !password) {
    showMessage("Please fill all fields.", false);
    return;
  }

  const formData = new FormData();
  formData.append('action', 'login');
  formData.append('email', email);
  formData.append('password', password);

  try {
    const response = await fetch('', { method: 'POST', body: formData });
    const result = await response.json();

    showMessage(result.message, result.success);

    if (result.success) {
      setTimeout(() => {
        window.location.href = 'dashboard.php'; // Redirect after successful login
      }, 1500);
    }
  } catch (error) {
    showMessage("An error occurred. Please try again.", false);
  }
});

function showMessage(msg, success) {
  const responseMsg = document.getElementById('responseMsg');
  responseMsg.textContent = msg;
  responseMsg.style.color = success ? 'green' : 'red';
}
</script>
</body>
</html>
