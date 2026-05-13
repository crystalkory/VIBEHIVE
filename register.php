<?php
// register.php
session_start();
require_once 'db.php'; // expects $pdo (PDO) configured for PostgreSQL

// helper to escape output
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$errors = [];
$old = ['fullname'=>'','username'=>'','email'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $old['fullname'] = $fullname;
    $old['username'] = $username;
    $old['email'] = $email;

    // basic validation
    if ($fullname === '' || $username === '' || $email === '' || $password === '') {
        $errors[] = "Please fill in all required fields.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address.";
    }

    if (strlen($username) < 3 || preg_match('/\s/',$username)) {
        $errors[] = "Username must be at least 3 characters and contain no spaces.";
    }

    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }

    if ($password !== $confirm) {
        $errors[] = "Passwords do not match.";
    }

    if (empty($errors)) {
        // Check uniqueness of username and email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1");
        $stmt->execute(['username'=>$username, 'email'=>$email]);
        $exists = $stmt->fetch();
        if ($exists) {
            $errors[] = "Username or email already taken. Try another.";
        } else {
            // All good — insert user (use RETURNING id to get new id)
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (full_name, username, email, password, profile_pic, cover_pic, bio, profile_privacy, created_at)
                    VALUES (:full_name, :username, :email, :password, :profile_pic, :cover_pic, :bio, :profile_privacy, NOW())
                    RETURNING id";
            $stmt = $pdo->prepare($sql);
            $params = [
                'full_name' => $fullname,
                'username' => $username,
                'email' => $email,
                'password' => $hashed,
                'profile_pic' => 'assets/default-avatar.png',
                'cover_pic' => 'assets/default-cover.png',
                'bio' => '',
                'profile_privacy' => 'public'
            ];
            try {
                $stmt->execute($params);
                $new = $stmt->fetch(PDO::FETCH_ASSOC);
                $newUserId = $new['id'] ?? null;
                if ($newUserId) {
                    // log in the user
                    $_SESSION['user_id'] = $newUserId;
                    $_SESSION['user_name'] = $fullname;
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $errors[] = "Registration failed (no id returned).";
                }
            } catch (PDOException $e) {
                // log $e->getMessage() on the server; show generic message to user
                $errors[] = "Registration failed. Please try again later.";
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Register — SocialApp</title>
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="auth-page">
  <div class="card">
    <h2>Create an account</h2>

    <?php if(!empty($errors)): ?>
      <div class="error-box">
        <?php foreach($errors as $err) echo '<div class="error">'.e($err).'</div>'; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="register.php" autocomplete="off">
      <label>Full name</label>
      <input type="text" name="fullname" value="<?php echo e($old['fullname']); ?>" required>

      <label>Username</label>
      <input type="text" name="username" value="<?php echo e($old['username']); ?>" required>

      <label>Email</label>
      <input type="email" name="email" value="<?php echo e($old['email']); ?>" required>

      <label>Password</label>
      <input type="password" name="password" required>

      <label>Confirm password</label>
      <input type="password" name="confirm_password" required>

      <button type="submit">Sign up</button>
    </form>

    <p>Already have an account? <a href="login.php">Log in</a></p>
  </div>
</body>
</html>
