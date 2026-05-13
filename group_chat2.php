<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

$userId = $_SESSION['user_id'];

$host = 'localhost'; $port = '5432'; $dbname = 'fbclone'; $dbUser = 'postgres'; $dbPass = 'Gi12,br12';
try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Connection Failed: " . $e->getMessage());
}

$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
if ($groupId <= 0) die("Invalid group ID.");

// Get group info
$stmt = $pdo->prepare("SELECT g.name, g.description FROM groups g WHERE g.id = :group_id");
$stmt->execute(['group_id' => $groupId]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$group) die("Group not found.");

// Check if user is approved member
$stmt = $pdo->prepare("SELECT status FROM group_members WHERE user_id=:user_id AND group_id=:group_id");
$stmt->execute(['user_id' => $userId, 'group_id' => $groupId]);
$status = $stmt->fetchColumn();

if ($status !== 'approved') die("Unauthorized access.");

require_once "back.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars($group['name']) ?> - Group Chat</title>
<style>
 * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    max-width: 800px;
    margin: 0 auto;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    flex-direction: column;
    height: 100vh;
    padding: 0 10px;
    color: #333;
}

header {
    display: flex;
    align-items: center;
    padding: 15px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    color: #7b68ee;
    border-radius: 15px;
    margin-top: 60px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    border: 2px solid #7b68ee;
}

header h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
}

.chat-container {
    flex-grow: 1;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    overflow-y: auto;
    padding: 15px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    margin: 15px 0;
    border: 2px solid #7b68ee;
}

.message {
    max-width: 80%;
    margin: 10px 0;
    padding: 12px 16px;
    border-radius: 18px;
    position: relative;
    clear: both;
    font-size: 15px;
    line-height: 1.3em;
    transition: all 0.3s ease;
}

.message:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.message.sent {
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    float: right;
    border-bottom-right-radius: 4px;
    color: white;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.message.received {
    background: rgba(255, 255, 255, 0.9);
    float: left;
    border-bottom-left-radius: 4px;
    color: #2d3748;
    border: 1px solid rgba(123, 104, 238, 0.2);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.username {
    font-weight: bold;
    font-size: 13px;
    color: #7b68ee;
    margin-bottom: 3px;
    font-weight: 600;
}

.timestamp {
    font-size: 10px;
    color: #718096;
    margin-top: 5px;
    text-align: right;
    clear: both;
}

.message.sent .timestamp {
    text-align: right;
    color: rgba(255, 255, 255, 0.8);
}

.message.received .timestamp {
    text-align: left;
}

.reply-bubble {
    background-color: rgba(123, 104, 238, 0.1);
    border-radius: 12px;
    font-size: 12px;
    padding: 8px 12px;
    margin-bottom: 8px;
    color: #2d3748;
    max-width: 70%;
    overflow-wrap: break-word;
    border-left: 3px solid #7b68ee;
}

.reply-username {
    font-weight: 600;
    color: #7b68ee;
    margin-bottom: 2px;
}

.chat-input-container {
    margin-top: 10px;
    display: flex;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 12px;
    border-radius: 15px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    border: 2px solid #7b68ee;
}

.chat-input {
    flex-grow: 1;
    border-radius: 20px;
    border: 2px solid #7b68ee;
    padding: 12px 18px;
    font-size: 16px;
    resize: none;
    background: rgba(255, 255, 255, 0.9);
    color: #2d3748;
    transition: all 0.3s ease;
    font-family: inherit;
}

.chat-input:focus {
    outline: none;
    border-color: #6a5acd;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
    transform: translateY(-2px);
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
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
    font-weight: bold;
}

.chat-send-btn:hover {
    background: linear-gradient(135deg, #6a5acd, #5a4abc);
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
}

.chat-attachment-btn {
    margin-right: 10px;
    background: rgba(123, 104, 238, 0.1);
    border: 2px solid #7b68ee;
    border-radius: 50%;
    width: 48px;
    height: 48px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    color: #7b68ee;
    font-weight: bold;
}

.chat-attachment-btn:hover {
    background: rgba(123, 104, 238, 0.2);
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.chat-audio-btn {
    margin-right: 5px;
    background: rgba(123, 104, 238, 0.1);
    border: 2px solid #7b68ee;
    border-radius: 50%;
    width: 48px;
    height: 48px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    color: #7b68ee;
    font-weight: bold;
}

.chat-audio-btn:hover {
    background: rgba(123, 104, 238, 0.2);
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.reply-icon {
    position: absolute;
    bottom: 2px;
    left: 10px;
    width: 16px;
    height: 16px;
    cursor: pointer;
    fill: #7b68ee;
    transition: all 0.3s ease;
}

.reply-icon:hover {
    transform: scale(1.2);
    fill: #6a5acd;
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
    margin-bottom: 10px;
    border-radius: 12px;
    font-size: 14px;
    position: relative;
    color: #2d3748;
    backdrop-filter: blur(10px);
}

#cancel-reply {
    background: none;
    border: none;
    color: #718096;
    cursor: pointer;
    font-size: 18px;
    position: absolute;
    right: 8px;
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
    margin-right: 25px;
}

.attachment-preview {
    margin-bottom: 10px;
    max-width: 100%;
    border-radius: 12px;
    overflow: hidden;
    position: relative;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.attachment-preview img, 
.attachment-preview video {
    max-width: 100%;
    max-height: 200px;
    border-radius: 12px;
    display: block;
    transition: transform 0.3s ease;
}

.attachment-preview img:hover, 
.attachment-preview video:hover {
    transform: scale(1.02);
}

.download-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    background: rgba(0, 0, 0, 0.7);
    color: white;
    border: none;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.download-btn:hover {
    background: rgba(0, 0, 0, 0.9);
    transform: scale(1.1);
}

.file-attachment {
    display: flex;
    align-items: center;
    padding: 12px;
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
}

.audio-attachment {
    display: flex;
    align-items: center;
    padding: 12px;
    background: rgba(123, 104, 238, 0.1);
    border-radius: 12px;
    margin-bottom: 8px;
    border: 1px solid rgba(123, 104, 238, 0.2);
    transition: all 0.3s ease;
}

.audio-attachment:hover {
    background: rgba(123, 104, 238, 0.15);
    transform: translateY(-2px);
}

.file-icon {
    font-size: 24px;
    margin-right: 12px;
    color: #7b68ee;
}

.file-info {
    flex-grow: 1;
}

.file-name {
    font-weight: bold;
    font-size: 14px;
    color: #2d3748;
    font-weight: 600;
}

.file-size {
    font-size: 12px;
    color: #718096;
}

#attachment-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.9);
    z-index: 1000;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(10px);
}

#attachment-modal-content {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    padding: 20px;
    border-radius: 15px;
    max-width: 90%;
    max-height: 90%;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    border: 2px solid #7b68ee;
}

#attachment-modal img,
#attachment-modal video {
    max-width: 100%;
    max-height: 80vh;
    border-radius: 8px;
}

.modal-close {
    position: absolute;
    top: 20px;
    right: 20px;
    background: rgba(0, 0, 0, 0.7);
    color: white;
    border: none;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.modal-close:hover {
    background: rgba(0, 0, 0, 0.9);
    transform: scale(1.1);
}

.message-content {
    position: relative;
}

.read-receipt {
    color: #4fc3f7;
    font-size: 12px;
    margin-left: 5px;
    font-weight: bold;
}

#typing-indicator {
    min-height: 20px;
    font-style: italic;
    color: #718096;
    padding: 8px 12px;
    background: rgba(123, 104, 238, 0.1);
    border-radius: 12px;
    margin: 5px 0;
    backdrop-filter: blur(10px);
}

#audio-recorder {
    display: none;
    margin-top: 10px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 15px;
    border-radius: 15px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    border: 2px solid #7b68ee;
}

.audio-controls {
    display: flex;
    align-items: center;
    gap: 12px;
}

.record-btn {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    border: none;
    border-radius: 50%;
    width: 44px;
    height: 44px;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(255, 107, 107, 0.3);
    font-weight: bold;
}

.record-btn:hover {
    background: linear-gradient(135deg, #ee5a52, #dd4941);
    transform: scale(1.05);
}

.stop-record-btn {
    background: linear-gradient(135deg, #007bff, #0056b3);
    border: none;
    border-radius: 50%;
    width: 44px;
    height: 44px;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
    font-weight: bold;
}

.stop-record-btn:hover {
    background: linear-gradient(135deg, #0056b3, #004085);
    transform: scale(1.05);
}

.audio-timer {
    font-size: 14px;
    color: #2d3748;
    font-weight: 600;
    min-width: 50px;
}

.audio-visualizer {
    height: 30px;
    flex-grow: 1;
    background: rgba(123, 104, 238, 0.1);
    border-radius: 15px;
    overflow: hidden;
    position: relative;
    border: 1px solid rgba(123, 104, 238, 0.2);
}

.audio-wave {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #7b68ee, #6a5acd);
    transform-origin: left;
    transform: scaleX(0);
    transition: transform 0.1s ease;
    border-radius: 15px;
}

/* Animation for new messages */
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

.message-container {
    animation: fadeInUp 0.3s ease-out;
}

/* Scrollbar styling */
.chat-container::-webkit-scrollbar {
    width: 6px;
}

.chat-container::-webkit-scrollbar-track {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 3px;
}

.chat-container::-webkit-scrollbar-thumb {
    background: #7b68ee;
    border-radius: 3px;
}

.chat-container::-webkit-scrollbar-thumb:hover {
    background: #6a5acd;
}

/* Responsive design */
@media (max-width: 600px) {
    body {
        padding: 0 8px;
    }
    
    header {
        margin-top: 50px;
        padding: 12px;
        border-radius: 12px;
    }
    
    .chat-container {
        padding: 12px;
        border-radius: 12px;
        margin: 12px 0;
    }
    
    .chat-input-container {
        padding: 10px;
        border-radius: 12px;
    }
    
    .message {
        max-width: 90%;
        padding: 10px 14px;
    }
    
    .chat-send-btn,
    .chat-attachment-btn,
    .chat-audio-btn {
        width: 44px;
        height: 44px;
    }
    
    .audio-recorder {
        padding: 12px;
        border-radius: 12px;
    }
    
    .record-btn,
    .stop-record-btn {
        width: 40px;
        height: 40px;
    }
}

@media (max-width: 480px) {
    .message {
        max-width: 95%;
        font-size: 14px;
    }
    
    .chat-input {
        padding: 10px 14px;
        font-size: 14px;
    }
    
    .file-attachment,
    .audio-attachment {
        padding: 10px;
    }
    
    .file-icon {
        font-size: 20px;
        margin-right: 8px;
    }
    
    .attachment-preview img,
    .attachment-preview video {
        max-height: 150px;
    }
}

/* Loading animation */
.chat-container p {
    text-align: center;
    color: #718096;
    font-style: italic;
    padding: 20px;
}

/* Empty state styling */
.chat-container:empty::before {
    content: "No messages yet. Start the conversation!";
    display: block;
    text-align: center;
    color: #718096;
    font-style: italic;
    padding: 40px 20px;
}
</style>
</head>
<body>

<header>
  <h2><?= htmlspecialchars($group['name']) ?></h2>
</header>

<div id="chatMessages" class="chat-container">
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

<div id="audio-recorder">
  <div class="audio-controls">
    <button class="record-btn" id="start-record">●</button>
    <button class="stop-record-btn" id="stop-record" disabled>■</button>
    <div class="audio-timer" id="audio-timer">00:00</div>
    <div class="audio-visualizer">
      <div class="audio-wave" id="audio-wave"></div>
    </div>
  </div>
</div>

<div class="chat-input-container">
  <input type="hidden" id="reply-to" name="reply_to" value="">
  <button class="chat-audio-btn" id="audio-btn" title="Record audio">🎤</button>
  <button class="chat-attachment-btn" id="attachment-btn" title="Attach file">📎</button>
  <input type="file" id="file-input" multiple style="display: none;" accept="image/*,video/*,.pdf,.doc,.docx,.txt,audio/*">
  <textarea id="chatInput" class="chat-input" name="message" rows="1" placeholder="Type a message..." required></textarea>
  <button id="chatSendButton" class="chat-send-btn" title="Send">➤</button>
</div>

<div id="attachment-modal">
  <button class="modal-close">&times;</button>
  <div id="attachment-modal-content"></div>
</div>

<script>
const currentUserId = <?= json_encode($userId) ?>;
const groupId = <?= json_encode($groupId) ?>;
const chatMessages = document.getElementById('chatMessages');
const typingIndicator = document.getElementById('typing-indicator');
const messageInput = document.getElementById('chatInput');
const replyToInput = document.getElementById('reply-to');
const replyPreview = document.getElementById('reply-preview');
const replyPreviewText = document.getElementById('reply-preview-text');
const cancelReplyBtn = document.getElementById('cancel-reply');
const chatSendBtn = document.getElementById('chatSendButton');
const attachmentBtn = document.getElementById('attachment-btn');
const audioBtn = document.getElementById('audio-btn');
const fileInput = document.getElementById('file-input');
const attachmentModal = document.getElementById('attachment-modal');
const attachmentModalContent = document.getElementById('attachment-modal-content');
const modalClose = document.querySelector('.modal-close');
const audioRecorder = document.getElementById('audio-recorder');
const startRecordBtn = document.getElementById('start-record');
const stopRecordBtn = document.getElementById('stop-record');
const audioTimer = document.getElementById('audio-timer');
const audioWave = document.getElementById('audio-wave');

let typingTimeout = null;
let replyingToMessageId = null;
let messageCache = {};
let selectedFiles = [];
let lastMessageId = 0;
let isScrolledToBottom = true;
let readReceiptInterval = null;
let unreadMessageIds = [];
let mediaRecorder = null;
let audioChunks = [];
let recordingTimer = null;
let recordingSeconds = 0;
let audioContext = null;
let analyser = null;
let microphone = null;
let javascriptNode = null;

// Check if browser supports audio recording
const isAudioRecordingSupported = () => {
    return navigator.mediaDevices && navigator.mediaDevices.getUserMedia && 
           (window.AudioContext || window.webkitAudioContext);
};

// Initialize the audio recorder
function initAudioRecorder() {
    if (!isAudioRecordingSupported()) {
        audioBtn.style.display = 'none';
        return;
    }
    
    audioBtn.addEventListener('click', toggleAudioRecorder);
    startRecordBtn.addEventListener('click', startRecording);
    stopRecordBtn.addEventListener('click', stopRecording);
}

function toggleAudioRecorder() {
    if (audioRecorder.style.display === 'block') {
        audioRecorder.style.display = 'none';
    } else {
        audioRecorder.style.display = 'block';
        // Hide any other open UI elements
        replyPreview.style.display = 'none';
    }
}

async function startRecording() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        audioChunks = [];
        
        // Setup audio context for visualization
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        analyser = audioContext.createAnalyser();
        microphone = audioContext.createMediaStreamSource(stream);
        javascriptNode = audioContext.createScriptProcessor(2048, 1, 1);
        
        microphone.connect(analyser);
        analyser.connect(javascriptNode);
        javascriptNode.connect(audioContext.destination);
        
        analyser.fftSize = 1024;
        const bufferLength = analyser.frequencyBinCount;
        const dataArray = new Uint8Array(bufferLength);
        
        javascriptNode.onaudioprocess = function() {
            analyser.getByteTimeDomainData(dataArray);
            
            // Calculate average volume
            let sum = 0;
            for (let i = 0; i < bufferLength; i++) {
                sum += dataArray[i];
            }
            const average = sum / bufferLength;
            
            // Update visualizer
            const scale = average / 128;
            audioWave.style.transform = `scaleX(${scale})`;
        };
        
        // Setup media recorder
        mediaRecorder = new MediaRecorder(stream);
        
        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                audioChunks.push(event.data);
            }
        };
        
        mediaRecorder.onstop = () => {
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            sendAudioMessage(audioBlob);
            
            // Clean up
            stream.getTracks().forEach(track => track.stop());
            if (audioContext) {
                audioContext.close();
            }
        };
        
        // Start recording
        mediaRecorder.start();
        startRecordBtn.disabled = true;
        stopRecordBtn.disabled = false;
        
        // Start timer
        recordingSeconds = 0;
        recordingTimer = setInterval(() => {
            recordingSeconds++;
            const minutes = Math.floor(recordingSeconds / 60);
            const seconds = recordingSeconds % 60;
            audioTimer.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }, 1000);
        
    } catch (error) {
        console.error('Error starting audio recording:', error);
        alert('Unable to access microphone. Please check your permissions.');
    }
}

function stopRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
        clearInterval(recordingTimer);
        startRecordBtn.disabled = false;
        stopRecordBtn.disabled = true;
        audioTimer.textContent = '00:00';
        audioWave.style.transform = 'scaleX(0)';
        audioRecorder.style.display = 'none';
    }
}

function sendAudioMessage(audioBlob) {
    const formData = new FormData();
    formData.append('group_id', groupId);
    formData.append('audio', audioBlob, 'audio-message.webm');
    
    if (replyingToMessageId) {
        formData.append('reply_to', replyingToMessageId);
    }

    chatSendBtn.disabled = true;

    fetch('group_chat_send_audio.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    }).then(res => res.json())
        .then(data => {
            if (data.success) {
                cancelReply();
                
                if (data.message_id) {
                    addToUnreadMessages(data.message_id);
                }
                
                lastMessageId = 0;
                fetchMessages();
            } else {
                alert('Failed to send audio message: ' + (data.message || 'Unknown error'));
            }
            chatSendBtn.disabled = false;
        })
        .catch(() => {
            alert('Error sending audio message');
            chatSendBtn.disabled = false;
        });
}

function fetchMessages() {
  fetch(`group_chat_fetch.php?group_id=${groupId}&last_id=${lastMessageId}`)
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
        chatMessages.appendChild(container);
      });

      // Restore scroll position
      if (wasScrolledToBottom) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
      }
    })
    .catch(error => {
      console.error('Error fetching messages:', error);
    });
}

function isChatScrolledToBottom() {
  const threshold = 50; // pixels from bottom
  return chatMessages.scrollTop + chatMessages.clientHeight >= chatMessages.scrollHeight - threshold;
}

function createMessageElement(msg) {
  const container = document.createElement('div');
  container.classList.add('message-container');
  container.dataset.messageId = msg.id;

  // Determine message class to style sent or received
  let msgClass = (msg.user_id == currentUserId) ? 'sent' : 'received';

  // Build replied-to bubble if reply_to set
  let replyHtml = '';
  if (msg.reply_to && messageCache[msg.reply_to]) {
    let rm = messageCache[msg.reply_to];
    let replyAuthor = rm.user_id == currentUserId ? 'You' : rm.username;
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
  const readReceiptHtml = (msg.user_id == currentUserId && msg.read_at) 
      ? ' <span class="read-receipt">✓✓</span>' 
      : '';

  // Track unread sent messages
  if (msg.user_id == currentUserId && !msg.read_at) {
      addToUnreadMessages(msg.id);
  }

  // Build message content based on type
  let messageContent = '';
  if (msg.attachment_url) {
    const fileExt = msg.attachment_url.split('.').pop().toLowerCase();
    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExt);
    const isVideo = ['mp4', 'webm', 'ogg'].includes(fileExt);
    const isAudio = ['mp3', 'wav', 'ogg', 'webm'].includes(fileExt);
    
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
    } else if (isAudio) {
      messageContent = `
        <div class="audio-attachment">
          <div class="file-icon">🎵</div>
          <div class="file-info">
            <div class="file-name">${escapeHtml(msg.attachment_name || 'Audio message')}</div>
            <audio controls>
              <source src="${msg.attachment_url}" type="audio/${fileExt}">
              Your browser does not support the audio element.
            </audio>
          </div>
          <button class="download-btn" onclick="downloadFile('${msg.attachment_url}', '${msg.attachment_name || 'audio.' + fileExt}')" title="Download">⬇️</button>
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
      ${msgClass === 'received' ? `<div class="username">${msg.username}</div>` : ''}
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
    const authorName = msg.user_id == currentUserId ? 'yourself' : msg.username;
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
  fetch(`group_chat_fetch.php?group_id=${groupId}`)
    .then(resp => resp.json())
    .then(data => {
      if (!data.success) {
        chatMessages.innerHTML = '<p>Error loading messages.</p>';
        return;
      }
      if (data.messages.length === 0) {
        chatMessages.innerHTML = '<p>No messages yet. Start the conversation!</p>';
        return;
      }

      chatMessages.innerHTML = '';
      data.messages.forEach(msg => {
        messageCache[msg.id] = msg;
        lastMessageId = Math.max(lastMessageId, msg.id);
        
        const container = createMessageElement(msg);
        chatMessages.appendChild(container);
      });
      chatMessages.scrollTop = chatMessages.scrollHeight;
    })
    .catch(() => {
      chatMessages.innerHTML = '<p>Error loading messages.</p>';
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
  formData.append('group_id', groupId);
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

  fetch('group_chat_send.php', {
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

function updateTyping(isTyping) {
  const formData = new FormData();
  formData.append('group_id', groupId);
  formData.append('is_typing', isTyping ? '1' : '0');

  fetch('group_typing_status.php', {
    method: 'POST',
    body: formData,
    credentials: 'same-origin'
  });
}

function fetchTypingStatus() {
  fetch(`group_typing_status.php?group_id=${groupId}`)
    .then(res => res.json())
    .then(data => {
      if (data.is_typing && data.user_id != currentUserId) {
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
  } else if (type === 'audio') {
    attachmentModalContent.innerHTML = `
      <audio controls autoplay>
        <source src="${url}">
        Your browser does not support the audio element.
      </audio>
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

function checkReadReceipts() {
  if (unreadMessageIds.length === 0) return;
  
  fetch('group_check_read_receipts.php', {
      method: 'POST',
      headers: {
          'Content-Type': 'application/json',
      },
      body: JSON.stringify({
          message_ids: unreadMessageIds,
          group_id: groupId
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

function addToUnreadMessages(messageId) {
  if (messageId && !unreadMessageIds.includes(messageId)) {
      unreadMessageIds.push(messageId);
  }
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
chatMessages.addEventListener('scroll', () => {
  isScrolledToBottom = isChatScrolledToBottom();
});

// Escape HTML for safe display
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Initialize audio recorder when page loads
document.addEventListener('DOMContentLoaded', () => {
  initAudioRecorder();
  loadInitialMessages();
  
  // Poll messages and typing status every 2 seconds for "real-time"
  setInterval(() => {
    fetchMessages();
    fetchTypingStatus();
  }, 2000);
  
  // Start checking for read receipts periodically
  readReceiptInterval = setInterval(checkReadReceipts, 3000);
});
</script>

</body>
</html>