<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

$userId = $_SESSION['user_id'];
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$createCall = isset($_GET['create']);

// Check group membership
$stmtMember = $pdo->prepare("SELECT status FROM group_members WHERE group_id = :group_id AND user_id = :user_id");
$stmtMember->execute(['group_id' => $groupId, 'user_id' => $userId]);
$isMember = $stmtMember->fetchColumn() === 'approved';

$stmtGroup = $pdo->prepare("SELECT * FROM groups WHERE id = :group_id");
$stmtGroup->execute(['group_id' => $groupId]);
$group = $stmtGroup->fetch(PDO::FETCH_ASSOC);
$isAdmin = $group && $group['creator_id'] == $userId;

if (!$group || (!$isMember && !$isAdmin)) {
    die("Access denied. You must be a member of this group.");
}

// Check settings
$stmtSettings = $pdo->prepare("SELECT only_admin_can_call FROM group_settings WHERE group_id = :group_id");
$stmtSettings->execute(['group_id' => $groupId]);
$settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);
$onlyAdminCanCall = $settings ? $settings['only_admin_can_call'] : false;

// Handle call creation
if ($createCall && $onlyAdminCanCall && !$isAdmin) {
    die("Only admin can create voice calls in this group.");
}

// Get or create active call
$stmtCall = $pdo->prepare("SELECT * FROM voice_calls WHERE group_id = :group_id AND is_active = true");
$stmtCall->execute(['group_id' => $groupId]);
$activeCall = $stmtCall->fetch(PDO::FETCH_ASSOC);

if ($createCall && !$activeCall) {
    $callToken = bin2hex(random_bytes(32));
    
    $stmtCreate = $pdo->prepare("INSERT INTO voice_calls (group_id, creator_id, call_token) VALUES (:group_id, :user_id, :token)");
    $stmtCreate->execute([
        'group_id' => $groupId, 
        'user_id' => $userId,
        'token' => $callToken
    ]);
    $activeCallId = $pdo->lastInsertId();
    
    // Add creator to participants
    $stmtParticipant = $pdo->prepare("INSERT INTO voice_call_participants (call_id, user_id) VALUES (:call_id, :user_id)");
    $stmtParticipant->execute(['call_id' => $activeCallId, 'user_id' => $userId]);
    
    header("Location: voice_call.php?group_id=$groupId");
    exit;
}

if (!$activeCall) {
    die("No active voice call found for this group.");
}

// Add user to participants if not already there
$stmtCheckParticipant = $pdo->prepare("SELECT 1 FROM voice_call_participants WHERE call_id = :call_id AND user_id = :user_id");
$stmtCheckParticipant->execute(['call_id' => $activeCall['id'], 'user_id' => $userId]);
$isParticipant = $stmtCheckParticipant->fetchColumn();

if (!$isParticipant) {
    $stmtAddParticipant = $pdo->prepare("INSERT INTO voice_call_participants (call_id, user_id) VALUES (:call_id, :user_id)");
    $stmtAddParticipant->execute(['call_id' => $activeCall['id'], 'user_id' => $userId]);
}

// Get all participants
$stmtParticipants = $pdo->prepare("
    SELECT vp.*, u.username, u.profile_pic_url, 
           CASE WHEN u.id = :admin_id THEN 'Admin' ELSE 'Member' END as role
    FROM voice_call_participants vp
    JOIN users u ON vp.user_id = u.id
    WHERE vp.call_id = :call_id
    ORDER BY vp.joined_at ASC
");
$stmtParticipants->execute(['call_id' => $activeCall['id'], 'admin_id' => $group['creator_id']]);
$participants = $stmtParticipants->fetchAll(PDO::FETCH_ASSOC);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'toggle_mute':
            $stmtMute = $pdo->prepare("UPDATE voice_call_participants SET is_muted = NOT is_muted WHERE call_id = :call_id AND user_id = :user_id RETURNING is_muted");
            $stmtMute->execute(['call_id' => $activeCall['id'], 'user_id' => $userId]);
            $newMuteStatus = $stmtMute->fetchColumn();
            
            echo json_encode(['success' => true, 'is_muted' => $newMuteStatus]);
            break;
            
        case 'leave_call':
            $stmtLeave = $pdo->prepare("DELETE FROM voice_call_participants WHERE call_id = :call_id AND user_id = :user_id");
            $stmtLeave->execute(['call_id' => $activeCall['id'], 'user_id' => $userId]);
            
            // If no participants left, end the call
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM voice_call_participants WHERE call_id = :call_id");
            $stmtCount->execute(['call_id' => $activeCall['id']]);
            $participantCount = $stmtCount->fetchColumn();
            
            if ($participantCount === 0) {
                $stmtEndCall = $pdo->prepare("UPDATE voice_calls SET is_active = false WHERE id = :call_id");
                $stmtEndCall->execute(['call_id' => $activeCall['id']]);
            }
            
            echo json_encode(['success' => true]);
            break;
            
        case 'get_ice_servers':
            $servers = [
                'iceServers' => [
                    ['urls' => 'stun:stun.l.google.com:19302'],
                    ['urls' => 'stun:stun1.l.google.com:19302'],
                    ['urls' => 'stun:stun2.l.google.com:19302']
                ]
            ];
            echo json_encode($servers);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}

// Get current user's mute status
$currentUserMuted = false;
foreach ($participants as $p) {
    if ($p['user_id'] == $userId) {
        $currentUserMuted = $p['is_muted'];
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voice Call - <?= htmlspecialchars($group['name']) ?></title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background: #128C7E; 
            color: white;
            height: 100vh;
            overflow: hidden;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            padding: 20px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 15px;
        }
        .call-container {
            display: flex;
            flex: 1;
            gap: 20px;
            height: calc(100% - 200px);
        }
        .participants-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }
        .participant-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            backdrop-filter: blur(10px);
            position: relative;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .participant-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 15px;
            border: 3px solid white;
        }
        .participant-name {
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 18px;
        }
        .participant-role {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 15px;
        }
        .audio-indicator {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #4CAF50;
            position: absolute;
            top: 15px;
            right: 15px;
            animation: pulse 2s infinite;
        }
        .audio-indicator.muted {
            background: #ff4444;
            animation: none;
        }
        .audio-indicator.connected { background: #4CAF50; }
        .audio-indicator.connecting { background: #FF9800; }
        .audio-indicator.disconnected { background: #f44336; }
        .controls {
            display: flex;
            justify-content: center;
            gap: 30px;
            padding: 20px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 20px;
            margin-top: auto;
        }
        .control-btn {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: none;
            background: #25D366;
            color: white;
            font-size: 24px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }
        .control-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 12px rgba(0,0,0,0.4);
        }
        .control-btn.mute {
            background: <?= $currentUserMuted ? '#dc3545' : '#25D366' ?>;
        }
        .control-btn.leave {
            background: #ff4444;
        }
        .control-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        .connection-status {
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 10px 15px;
            border-radius: 20px;
            font-size: 12px;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        .connection-status.connected { background: #4CAF50; }
        .connection-status.connecting { background: #FF9800; }
        .connection-status.disconnected { background: #f44336; }
        .debug-panel {
            position: fixed;
            top: 10px;
            left: 10px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 15px;
            border-radius: 10px;
            z-index: 1000;
            max-width: 400px;
            max-height: 300px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 12px;
        }
        .debug-item { margin: 5px 0; }
        .status-good { color: #4CAF50; }
        .status-warning { color: #FF9800; }
        .status-error { color: #f44336; }
        .test-buttons { margin: 10px 0; }
        .test-btn { 
            background: #007bff; 
            color: white; 
            border: none; 
            padding: 5px 10px; 
            margin: 2px; 
            border-radius: 3px; 
            cursor: pointer;
            font-size: 12px;
        }
        #audioVisualization {
            position: fixed;
            top: 10px;
            left: 450px;
            background: rgba(0,0,0,0.8);
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="connection-status connecting" id="connectionStatus">Connecting...</div>
    
    <!-- Debug Panel -->
    <div class="debug-panel">
        <h4 style="margin: 0 0 10px 0;">Voice Call Debug</h4>
        <div id="debugInfo"></div>
        <div class="test-buttons">
            <button class="test-btn" onclick="testMicrophone()">Test Mic</button>
            <button class="test-btn" onclick="testAudioPlayback()">Test Speaker</button>
            <button class="test-btn" onclick="testWebRTC()">Test WebRTC</button>
        </div>
    </div>

    <div class="container">
        <div class="header">
            <h1>🔊 Voice Call - <?= htmlspecialchars($group['name']) ?></h1>
            <p id="participantCount"><?= count($participants) ?> participant<?= count($participants) !== 1 ? 's' : '' ?> in call</p>
            <div style="font-size: 12px; opacity: 0.8;">Call started by: <?= htmlspecialchars($group['creator_id'] == $userId ? 'You' : 'Admin') ?></div>
        </div>
        
        <div class="call-container">
            <div class="participants-grid" id="participantsContainer">
                <?php foreach ($participants as $participant): ?>
                    <div class="participant-card" data-user-id="<?= $participant['user_id'] ?>">
                        <div class="audio-indicator <?= $participant['is_muted'] ? 'muted' : 'connected' ?>" 
                             id="audioIndicator_<?= $participant['user_id'] ?>"></div>
                        <img src="<?= htmlspecialchars($participant['profile_pic_url'] ?: 'default_profile.png') ?>" 
                             alt="Avatar" class="participant-avatar"
                             onerror="this.src='default_profile.png'">
                        <div class="participant-name">
                            <?= htmlspecialchars($participant['username']) ?>
                            <?= $participant['user_id'] == $userId ? ' (You)' : '' ?>
                        </div>
                        <div class="participant-role"><?= $participant['role'] ?></div>
                        <div style="font-size: 12px; opacity: 0.7;">
                            <?= $participant['is_muted'] ? '🔇 Muted' : '🎤 Speaking' ?>
                        </div>
                        <audio id="audio_<?= $participant['user_id'] ?>" autoplay playsinline></audio>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="controls">
            <button class="control-btn mute" id="muteBtn" title="<?= $currentUserMuted ? 'Unmute' : 'Mute' ?>">
                <?= $currentUserMuted ? '🎤' : '🔇' ?>
            </button>
            <button class="control-btn" id="speakerBtn" title="Toggle Speaker">
                🔈
            </button>
            <button class="control-btn leave" id="leaveBtn" title="Leave Call">
                📞
            </button>
        </div>
    </div>

    <script>
        // Diagnostic functions
        function updateDebugInfo(type, message) {
            const debugInfo = document.getElementById('debugInfo');
            const timestamp = new Date().toLocaleTimeString();
            const div = document.createElement('div');
            div.className = `debug-item status-${type}`;
            div.innerHTML = `[${timestamp}] ${message}`;
            debugInfo.appendChild(div);
            debugInfo.scrollTop = debugInfo.scrollHeight;
        }

        async function testMicrophone() {
            updateDebugInfo('warning', 'Testing microphone...');
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                updateDebugInfo('good', 'Microphone working! Permission granted.');
                stream.getTracks().forEach(track => track.stop());
            } catch (error) {
                updateDebugInfo('error', `Microphone error: ${error.message}`);
            }
        }

        function testAudioPlayback() {
            updateDebugInfo('warning', 'Testing audio playback...');
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            oscillator.frequency.value = 440;
            gainNode.gain.value = 0.1;
            oscillator.start();
            
            setTimeout(() => {
                oscillator.stop();
                updateDebugInfo('good', 'Audio playback working! You should hear a beep.');
            }, 500);
        }

        async function testWebRTC() {
            updateDebugInfo('warning', 'Testing WebRTC connectivity...');
            try {
                const pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
                pc.onicecandidate = (e) => e.candidate && updateDebugInfo('good', 'WebRTC working! ICE candidate generated.');
                pc.createDataChannel('test');
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                setTimeout(() => pc.close(), 3000);
            } catch (error) {
                updateDebugInfo('error', `WebRTC error: ${error.message}`);
            }
        }

        // Main VoiceCall class
        class VoiceCall {
            constructor() {
                this.localStream = null;
                this.peerConnections = {};
                this.localUserId = <?= $userId ?>;
                this.callId = <?= $activeCall['id'] ?>;
                this.participants = <?= json_encode($participants) ?>;
                this.isMuted = <?= $currentUserMuted ? 'true' : 'false' ?>;
                this.iceServers = [];
                this.lastSignalId = 0;
                this.audioContext = null;
                this.analyser = null;
                
                this.init();
            }
            
            async init() {
                updateDebugInfo('warning', 'Initializing voice call...');
                await this.getIceServers();
                await this.initLocalStream();
                this.setupEventListeners();
                this.startSignalingLoop();
                this.connectToParticipants();
                this.runInitialDiagnostics();
            }
            
            async runInitialDiagnostics() {
                // Test basic functionality
                const tests = {
                    'WebRTC Support': !!window.RTCPeerConnection,
                    'Media Devices': !!navigator.mediaDevices,
                    'GetUserMedia': !!navigator.mediaDevices?.getUserMedia,
                    'AudioContext': !!window.AudioContext,
                    'Secure Context': window.location.protocol === 'https:' || window.location.hostname === 'localhost'
                };
                
                Object.entries(tests).forEach(([test, result]) => {
                    updateDebugInfo(result ? 'good' : 'error', `${test}: ${result ? 'PASS' : 'FAIL'}`);
                });
            }
            
            async getIceServers() {
                this.iceServers = [
                    { urls: 'stun:stun.l.google.com:19302' },
                    { urls: 'stun:stun1.l.google.com:19302' },
                    { urls: 'stun:stun2.l.google.com:19302' }
                ];
                updateDebugInfo('good', 'ICE servers configured');
            }
            
            async initLocalStream() {
                try {
                    // Request microphone access with user interaction
                    const stream = await navigator.mediaDevices.getUserMedia({ 
                        audio: {
                            echoCancellation: true,
                            noiseSuppression: true,
                            autoGainControl: true
                        }, 
                        video: false 
                    });
                    
                    this.localStream = stream;
                    updateDebugInfo('good', 'Microphone access granted');
                    
                    // Set initial mute state
                    this.localStream.getAudioTracks().forEach(track => {
                        track.enabled = !this.isMuted;
                    });
                    
                    // Create audio visualization
                    this.setupAudioVisualization();
                    
                    this.updateConnectionStatus('connected');
                    
                } catch (error) {
                    updateDebugInfo('error', `Microphone error: ${error.message}`);
                    this.handleMicrophoneError(error);
                    this.updateConnectionStatus('disconnected');
                }
            }
            
            setupAudioVisualization() {
                if (!this.localStream) return;
                
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                this.analyser = this.audioContext.createAnalyser();
                const source = this.audioContext.createMediaStreamSource(this.localStream);
                source.connect(this.analyser);
                
                this.createVisualizationCanvas();
            }
            
            createVisualizationCanvas() {
                const canvas = document.createElement('canvas');
                canvas.width = 200;
                canvas.height = 80;
                canvas.id = 'audioVisualization';
                document.body.appendChild(canvas);
                
                const ctx = canvas.getContext('2d');
                const bufferLength = this.analyser.frequencyBinCount;
                const dataArray = new Uint8Array(bufferLength);
                
                const draw = () => {
                    requestAnimationFrame(draw);
                    this.analyser.getByteFrequencyData(dataArray);
                    
                    ctx.fillStyle = 'black';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    
                    const barWidth = (canvas.width / bufferLength) * 2.5;
                    let x = 0;
                    
                    for(let i = 0; i < bufferLength; i++) {
                        const barHeight = dataArray[i] / 2;
                        ctx.fillStyle = `rgb(${barHeight + 100},50,50)`;
                        ctx.fillRect(x, canvas.height - barHeight, barWidth, barHeight);
                        x += barWidth + 1;
                    }
                };
                draw();
            }
            
            setupEventListeners() {
                // Mute/unmute button
                document.getElementById('muteBtn').addEventListener('click', () => this.toggleMute());
                
                // Leave call button
                document.getElementById('leaveBtn').addEventListener('click', () => this.leaveCall());
                
                // Speaker button
                document.getElementById('speakerBtn').addEventListener('click', () => this.toggleSpeaker());
                
                // Handle page unload
                window.addEventListener('beforeunload', () => this.cleanup());
                window.addEventListener('pagehide', () => this.cleanup());
                
                // Click to resume audio context if suspended
                document.addEventListener('click', async () => {
                    if (this.audioContext && this.audioContext.state === 'suspended') {
                        await this.audioContext.resume();
                        updateDebugInfo('good', 'Audio context resumed');
                    }
                });
            }
            
            connectToParticipants() {
                // Connect to other participants
                this.participants.forEach(participant => {
                    if (participant.user_id !== this.localUserId) {
                        this.createPeerConnection(participant.user_id);
                    }
                });
                updateDebugInfo('good', `Connecting to ${this.participants.length - 1} participants`);
            }
            
            createPeerConnection(remoteUserId) {
                updateDebugInfo('warning', `Creating peer connection for user ${remoteUserId}`);
                
                const pc = new RTCPeerConnection({
                    iceServers: this.iceServers
                });
                
                // Add local stream to connection
                if (this.localStream) {
                    this.localStream.getTracks().forEach(track => {
                        pc.addTrack(track, this.localStream);
                    });
                }
                
                // Handle incoming stream
                pc.ontrack = (event) => {
                    updateDebugInfo('good', `Received audio stream from user ${remoteUserId}`);
                    const audioElem = document.getElementById(`audio_${remoteUserId}`);
                    if (audioElem) {
                        audioElem.srcObject = event.streams[0];
                        this.updateAudioIndicator(remoteUserId, 'connected');
                    }
                };
                
                // Handle ICE candidates
                pc.onicecandidate = (event) => {
                    if (event.candidate) {
                        this.sendSignal(remoteUserId, 'ice-candidate', event.candidate);
                    }
                };
                
                // Handle connection state changes
                pc.onconnectionstatechange = () => {
                    this.updateAudioIndicator(remoteUserId, pc.connectionState);
                    updateDebugInfo('warning', `Connection with ${remoteUserId}: ${pc.connectionState}`);
                };
                
                this.peerConnections[remoteUserId] = pc;
                
                // Create and send offer
                this.createOffer(remoteUserId, pc);
            }
            
            async createOffer(remoteUserId, pc) {
                try {
                    const offer = await pc.createOffer();
                    await pc.setLocalDescription(offer);
                    this.sendSignal(remoteUserId, 'offer', offer);
                    updateDebugInfo('good', `Offer sent to user ${remoteUserId}`);
                } catch (error) {
                    updateDebugInfo('error', `Error creating offer: ${error.message}`);
                }
            }
            
            async sendSignal(toUserId, type, data) {
                try {
                    const response = await fetch('signaling.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            call_id: this.callId,
                            to_user_id: toUserId,
                            type: type,
                            data: data
                        })
                    });
                    
                    const result = await response.json();
                    if (!result.success) {
                        updateDebugInfo('error', `Signal error: ${result.error}`);
                    }
                } catch (error) {
                    updateDebugInfo('error', `Network error: ${error.message}`);
                }
            }
            
            async checkForSignals() {
                try {
                    const response = await fetch(`signaling.php?call_id=${this.callId}&last_id=${this.lastSignalId}`);
                    const data = await response.json();
                    
                    if (data.signals && data.signals.length > 0) {
                        data.signals.forEach(signal => {
                            this.handleSignal(signal);
                            this.lastSignalId = Math.max(this.lastSignalId, signal.id);
                        });
                    }
                } catch (error) {
                    console.error('Error checking for signals:', error);
                }
            }
            
            async handleSignal(signal) {
                updateDebugInfo('warning', `Received ${signal.signal_type} from user ${signal.from_user_id}`);
                
                const pc = this.peerConnections[signal.from_user_id] || 
                           this.createPeerConnection(signal.from_user_id);
                
                const signalData = JSON.parse(signal.signal_data);
                
                try {
                    switch (signal.signal_type) {
                        case 'offer':
                            await pc.setRemoteDescription(new RTCSessionDescription(signalData));
                            const answer = await pc.createAnswer();
                            await pc.setLocalDescription(answer);
                            this.sendSignal(signal.from_user_id, 'answer', answer);
                            break;
                            
                        case 'answer':
                            await pc.setRemoteDescription(new RTCSessionDescription(signalData));
                            break;
                            
                        case 'ice-candidate':
                            await pc.addIceCandidate(new RTCIceCandidate(signalData));
                            break;
                    }
                } catch (error) {
                    updateDebugInfo('error', `Signal handling error: ${error.message}`);
                }
            }
            
            startSignalingLoop() {
                // Check for new signals every 2 seconds
                setInterval(() => {
                    this.checkForSignals();
                }, 2000);
                
                // Update participants list every 5 seconds
                setInterval(() => {
                    this.updateParticipantsList();
                }, 5000);
                
                updateDebugInfo('good', 'Signaling loop started');
            }
            
            async updateParticipantsList() {
                try {
                    const response = await fetch(`get_participants.php?call_id=${this.callId}`);
                    const participants = await response.json();
                    
                    this.participants = participants;
                    this.updateParticipantUI(participants);
                    
                } catch (error) {
                    console.error('Error updating participants:', error);
                }
            }
            
            updateParticipantUI(participants) {
                document.getElementById('participantCount').textContent = 
                    `${participants.length} participant${participants.length !== 1 ? 's' : ''} in call`;
            }
            
            updateAudioIndicator(userId, state) {
                const indicator = document.getElementById(`audioIndicator_${userId}`);
                if (indicator) {
                    indicator.className = `audio-indicator ${state}`;
                }
            }
            
            async toggleMute() {
                if (!this.localStream) return;
                
                this.isMuted = !this.isMuted;
                this.localStream.getAudioTracks().forEach(track => {
                    track.enabled = !this.isMuted;
                });
                
                // Update UI
                const muteBtn = document.getElementById('muteBtn');
                muteBtn.innerHTML = this.isMuted ? '🎤' : '🔇';
                muteBtn.style.background = this.isMuted ? '#dc3545' : '#25D366';
                muteBtn.title = this.isMuted ? 'Unmute' : 'Mute';
                
                // Update audio indicator
                this.updateAudioIndicator(this.localUserId, this.isMuted ? 'muted' : 'connected');
                
                // Send mute status to server
                try {
                    await fetch('voice_call.php?group_id=<?= $groupId ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=toggle_mute'
                    });
                } catch (error) {
                    console.error('Error updating mute status:', error);
                }
                
                updateDebugInfo('good', `Microphone ${this.isMuted ? 'muted' : 'unmuted'}`);
            }
            
            toggleSpeaker() {
                const audios = document.querySelectorAll('audio');
                const isMuted = audios[0]?.muted || false;
                
                audios.forEach(audio => {
                    audio.muted = !isMuted;
                });
                
                const speakerBtn = document.getElementById('speakerBtn');
                speakerBtn.innerHTML = isMuted ? '🔈' : '🔊';
                speakerBtn.title = isMuted ? 'Unmute Speakers' : 'Mute Speakers';
                
                updateDebugInfo('good', `Speakers ${isMuted ? 'unmuted' : 'muted'}`);
            }
            
            async leaveCall() {
                if (confirm('Leave the voice call?')) {
                    await this.cleanup();
                    window.location.href = 'group.php?id=<?= $groupId ?>';
                }
            }
            
            async cleanup() {
                updateDebugInfo('warning', 'Cleaning up voice call...');
                
                // Stop local stream
                if (this.localStream) {
                    this.localStream.getTracks().forEach(track => track.stop());
                }
                
                // Close all peer connections
                Object.values(this.peerConnections).forEach(pc => {
                    if (pc) pc.close();
                });
                
                // Notify server we're leaving
                try {
                    await fetch('voice_call.php?group_id=<?= $groupId ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=leave_call'
                    });
                } catch (error) {
                    console.error('Error leaving call:', error);
                }
                
                updateDebugInfo('good', 'Voice call cleanup complete');
            }
            
            updateConnectionStatus(status) {
                const statusElem = document.getElementById('connectionStatus');
                if (statusElem) {
                    statusElem.textContent = status.charAt(0).toUpperCase() + status.slice(1);
                    statusElem.className = `connection-status ${status}`;
                }
            }
            
            handleMicrophoneError(error) {
                let message = 'Microphone error: ';
                
                switch(error.name) {
                    case 'NotAllowedError':
                        message += 'Permission denied. Please allow microphone access.';
                        break;
                    case 'NotFoundError':
                        message += 'No microphone found.';
                        break;
                    case 'NotSupportedError':
                        message += 'Browser does not support audio.';
                        break;
                    case 'NotReadableError':
                        message += 'Microphone is already in use.';
                        break;
                    default:
                        message += error.message;
                }
                
                alert(message);
            }
        }

        // Initialize voice call when page loads
        document.addEventListener('DOMContentLoaded', () => {
            // Check if browser supports required features
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.RTCPeerConnection) {
                alert('Your browser does not support voice calls. Please use a modern browser like Chrome, Firefox, or Edge.');
                return;
            }
            
            // Initialize voice call
            window.voiceCall = new VoiceCall();
        });

        // Make test functions global
        window.testMicrophone = testMicrophone;
        window.testAudioPlayback = testAudioPlayback;
        window.testWebRTC = testWebRTC;

        // Enhanced connection monitoring
class ConnectionMonitor {
    constructor() {
        this.connectionStats = {
            audioTracks: 0,
            connectedPeers: 0,
            iceState: 'new',
            signalingState: 'stable'
        };
        
        this.startMonitoring();
    }
    
    startMonitoring() {
        setInterval(() => this.updateStats(), 2000);
    }
    
    updateStats() {
        const stats = {
            timestamp: new Date().toISOString(),
            userAgent: navigator.userAgent,
            platform: navigator.platform,
            audioContextState: window.audioContext ? window.audioContext.state : 'none',
            localStream: window.localStream ? window.localStream.active : false,
            peerConnections: Object.keys(window.peerConnections || {}).length
        };
        
        console.log('Connection Stats:', stats);
        this.updateUI(stats);
    }
    
    updateUI(stats) {
        // Create or update stats display
        let statsDiv = document.getElementById('connectionStats');
        if (!statsDiv) {
            statsDiv = document.createElement('div');
            statsDiv.id = 'connectionStats';
            statsDiv.style.cssText = `
                position: fixed; bottom: 10px; right: 10px; 
                background: rgba(0,0,0,0.8); color: white; 
                padding: 10px; border-radius: 5px; font-size: 12px;
                z-index: 1000; max-width: 300px;
            `;
            document.body.appendChild(statsDiv);
        }
        
        statsDiv.innerHTML = `
            <strong>Connection Stats:</strong><br>
            Stream: ${stats.localStream ? 'Active' : 'Inactive'}<br>
            Peers: ${stats.peerConnections}<br>
            Audio Context: ${stats.audioContextState}<br>
            Time: ${new Date().toLocaleTimeString()}
        `;
    }
}

// Initialize monitor
const monitor = new ConnectionMonitor();
    </script>
</body>
</html>