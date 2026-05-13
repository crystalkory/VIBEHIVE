<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: signup.php');
    exit;
}

$pdo = new PDO("pgsql:host=localhost;dbname=fbclone", "postgres", "Gi12,br12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$currentUserId = $_SESSION['user_id'];
$chatUserId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$chatUserId) {
    die("Invalid user to chat with.");
}

// Fetch chat user details
$stmt = $pdo->prepare("SELECT username, profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$chatUserId]);
$chatUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$chatUser) {
    die("User not found.");
}

require_once "back.php";
?>

<!DOCTYPE html>
<html>
<head>
    <title>Chat with <?= htmlspecialchars($chatUser['username']) ?></title>
    <style>
      * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 600px;
    margin: 30px auto;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 20px;
    color: #333;
    min-height: 100vh;
}

h2 {
    color: #7b68ee;
    font-size: 28px;
    font-weight: 800;
    text-align: center;
    margin-bottom: 25px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    padding: 20px;
    border-radius: 15px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    margin-top: 50px;
}

#chatBox {
    border: 2px solid rgba(123, 104, 238, 0.3);
    min-height: 500px;
    padding: 20px;
    overflow-y: auto;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    margin-bottom: 20px;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.message {
    margin-bottom: 15px;
    padding: 15px 20px;
    border-radius: 20px;
    max-width: 75%;
    word-wrap: break-word;
    font-size: 15px;
    line-height: 1.4;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    position: relative;
}

.message:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
}

.message.self {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    text-align: right;
    margin-left: auto;
    border-bottom-right-radius: 8px;
}

.message.self::before {
    content: '';
    position: absolute;
    right: -8px;
    top: 50%;
    transform: translateY(-50%);
    width: 0;
    height: 0;
    border-left: 8px solid #6a5acd;
    border-top: 8px solid transparent;
    border-bottom: 8px solid transparent;
}

.message.other {
    background: rgba(255, 255, 255, 0.9);
    color: #2d3748;
    text-align: left;
    margin-right: auto;
    border: 1px solid rgba(123, 104, 238, 0.2);
    border-bottom-left-radius: 8px;
}

.message.other::before {
    content: '';
    position: absolute;
    left: -8px;
    top: 50%;
    transform: translateY(-50%);
    width: 0;
    height: 0;
    border-right: 8px solid rgba(255, 255, 255, 0.9);
    border-top: 8px solid transparent;
    border-bottom: 8px solid transparent;
}

#messageForm {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 20px;
    border-radius: 15px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

#messageForm textarea {
    width: 100%;
    height: 80px;
    box-sizing: border-box;
    resize: vertical;
    padding: 15px;
    border: 2px solid rgba(123, 104, 238, 0.3);
    border-radius: 12px;
    font-size: 15px;
    font-family: inherit;
    background: rgba(255, 255, 255, 0.9);
    color: #2d3748;
    transition: all 0.3s ease;
}

#messageForm textarea:focus {
    outline: none;
    border-color: #7b68ee;
    box-shadow: 0 0 0 3px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
}

#messageForm textarea::placeholder {
    color: #a0aec0;
    font-weight: 500;
}

#messageForm button {
    margin-top: 12px;
    padding: 14px 28px;
    cursor: pointer;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
    font-family: inherit;
    width: 100%;
}

#messageForm button:hover {
    background: linear-gradient(135deg, #6a5acd, #5d4fbb);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(123, 104, 238, 0.6);
}

#messageForm button:disabled {
    background: #cbd5e0;
    transform: none;
    box-shadow: none;
    cursor: not-allowed;
}

/* Message timestamp */
.message-time {
    font-size: 11px;
    opacity: 0.7;
    margin-top: 5px;
    font-weight: 500;
}

.message.self .message-time {
    color: rgba(255, 255, 255, 0.8);
}

.message.other .message-time {
    color: #718096;
}

/* Scrollbar styling */
#chatBox::-webkit-scrollbar {
    width: 8px;
}

#chatBox::-webkit-scrollbar-track {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 4px;
}

#chatBox::-webkit-scrollbar-thumb {
    background: rgba(123, 104, 238, 0.3);
    border-radius: 4px;
}

#chatBox::-webkit-scrollbar-thumb:hover {
    background: rgba(123, 104, 238, 0.5);
}

/* Message animations */
@keyframes messageSlideIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.message {
    animation: messageSlideIn 0.3s ease-out;
}

/* Typing indicator */
.typing-indicator {
    display: inline-block;
    background: rgba(123, 104, 238, 0.1);
    color: #718096;
    padding: 10px 15px;
    border-radius: 20px;
    font-style: italic;
    font-size: 14px;
    margin-bottom: 10px;
    border: 1px solid rgba(123, 104, 238, 0.2);
}

/* Message status indicators */
.message-status {
    font-size: 10px;
    margin-left: 8px;
    opacity: 0.7;
}

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
        margin: 20px auto;
    }
    
    h2 {
        font-size: 24px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    #chatBox {
        min-height: 400px;
        padding: 15px;
        margin-bottom: 15px;
    }
    
    .message {
        max-width: 85%;
        padding: 12px 16px;
        font-size: 14px;
    }
    
    #messageForm {
        padding: 15px;
    }
    
    #messageForm textarea {
        height: 70px;
        padding: 12px;
        font-size: 14px;
    }
    
    #messageForm button {
        padding: 12px 24px;
        font-size: 15px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
        margin: 15px auto;
    }
    
    h2 {
        font-size: 22px;
        padding: 12px;
        margin-bottom: 15px;
    }
    
    #chatBox {
        min-height: 350px;
        padding: 12px;
        margin-bottom: 12px;
    }
    
    .message {
        max-width: 90%;
        padding: 10px 14px;
        font-size: 13px;
    }
    
    #messageForm {
        padding: 12px;
    }
    
    #messageForm textarea {
        height: 60px;
        padding: 10px;
        font-size: 13px;
    }
    
    #messageForm button {
        padding: 10px 20px;
        font-size: 14px;
    }
    
    .message-time {
        font-size: 10px;
    }
}

/* Focus states for accessibility */
#messageForm textarea:focus,
#messageForm button:focus {
    outline: 2px solid #7b68ee;
    outline-offset: 2px;
}

/* Loading state for chat */
#chatBox.loading {
    position: relative;
    overflow: hidden;
}

#chatBox.loading::after {
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

/* High contrast mode support */
@media (prefers-contrast: high) {
    #chatBox {
        border: 2px solid #7b68ee;
    }
    
    .message.self {
        background: #7b68ee;
    }
    
    .message.other {
        border: 2px solid #7b68ee;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    .message,
    #messageForm textarea,
    #messageForm button {
        transition: none;
        animation: none;
    }
    
    .message:hover {
        transform: none;
    }
    
    #messageForm textarea:focus {
        transform: none;
    }
    
    #messageForm button:hover {
        transform: none;
    }
    
    #chatBox.loading::after {
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
    
    #chatBox {
        background: rgba(45, 55, 72, 0.95);
    }
    
    .message.other {
        background: rgba(74, 85, 104, 0.9);
        color: #e2e8f0;
    }
    
    #messageForm {
        background: rgba(45, 55, 72, 0.95);
    }
    
    #messageForm textarea {
        background: rgba(74, 85, 104, 0.9);
        color: #e2e8f0;
        border-color: rgba(123, 104, 238, 0.5);
    }
    
    #messageForm textarea::placeholder {
        color: #a0aec0;
    }
}

/* Print styles */
@media print {
    body {
        background: white;
    }
    
    #chatBox {
        background: white;
        border: 1px solid #ccc;
        box-shadow: none;
    }
    
    #messageForm {
        display: none;
    }
    
    .message.self {
        background: #f0f0f0;
        color: black;
    }
    
    .message.other {
        background: #e0e0e0;
        color: black;
    }
}

/* Message grouping for consecutive messages */
.message.consecutive {
    margin-top: 5px;
}

.message.consecutive.self {
    border-top-right-radius: 8px;
}

.message.consecutive.other {
    border-top-left-radius: 8px;
}
    </style>
</head>
<body>

<h2>Chat with <?= htmlspecialchars($chatUser['username']) ?></h2>

<div id="chatBox"></div>

<form id="messageForm">
    <textarea name="message" required placeholder="Type your message here..."></textarea>
    <button type="submit">Send</button>
</form>

<script>
const currentUserId = <?= json_encode($currentUserId) ?>;
const chatUserId = <?= json_encode($chatUserId) ?>;

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function displayMessage(text, isSelf) {
    const chatBox = document.getElementById('chatBox');
    const div = document.createElement('div');
    div.className = 'message ' + (isSelf ? 'self' : 'other');
    div.innerHTML = escapeHtml(text);
    chatBox.appendChild(div);
    chatBox.scrollTop = chatBox.scrollHeight;
}

async function fetchMessages() {
    try {
        const response = await fetch(`load_messages.php?user1=${currentUserId}&user2=${chatUserId}`, {
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (data.success) {
            const chatBox = document.getElementById('chatBox');
            chatBox.innerHTML = '';
            data.messages.forEach(msg => {
                displayMessage(msg.message, msg.sender_id == currentUserId);
            });
        } else {
            console.error('Failed to load messages:', data.message);
        }
    } catch (error) {
        console.error('Error fetching messages:', error);
    }
}

document.getElementById('messageForm').addEventListener('submit', async event => {
    event.preventDefault();
    const textarea = event.target.querySelector('textarea');
    const message = textarea.value.trim();
    if (!message) return;

    try {
        const response = await fetch('send_message2.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                receiver_id: chatUserId,
                message: message
            })
        });
        const data = await response.json();
        if (data.success) {
            displayMessage(message, true);
            textarea.value = '';
        } else {
            alert('Failed to send message: ' + data.message);
        }
    } catch (error) {
        alert('Error sending message');
        console.error(error);
    }
});

// Load existing messages on page load
fetchMessages();
// Poll periodically every 5 seconds
setInterval(fetchMessages, 5000);
</script>

</body>
</html>
