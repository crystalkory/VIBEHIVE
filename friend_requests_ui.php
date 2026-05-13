<?php

?>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 900px;
    margin: 20px auto;
    padding: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #333;
    min-height: 100vh;
}

h2 {
    text-align: center;
    margin-bottom: 30px;
    color: #7b68ee;
    font-size: 32px;
    font-weight: 800;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    padding: 20px;
    border-radius: 15px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.request-card {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 20px;
    padding: 20px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease-in-out;
    flex-wrap: wrap;
    border: 2px solid rgba(123, 104, 238, 0.3);
    position: relative;
    overflow: hidden;
}

.request-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    border-color: #7b68ee;
}

.request-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(123, 104, 238, 0.1), transparent);
    opacity: 0;
    transition: opacity 0.3s ease;
    border-radius: 15px;
}

.request-card:hover::before {
    opacity: 1;
}

.request-card img {
    width: 65px;
    height: 65px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
    flex-shrink: 0;
    border: 3px solid #7b68ee;
    box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}

.request-card:hover img {
    transform: scale(1.1);
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.request-info {
    flex-grow: 1;
    font-weight: 700;
    cursor: pointer;
    font-size: 18px;
    color: #2d3748;
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}

.request-info:hover {
    color: #7b68ee;
    text-shadow: 0 2px 4px rgba(123, 104, 238, 0.2);
}

.request-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    position: relative;
    z-index: 1;
}

.request-actions button {
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
    font-family: inherit;
    position: relative;
    overflow: hidden;
}

.request-actions button::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), transparent);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.request-actions button:hover::before {
    opacity: 1;
}

.accept-btn {
    background: linear-gradient(135deg, #48bb78, #38a169);
    color: white;
}

.accept-btn:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(72, 187, 120, 0.4);
}

.delete-btn {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    color: white;
}

.delete-btn:hover {
    background: linear-gradient(135deg, #ee5a52, #e53e3e);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
}

.status-text {
    font-weight: 700;
    color: #48bb78;
    margin-left: auto;
    font-size: 16px;
    background: rgba(72, 187, 120, 0.1);
    padding: 10px 16px;
    border-radius: 8px;
    border: 1px solid rgba(72, 187, 120, 0.3);
    position: relative;
    z-index: 1;
}

#requests-list {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    padding: 25px;
    border-radius: 15px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    min-height: 200px;
}

#requests-list p {
    text-align: center;
    color: #718096;
    font-size: 16px;
    font-weight: 500;
    margin: 0;
}

/* Animation for request cards */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.request-card {
    animation: fadeInUp 0.4s ease-out;
}

/* Loading state */
#requests-list.loading {
    position: relative;
    overflow: hidden;
}

#requests-list.loading::after {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    animation: loading 1.5s infinite;
}

@keyframes loading {
    0% { left: -100%; }
    100% { left: 100%; }
}

/* Focus states for accessibility */
.request-actions button:focus,
.request-card img:focus,
.request-info:focus {
    outline: 2px solid #7b68ee;
    outline-offset: 2px;
}

/* Success message animation */
@keyframes successFade {
    0% { background: rgba(72, 187, 120, 0.2); }
    50% { background: rgba(72, 187, 120, 0.4); }
    100% { background: rgba(72, 187, 120, 0.1); }
}

.request-card.success {
    animation: successFade 1s ease-in-out;
}

/* Responsive Design */
@media (max-width: 768px) {
    body {
        margin: 15px auto;
        padding: 15px;
    }
    
    h2 {
        font-size: 28px;
        margin-bottom: 25px;
        padding: 15px;
    }
    
    .request-card {
        flex-direction: column;
        align-items: center;
        padding: 25px;
        gap: 15px;
        text-align: center;
    }
    
    .request-card img {
        width: 80px;
        height: 80px;
    }
    
    .request-info {
        font-size: 20px;
        width: 100%;
    }
    
    .request-actions {
        width: 100%;
        justify-content: center;
    }
    
    .request-actions button {
        flex: 1;
        font-size: 15px;
        padding: 14px;
        max-width: 140px;
    }
    
    .status-text {
        width: 100%;
        text-align: center;
        margin: 10px 0 0;
    }
    
    #requests-list {
        padding: 20px;
    }
}

@media (max-width: 480px) {
    body {
        margin: 10px auto;
        padding: 10px;
    }
    
    h2 {
        font-size: 24px;
        margin-bottom: 20px;
        padding: 12px;
    }
    
    .request-card {
        padding: 20px;
        gap: 12px;
    }
    
    .request-card img {
        width: 70px;
        height: 70px;
    }
    
    .request-info {
        font-size: 18px;
    }
    
    .request-actions {
        flex-direction: column;
        width: 100%;
        gap: 8px;
    }
    
    .request-actions button {
        width: 100%;
        max-width: none;
        padding: 12px;
    }
    
    #requests-list {
        padding: 15px;
    }
}

/* Medium tablets */
@media (min-width: 769px) and (max-width: 1024px) {
    .request-card {
        padding: 18px;
    }
    
    .request-card img {
        width: 60px;
        height: 60px;
    }
    
    .request-info {
        font-size: 17px;
    }
    
    .request-actions button {
        padding: 10px 20px;
    }
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    .request-card {
        border: 2px solid #7b68ee;
    }
    
    .accept-btn {
        background: #28a745;
    }
    
    .delete-btn {
        background: #dc3545;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    .request-card,
    .request-card img,
    .request-actions button,
    .request-info {
        transition: none;
        animation: none;
    }
    
    .request-card:hover {
        transform: none;
    }
    
    .request-card:hover img {
        transform: none;
    }
    
    .request-actions button:hover {
        transform: none;
    }
    
    #requests-list.loading::after {
        animation: none;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    body {
        background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
    }
    
    h2 {
        background: rgba(45, 55, 72, 0.95);
        color: #7b68ee;
    }
    
    .request-card {
        background: rgba(45, 55, 72, 0.95);
    }
    
    .request-info {
        color: #e2e8f0;
    }
    
    #requests-list {
        background: rgba(45, 55, 72, 0.9);
    }
    
    #requests-list p {
        color: #a0aec0;
    }
}

/* Print styles */
@media print {
    body {
        background: white;
    }
    
    .request-card {
        background: white;
        border: 1px solid #ccc;
        box-shadow: none;
    }
    
    .request-actions {
        display: none;
    }
    
    h2 {
        background: white;
        color: #2d3748;
        box-shadow: none;
        border: 1px solid #ccc;
    }
}

/* Empty state styling */
#requests-list:empty::before {
    content: 'No friend requests at this time.';
    display: block;
    text-align: center;
    color: #718096;
    font-style: italic;
    padding: 40px 20px;
    font-size: 16px;
    font-weight: 500;
}

/* Scrollbar styling */
#requests-list::-webkit-scrollbar {
    width: 8px;
}

#requests-list::-webkit-scrollbar-track {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 4px;
}

#requests-list::-webkit-scrollbar-thumb {
    background: rgba(123, 104, 238, 0.3);
    border-radius: 4px;
}

#requests-list::-webkit-scrollbar-thumb:hover {
    background: rgba(123, 104, 238, 0.5);
}
</style>
</head>
<body>
<h2>Friend Requests</h2>
<div id="requests-list">
  <p>Loading friend requests...</p>
</div>

<script>
function loadFriendRequests() {
  const formData = new FormData();
  formData.append('action', 'get_requests');
  fetch('friend_request.php', {
    method: 'POST',
    body: formData,
    credentials: 'same-origin'
  })
  .then(res => res.json())
  .then(data => {
    const container = document.getElementById('requests-list');
    container.innerHTML = ''; // clear loading text
    if (!data.success || !data.requests || data.requests.length === 0) {
      container.innerHTML = '<p>No friend requests at this time.</p>';
      return;
    }

    data.requests.forEach(request => {
      const card = document.createElement('div');
      card.className = 'request-card';

      const img = document.createElement('img');
      img.src = request.profile_pic_url || 'default_profile.png';
      img.alt = 'User pic';
      img.onclick = () => window.location.href = `profile.php?id=${request.user_id}`;

      const info = document.createElement('div');
      info.className = 'request-info';
      info.textContent = request.username;
      info.onclick = () => window.location.href = `profile.php?id=${request.user_id}`;

      const actions = document.createElement('div');
      actions.className = 'request-actions';

      const acceptBtn = document.createElement('button');
      acceptBtn.className = 'accept-btn';
      acceptBtn.textContent = 'Accept';
      acceptBtn.onclick = () => respondRequest(request.request_id, 'accept_request', card);

      const deleteBtn = document.createElement('button');
      deleteBtn.className = 'delete-btn';
      deleteBtn.textContent = 'Delete';
      deleteBtn.onclick = () => respondRequest(request.request_id, 'delete_request', card);

      actions.appendChild(acceptBtn);
      actions.appendChild(deleteBtn);

      card.appendChild(img);
      card.appendChild(info);
      card.appendChild(actions);

      container.appendChild(card);
    });
  })
  .catch(() => {
    document.getElementById('requests-list').innerHTML = '<p>Error loading friend requests.</p>';
  });
}

function respondRequest(requestId, action, cardElement) {
  const formData = new FormData();
  formData.append('action', action);
  formData.append('request_id', requestId);

  fetch('friend_request.php', {
    method: 'POST',
    body: formData,
    credentials: 'same-origin'
  })
  .then(res => res.json())
  .then(data => {
    if (!data.success) {
      alert('Error: ' + (data.message || 'Could not process the request.'));
      return;
    }
    if (action === 'accept_request') {
      cardElement.querySelector('.request-actions').innerHTML = '<span class="status-text">You are now friends</span>';
    } else if (action === 'delete_request') {
      cardElement.remove();
    }
  })
  .catch(() => alert('Request failed. Please try again.'));
}

document.addEventListener('DOMContentLoaded', loadFriendRequests);
</script>

</body>
</html>
