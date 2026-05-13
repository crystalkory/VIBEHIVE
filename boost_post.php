<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once "config.php";


$userId = $_SESSION['user_id'];
$pricePerDayPerPost = 6;
$daysOptions = [1, 2, 3, 7, 14, 30, 45, 60, 75, 90, 180, 365];

// Fetch all posts by this user
$stmt = $pdo->prepare("SELECT * FROM posts WHERE user_id = :user_id ORDER BY created_at DESC");
$stmt->execute(['user_id' => $userId]);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

function filterPostsByType(array $posts, string $type) {
    return array_filter($posts, fn($post) =>
        ($type === 'word' && empty($post['media_url'])) ||
        ($type === 'photo' && $post['post_type'] === 'photo' && !empty($post['media_url'])) ||
        ($type === 'video' && ($post['post_type'] === 'video' || $post['post_type'] === 'reel') && !empty($post['media_url']))
    );
}

$wordPosts = filterPostsByType($posts, 'word');
$imagePosts = filterPostsByType($posts, 'photo');
$videoPosts = filterPostsByType($posts, 'video');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Boost Posts - Fbclone</title>
<style>
body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; 
    max-width: 900px; 
    margin: 20px auto; 
    padding: 20px; 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    color: #333;
}
h1 { 
    text-align: center; 
    margin-bottom: 30px; 
    color: white;
    font-size: 28px;
    font-weight: 800;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}
.tabs {
  display: flex;
  border-bottom: 2px solid rgba(123, 104, 238, 0.3);
  margin-bottom: 20px;
  flex-wrap: wrap;
  gap: 5px;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  border-radius: 15px 15px 0 0;
  padding: 10px 10px 0 10px;
}
.tab-button {
  background: none;
  border: none;
  padding: 12px 24px;
  font-size: 16px;
  cursor: pointer;
  border-bottom: 3px solid transparent;
  flex-grow: 1;
  text-align: center;
  transition: all 0.3s ease;
  border-radius: 8px 8px 0 0;
  font-weight: 600;
  color: #718096;
}
.tab-button.active {
  border-color: #7b68ee;
  font-weight: bold;
  color: #7b68ee;
  background: rgba(123, 104, 238, 0.1);
}
.tab-button:hover {
  background: rgba(123, 104, 238, 0.05);
  color: #7b68ee;
}
.tab-content {
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  border-radius: 0 0 15px 15px;
  padding: 25px;
  min-height: 300px;
  border: 2px solid #7b68ee;
  box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}
.post-list {
  max-height: 450px;
  overflow-y: auto;
  border: 2px solid rgba(123, 104, 238, 0.3);
  border-radius: 10px;
  padding: 15px;
  background: rgba(255, 255, 255, 0.8);
}
.post-item {
  display: flex;
  align-items: flex-start;
  padding: 12px;
  border-bottom: 1px solid rgba(123, 104, 238, 0.2);
  border-radius: 8px;
  margin-bottom: 8px;
  transition: all 0.3s ease;
}
.post-item:hover {
  background: rgba(123, 104, 238, 0.1);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
}
.post-item:last-child {
  border-bottom: none;
  margin-bottom: 0;
}
.post-checkbox {
  margin-right: 15px;
  margin-top: 15px;
  cursor: pointer;
  transform: scale(1.2);
  accent-color: #7b68ee;
}
.post-media {
  margin-right: 15px;
  border-radius: 10px;
  overflow: hidden;
  cursor: pointer;
  flex-shrink: 0;
  border: 2px solid #7b68ee;
  box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
  transition: transform 0.3s ease;
}
.post-media:hover {
  transform: scale(1.05);
}
.post-media img, .post-media video {
  display: block;
  width: 100px;
  height: 70px;
  object-fit: cover;
}
.post-content-preview {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex-grow: 1;
  font-size: 14px;
  margin-top: 10px;
  color: #2d3748;
  font-weight: 500;
  cursor: pointer;
}
.controls {
  margin-top: 30px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 20px;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  padding: 20px;
  border-radius: 15px;
  border: 2px solid #7b68ee;
  box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}
label {
  cursor: pointer;
  font-weight: 600;
  color: #2d3748;
}
select, input[readonly] {
  padding: 10px 15px;
  font-size: 16px;
  border-radius: 8px;
  border: 2px solid #7b68ee;
  min-width: 150px;
  background: white;
  transition: all 0.3s ease;
  font-weight: 500;
}
select:focus {
  outline: none;
  border-color: #6a5acd;
  box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
  transform: translateY(-2px);
}
input[readonly] {
  background-color: rgba(123, 104, 238, 0.1);
  color: #7b68ee;
  font-weight: bold;
  border-color: #7b68ee;
}
button {
  padding: 12px 30px;
  background: linear-gradient(135deg, #7b68ee, #6a5acd);
  border: none;
  border-radius: 10px;
  color: white;
  font-size: 16px;
  cursor: pointer;
  font-weight: bold;
  transition: all 0.3s ease;
  box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}
button:hover:not(:disabled) {
  background: linear-gradient(135deg, #6a5acd, #5a4abc);
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}
button:disabled {
  background: linear-gradient(135deg, #a0aec0, #718096);
  cursor: default;
  transform: none;
  box-shadow: none;
}

/* Custom checkbox styling */
.selectAll {
  accent-color: #7b68ee;
  transform: scale(1.1);
  margin-right: 8px;
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

.tab-content {
  animation: fadeInUp 0.6s ease-out;
}

/* Scrollbar styling */
.post-list::-webkit-scrollbar {
  width: 8px;
}

.post-list::-webkit-scrollbar-track {
  background: rgba(123, 104, 238, 0.1);
  border-radius: 4px;
}

.post-list::-webkit-scrollbar-thumb {
  background: #7b68ee;
  border-radius: 4px;
}

.post-list::-webkit-scrollbar-thumb:hover {
  background: #6a5acd;
}

/* Responsive Design */
@media (max-width: 768px) {
  body {
    padding: 15px;
  }
  
  .tabs {
    flex-direction: column;
  }
  
  .tab-button {
    border-radius: 8px;
    margin-bottom: 5px;
  }
  
  .controls {
    flex-direction: column;
    align-items: stretch;
    gap: 15px;
  }
  
  select, input[readonly] {
    width: 100%;
    min-width: auto;
  }
  
  button {
    width: 100%;
  }
}

@media (max-width: 480px) {
  body {
    padding: 10px;
  }
  
  .post-item {
    flex-direction: column;
    align-items: stretch;
    padding: 15px;
  }
  
  .post-media img, .post-media video {
    width: 100%;
    height: 150px;
    margin-bottom: 10px;
  }
  
  .post-content-preview {
    white-space: normal;
    margin-top: 5px;
  }
  
  .post-checkbox {
    margin-top: 5px;
    margin-bottom: 10px;
  }
  
  h1 {
    font-size: 24px;
  }
  
  .tab-content {
    padding: 15px;
  }
  
  .post-list {
    padding: 10px;
  }
}
</style>
</head>
<body>

<h1>Boost Your Posts</h1>

<div class="tabs">
  <button class="tab-button active" data-tab="tab-word">Word Posts</button>
  <button class="tab-button" data-tab="tab-photo">Image Posts</button>
  <button class="tab-button" data-tab="tab-video">Video Posts</button>
</div>

<form id="boostForm" method="POST" action="boost_payment.php">

  <div id="tab-word" class="tab-content">
    <label><input type="checkbox" class="selectAll" data-group="word"> Select All Word Posts</label>
    <div class="post-list">
      <?php if (!$wordPosts): ?>
        <p>No word-only posts found.</p>
      <?php else: foreach ($wordPosts as $post): ?>
        <div class="post-item">
          <input type="checkbox" name="post_ids[]" value="<?= $post['id'] ?>" id="word_<?= $post['id'] ?>" class="post-checkbox word">
          <label for="word_<?= $post['id'] ?>" class="post-content-preview"><?= htmlspecialchars($post['content']) ?></label>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div id="tab-photo" class="tab-content" style="display:none">
    <label><input type="checkbox" class="selectAll" data-group="photo"> Select All Image Posts</label>
    <div class="post-list">
      <?php if (!$imagePosts): ?>
        <p>No image posts found.</p>
      <?php else: foreach ($imagePosts as $post):
        $mediaArray = explode(',', $post['media_url']);
        $firstMedia = trim($mediaArray[0]);
      ?>
        <div class="post-item">
          <input type="checkbox" name="post_ids[]" value="<?= $post['id'] ?>" id="photo_<?= $post['id'] ?>" class="post-checkbox photo">
          <div class="post-media"><img src="<?= htmlspecialchars($firstMedia) ?>" alt="Image"></div>
          <label for="photo_<?= $post['id'] ?>" class="post-content-preview"><?= htmlspecialchars(substr($post['content'], 0, 80)) ?><?= strlen($post['content']) > 80 ? "..." : "" ?></label>
        </div>
      <?php endforeach; endif;?>
    </div>
  </div>

  <div id="tab-video" class="tab-content" style="display:none">
    <label><input type="checkbox" class="selectAll" data-group="video"> Select All Video Posts</label>
    <div class="post-list">
      <?php if (!$videoPosts): ?>
        <p>No video posts found.</p>
      <?php else: foreach ($videoPosts as $post):
          $mediaArray = explode(',', $post['media_url']);
          $firstMedia = trim($mediaArray[0]);
      ?>
        <div class="post-item">
          <input type="checkbox" name="post_ids[]" value="<?= $post['id'] ?>" id="video_<?= $post['id'] ?>" class="post-checkbox video">
          <div class="post-media">
            <video muted loop>
              <source src="<?= htmlspecialchars($firstMedia) ?>" type="video/mp4">
              Your browser does not support the video tag.
            </video>
          </div>
          <label for="video_<?= $post['id'] ?>" class="post-content-preview"><?= htmlspecialchars(substr($post['content'], 0, 80)) ?><?= strlen($post['content']) > 80 ? "..." : "" ?></label>
        </div>
      <?php endforeach; endif;?>
    </div>
  </div>

  <div class="controls">
    <label for="boostDays">Boost Duration:</label>
    <select id="boostDays" name="boost_days" required>
      <?php foreach($daysOptions as $d): ?>
        <option value="<?= $d ?>"><?= $d ?> <?= $d === 1 ? 'day' : 'days' ?></option>
      <?php endforeach; ?>
    </select>

    <label for="totalPrice">Total Price (USD):</label>
    <input type="text" id="totalPrice" readonly value="0">

    <button type="submit" id="makePaymentBtn" disabled>Make Payment</button>
  </div>
</form>

<script>
// Tabs logic
document.querySelectorAll('.tab-button').forEach(btn => {
  btn.addEventListener('click', () => {
    const tab = btn.getAttribute('data-tab');
    document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
    btn.classList.add('active');
    document.getElementById(tab).style.display = 'block';
  });
});

// Select all checkboxes in each tab
document.querySelectorAll('.selectAll').forEach(selectAll => {
  selectAll.addEventListener('change', () => {
    const group = selectAll.getAttribute('data-group');
    document.querySelectorAll('.post-checkbox.' + group).forEach(cb => cb.checked = selectAll.checked);
    updatePrice();
  });
});

// Update select all checkbox if individual post checkboxes change
document.querySelectorAll('.post-checkbox').forEach(chk => {
  chk.addEventListener('change', () => {
    ['word', 'photo', 'video'].forEach(group => {
      const groupBoxes = document.querySelectorAll('.post-checkbox.' + group);
      if (!groupBoxes.length) return;
      const allChecked = Array.from(groupBoxes).every(box => box.checked);
      const selectAllBox = document.querySelector(`.selectAll[data-group="${group}"]`);
      if (selectAllBox) selectAllBox.checked = allChecked;
    });
    updatePrice();
  });
});

const boostDaysSelect = document.getElementById('boostDays');
const totalPriceInput = document.getElementById('totalPrice');
const makePaymentBtn = document.getElementById('makePaymentBtn');
const pricePerDayPerPost = <?= $pricePerDayPerPost ?>;

boostDaysSelect.addEventListener('change', updatePrice);

function updatePrice() {
  const selectedCount = Array.from(document.querySelectorAll('.post-checkbox:checked')).length;
  if (selectedCount === 0) {
    totalPriceInput.value = '0.00';
    makePaymentBtn.disabled = true;
    return;
  }
  const days = parseInt(boostDaysSelect.value, 10);
  const total = selectedCount * days * pricePerDayPerPost;
  totalPriceInput.value = total.toFixed(2);
  makePaymentBtn.disabled = false;
}

updatePrice();
</script>

</body>
</html>
