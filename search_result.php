<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: auth.php');
  exit;
}
$searchQuery = trim($_GET['q'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Search results for <?=htmlspecialchars($searchQuery)?></title>
<style>
  body {
    font-family: Arial, sans-serif;
    max-width: 900px;
    margin: 30px auto;
    padding: 0 15px;
  }
  .tabs {
    display: flex;
    border-bottom: 1px solid #ccc;
    margin-bottom: 20px;
    flex-wrap: wrap;
  }
  .tab {
    padding: 10px 20px;
    cursor: pointer;
    border: 1px solid #ccc;
    border-bottom: none;
    background: #f5f5f5;
    margin-right: 4px;
    border-radius: 6px 6px 0 0;
    user-select: none;
    flex: 1 1 auto;
    text-align: center;
  }
  .tab.active {
    background: white;
    font-weight: bold;
  }
  .tab-content {
    border: 1px solid #ccc;
    padding: 15px;
    border-radius: 0 6px 6px 6px;
    background: white;
    min-height: 200px;
  }
  .friend-card, .group-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px;
    border-bottom: 1px solid #eee;
    flex-wrap: wrap;
  }
  .friend-card img, .group-card img {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
  }
  .friend-info, .group-info {
    flex-grow: 1;
    min-width: 150px;
  }
  .friend-info a, .group-info a {
    font-weight: bold;
    font-size: 16px;
    color: #007bff;
    text-decoration: none;
  }
  .friend-info a:hover, .group-info a:hover {
    text-decoration: underline;
  }
  button.friend-btn, button.group-btn {
    margin-left: auto;
    padding: 6px 14px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    background: #007bff;
    color: white;
    font-weight: bold;
  }
  button.friend-btn.pending {
    background: grey;
    cursor: default;
  }
  button.friend-btn.friends {
    background: green;
    cursor: default;
  }

  @media (max-width: 600px) {
    .tab {
      flex: 1 1 100%;
      margin-bottom: 6px;
    }
    .friend-card, .group-card {
      flex-direction: column;
      align-items: flex-start;
    }
    button.friend-btn, button.group-btn {
      margin-left: 0;
      margin-top: 6px;
      width: 100%;
    }
  }
</style>
</head>
<body>

<h2>Search Results for "<?=htmlspecialchars($searchQuery)?>"</h2>

<div class="tabs">
  <div class="tab active" data-tab="posts">Posts</div>
  <div class="tab" data-tab="people">People</div>
  <div class="tab" data-tab="groups">Groups</div>
</div>

<div class="tab-content" id="tab-posts">Loading posts...</div>
<div class="tab-content" id="tab-people" style="display:none">Loading people...</div>
<div class="tab-content" id="tab-groups" style="display:none">Loading groups...</div>

<script>
const tabs = document.querySelectorAll('.tab');
const contents = document.querySelectorAll('.tab-content');
const searchQuery = <?=json_encode($searchQuery)?>;

tabs.forEach(tab => {
  tab.onclick = () => {
    tabs.forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    const selected = tab.getAttribute('data-tab');
    contents.forEach(c => c.style.display = 'none');
    document.getElementById('tab-' + selected).style.display = 'block';

    loadTabData(selected);
  };
});

function loadTabData(tab) {
  const container = document.getElementById('tab-' + tab);
  container.innerHTML = 'Loading...';

  fetch(`search_data.php?tab=${tab}&q=${encodeURIComponent(searchQuery)}`)
    .then(res => res.json())
    .then(data => {
      if (!data.success) {
        container.innerHTML = '<p>Error loading data.</p>';
        return;
      }
      if (tab === 'posts') {
        container.innerHTML = data.posts.length
          ? data.posts.map(p => `<div><strong>${p.title}</strong><p>${p.snippet}</p></div>`).join('')
          : '<p>No posts found.</p>';
      } else if (tab === 'people') {
        if (!data.people.length) {
          container.innerHTML = '<p>No users found.</p>';
          return;
        }
        container.innerHTML = data.people.map(person => `
          <div class="friend-card" id="friend-${person.id}">
            <img src="${person.profile_pic_url || 'default_profile.png'}" alt="${person.username}" />
            <div class="friend-info"><a href="profile.php?id=${person.id}">${person.username}</a></div>
            <button class="friend-btn ${person.friend_status}" data-user-id="${person.id}">${person.friend_text}</button>
          </div>
        `).join('');
      } else if (tab === 'groups') {
        if (!data.groups.length) {
          container.innerHTML = '<p>No groups found.</p>';
          return;
        }
        container.innerHTML = data.groups.map(g => `
          <div class="group-card" id="group-${g.id}">
            <img src="${g.cover_pic_url || 'default_cover.png'}" alt="${g.name}" style="width:100px; height:50px; object-fit:cover; margin-right: 10px;" />
            <img src="${g.profile_pic_url || 'default_profile.png'}" alt="Group Pic" />
            <div class="group-info">${g.name}</div>
            <button class="group-btn">${g.join_status === 'joined' ? 'Visit' : 'Join'}</button>
          </div>
        `).join('');
      }
    })
    .catch(() => {
      container.innerHTML = '<p>Error loading data.</p>';
    });
}

// Load initial posts tab data
loadTabData('posts');
</script>
</body>
</html>
