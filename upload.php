<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$uploadDir = __DIR__ . '/uploads/messages/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$allowedMimeTypes = [
    'image/jpeg', 'image/png', 'image/gif',
    'video/mp4', 'video/webm', 'video/ogg',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/zip',
    'application/x-rar-compressed'
];

$maxFileSize = 30 * 1024 * 1024; // 30 MB max file size

$uploadedUrls = [];

if (!empty($_FILES['media_files']) && is_array($_FILES['media_files']['name'])) {
    for ($i = 0; $i < count($_FILES['media_files']['name']); $i++) {
        $error = $_FILES['media_files']['error'][$i];
        if ($error !== UPLOAD_ERR_OK) {
            continue;
        }
        $size = $_FILES['media_files']['size'][$i];
        if ($size > $maxFileSize) {
            continue;
        }
        $tmpName = $_FILES['media_files']['tmp_name'][$i];
        $type = mime_content_type($tmpName);
        if (!in_array($type, $allowedMimeTypes)) {
            continue;
        }
        $ext = pathinfo($_FILES['media_files']['name'][$i], PATHINFO_EXTENSION);
        $filename = uniqid('msgmedia_') . '.' . $ext;
        $destination = $uploadDir . $filename;
        if (move_uploaded_file($tmpName, $destination)) {
            $uploadedUrls[] = 'uploads/messages/' . $filename;
        }
    }
}

if (count($uploadedUrls) > 0) {
    echo json_encode(['success' => true, 'urls' => $uploadedUrls]);
} else {
    echo json_encode(['success' => false, 'message' => 'No valid files uploaded']);
}
