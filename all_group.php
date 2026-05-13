<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once "header.php";
require_once "footer.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
<div style="margin-top: 60px;">
    <p style="text-align: center;font-weight: bolder;font-size: 25px;color: #7b68ee;">All Groups</p>
    <nav class="tabs">
    <button id="posts-btn" onclick="showTab('posts')" class="active">Search Group</button>
    <button id="photos-btn" onclick="showTab('photos')">My Group</button>
</div>

<div id="posts" class="tab-panel">
    <?php
        require_once "Join_group.php";
    ?>
</div>
<div id="photos" class="tab-panel" style="display:none;">
    <?php
        require_once "user_group.php";
    ?>
</div>
<div id="videos" class="tab-panel" style="display:none;">

</div>
<div id="reels" class="tab-panel" style="display:none;">
    
</div>
</body>
</html>
<style>
    body{
        background-color: #1e1e2f;
    }
    nav.tabs { margin:20px 0; border-bottom:2px solid #ddd; display:flex; gap: 15px; flex-wrap: wrap; }
nav.tabs button { background:none; border:none; padding:10px 20px; font-size:16px; cursor:pointer;color: #7b68ee; }
nav.tabs button.active { border-bottom:3px solid #0069d9; font-weight:bold; }
.tab-panel { background:#fff; padding:15px; border-radius:6px; min-height:300px; background-color: #1e1e2f;}

</style>
<script>
    function showTab(tab) {
  document.querySelectorAll('.tab-panel').forEach(el => el.style.display = 'none');
  document.getElementById(tab).style.display = 'block';
  document.querySelectorAll('nav.tabs button').forEach(btn => btn.classList.remove('active'));
  document.getElementById(tab + '-btn').classList.add('active');
}
document.addEventListener('DOMContentLoaded', () => showTab('posts'));

</script>