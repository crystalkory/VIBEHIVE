<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once "config.php";


$userId = $_SESSION['user_id'];

// Define categories
$categories = ['Entertainment', 'Dance', 'Lip-Sync', 'Comedy', 'Music', 'Beauty and Fashion', 'Food and Cooking', 'DIY and Crafting', 'Gaming'];

// Helper function for image upload
function uploadImageFile($file)
{
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload error.'];
    }
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'File size must be less than 5MB.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $allowedMimeTypes)) {
        return ['success' => false, 'error' => 'Only JPEG, PNG, and GIF images are allowed.'];
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeName = uniqid('groupimg_', true) . '.' . $ext;
    $uploadDir = __DIR__ . '/uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $destination = $uploadDir . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Failed to move uploaded file.'];
    }
    return ['success' => true, 'filename' => 'uploads/' . $safeName];
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupName = trim($_POST['group_name'] ?? '');
    $groupDescription = trim($_POST['group_description'] ?? '');
    $privacy = $_POST['privacy'] ?? 'public';
    $category1 = $_POST['category1'] ?? null;
    $category2 = $_POST['category2'] ?? null;
    $category3 = $_POST['category3'] ?? null;

    // Validate categories - they can't be the same
    $selectedCategories = array_filter([$category1, $category2, $category3]);
    if (count($selectedCategories) !== count(array_unique($selectedCategories))) {
        $errors[] = "Please choose different categories for each dropdown.";
    }

    if ($groupName === '') {
        $errors[] = "Group name is required.";
    }
    if ($groupDescription === '') {
        $errors[] = "Group description is required.";
    }

    $profilePicPath = null;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        $result = uploadImageFile($_FILES['profile_picture']);
        if ($result['success']) {
            $profilePicPath = $result['filename'];
        } else {
            $errors[] = "Profile picture error: " . $result['error'];
        }
    }
    $coverPicPath = null;
    if (isset($_FILES['cover_picture']) && $_FILES['cover_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        $result = uploadImageFile($_FILES['cover_picture']);
        if ($result['success']) {
            $coverPicPath = $result['filename'];
        } else {
            $errors[] = "Cover picture error: " . $result['error'];
        }
    }

    if (!$errors) {
        // Admin approval flag default false
        $adminApprovalRequired = false;

        $stmt = $pdo->prepare("INSERT INTO groups (name, description, profile_pic_url, cover_pic_url, privacy_setting, creator_id, admin_approval_required, category1, category2, category3) VALUES (:name, :description, :profile_pic_url, :cover_pic_url, :privacy, :creator_id, :admin_approval, :category1, :category2, :category3)");

        // Bind parameters explicitly, including boolean type
        $stmt->bindValue(':name', $groupName, PDO::PARAM_STR);
        $stmt->bindValue(':description', $groupDescription, PDO::PARAM_STR);
        $stmt->bindValue(':profile_pic_url', $profilePicPath, PDO::PARAM_STR);
        $stmt->bindValue(':cover_pic_url', $coverPicPath, PDO::PARAM_STR);
        $stmt->bindValue(':privacy', $privacy, PDO::PARAM_STR);
        $stmt->bindValue(':creator_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':admin_approval', $adminApprovalRequired, PDO::PARAM_BOOL);
        $stmt->bindValue(':category1', $category1, PDO::PARAM_STR);
        $stmt->bindValue(':category2', $category2, PDO::PARAM_STR);
        $stmt->bindValue(':category3', $category3, PDO::PARAM_STR);

        $stmt->execute();

        // Get the inserted group ID
        $groupId = $pdo->lastInsertId();

        // Insert creator as approved member
        $stmtMember = $pdo->prepare("INSERT INTO group_members (group_id, user_id, status, joined_at) VALUES (:group_id, :user_id, 'approved', NOW())");
        $stmtMember->execute(['group_id' => $groupId, 'user_id' => $userId]);

        $success = true;
    }
}

require_once "back.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Create Group - Fbclone</title>
<style>
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 600px;
    margin: 20px auto;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #333;
    min-height: 100vh;
    padding: 20px;
}
form {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    border: 2px solid #7b68ee;
    margin-bottom: 30px;
}
form input[type="text"], form textarea, form select {
    width: 100%;
    padding: 12px 15px;
    margin: 8px 0 20px 0;
    border-radius: 8px;
    border: 2px solid #7b68ee;
    font-size: 16px;
    box-sizing: border-box;
    background-color: white;
    color: #2d3748;
    transition: all 0.3s ease;
    font-weight: 500;
}
form input[type="text"]:focus, form textarea:focus, form select:focus {
    outline: none;
    border-color: #6a5acd;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}
form input[type="file"] {
    width: 100%;
    padding: 10px;
    margin: 8px 0 20px 0;
    border-radius: 8px;
    border: 2px dashed #7b68ee;
    background: rgba(123, 104, 238, 0.1);
    color: #2d3748;
    cursor: pointer;
    transition: all 0.3s ease;
}
form input[type="file"]:hover {
    background: rgba(123, 104, 238, 0.2);
    border-color: #6a5acd;
}
form label {
    font-weight: bold;
    margin-top: 12px;
    display: block;
    color: #7b68ee;
    font-weight: 700;
}
form button {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 10px;
    font-weight: bold;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.3s ease;
    width: 100%;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
    margin-top: 10px;
}
form button:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}
.errors {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    border: 2px solid #ff4757;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 10px;
    color: white;
    box-shadow: 0 4px 12px rgba(255, 107, 107, 0.3);
}
.success {
    background: linear-gradient(135deg, #48bb78, #38a169);
    border: 2px solid #2dce89;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 10px;
    color: white;
    box-shadow: 0 4px 12px rgba(72, 187, 120, 0.3);
}
.category-row {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
}
.category-row > div {
    flex: 1;
}
.category-row select {
    margin-bottom: 0;
}

/* Animation for page load */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

form {
    animation: fadeInUp 0.6s ease-out;
}

/* Custom styling for select dropdowns */
select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%237b68ee' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
    background-position: right 12px center;
    background-repeat: no-repeat;
    background-size: 16px;
    padding-right: 40px;
}

/* Textarea specific styling */
textarea {
    resize: vertical;
    min-height: 100px;
    font-family: inherit;
}

/* File input styling */
input[type="file"]::file-selector-button {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    margin-right: 10px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
}

input[type="file"]::file-selector-button:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
}

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
        margin: 10px auto;
    }
    
    form {
        padding: 20px;
    }
    
    .category-row {
        flex-direction: column;
        gap: 10px;
    }
    
    .category-row > div {
        width: 100%;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
    }
    
    form {
        padding: 15px;
    }
    
    form input[type="text"], form textarea, form select {
        padding: 10px 12px;
        font-size: 14px;
    }
    
    form button {
        padding: 12px 20px;
        font-size: 15px;
    }
    
    h1 {
        font-size: 24px;
        margin-top: 40px;
    }
}

/* Success link styling */
.success a {
    color: white;
    text-decoration: underline;
    font-weight: 600;
}

.success a:hover {
    color: #e3f2fd;
}

/* Error list styling */
.errors ul {
    margin: 0;
    padding-left: 20px;
}

.errors li {
    margin-bottom: 5px;
}

.errors li:last-child {
    margin-bottom: 0;
}
</style>
</head>
<body>

<h1 style="color: #7b68ee;margin-top: 60px;">Create Group</h1>

<?php if ($errors): ?>
    <div class="errors">
        <ul>
        <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
<?php elseif ($success): ?>
    <div class="success">Group created successfully! <a href="join_group.php" style="color: white; text-decoration: underline;">Go to Join Group</a></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="form1">
    <label for="group_name">Group Name *</label>
    <input type="text" id="group_name"  maxlength="20" name="group_name" required value="<?= htmlspecialchars($_POST['group_name'] ?? '') ?>" />

    <label for="group_description">Group Description *</label>
    <textarea id="group_description"  maxlength="150"name="group_description" rows="4" required><?= htmlspecialchars($_POST['group_description'] ?? '') ?></textarea>

    <label>Categories (Optional)</label>
    <div class="category-row">
        <div>
            <select id="category1" name="category1">
                <option value="">Select Category 1</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= htmlspecialchars($category) ?>" <?= ($_POST['category1'] ?? '') === $category ? 'selected' : '' ?>>
                        <?= htmlspecialchars($category) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <select id="category2" name="category2">
                <option value="">Select Category 2</option>
               <?php foreach ($categories as $category): ?>
                    <option value="<?= htmlspecialchars($category) ?>" <?= ($_POST['category1'] ?? '') === $category ? 'selected' : '' ?>>
                        <?= htmlspecialchars($category) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <select id="category3" name="category3">
                <option value="">Select Category 3</option>

            </select>
        </div>
    </div>

    <label for="profile_picture">Upload Profile Picture (optional)</label>
    <input type="file" id="profile_picture" name="profile_picture" accept="image/*" />

    <label for="cover_picture">Upload Cover Picture (optional)</label>
    <input type="file" id="cover_picture" name="cover_picture" accept="image/*" />

    <label for="privacy" style="color: #7b68ee;">Group Privacy *</label>
    <select id="privacy" name="privacy" required>
        <option value="public" <?= (($_POST['privacy'] ?? '') === 'public') ? 'selected' : '' ?>>Make Group Public</option>
        <option value="private" <?= (($_POST['privacy'] ?? '') === 'private') ? 'selected' : '' ?>>Make Group Private</option>
    </select>

    <button type="submit">Create Group</button>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const category1 = document.getElementById('category1');
    const category2 = document.getElementById('category2');
    const category3 = document.getElementById('category3');
    
    function validateCategories() {
        const cat1 = category1.value;
        const cat2 = category2.value;
        const cat3 = category3.value;
        
        // Reset custom validity
        category2.setCustomValidity('');
        category3.setCustomValidity('');
        
        if (cat1 && cat2 && cat1 === cat2) {
            category2.setCustomValidity('Please choose a different category from Category 1');
        }
        if (cat1 && cat3 && cat1 === cat3) {
            category3.setCustomValidity('Please choose a different category from Category 1');
        }
        if (cat2 && cat3 && cat2 === cat3) {
            category3.setCustomValidity('Please choose a different category from Category 2');
        }
    }
    
    category1.addEventListener('change', validateCategories);
    category2.addEventListener('change', validateCategories);
    category3.addEventListener('change', validateCategories);
});
</script>

</body>
</html>