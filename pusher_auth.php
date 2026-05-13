<?php
require __DIR__ . '/vendor/autoload.php';

use Pusher\Pusher;

$app_id = '2047490';
$app_key = '31c0ee9ab9305d311a22';
$app_secret = 'ae60d75ae629ffe987ab';
$app_cluster = 'mt1';

$pusher = new Pusher(
    $app_key,
    $app_secret,
    $app_id,
    ['cluster' => $app_cluster, 'useTLS' => true]
);

session_start();

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$user_name = $_SESSION['username'] ?? 'Anonymous';
$socket_id = $_POST['socket_id'] ?? '';
$channel_name = $_POST['channel_name'] ?? '';

if (strpos($channel_name, 'private-') === 0) {
    $auth = $pusher->authorizeChannel($socket_id, $channel_name);
    header('Content-Type: application/json');
    echo $auth;
    exit;
}

if (strpos($channel_name, 'presence-') === 0) {
    $presence_data = ['name' => $user_name];
    $auth = $pusher->authorizePresenceChannel($socket_id, $channel_name, (string)$user_id, $presence_data);
    header('Content-Type: application/json');
    echo $auth;
    exit;
}

http_response_code(403);
echo 'Forbidden';
