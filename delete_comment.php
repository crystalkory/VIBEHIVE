$profileUserId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($profileUserId < 1) {
    die("Invalid Profile ID");
}

$viewerUserId = $_SESSION['user_id'];

// Fetch profile user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$profileUserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("User not found.");
}
