<?php
session_start();
require __DIR__ . '/../vendor/autoload.php';

use Pusher\Pusher;

$pdo = new PDO("pgsql:host=localhost;dbname=fbclone", 'postgres', 'Gi12,br12');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$app_id = '2047490';
$app_key = '31c0ee9ab9305d311a22';
$app_secret = 'ae60d75ae629ffe987ab';
$app_cluster = 'mt1';

$pusher = new Pusher($app_key, $app_secret, $app_id, [
    'cluster' => $app_cluster,
    'useTLS' => true
]);

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) exit('User not logged in');
$username = $_SESSION['username'] ?? 'Anonymous';
$profilePic = 'default_profile.png'; // Get from DB in production

$groupId = (int)($_GET['group_id'] ?? 0);
$callId = (int)($_GET['call_id'] ?? 0);

if ($callId === 0) {
    // In production, create call and get ID
    $callId = 1;
}

$stmtGroup = $pdo->prepare("SELECT name FROM groups WHERE id = ?");
$stmtGroup->execute([$groupId]);
$groupName = $stmtGroup->fetchColumn() ?: 'Unknown Group';

$channelName = "presence-group-voice-call-$callId";

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Voice Call - <?=htmlspecialchars($groupName)?></title>
  <script src="https://js.pusher.com/7.2/pusher.min.js"></script>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    #header { font-weight: bold; font-size: 1.5rem; }
    #userCount { font-style: italic; margin-left: 10px; }
    #participants { display: flex; flex-wrap: wrap; gap: 15px; margin-top: 10px; }
    .participant { border: 1px solid #ccc; padding: 10px; border-radius: 8px; width: 140px; text-align: center; }
    .participant img { border-radius: 50%; width: 80px; height: 80px; object-fit: cover; }
    audio { width: 140px; margin-top: 8px; border-radius: 8px; }
    button { margin-top: 15px; padding: 8px 12px; border: none; border-radius: 4px; cursor: pointer; }
    #muteBtn { background-color: #007bff; color: white; }
    #exitBtn { background-color: #dc3545; color: white; margin-left: 10px; }
    #endCallBtn { background-color: #6f42c1; color: white; float: right; margin-top: 10px; border-radius: 4px; }
  </style>
</head>
<body>

<div id="header">
  Voice Call for Group: <?= htmlspecialchars($groupName) ?>
  <span id="userCount">(0 users)</span>
</div>

<div id="participants"></div>

<div>
  <button id="muteBtn">Mute</button>
  <button id="exitBtn">Exit Call</button>
  <?php if ($userId === 1): ?>
  <button id="endCallBtn">End Call</button>
  <?php endif; ?>
</div>

<script>
const userId = '<?= $userId ?>';
const username = '<?= addslashes($username) ?>';
const profilePic = '<?= $profilePic ?>';
const channelName = '<?= $channelName ?>';

const pusher = new Pusher('<?= $app_key ?>', {
  cluster: '<?= $app_cluster ?>',
  authEndpoint: 'pusher_auth.php',
  forceTLS: true
});
const channel = pusher.subscribe(channelName);

const participantsDiv = document.getElementById('participants');
const userCountSpan = document.getElementById('userCount');
const muteBtn = document.getElementById('muteBtn');
const exitBtn = document.getElementById('exitBtn');
const endCallBtn = document.getElementById('endCallBtn');

let localStream;
let muted = false;
const peers = {};
const configuration = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };

function updateUserCount(count) {
  userCountSpan.textContent = `(${count} user${count !== 1 ? 's' : ''})`;
}

function addParticipant(user) {
  if (!document.getElementById('participant-' + user.id)) {
    const div = document.createElement('div');
    div.id = 'participant-' + user.id;
    div.className = 'participant';
    div.innerHTML = `
      <img src="${user.profilePic}" alt="${user.username}" />
      <div>${user.username}</div>
      <audio id="audio-${user.id}" autoplay></audio>
    `;
    participantsDiv.appendChild(div);
  }
}

function removeParticipant(id) {
  const el = document.getElementById('participant-' + id);
  if (el) el.remove();
  if (peers[id]) {
    peers[id].close();
    delete peers[id];
  }
}

function createPeerConnection(remoteId, initiator) {
  if (peers[remoteId]) return peers[remoteId];

  const pc = new RTCPeerConnection(configuration);
  peers[remoteId] = pc;

  pc.onicecandidate = e => {
    if (e.candidate) sendSignal(remoteId, { type: 'ice-candidate', candidate: e.candidate });
  };

  pc.ontrack = e => {
    const audio = document.getElementById('audio-' + remoteId);
    if(audio) audio.srcObject = e.streams[0];
  };

  localStream.getTracks().forEach(track => pc.addTrack(track, localStream));

  if (initiator) {
    pc.createOffer()
      .then(offer => pc.setLocalDescription(offer))
      .then(() => sendSignal(remoteId, { type: 'offer', sdp: pc.localDescription }));
  }

  return pc;
}

function handleOffer(fromId, sdp) {
  let pc = peers[fromId] || createPeerConnection(fromId, false);
  pc.setRemoteDescription(new RTCSessionDescription(sdp))
    .then(() => pc.createAnswer())
    .then(answer => pc.setLocalDescription(answer))
    .then(() => sendSignal(fromId, { type: 'answer', sdp: pc.localDescription }));
}

function handleAnswer(fromId, sdp) {
  const pc = peers[fromId];
  if (pc) pc.setRemoteDescription(new RTCSessionDescription(sdp));
}

function handleIceCandidate(fromId, candidate) {
  const pc = peers[fromId];
  if (pc) pc.addIceCandidate(new RTCIceCandidate(candidate));
}

function sendSignal(toId, signal) {
  channel.trigger('client-signal', { to: toId, from: userId, signal });
}

channel.bind('pusher:subscription_succeeded', members => {
  updateUserCount(members.count);
  members.each(member => {
    addParticipant({ id: member.id, username: member.info.name, profilePic: member.info.profilePic || 'default_profile.png' });
    if (member.id !== userId) createPeerConnection(member.id, true);
  });
  addParticipant({ id: userId, username, profilePic });
});

channel.bind('pusher:member_added', member => {
  updateUserCount(parseInt(userCountSpan.textContent.match(/\d+/)) + 1);
  addParticipant({ id: member.id, username: member.info.name, profilePic: member.info.profilePic || 'default_profile.png' });
  createPeerConnection(member.id, true);
});

channel.bind('pusher:member_removed', member => {
  updateUserCount(parseInt(userCountSpan.textContent.match(/\d+/)) - 1);
  removeParticipant(member.id);
});

channel.bind('client-signal', data => {
  if(data.to !== userId) return;
  const { signal, from } = data;
  switch(signal.type) {
    case 'offer': handleOffer(from, signal.sdp); break;
    case 'answer': handleAnswer(from, signal.sdp); break;
    case 'ice-candidate': handleIceCandidate(from, signal.candidate); break;
  }
});

navigator.mediaDevices.getUserMedia({ audio: true })
  .then(stream => localStream = stream)
  .catch(() => alert('Microphone access is required.'));

muteBtn.onclick = () => {
  muted = !muted;
  if(localStream) localStream.getAudioTracks()[0].enabled = !muted;
  muteBtn.textContent = muted ? 'Unmute' : 'Mute';
};

exitBtn.onclick = () => {
  if(localStream) localStream.getTracks().forEach(track => track.stop());
  Object.values(peers).forEach(pc => pc.close());
  channel.unsubscribe();
  pusher.disconnect();
  window.location.href = `group.php?id=<?= $groupId ?>`;
};

if(endCallBtn) {
  endCallBtn.onclick = () => {
    if(confirm('End the call for all participants?')) {
      alert('Call ended.');
      window.location.href = `group.php?id=<?= $groupId ?>`;
    }
  };
}
</script>

</body>
</html>
