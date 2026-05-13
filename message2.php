<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

$currentUserId = $_SESSION['user_id'];
$friendId = filter_input(INPUT_GET, 'friend_id', FILTER_VALIDATE_INT);
if (!$friendId) {
    die('Friend ID required');
}

$stmt = $pdo->prepare("SELECT username, profile_pic_url FROM users WHERE id = ?");
$stmt->execute([$friendId]);
$friend = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$friend) {
    die('Friend not found');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Chat with <?= htmlspecialchars($friend['username']) ?></title>
<style>
 * {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: #333;
  display: flex;
  flex-direction: column;
  height: 100vh;
  max-width: 600px;
  margin: 0 auto;
  padding: 20px;
}

header {
  display: flex;
  align-items: center;
  padding: 15px 20px;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  color: #7b68ee;
  border-radius: 15px;
  margin-top: 10px;
  margin-bottom: 15px;
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.2);
}

header img {
  width: 45px;
  height: 45px;
  border-radius: 50%;
  object-fit: cover;
  margin-right: 15px;
  border: 2px solid #7b68ee;
  box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
  background-color: rgba(255, 255, 255, 0.8);
}

header h2 {
  font-size: 18px;
  font-weight: 600;
  color: #2d3748;
}

#chat-box {
  flex-grow: 1;
  overflow-y: auto;
  padding: 20px;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  border: 2px solid rgba(123, 104, 238, 0.3);
  border-radius: 15px;
  margin: 10px 0;
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.message {
  max-width: 80%;
  margin: 15px 0;
  padding: 12px 16px;
  border-radius: 20px;
  position: relative;
  clear: both;
  font-size: 15px;
  line-height: 1.4em;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
  transition: all 0.3s ease;
}

.message:hover {
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
}

.message.sent {
  background: linear-gradient(135deg, #7b68ee, #6a5acd);
  float: right;
  border-bottom-right-radius: 8px;
  color: white;
}

.message.received {
  background: rgba(255, 255, 255, 0.9);
  float: left;
  border-bottom-left-radius: 8px;
  color: #2d3748;
  border: 1px solid rgba(123, 104, 238, 0.2);
}

.timestamp {
  font-size: 11px;
  color: rgba(255, 255, 255, 0.8);
  margin-top: 6px;
  text-align: right;
  clear: both;
  font-weight: 500;
}

.message.sent .timestamp {
  text-align: right;
  color: rgba(255, 255, 255, 0.8);
}

.message.received .timestamp {
  text-align: left;
  color: #718096;
}

.reply-bubble {
  background-color: rgba(255, 255, 255, 0.3);
  border-radius: 12px;
  font-size: 12px;
  padding: 8px 12px;
  margin-bottom: 8px;
  color: rgba(255, 255, 255, 0.9);
  max-width: 90%;
  overflow-wrap: break-word;
  border-left: 3px solid rgba(255, 255, 255, 0.5);
}

.message.received .reply-bubble {
  background-color: rgba(123, 104, 238, 0.1);
  color: #4a5568;
  border-left: 3px solid #7b68ee;
}

.reply-username {
  font-weight: 600;
  color: rgba(255, 255, 255, 0.9);
  margin-bottom: 3px;
  font-size: 11px;
}

.message.received .reply-username {
  color: #7b68ee;
}

.chat-input-container {
  margin-top: 15px;
  display: flex;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  padding: 12px;
  border-radius: 25px;
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.2);
  align-items: center;
}

.chat-input {
  flex-grow: 1;
  border-radius: 20px;
  border: 2px solid rgba(123, 104, 238, 0.2);
  padding: 12px 18px;
  font-size: 15px;
  resize: none;
  background: rgba(255, 255, 255, 0.8);
  transition: all 0.3s ease;
  font-family: inherit;
}

.chat-input:focus {
  outline: none;
  border-color: #7b68ee;
  box-shadow: 0 0 0 3px rgba(123, 104, 238, 0.2);
  background: white;
}

.chat-send-btn {
  margin-left: 10px;
  background: linear-gradient(135deg, #7b68ee, #6a5acd);
  border: none;
  color: white;
  border-radius: 50%;
  width: 48px;
  height: 48px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
  box-shadow: 0 3px 10px rgba(123, 104, 238, 0.4);
  font-size: 18px;
}

.chat-send-btn:hover {
  background: linear-gradient(135deg, #6a5acd, #5d4fbb);
  transform: scale(1.05);
  box-shadow: 0 5px 15px rgba(123, 104, 238, 0.6);
}

.chat-send-btn:disabled {
  background: #cbd5e0;
  transform: none;
  box-shadow: none;
  cursor: not-allowed;
}

.chat-attachment-btn {
  margin-right: 10px;
  background: rgba(123, 104, 238, 0.1);
  border: 2px solid rgba(123, 104, 238, 0.3);
  border-radius: 50%;
  width: 48px;
  height: 48px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
  font-size: 18px;
  color: #7b68ee;
}

.chat-attachment-btn:hover {
  background: rgba(123, 104, 238, 0.2);
  transform: scale(1.05);
  box-shadow: 0 3px 10px rgba(123, 104, 238, 0.3);
}

.message-container {
  position: relative;
  clear: both;
}

#reply-preview {
  display: none;
  background: rgba(123, 104, 238, 0.1);
  padding: 12px 16px;
  border-left: 4px solid #7b68ee;
  margin-bottom: 15px;
  border-radius: 10px;
  font-size: 14px;
  position: relative;
  backdrop-filter: blur(10px);
  border: 1px solid rgba(123, 104, 238, 0.2);
}

#cancel-reply {
  background: none;
  border: none;
  color: #718096;
  cursor: pointer;
  font-size: 18px;
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  transition: all 0.3s ease;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}

#cancel-reply:hover {
  background: rgba(123, 104, 238, 0.1);
  color: #7b68ee;
}

.reply-preview-content {
  margin-right: 30px;
  color: #2d3748;
  font-weight: 500;
}

.reply-preview-content strong {
  color: #7b68ee;
}

.attachment-preview {
  margin-bottom: 12px;
  max-width: 100%;
  border-radius: 12px;
  overflow: hidden;
  position: relative;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
  transition: all 0.3s ease;
}

.attachment-preview:hover {
  transform: scale(1.02);
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
}

.attachment-preview img, 
.attachment-preview video {
  max-width: 100%;
  max-height: 200px;
  border-radius: 12px;
  display: block;
  cursor: pointer;
}

.download-btn {
  position: absolute;
  top: 10px;
  right: 10px;
  background: rgba(123, 104, 238, 0.9);
  color: white;
  border: none;
  border-radius: 50%;
  width: 36px;
  height: 36px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  transition: all 0.3s ease;
  backdrop-filter: blur(10px);
  border: 2px solid rgba(255, 255, 255, 0.3);
}

.download-btn:hover {
  background: rgba(123, 104, 238, 1);
  transform: scale(1.1);
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
}

.file-attachment {
  display: flex;
  align-items: center;
  padding: 15px;
  background: rgba(123, 104, 238, 0.1);
  border-radius: 12px;
  margin-bottom: 8px;
  position: relative;
  border: 1px solid rgba(123, 104, 238, 0.2);
  transition: all 0.3s ease;
}

.file-attachment:hover {
  background: rgba(123, 104, 238, 0.15);
  transform: translateY(-2px);
  box-shadow: 0 3px 10px rgba(123, 104, 238, 0.2);
}

.file-icon {
  font-size: 28px;
  margin-right: 15px;
  color: #7b68ee;
}

.file-info {
  flex-grow: 1;
}

.file-name {
  font-weight: 600;
  font-size: 14px;
  color: #2d3748;
  margin-bottom: 3px;
}

.file-size {
  font-size: 12px;
  color: #718096;
  font-weight: 500;
}

#attachment-modal {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.9);
  backdrop-filter: blur(10px);
  z-index: 1000;
  justify-content: center;
  align-items: center;
  padding: 20px;
}

#attachment-modal-content {
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(20px);
  padding: 25px;
  border-radius: 20px;
  max-width: 90%;
  max-height: 90%;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
  border: 1px solid rgba(255, 255, 255, 0.2);
  position: relative;
}

#attachment-modal img,
#attachment-modal video {
  max-width: 100%;
  max-height: 80vh;
  border-radius: 12px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
}

.modal-close {
  position: absolute;
  top: 15px;
  right: 15px;
  background: rgba(123, 104, 238, 0.9);
  color: white;
  border: none;
  border-radius: 50%;
  width: 36px;
  height: 36px;
  cursor: pointer;
  font-size: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
  z-index: 1001;
  backdrop-filter: blur(10px);
  border: 2px solid rgba(255, 255, 255, 0.3);
}

.modal-close:hover {
  background: rgba(123, 104, 238, 1);
  transform: scale(1.1);
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
}

.message-content {
  position: relative;
}

.message-actions {
  position: absolute;
  top: 8px;
  right: 8px;
  opacity: 0;
  transition: all 0.3s ease;
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(10px);
  border-radius: 8px;
  padding: 5px;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
  border: 1px solid rgba(123, 104, 238, 0.2);
}

.message:hover .message-actions {
  opacity: 1;
}

.reply-icon {
  position: absolute;
  bottom: 8px;
  left: 12px;
  width: 18px;
  height: 18px;
  cursor: pointer;
  fill: rgba(255, 255, 255, 0.7);
  transition: all 0.3s ease;
  opacity: 0;
}

.message:hover .reply-icon {
  opacity: 1;
}

.reply-icon:hover {
  fill: white;
  transform: scale(1.1);
}

.message.received .reply-icon {
  fill: rgba(113, 128, 150, 0.7);
}

.message.received .reply-icon:hover {
  fill: #7b68ee;
}

#typing-indicator {
  padding: 10px 15px;
  color: #7b68ee;
  font-style: italic;
  font-size: 14px;
  font-weight: 500;
  text-align: left;
  background: rgba(123, 104, 238, 0.1);
  border-radius: 10px;
  margin: 5px 0;
  display: inline-block;
  max-width: fit-content;
  border: 1px solid rgba(123, 104, 238, 0.2);
}

.read-receipt {
  color: #4fc3f7;
  font-size: 12px;
  margin-left: 5px;
  font-weight: bold;
}

/* Scrollbar styling */
#chat-box::-webkit-scrollbar {
  width: 6px;
}

#chat-box::-webkit-scrollbar-track {
  background: rgba(123, 104, 238, 0.1);
  border-radius: 3px;
}

#chat-box::-webkit-scrollbar-thumb {
  background: rgba(123, 104, 238, 0.3);
  border-radius: 3px;
}

#chat-box::-webkit-scrollbar-thumb:hover {
  background: rgba(123, 104, 238, 0.5);
}

/* Animation for new messages */
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

.message-container {
  animation: messageSlideIn 0.3s ease-out;
}

/* Responsive design */
@media (max-width: 768px) {
  body {
    padding: 15px;
    height: 100vh;
  }
  
  header {
    padding: 12px 15px;
    margin-top: 5px;
  }
  
  header img {
    width: 40px;
    height: 40px;
  }
  
  header h2 {
    font-size: 16px;
  }
  
  #chat-box {
    padding: 15px;
    margin: 8px 0;
  }
  
  .message {
    max-width: 85%;
    padding: 10px 14px;
    font-size: 14px;
  }
  
  .chat-input-container {
    padding: 10px;
  }
  
  .chat-input {
    padding: 10px 15px;
    font-size: 14px;
  }
  
  .chat-send-btn,
  .chat-attachment-btn {
    width: 44px;
    height: 44px;
    font-size: 16px;
  }
  
  .attachment-preview img,
  .attachment-preview video {
    max-height: 180px;
  }
}

@media (max-width: 480px) {
  body {
    padding: 10px;
  }
  
  header {
    padding: 10px 12px;
  }
  
  header img {
    width: 36px;
    height: 36px;
    margin-right: 12px;
  }
  
  header h2 {
    font-size: 15px;
  }
  
  #chat-box {
    padding: 12px;
  }
  
  .message {
    max-width: 90%;
    padding: 8px 12px;
    font-size: 13px;
  }
  
  .chat-input-container {
    padding: 8px;
  }
  
  .chat-input {
    padding: 8px 12px;
    font-size: 13px;
  }
  
  .chat-send-btn,
  .chat-attachment-btn {
    width: 40px;
    height: 40px;
    font-size: 15px;
  }
  
  .attachment-preview img,
  .attachment-preview video {
    max-height: 160px;
  }
  
  .file-attachment {
    padding: 12px;
  }
  
  .file-icon {
    font-size: 24px;
    margin-right: 12px;
  }
  
  .file-name {
    font-size: 13px;
  }
  
  .file-size {
    font-size: 11px;
  }
}

/* Loading animation */
#chat-box p {
  text-align: center;
  color: #718096;
  font-style: italic;
  padding: 20px;
}

/* Empty state styling */
#chat-box:empty::before {
  content: "No messages yet. Start the conversation!";
  display: block;
  text-align: center;
  color: #718096;
  font-style: italic;
  padding: 40px 20px;
  font-size: 16px;
}

/* Focus states for accessibility */
.chat-input:focus,
.chat-send-btn:focus,
.chat-attachment-btn:focus {
  outline: 2px solid #7b68ee;
  outline-offset: 2px;
}

/* High contrast mode support */
@media (prefers-contrast: high) {
  .message.sent {
    background: #7b68ee;
    color: white;
  }
  
  .message.received {
    background: white;
    color: black;
    border: 2px solid #7b68ee;
  }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
  .message,
  .message-container,
  .chat-send-btn,
  .chat-attachment-btn,
  .download-btn,
  .modal-close {
    transition: none;
    animation: none;
  }
  
  .message:hover {
    transform: none;
  }
}
</style>
</head>
<body>
<header>
  <img src="<?= htmlspecialchars($friend['profile_pic_url'] ?: 'default_profile.png') ?>" alt="Friend" />
  <h2>Chat with <?= htmlspecialchars($friend['username']) ?></h2>
</header>

<div id="chat-box">
  <p>Loading messages...</p>
</div>

<div id="reply-preview">
  <div class="reply-preview-content">
    <strong>Replying to:</strong>
    <span id="reply-preview-text"></span>
  </div>
  <button id="cancel-reply">&times;</button>
</div>

<div id="typing-indicator"></div>

<div class="chat-input-container">
  <input type="hidden" id="reply-to" name="reply_to" value="">
  <button class="chat-attachment-btn" id="attachment-btn" title="Attach file">📎</button>
  <input type="file" id="file-input" multiple style="display: none;" accept="image/*,video/*,.pdf,.doc,.docx,.txt">
  <textarea id="message-input" class="chat-input" name="message" rows="1" placeholder="Type a message..." required></textarea>
  <button id="chat-send-btn" class="chat-send-btn" title="Send">➤</button>
</div>

<div id="attachment-modal">
  <button class="modal-close">&times;</button>
  <div id="attachment-modal-content"></div>
</div>

<script>
const currentUserId = <?= json_encode($currentUserId) ?>;
const friendId = <?= json_encode($friendId) ?>;
const chatBox = document.getElementById('chat-box');
const typingIndicator = document.getElementById('typing-indicator');
const messageInput = document.getElementById('message-input');
const replyToInput = document.getElementById('reply-to');
const replyPreview = document.getElementById('reply-preview');
const replyPreviewText = document.getElementById('reply-preview-text');
const cancelReplyBtn = document.getElementById('cancel-reply');
const chatSendBtn = document.getElementById('chat-send-btn');
const attachmentBtn = document.getElementById('attachment-btn');
const fileInput = document.getElementById('file-input');
const attachmentModal = document.getElementById('attachment-modal');
const attachmentModalContent = document.getElementById('attachment-modal-content');
const modalClose = document.querySelector('.modal-close');

let typingTimeout = null;
let replyingToMessageId = null;
let messageCache = {};
let selectedFiles = [];
let lastMessageId = 0;
let isScrolledToBottom = true;

function fetchMessages() {
  fetch(`fetch_messages.php?friend_id=${friendId}&last_id=${lastMessageId}`)
    .then(resp => resp.json())
    .then(data => {
      if (!data.success) {
        console.error('Error loading messages:', data.message);
        return;
      }
      
      if (data.messages.length === 0) {
        // No new messages
        return;
      }

      // Store current scroll position
      const wasScrolledToBottom = isChatScrolledToBottom();
      
      // Add new messages without clearing the chat
      data.messages.forEach(msg => {
        // Skip if message already exists
        if (messageCache[msg.id]) return;
        
        messageCache[msg.id] = msg;
        lastMessageId = Math.max(lastMessageId, msg.id);
        
        const container = createMessageElement(msg);
        chatBox.appendChild(container);
      });

      // Restore scroll position
      if (wasScrolledToBottom) {
        chatBox.scrollTop = chatBox.scrollHeight;
      }
    })
    .catch(error => {
      console.error('Error fetching messages:', error);
    });
}

function isChatScrolledToBottom() {
  const threshold = 50; // pixels from bottom
  return chatBox.scrollTop + chatBox.clientHeight >= chatBox.scrollHeight - threshold;
}

function createMessageElement(msg) {
  const container = document.createElement('div');
  container.classList.add('message-container');
  container.dataset.messageId = msg.id;

  // Determine message class to style sent or received
  let msgClass = (msg.sender_id == currentUserId) ? 'sent' : 'received';

  // Build replied-to bubble if reply_to set
  let replyHtml = '';
  if (msg.reply_to_id && messageCache[msg.reply_to_id]) {
    let rm = messageCache[msg.reply_to_id];
    let replyAuthor = rm.sender_id == currentUserId ? 'You' : rm.sender_username;
    replyHtml = `
      <div class="reply-bubble">
        <div class="reply-username">${replyAuthor}</div>
        <div class="reply-text">${escapeHtml(rm.message)}</div>
      </div>
    `;
  }

  // Format timestamp
  const timestamp = formatTimestamp(msg.created_at);

  // Build message content based on type
  let messageContent = '';
  if (msg.attachment_url) {
    const fileExt = msg.attachment_url.split('.').pop().toLowerCase();
    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExt);
    const isVideo = ['mp4', 'webm', 'ogg'].includes(fileExt);
    
    if (isImage) {
      messageContent = `
        <div class="attachment-preview">
          <img src="${msg.attachment_url}" alt="Shared image" onclick="openAttachmentModal('${msg.attachment_url}', 'image')">
          <button class="download-btn" onclick="downloadFile('${msg.attachment_url}', '${msg.attachment_name || 'image.' + fileExt}')" title="Download">⬇️</button>
        </div>
        <div class="timestamp">${timestamp}</div>
      `;
    } else if (isVideo) {
      messageContent = `
        <div class="attachment-preview">
          <video controls onclick="openAttachmentModal('${msg.attachment_url}', 'video')">
            <source src="${msg.attachment_url}" type="video/${fileExt}">
            Your browser does not support the video tag.
          </video>
          <button class="download-btn" onclick="downloadFile('${msg.attachment_url}', '${msg.attachment_name || 'video.' + fileExt}')" title="Download">⬇️</button>
        </div>
        <div class="timestamp">${timestamp}</div>
      `;
    } else {
      // For other file types
      messageContent = `
        <div class="file-attachment">
          <div class="file-icon">📄</div>
          <div class="file-info">
            <div class="file-name">${escapeHtml(msg.attachment_name || 'Download file')}</div>
            <div class="file-size">${formatFileSize(msg.attachment_size)}</div>
          </div>
          <button class="download-btn" onclick="downloadFile('${msg.attachment_url}', '${msg.attachment_name || 'file.' + fileExt}')" title="Download">⬇️</button>
        </div>
        <div class="timestamp">${timestamp}</div>
      `;
    }
  } else {
    messageContent = `
      <div class="message-content">
        <div class="message-text">${escapeHtml(msg.message).replace(/\n/g, '<br>')}</div>
        <div class="timestamp">${timestamp}</div>
      </div>
    `;
  }

  container.innerHTML = `
    <div class="message ${msgClass}">
      ${replyHtml}
      ${messageContent}
      <svg class="reply-icon" title="Reply" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.8 11 5.1-1-5-4-10-11-11z"/>
      </svg>
      ${msg.sender_id == currentUserId && msg.read_at ? '<span class="read-receipt">✓✓</span>' : ''}
    </div>
  `;

  // Add reply event listener
  const replyIcon = container.querySelector('.reply-icon');
  replyIcon.addEventListener('click', () => {
    const authorName = msg.sender_id == currentUserId ? 'yourself' : msg.sender_username;
    setReplyMessage(msg.id, msg.message, authorName);
  });

  return container;
}

function formatTimestamp(timestamp) {
  const date = new Date(timestamp);
  const now = new Date();
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  
  if (diffMins < 1) {
    return 'Just now';
  } else if (diffMins < 60) {
    return `${diffMins}m ago`;
  } else if (diffHours < 24) {
    return `${diffHours}h ago`;
  } else {
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
  }
}

function downloadFile(url, filename) {
  // Create a temporary anchor element to trigger download
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.target = '_blank';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

function loadInitialMessages() {
  fetch(`fetch_messages.php?friend_id=${friendId}`)
    .then(resp => resp.json())
    .then(data => {
      if (!data.success) {
        chatBox.innerHTML = '<p>Error loading messages.</p>';
        return;
      }
      if (data.messages.length === 0) {
        chatBox.innerHTML = '<p>No messages yet. Say hello!</p>';
        return;
      }

      chatBox.innerHTML = '';
      data.messages.forEach(msg => {
        messageCache[msg.id] = msg;
        lastMessageId = Math.max(lastMessageId, msg.id);
        
        const container = createMessageElement(msg);
        chatBox.appendChild(container);
      });
      chatBox.scrollTop = chatBox.scrollHeight;
    })
    .catch(() => {
      chatBox.innerHTML = '<p>Error loading messages.</p>';
    });
}

function setReplyMessage(messageId, messageText, authorName) {
  replyingToMessageId = messageId;
  replyToInput.value = messageId;
  replyPreviewText.textContent = `${escapeHtml(messageText).substring(0, 50)}${messageText.length > 50 ? '...' : ''}`;
  replyPreview.style.display = 'block';
  messageInput.focus();
}

function cancelReply() {
  replyingToMessageId = null;
  replyToInput.value = '';
  replyPreview.style.display = 'none';
}

function sendMessage() {
  const message = messageInput.value.trim();
  
  if (!message && selectedFiles.length === 0) return;

  const formData = new FormData();
  formData.append('friend_id', friendId);
  formData.append('message', message);
  
  // Add files if any
  selectedFiles.forEach(file => {
    formData.append('attachments[]', file);
  });
  
  // Add reply_to if we're replying to a message
  if (replyingToMessageId) {
    formData.append('reply_to', replyingToMessageId);
  }

  chatSendBtn.disabled = true;
  messageInput.disabled = true;

  fetch('send_message.php', {
    method: 'POST',
    body: formData,
    credentials: 'same-origin'
  }).then(res => res.json())
    .then(data => {
      if (data.success) {
        messageInput.value = '';
        updateTyping(false);
        cancelReply();
        selectedFiles = [];
        // Force fetch new messages on next poll
        lastMessageId = 0;
      } else {
        alert('Failed to send message: ' + (data.message || 'Unknown error'));
      }
      chatSendBtn.disabled = false;
      messageInput.disabled = false;
    })
    .catch(() => {
      alert('Error sending message');
      chatSendBtn.disabled = false;
      messageInput.disabled = false;
    });
}

function updateTyping(isTyping) {
  const formData = new FormData();
  formData.append('friend_id', friendId);
  formData.append('is_typing', isTyping ? '1' : '0');

  fetch('typing_status.php', {
    method: 'POST',
    body: formData,
    credentials: 'same-origin'
  });
}

function fetchTypingStatus() {
  fetch(`typing_status.php?friend_id=${friendId}`)
    .then(res => res.json())
    .then(data => {
      if (data.is_typing) {
        typingIndicator.textContent = `${data.username} is typing...`;
      } else {
        typingIndicator.textContent = '';
      }
    });
}

function openAttachmentModal(url, type) {
  if (type === 'image') {
    attachmentModalContent.innerHTML = `<img src="${url}" alt="Full size image">`;
  } else if (type === 'video') {
    attachmentModalContent.innerHTML = `
      <video controls autoplay>
        <source src="${url}">
        Your browser does not support the video tag.
      </video>
    `;
  }
  attachmentModal.style.display = 'flex';
}

function formatFileSize(bytes) {
  if (!bytes) return 'Unknown size';
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1048576).toFixed(1) + ' MB';
}

// Event listeners
attachmentBtn.addEventListener('click', () => {
  fileInput.click();
});

fileInput.addEventListener('change', (e) => {
  selectedFiles = Array.from(e.target.files);
  if (selectedFiles.length > 0) {
    sendMessage();
  }
});

modalClose.addEventListener('click', () => {
  attachmentModal.style.display = 'none';
});

messageInput.addEventListener('input', () => {
  updateTyping(true);
  clearTimeout(typingTimeout);
  typingTimeout = setTimeout(() => {
    updateTyping(false);
  }, 2000);
});

chatSendBtn.addEventListener('click', sendMessage);

messageInput.addEventListener('keypress', e => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
});

cancelReplyBtn.addEventListener('click', cancelReply);

// Track scroll position
chatBox.addEventListener('scroll', () => {
  isScrolledToBottom = isChatScrolledToBottom();
});

// Escape HTML for safe display
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Poll messages and typing status every 2 seconds for "real-time"
setInterval(() => {
  fetchMessages();
  fetchTypingStatus();
}, 2000);

window.onload = loadInitialMessages;

// Add these variables at the top with your other declarations
let readReceiptInterval = null;
let unreadMessageIds = [];

// Add this function to check for read receipts
function checkReadReceipts() {
    if (unreadMessageIds.length === 0) return;
    
    fetch('check_read_receipts.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            message_ids: unreadMessageIds,
            friend_id: friendId
        }),
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.read_messages && data.read_messages.length > 0) {
            // Update read status for messages that have been read
            data.read_messages.forEach(messageId => {
                const messageElement = document.querySelector(`.message-container[data-message-id="${messageId}"]`);
                if (messageElement) {
                    const readReceipt = messageElement.querySelector('.read-receipt');
                    if (!readReceipt) {
                        const timestampElement = messageElement.querySelector('.timestamp');
                        if (timestampElement) {
                            timestampElement.innerHTML += ' <span class="read-receipt">✓✓</span>';
                        }
                    }
                }
                
                // Remove from unread array
                unreadMessageIds = unreadMessageIds.filter(id => id !== messageId);
            });
        }
    })
    .catch(error => {
        console.error('Error checking read receipts:', error);
    });
}

// Add this function to mark messages as unread (call when sending a message)
function addToUnreadMessages(messageId) {
    if (messageId && !unreadMessageIds.includes(messageId)) {
        unreadMessageIds.push(messageId);
    }
}

// Modify the sendMessage function to handle the response and track unread messages
function sendMessage() {
    const message = messageInput.value.trim();
    
    if (!message && selectedFiles.length === 0) return;

    const formData = new FormData();
    formData.append('friend_id', friendId);
    formData.append('message', message);
    
    // Add files if any
    selectedFiles.forEach(file => {
        formData.append('attachments[]', file);
    });
    
    // Add reply_to if we're replying to a message
    if (replyingToMessageId) {
        formData.append('reply_to', replyingToMessageId);
    }

    chatSendBtn.disabled = true;
    messageInput.disabled = true;

    fetch('send_message.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    }).then(res => res.json())
        .then(data => {
            if (data.success) {
                messageInput.value = '';
                updateTyping(false);
                cancelReply();
                selectedFiles = [];
                
                // Add the sent message to unread tracking
                if (data.message_id) {
                    addToUnreadMessages(data.message_id);
                }
                
                // Force fetch new messages on next poll
                lastMessageId = 0;
            } else {
                alert('Failed to send message: ' + (data.message || 'Unknown error'));
            }
            chatSendBtn.disabled = false;
            messageInput.disabled = false;
        })
        .catch(() => {
            alert('Error sending message');
            chatSendBtn.disabled = false;
            messageInput.disabled = false;
        });
}

// Modify the createMessageElement function to show read receipts for sent messages
function createMessageElement(msg) {
    const container = document.createElement('div');
    container.classList.add('message-container');
    container.dataset.messageId = msg.id;

    // Determine message class to style sent or received
    let msgClass = (msg.sender_id == currentUserId) ? 'sent' : 'received';

    // Build replied-to bubble if reply_to set
    let replyHtml = '';
    if (msg.reply_to_id && messageCache[msg.reply_to_id]) {
        let rm = messageCache[msg.reply_to_id];
        let replyAuthor = rm.sender_id == currentUserId ? 'You' : rm.sender_username;
        replyHtml = `
            <div class="reply-bubble">
                <div class="reply-username">${replyAuthor}</div>
                <div class="reply-text">${escapeHtml(rm.message)}</div>
            </div>
        `;
    }

    // Format timestamp
    const timestamp = formatTimestamp(msg.created_at);
    
    // Add read receipt if message is sent by current user and has been read
    const readReceiptHtml = (msg.sender_id == currentUserId && msg.read_at) 
        ? ' <span class="read-receipt">✓✓</span>' 
        : '';

    // Track unread sent messages
    if (msg.sender_id == currentUserId && !msg.read_at) {
        addToUnreadMessages(msg.id);
    }

    // Build message content based on type
    let messageContent = '';
    if (msg.attachment_url) {
        const fileExt = msg.attachment_url.split('.').pop().toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExt);
        const isVideo = ['mp4', 'webm', 'ogg'].includes(fileExt);
        
        if (isImage) {
            messageContent = `
                <div class="attachment-preview">
                    <img src="${msg.attachment_url}" alt="Shared image" onclick="openAttachmentModal('${msg.attachment_url}', 'image')">
                    <button class="download-btn" onclick="downloadFile('${msg.attachment_url}', '${msg.attachment_name || 'image.' + fileExt}')" title="Download">⬇️</button>
                </div>
                <div class="timestamp">${timestamp}${readReceiptHtml}</div>
            `;
        } else if (isVideo) {
            messageContent = `
                <div class="attachment-preview">
                    <video controls onclick="openAttachmentModal('${msg.attachment_url}', 'video')">
                        <source src="${msg.attachment_url}" type="video/${fileExt}">
                        Your browser does not support the video tag.
                    </video>
                    <button class="download-btn" onclick="downloadFile('${msg.attachment_url}', '${msg.attachment_name || 'video.' + fileExt}')" title="Download">⬇️</button>
                </div>
                <div class="timestamp">${timestamp}${readReceiptHtml}</div>
            `;
        } else {
            // For other file types
            messageContent = `
                <div class="file-attachment">
                    <div class="file-icon">📄</div>
                    <div class="file-info">
                        <div class="file-name">${escapeHtml(msg.attachment_name || 'Download file')}</div>
                        <div class="file-size">${formatFileSize(msg.attachment_size)}</div>
                    </div>
                    <button class="download-btn" onclick="downloadFile('${msg.attachment_url}', '${msg.attachment_name || 'file.' + fileExt}')" title="Download">⬇️</button>
                </div>
                <div class="timestamp">${timestamp}${readReceiptHtml}</div>
            `;
        }
    } else {
        messageContent = `
            <div class="message-content">
                <div class="message-text">${escapeHtml(msg.message).replace(/\n/g, '<br>')}</div>
                <div class="timestamp">${timestamp}${readReceiptHtml}</div>
            </div>
        `;
    }

    container.innerHTML = `
        <div class="message ${msgClass}">
            ${replyHtml}
            ${messageContent}
            <svg class="reply-icon" title="Reply" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                <path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.8 11 5.1-1-5-4-10-11-11z"/>
            </svg>
        </div>
    `;

    // Add reply event listener
    const replyIcon = container.querySelector('.reply-icon');
    replyIcon.addEventListener('click', () => {
        const authorName = msg.sender_id == currentUserId ? 'yourself' : msg.sender_username;
        setReplyMessage(msg.id, msg.message, authorName);
    });

    return container;
}

// Add this CSS for read receipts
const readReceiptStyle = `
    .read-receipt {
        color: #4fc3f7;
        font-size: 12px;
        margin-left: 5px;
    }
`;
const styleSheet = document.createElement('style');
styleSheet.textContent = readReceiptStyle;
document.head.appendChild(styleSheet);

// Start checking for read receipts periodically
readReceiptInterval = setInterval(checkReadReceipts, 3000); // Check every 3 seconds

// Don't forget to clear the interval when needed (optional)
// window.addEventListener('beforeunload', () => {
//     if (readReceiptInterval) {
//         clearInterval(readReceiptInterval);
//     }
// });
</script>

</body>
</html>