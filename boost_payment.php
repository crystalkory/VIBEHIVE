<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


$userId = $_SESSION['user_id'];
$pricePerDayPerPost = 6;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postIds = $_POST['post_ids'] ?? [];
    $boostDays = (int)($_POST['boost_days'] ?? 0);

    if (empty($postIds) || $boostDays < 1) {
        die("Invalid boost data.");
    }

    $postIds = array_filter($postIds, fn($id) => is_numeric($id));
    if (empty($postIds)) {
        die("No valid posts selected.");
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $stmt = $pdo->prepare("SELECT id, content, post_type, media_url FROM posts WHERE id IN ($placeholders) AND user_id = ?");
    $stmt->execute([...$postIds, $userId]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$posts) {
        die("Selected posts not found or unauthorized.");
    }

$boostStart = (new DateTime())->format('Y-m-d H:i:s');
$boostEnd = (new DateTime())->modify("+$boostDays days")->format('Y-m-d H:i:s');

$insertStmt = $pdo->prepare("
    INSERT INTO boost_posts (post_id, user_id, boost_start, boost_end)
    VALUES (?, ?, ?, ?)
    ON CONFLICT (post_id) DO UPDATE SET boost_start = EXCLUDED.boost_start, boost_end = EXCLUDED.boost_end
");

foreach ($posts as $post) {
    $insertStmt->execute([$post['id'], $userId, $boostStart, $boostEnd]);
}


        header("Location: profile.php?id=$userId");
        exit;
    }
 else {
    die("Invalid access method.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Boost Payment - Fbclone</title>
<style>
body { font-family: Arial, sans-serif; max-width: 900px; margin: 20px auto; padding: 10px; }
h1 { text-align: center; }
.post-summary { border: 1px solid #ccc; border-radius: 8px; padding: 10px; max-height: 300px; overflow-y: auto; margin-bottom: 25px;}
.post-item { margin-bottom: 10px; padding: 6px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 10px; }
.post-item img, .post-item video { max-width: 150px; max-height: 100px; object-fit: cover; border-radius: 6px; }
label { font-weight: bold; margin-bottom: 5px; }
input, select { width: 100%; padding: 10px; margin-bottom: 15px; border-radius: 6px; border: 1px solid #ccc; font-size: 16px; box-sizing: border-box;}
button { width: 100%; padding: 14px; font-size: 18px; background: #007bff; border:none; border-radius: 6px; color: #fff; cursor: pointer;}
button:hover { background: #0056b3; }
</style>
</head>
<body>

<h1>Confirm and Pay for Boost</h1>

<h2>Selected Posts</h2>
<div class="post-summary">
<?php foreach ($posts as $post): ?>
    <div class="post-item">
        <?php
        if (!empty($post['media_url'])) {
            $mediaArray = explode(',', $post['media_url']);
            $firstMedia = trim($mediaArray[0]);
            if ($post['post_type'] === 'photo') {
                echo '<img src="' . htmlspecialchars($firstMedia) . '" alt="Image">';
            } elseif ($post['post_type'] === 'video' || $post['post_type'] === 'reel') {
                echo '<video controls muted style="max-width:150px; max-height:100px;"><source src="' . htmlspecialchars($firstMedia) . '" type="video/mp4">Your browser does not support video.</video>';
            }
        }
        ?>
        <div><?= nl2br(htmlspecialchars(substr($post['content'], 0, 150))) ?><?= strlen($post['content']) > 150 ? "..." : "" ?></div>
    </div>
<?php endforeach; ?>
</div>

<form method="POST" action="">
    <input type="hidden" name="boost_days" value="<?= htmlspecialchars($boostDays) ?>">
    <?php foreach ($postIds as $pid): ?>
        <input type="hidden" name="post_ids[]" value="<?= htmlspecialchars($pid) ?>">
    <?php endforeach; ?>

    <label>Total Price (USD)</label>
    <input type="text" readonly value="<?= number_format($totalPrice, 2) ?>">

    <h2>Payment Information</h2>
    <label for="card_name">Cardholder Name</label>
    <input type="text" id="card_name" name="card_name" required>

    <label for="card_number">Card Number</label>
    <input type="text" id="card_number" name="card_number" required pattern="\d{13,19}" placeholder="13 to 19 digits">

    <label for="expiry_date">Expiry Date (MM/YY)</label>
    <input type="text" id="expiry_date" name="expiry_date" required pattern="(0[1-9]|1[0-2])\/\d{2}" placeholder="MM/YY">

    <label for="cvv">CVV</label>
    <input type="text" id="cvv" name="cvv" required pattern="\d{3,4}" placeholder="3 or 4 digits">

    <button type="submit" name="make_payment">Pay</button>
</form>

</body>
</html>
