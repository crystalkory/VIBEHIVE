<?php
session_start();

// Set logged in user id from session
$loggedInUserId = $_SESSION['user_id'] ?? null;

// Profile user id (whose profile page is being viewed), from GET or default to logged-in user
$profileUserId = isset($_GET['id']) ? (int)$_GET['id'] : $loggedInUserId;

// Determine if user can edit (viewer owns the profile)
$canEdit = ($loggedInUserId === $profileUserId);

// Connect to DB and fetch bio for the profile user
$bioText = '';

if ($profileUserId) {
    $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=fbclone", "postgres", "Gi12,br12");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT bio FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $profileUserId]);
    $bioText = $stmt->fetchColumn() ?: '';
}
?>
<!-- Then your bio display code goes here using $canEdit and $bioText -->


<style>
  #bioContainer {
    max-width: 600px;
    margin: 15px auto;
    font-size: 16px;
    line-height: 1.5;
    position: relative;
    margin-top: 60px;
  }

  #bioText, #bioTextarea {
    white-space: pre-wrap;
  }

  #editBioBtn {
    cursor: pointer;
    color: #007bff;
    font-size: 18px;
    position: absolute;
    top: 0;
    right: 0;
  }

  #bioTextarea {
    width: 100%;
    font-size: 16px;
    padding: 6px;
    box-sizing: border-box;
    border-radius: 4px;
    border: 1px solid #ccc;
  }

  #saveBioBtn {
    margin-top: 8px;
    padding: 6px 14px;
    font-size: 16px;
    background-color: #28a745;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
  }

  #showMoreBtn {
    margin-top: 6px;
    color: #007bff;
    cursor: pointer;
    user-select: none;
    font-weight: 600;
    display: inline-block;
  }
</style>

<div id="bioContainer">
  <?php if ($canEdit): ?>
    <div id="bioDisplay" style="position:relative;">
      <div id="bioText"><?= $bioText ?></div>
      <span id="editBioBtn" title="Edit bio">&#9998;</span>
    </div>
    <div id="bioEdit" style="display:none;">
      <textarea id="bioTextarea" rows="4"><?= $bioText ?></textarea>
      <button id="saveBioBtn">Save</button>
    </div>
  <?php else: ?>
    <div id="bioDisplayUser">
      <div id="bioTextUser"></div>
      <div id="showMoreBtn" style="display:none;">Show More</div>
    </div>
  <?php endif; ?>
</div>

<script>
<?php if ($canEdit) : ?>
  const bioTextElem = document.getElementById('bioText');
  const editBtn = document.getElementById('editBioBtn');
  const bioEditDiv = document.getElementById('bioEdit');
  const bioDisplayDiv = document.getElementById('bioDisplay');
  const bioTextarea = document.getElementById('bioTextarea');
  const saveBtn = document.getElementById('saveBioBtn');

  editBtn.addEventListener('click', () => {
    bioDisplayDiv.style.display = 'none';
    bioEditDiv.style.display = 'block';
    bioTextarea.focus();
  });

  saveBtn.addEventListener('click', () => {
    const newBio = bioTextarea.value.trim();
    saveBtn.disabled = true;

    fetch('save_bio.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'bio=' + encodeURIComponent(newBio)
    }).then(res => res.json()).then(data => {
      if (data.success) {
        bioTextElem.textContent = newBio;
        bioDisplayDiv.style.display = 'block';
        bioEditDiv.style.display = 'none';
      } else {
        alert(data.message || 'Error saving bio');
      }
    }).catch(() => alert('Network error saving bio')).finally(() => saveBtn.disabled = false);
  });
<?php else: ?>
  const bioTextUserElem = document.getElementById('bioTextUser');
  const showMoreBtn = document.getElementById('showMoreBtn');
  const fullBio = `<?= addslashes($bioText) ?>`;
  const maxLength = 150;
  let expanded = false;

  function updateBioDisplay() {
    if (!fullBio) {
      bioTextUserElem.textContent = "No bio available.";
      showMoreBtn.style.display = 'none';
      return;
    }
    if (fullBio.length <= maxLength) {
      bioTextUserElem.textContent = fullBio;
      showMoreBtn.style.display = 'none';
    } else if (expanded) {
      bioTextUserElem.textContent = fullBio;
      showMoreBtn.textContent = 'Show Less';
      showMoreBtn.style.display = 'inline-block';
    } else {
      bioTextUserElem.textContent = fullBio.slice(0, maxLength) + '...';
      showMoreBtn.textContent = 'Show More';
      showMoreBtn.style.display = 'inline-block';
    }
  }

  showMoreBtn.addEventListener('click', () => {
    expanded = !expanded;
    updateBioDisplay();
  });

  updateBioDisplay();
<?php endif; ?>
</script>
