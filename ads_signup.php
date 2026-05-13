<?php
session_start();

require_once "config.php";


// Define categories
$categories = [
    'Entertainment', 'Dance', 'Lip-Sync', 'Comedy', 'Music', 
    'Beauty and Fashion', 'Food and Cooking', 'DIY and Crafting', 'Gaming'
];

// Country list
$countries = [
    'US' => 'United States',
    'CA' => 'Canada',
    'GB' => 'United Kingdom',
    'AU' => 'Australia',
    'DE' => 'Germany',
    'FR' => 'France',
    'IT' => 'Italy',
    'ES' => 'Spain',
    'JP' => 'Japan',
    'KR' => 'South Korea',
    'CN' => 'China',
    'IN' => 'India',
    'BR' => 'Brazil',
    'MX' => 'Mexico',
    'RU' => 'Russia',
    'ZA' => 'South Africa',
    'NG' => 'Nigeria',
    'EG' => 'Egypt',
    'KE' => 'Kenya',
    'GH' => 'Ghana',
    // Add more countries as needed
];

// Handle AJAX requests for signup and login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'signup') {
        $username = trim($_POST['username'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $category1 = $_POST['category1'] ?? null;
        $category2 = $_POST['category2'] ?? null;
        $country = $_POST['country'] ?? null;
        $gender = $_POST['gender'] ?? null;
        $invited_by = isset($_POST['invited_by']) ? (int)$_POST['invited_by'] : null;
        
        if ($invited_by === 0) {
            $invited_by = null;
        }

        // Validate categories - they can't be the same
        if ($category1 && $category2 && $category1 === $category2) {
            http_response_code(400);
            echo json_encode(['error' => 'Please choose different categories']);
            exit;
        }

        if (!$username || (!$email && !$phone) || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Username, email or phone, and password are required']);
            exit;
        }

        try {
            // Check uniqueness of email or phone
            $conditions = [];
            $params = [];
            if ($email !== false) {
                $conditions[] = "email = :email";
                $params[':email'] = $email;
            }
            if ($phone) {
                $conditions[] = "phone = :phone";
                $params[':phone'] = $phone;
            }
            $sql = "SELECT 1 FROM users WHERE " . implode(" OR ", $conditions);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'Email or phone already in use']);
                exit;
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            // Insert user record with inviter info, categories, country, and gender
            $insert = $pdo->prepare("
                INSERT INTO users (username, email, phone, password_hash, invited_by, category1, category2, country, gender)
                VALUES (:username, :email, :phone, :password_hash, :invited_by, :category1, :category2, :country, :gender)
            ");
            $insert->execute([
                ':username' => $username,
                ':email' => $email,
                ':phone' => $phone,
                ':password_hash' => $password_hash,
                ':invited_by' => $invited_by,
                ':category1' => $category1,
                ':category2' => $category2,
                ':country' => $country,
                ':gender' => $gender
            ]);

            $user_id = $pdo->lastInsertId();

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $username;

            echo json_encode(['success' => true, 'user_id' => $user_id]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'login') {
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!$login || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Login and password are required']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE email = :login OR phone = :login LIMIT 1");
            $stmt->execute([':login' => $login]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];

                echo json_encode(['success' => true]);
            } else {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid credentials']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Get invite user ID from query param if present
$invited_by = isset($_GET['invite']) ? (int)$_GET['invite'] : null;

// Get user's country from IP address for auto-detection
function getCountryFromIP() {
    $ip = $_SERVER['REMOTE_ADDR'];
    
    // For localhost/testing, use a fallback IP or default country
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return 'US'; // Default to United States for local development
    }
    
    // Use ipapi.co service (free tier available)
    $url = "http://ipapi.co/{$ip}/country_code/";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $country_code = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && !empty($country_code) && strlen($country_code) === 2) {
        return $country_code;
    }
    
    return 'US'; // Fallback to United States
}

$detected_country = getCountryFromIP();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Signup / Login</title>
<style>
  body {
    font-family: Arial, sans-serif;
    background: #f4f7f8;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 30px 15px;
  }
  .container {
    background: #fff;
    padding: 25px 30px;
    max-width: 400px;
    width: 100%;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border-radius: 8px;
  }
  h2 {
    margin-bottom: 15px;
    text-align: center;
    color: #333;
  }
  label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
  }
  input[type=text],
  input[type=email],
  input[type=tel],
  input[type=password],
  select {
    width: 100%;
    padding: 10px 12px;
    margin-bottom: 15px;
    border: 1px solid #bbb;
    border-radius: 4px;
    font-size: 14px;
  }
  button {
    width: 100%;
    background: #0069d9;
    color: #fff;
    padding: 12px;
    font-size: 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
  }
  button:hover {
    background: #0053ba;
  }
  .form-switch {
    text-align: center;
    margin-top: 15px;
    color: #555;
    font-size: 14px;
  }
  .form-switch a {
    color: #0069d9;
    cursor: pointer;
    text-decoration: none;
  }
  .form-switch a:hover {
    text-decoration: underline;
  }
  .message {
    margin-bottom: 15px;
    font-size: 14px;
  }
  .error {
    color: #d9534f;
  }
  .success {
    color: #28a745;
  }
  .category-row {
    display: flex;
    gap: 10px;
  }
  .category-row > div {
    flex: 1;
  }
  .country-info {
    font-size: 12px;
    color: #666;
    margin-top: -10px;
    margin-bottom: 15px;
  }
</style>
</head>
<body>
<div class="container">

  <!-- Signup Form -->
  <form id="signupForm" style="display: block;">
    <h2>Create Account</h2>
    <div class="message" id="signupMsg"></div>

    <label for="username">Username *</label>
    <input type="text" id="username" name="username" required />

    <label for="email">Email</label>
    <input type="email" id="email" name="email" placeholder="Optional if phone" />

    <label for="phone">Phone</label>
    <input type="tel" id="phone" name="phone" placeholder="Optional if email" />

    <label for="password">Password *</label>
    <input type="password" id="password" name="password" required />

    <label for="gender">Gender</label>
    <select id="gender" name="gender">
      <option value="">Select Gender</option>
      <option value="male">Male</option>
      <option value="female">Female</option>
      <option value="other">I'd rather not say</option>
    </select>

    <label for="country">Country</label>
    <select id="country" name="country">
      <option value="">Select Country</option>
      <?php foreach ($countries as $code => $name): ?>
        <option value="<?= htmlspecialchars($code) ?>" <?= $code === $detected_country ? 'selected' : '' ?>>
          <?= htmlspecialchars($name) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="country-info" id="countryInfo">
      We've detected your location. You can change this if needed.
    </div>

    <label>Categories (Optional)</label>
    <div class="category-row">
      <div>
        <select id="category1" name="category1">
          <option value="">Select Category 1</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <select id="category2" name="category2">
          <option value="">Select Category 2</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <input type="hidden" id="invited_by" name="invited_by" value="<?= htmlspecialchars($invited_by) ?>" />

    <button type="submit">Sign Up</button>

    <p class="form-switch">Already have an account? <a id="toLogin">Login here</a></p>
  </form>

  <!-- Login Form -->
  <form id="loginForm" style="display: none;">
    <h2>Login</h2>
    <div class="message" id="loginMsg"></div>

    <label for="login">Email or Phone *</label>
    <input type="text" id="login" name="login" required />

    <label for="loginPassword">Password *</label>
    <input type="password" id="loginPassword" name="password" required />

    <button type="submit">Login</button>

    <p class="form-switch">Don't have an account? <a id="toSignup">Sign up here</a></p>
  </form>
</div>

<script>
  document.getElementById('toLogin').addEventListener('click', () => {
    document.getElementById('signupForm').style.display = 'none';
    document.getElementById('loginForm').style.display = 'block';
    clearMessages();
  });

  document.getElementById('toSignup').addEventListener('click', () => {
    document.getElementById('loginForm').style.display = 'none';
    document.getElementById('signupForm').style.display = 'block';
    clearMessages();
  });

  function clearMessages() {
    document.getElementById('signupMsg').textContent = '';
    document.getElementById('loginMsg').textContent = '';
  }

  // Category validation
  document.getElementById('category1').addEventListener('change', validateCategories);
  document.getElementById('category2').addEventListener('change', validateCategories);

  function validateCategories() {
    const category1 = document.getElementById('category1').value;
    const category2 = document.getElementById('category2').value;
    
    if (category1 && category2 && category1 === category2) {
      document.getElementById('category2').setCustomValidity('Please choose a different category from Category 1');
    } else {
      document.getElementById('category2').setCustomValidity('');
    }
  }

  // Enhanced country detection using browser's geolocation API as fallback
  function detectCountryWithGeolocation() {
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        function(position) {
          // Reverse geocoding would be needed here, but for simplicity we'll just update the message
          document.getElementById('countryInfo').textContent = 'Location detected via GPS. You can change the country if needed.';
        },
        function(error) {
          // If geolocation fails, keep the IP-based detection
          console.log('Geolocation failed, using IP-based detection');
        }
      );
    }
  }

  // Try enhanced detection when page loads
  document.addEventListener('DOMContentLoaded', function() {
    detectCountryWithGeolocation();
  });

  document.getElementById('signupForm').addEventListener('submit', async e => {
    e.preventDefault();
    clearMessages();

    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'signup');

    // Validate categories
    const category1 = formData.get('category1');
    const category2 = formData.get('category2');
    if (category1 && category2 && category1 === category2) {
      signupMsg('Please choose different categories', true);
      return;
    }

    if (!formData.get('username')) {
      signupMsg('Username is required', true);
      return;
    }
    if (!formData.get('password')) {
      signupMsg('Password is required', true);
      return;
    }
    if (!formData.get('email') && !formData.get('phone')) {
      signupMsg('Please provide either email or phone number', true);
      return;
    }

    const response = await fetch('', {
      method: 'POST',
      body: formData
    });
    const result = await response.json();

    if (result.success) {
      signupMsg('Signup successful! Redirecting...', false);
      setTimeout(() => window.location.href = 'dashboard.php', 1500);
    } else {
      signupMsg(result.error || 'Signup failed', true);
    }
  });

  function signupMsg(msg, isError) {
    const el = document.getElementById('signupMsg');
    el.textContent = msg;
    el.className = 'message ' + (isError ? 'error' : 'success');
  }

  document.getElementById('loginForm').addEventListener('submit', async e => {
    e.preventDefault();
    clearMessages();

    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'login');

    if (!formData.get('login')) {
      loginMsg('Email or Phone is required', true);
      return;
    }
    if (!formData.get('password')) {
      loginMsg('Password is required', true);
      return;
    }

    const response = await fetch('', {
      method: 'POST',
      body: formData
    });
    const result = await response.json();

    if (result.success) {
      loginMsg('Login successful! Redirecting...', false);
      setTimeout(() => window.location.href = 'dashboard.php', 1500);
    } else {
      loginMsg(result.error || 'Login failed', true);
    }
  });

  function loginMsg(msg, isError) {
    const el = document.getElementById('loginMsg');
    el.textContent = msg;
    el.className = 'message ' + (isError ? 'error' : 'success');
  }a
</script>
</body>
</html>