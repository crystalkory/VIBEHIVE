<?php
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>My Friends</title>
<style>
  body {
    font-family: Arial, sans-serif;
    max-width: 700px;
    margin: 30px auto;
  }
  h2 {
    text-align: center;
    margin-bottom: 20px;
    color: #7b68ee;
  }
  .friend-card {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 15px;
    padding: 10px;
    border: 1px solid #7b68ee;
    border-radius: 6px;
    position: relative;
    transition: transform 0.3s ease, margin-top 0.3s ease;
  }
  .friend-card.move-up {
    transform: translateY(-10px);
    margin-top: -10px;
    margin-bottom: 25px;
  }
  .friend-card img {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
  }
  .friend-name {
    font-weight: bold;
    cursor: pointer;
    color: #7b68ee;
  }
  .last-message {
    font-size: 14px;
    color: #555;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex-grow: 1;
  }
  .message-btn {
    padding: 6px 16px;
    background-color: #7b68ee;
    color: aqua;
    border: 1px solid aqua;
    border-radius: 4px;
    cursor: pointer;
  }
  .message-btn:hover {
    background-color: #0056b3;
  }
  .notification-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: red;
    color: white;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    font-size: 12px;
    font-weight: bold;
    text-align: center;
    line-height: 18px;
    user-select: none;
  }
  #friends-container {
    position: relative;
  }
  #friends-container p {
    text-align: center;
    font-style: italic;
  }
</style>
</head>
<body>

<h2>My Friends</h2>
<div id="friends-container">
  <p>Loading friends...</p>
</div>

<script>
// Store the current order of friend IDs
let currentFriendOrder = [];
let activeUser = <?php echo json_encode($_SESSION['user_id']); ?>;
let lastMessageTimestamps = {};

function fetchFriends() {
  fetch('friends_data.php')
    .then(response => {
      if (!response.ok) {
        throw new Error('Network response was not OK: ' + response.statusText);
      }
      return response.json();
    })
    .then(data => {
      const container = document.getElementById('friends-container');
      
      if (!data.success) {
        container.innerHTML = `<p>Error: ${data.message || 'Failed to load friends'}</p>`;
        return;
      }
      if (!data.friends || data.friends.length === 0) {
        container.innerHTML = '<p>You have no friends yet.</p>';
        return;
      }
      
      // Create a map of existing friend cards
      const existingCards = {};
      const existingElements = container.querySelectorAll('.friend-card');
      existingElements.forEach(card => {
        const friendId = card.dataset.friendId;
        if (friendId) {
          existingCards[friendId] = card;
        }
      });
      
      // Clear container but keep existing cards for animation
      container.innerHTML = '';
      
      // Sort friends by last_message_time (newest first)
      const sortedFriends = [...data.friends].sort((a, b) => {
        const timeA = a.last_message_time ? new Date(a.last_message_time).getTime() : 0;
        const timeB = b.last_message_time ? new Date(b.last_message_time).getTime() : 0;
        return timeB - timeA;
      });
      
      // Store new order
      const newOrder = sortedFriends.map(friend => friend.id);
      
      // Check if any friend needs to move up
      const movedUpFriends = new Set();
      
      sortedFriends.forEach(friend => {
        const friendId = friend.id;
        const newTimestamp = friend.last_message_time;
        const oldTimestamp = lastMessageTimestamps[friendId];
        
        // Check if this is a new message (timestamp changed)
        if (newTimestamp && oldTimestamp && newTimestamp !== oldTimestamp) {
          movedUpFriends.add(friendId);
        }
        
        // Update timestamp
        lastMessageTimestamps[friendId] = newTimestamp;
      });
      
      // Build the new list with animation
      sortedFriends.forEach(friend => {
        const friendId = friend.id;
        let card;
        
        if (existingCards[friendId]) {
          // Reuse existing card
          card = existingCards[friendId];
          
          // Update card content
          const img = card.querySelector('img');
          const nameDiv = card.querySelector('.friend-name');
          const lastMsgDiv = card.querySelector('.last-message');
          const badge = card.querySelector('.notification-badge');
          
          img.src = friend.profile_pic_url || 'default_profile.png';
          nameDiv.textContent = friend.username;
          lastMsgDiv.textContent = friend.last_message || '';
          
          // Update notification badge
          if (badge) {
            if (friend.unread_count && friend.unread_count > 0) {
              badge.textContent = '+' + friend.unread_count;
            } else {
              badge.remove();
            }
          } else if (friend.unread_count && friend.unread_count > 0) {
            const newBadge = document.createElement('span');
            newBadge.className = 'notification-badge';
            newBadge.textContent = '+' + friend.unread_count;
            card.appendChild(newBadge);
          }
          
          // Remove existing event listeners to prevent duplicates
          const messageBtn = card.querySelector('.message-btn');
          if (messageBtn) {
            messageBtn.onclick = () => window.location.href = `message.php?friend_id=${friendId}`;
          }
        } else {
          // Create new card
          card = document.createElement('div');
          card.className = 'friend-card';
          card.dataset.friendId = friendId;

          const img = document.createElement('img');
          img.src = friend.profile_pic_url || 'default_profile.png';
          img.alt = 'Profile Picture';
          img.onclick = () => window.location.href = `profile.php?id=${friendId}`;

          const nameDiv = document.createElement('div');
          nameDiv.className = 'friend-name';
          nameDiv.textContent = friend.username;
          nameDiv.onclick = () => window.location.href = `profile.php?id=${friendId}`;

          const lastMsgDiv = document.createElement('div');
          lastMsgDiv.className = 'last-message';
          lastMsgDiv.textContent = friend.last_message || '';

          const messageBtn = document.createElement('button');
          messageBtn.className = 'message-btn';
          messageBtn.textContent = 'Message';
          messageBtn.onclick = () => window.location.href = `message.php?friend_id=${friendId}`;

          card.appendChild(img);
          card.appendChild(nameDiv);
          card.appendChild(lastMsgDiv);
          card.appendChild(messageBtn);

          if (friend.unread_count && friend.unread_count > 0) {
            const badge = document.createElement('span');
            badge.className = 'notification-badge';
            badge.textContent = '+' + friend.unread_count;
            card.appendChild(badge);
          }
        }
        
        // Add move-up animation if this friend has a new message
        if (movedUpFriends.has(friendId)) {
          card.classList.add('move-up');
          // Remove animation class after animation completes
          setTimeout(() => {
            card.classList.remove('move-up');
          }, 300);
        }
        
        container.appendChild(card);
      });
      
      // Update current order
      currentFriendOrder = newOrder;
    })
    .catch(error => {
      console.error('Fetch error:', error);
      document.getElementById('friends-container').innerHTML = `<p>Error loading friends: ${error.message}</p>`;
    });
}

// Function to simulate sending a message (for testing)
function simulateSendMessage(friendId) {
  // This would typically be called from your message sending function
  // For now, we'll just update the timestamp to trigger a reorder
  lastMessageTimestamps[friendId] = new Date().toISOString();
  fetchFriends(); // Refresh to show the reorder
}

// Initial fetch
fetchFriends();
// Refresh every 10 seconds
setInterval(fetchFriends, 10000);
</script>

</body>
</html>