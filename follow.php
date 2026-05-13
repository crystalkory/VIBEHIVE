<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
<div>
    <nav class="tabs" style="justify-content: center;">
    <button id="posts-btn" onclick="showTab('posts')" class="active">followers</button>
    <button id="photos-btn" onclick="showTab('photos')">following</button>
</div>

<div id="posts" class="tab-panel">
    <?php 
    
    ?>
</div>
<div id="photos" class="tab-panel" style="display:none;">
    <?php
   
    ?>
</div>
<div id="videos" class="tab-panel" style="display:none;"></div>
<div id="reels" class="tab-panel" style="display:none;"></div>
</body>
</html>
<style>
    nav.tabs { margin:20px 0; border-bottom:2px solid #ddd; display:flex; gap: 15px; flex-wrap: wrap; }
nav.tabs button { background:none; border:none; padding:10px 20px; font-size:16px; cursor:pointer; }
nav.tabs button.active { border-bottom:3px solid #0069d9; font-weight:bold; }
.tab-panel { background:#fff; padding:15px; border-radius:6px; min-height:300px; }

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