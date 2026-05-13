<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];

try {
    $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=face2", "postgres", "Gi12,br12", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Fetch normal user info
    $stmtUser = $pdo->prepare("SELECT id, username, profile_picture FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $normalAccount = $stmtUser->fetch(PDO::FETCH_ASSOC);

    // Fetch business user info from business_users table
    $stmtBizUser = $pdo->prepare("SELECT bu.id, bu.username, bp.business_name, bp.profile_picture 
                                  FROM business_users bu
                                  LEFT JOIN business_profiles bp ON bu.id = bp.user_id
                                  WHERE bu.id = ?");
    $stmtBizUser->execute([$userId]);
    $businessAccount = $stmtBizUser->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountType = $_POST['account_type'] ?? 'normal';

    if ($accountType === 'normal') {
        $_SESSION['active_account'] = 'normal';
    } elseif ($accountType === 'business' && $businessAccount) {
        $_SESSION['active_account'] = 'business';
    }

    header("Location: dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Switch Account</title>
<style>
body { font-family: Arial, sans-serif; max-width: 600px; margin: 30px auto; padding: 20px; }
h1 { margin-bottom: 20px; }
form { display: flex; flex-direction: column; max-width: 350px; }
label { margin: 10px 0 5px; font-weight: bold; cursor: pointer; display: flex; align-items: center; }
input[type=radio] { margin-right: 10px; }
.profile-pic { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; margin-right: 15px; }
button { margin-top: 20px; padding: 10px; font-size: 16px; background: #007bff; color: white; border: none; border-radius: 6px; cursor: pointer; }
button:hover { background: #0056b3; }
.account-option { display: flex; align-items: center; margin-bottom: 15px; }
.account-name { font-size: 16px; }
</style>
</head>
<body>

<h1>Switch Account</h1>

<form method="POST">
    <label class="account-option">
        <input type="radio" name="account_type" value="normal" checked />
        <img src="<?= htmlspecialchars($normalAccount['profile_picture'] ?? 'default-profile.png') ?>" alt="Normal Profile" class="profile-pic" />
        <span class="account-name"><?= htmlspecialchars($normalAccount['username']) ?></span>
    </label>

    <?php if ($businessAccount && !empty($businessAccount['business_name'])): ?>
        <label class="account-option">
            <input type="radio" name="account_type" value="business" />
            <img src="<?= htmlspecialchars($businessAccount['profile_picture'] ?? 'default-profile.png') ?>" alt="Business Profile" class="profile-pic" />
            <span class="account-name"><?= htmlspecialchars($businessAccount['business_name']) ?></span>
        </label>
    <?php endif; ?>

    <button type="submit">Continue</button>
</form>

</body>
</html>
