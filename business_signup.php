<?php
session_start();

if (isset($_SESSION['business_user_id'])) {
    header("Location: setup_business.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$username || !$email || !$password || !$confirm_password) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        try {
            $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=face2", "postgres", "Gi12,br12", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            // Check if username or email exists
            $stmt = $pdo->prepare("SELECT id FROM business_users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = "Username or Email already registered.";
            } else {
                // Insert new business user
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO business_users (username, email, password) VALUES (?, ?, ?)");
                $stmt->execute([$username, $email, $hashedPassword]);
                header("Location: business_login.php?registered=1");
                exit;
            }
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Business Signup</title>
</head>
<body>

<h2>Create Business Account</h2>

<?php if ($error): ?>
<p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<form method="POST">
    <label>Username:<br />
        <input type="text" name="username" required />
    </label><br />
    <label>Email:<br />
        <input type="email" name="email" required />
    </label><br />
    <label>Password:<br />
        <input type="password" name="password" required />
    </label><br />
    <label>Confirm Password:<br />
        <input type="password" name="confirm_password" required />
    </label><br />
    <button type="submit">Sign Up</button>
</form>

</body>
</html>
