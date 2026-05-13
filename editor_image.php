<?php
// editor_image.php - CANVA-STYLE IMAGE EDITOR
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


// Fetch user info
$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url, username FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$username = $userData['username'];

// Initialize session for editor state
if (!isset($_SESSION['editor_state'])) {
    $_SESSION['editor_state'] = [
        'elements' => [],
        'current_project' => null,
        'history' => [],
        'history_index' => -1,
        'canvas_width' => 1200,
        'canvas_height' => 1200
    ];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false];
    
    try {
        switch ($_POST['action']) {
            case 'save_state':
                $_SESSION['editor_state'] = json_decode($_POST['state'], true);
                $response = ['success' => true];
                break;
                
            case 'upload_image':
                if (!empty($_FILES['image']['name'])) {
                    $uploadDir = __DIR__ . '/image_uploads/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $file = $_FILES['image'];
                    $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (in_array(strtolower($fileExt), $allowedTypes)) {
                        $filename = uniqid('image_') . '.' . $fileExt;
                        $filePath = $uploadDir . $filename;
                        
                        if (move_uploaded_file($file['tmp_name'], $filePath)) {
                            $response = [
                                'success' => true,
                                'url' => 'image_uploads/' . $filename,
                                'filename' => $filename
                            ];
                        }
                    }
                }
                break;
                
            case 'export_image':
                $imageData = $_POST['image_data'];
                $format = $_POST['format'] ?? 'png';
                $filename = 'export_' . uniqid() . '.' . $format;
                $filePath = __DIR__ . '/image_uploads/' . $filename;
                
                // Remove data:image prefix
                $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
                $imageData = str_replace(' ', '+', $imageData);
                $imageData = base64_decode($imageData);
                
                if (file_put_contents($filePath, $imageData)) {
                    $response = [
                        'success' => true,
                        'url' => 'image_uploads/' . $filename,
                        'filename' => $filename
                    ];
                }
                break;
                
            case 'save_project':
                $projectName = $_POST['project_name'] ?? 'Untitled Design';
                $projectData = $_POST['project_data'];
                
                // Save project to database
                $stmt = $pdo->prepare("INSERT INTO user_projects (user_id, project_name, project_data) VALUES (?, ?, ?)");
                $stmt->execute([$currentUserId, $projectName, $projectData]);
                
                $response = [
                    'success' => true,
                    'project_id' => $pdo->lastInsertId(),
                    'message' => 'Project saved successfully!'
                ];
                break;
                
            case 'load_project':
                $projectId = $_POST['project_id'];
                $stmt = $pdo->prepare("SELECT project_data FROM user_projects WHERE id = ? AND user_id = ?");
                $stmt->execute([$projectId, $currentUserId]);
                $project = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($project) {
                    $_SESSION['editor_state'] = json_decode($project['project_data'], true);
                    $response = [
                        'success' => true,
                        'data' => $project['project_data']
                    ];
                } else {
                    $response['error'] = 'Project not found';
                }
                break;
        }
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// Fetch user projects
$stmt = $pdo->prepare("SELECT id, project_name, created_at FROM user_projects WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$currentUserId]);
$userProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Canva-Style Image Editor</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #00c4cc;
            --secondary: #ff6b6b;
            --dark: #1e1e2f;
            --darker: #151521;
            --light: #2c2c3d;
            --lighter: #3a3a4d;
            --text: #ffffff;
            --text-secondary: #b0b0b0;
            --sidebar-width: 280px;
            --header-height: 70px;
            --toolbar-height: 60px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--dark);
            color: var(--text);
            overflow: hidden;
        }
        
        .editor-container {
            display: grid;
            grid-template-rows: var(--header-height) 1fr var(--toolbar-height);
            height: 100vh;
        }
        
        /* Header */
        .editor-header {
            background: var(--darker);
            padding: 0 30px;
            border-bottom: 2px solid var(--primary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo h1 {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 24px;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            color: white;
        }
        
        .btn-secondary {
            background: var(--light);
            color: var(--text);
        }
        
        .btn-danger {
            background: var(--secondary);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 196, 204, 0.4);
        }
        
        /* Main Content */
        .editor-main {
            display: grid;
            grid-template-columns: var(--sidebar-width) 1fr var(--sidebar-width);
            height: 100%;
            overflow: hidden;
        }
        
        /* Sidebars */
        .sidebar {
            background: var(--darker);
            border-right: 1px solid var(--lighter);
            overflow-y: auto;
        }
        
        .sidebar-right {
            border-right: none;
            border-left: 1px solid var(--lighter);
        }
        
        .sidebar-section {
            padding: 20px;
            border-bottom: 1px solid var(--lighter);
        }
        
        .sidebar-section h3 {
            margin-bottom: 15px;
            color: var(--primary);
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Canvas Area */
        .canvas-area {
            background: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: auto;
        }
        
        .canvas-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            position: relative;
            overflow: hidden;
        }
        
        #designCanvas {
            display: block;
            max-width: 100%;
            max-height: 80vh;
            cursor: move;
        }
        
        .canvas-controls {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.8);
            padding: 10px 20px;
            border-radius: 25px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .control-btn {
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 5px;
            transition: background 0.3s ease;
        }
        
        .control-btn:hover {
            background: rgba(255,255,255,0.1);
        }
        
        /* Tools Panel */
        .tools-panel {
            background: var(--darker);
            border-top: 1px solid var(--lighter);
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 20px;
            overflow-x: auto;
        }
        
        .tool-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .tool-btn {
            background: var(--light);
            border: none;
            color: var(--text);
            padding: 8px 15px;
            border-radius: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .tool-btn:hover {
            background: var(--primary);
            transform: translateY(-2px);
        }
        
        .tool-btn.active {
            background: var(--primary);
            box-shadow: 0 0 10px rgba(0, 196, 204, 0.5);
        }
        
        /* Grid Layouts */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }
        
        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }
        
        /* Template & Element Items */
        .template-item, .element-item {
            aspect-ratio: 1;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            text-align: center;
            transition: all 0.3s ease;
            background: var(--lighter);
            flex-direction: column;
            padding: 10px;
            overflow: hidden;
        }
        
        .template-item:hover, .element-item:hover {
            background: var(--primary);
            transform: scale(1.05);
        }
        
        .template-item i, .element-item i {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .color-palette {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 5px;
        }
        
        .color-item {
            aspect-ratio: 1;
            border-radius: 5px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        
        .color-item:hover {
            transform: scale(1.1);
        }
        
        .color-item.active {
            border-color: white;
            box-shadow: 0 0 10px rgba(255,255,255,0.5);
        }
        
        /* Font Selector */
        .font-selector {
            max-height: 200px;
            overflow-y: auto;
        }
        
        .font-option {
            padding: 10px;
            margin-bottom: 5px;
            background: var(--lighter);
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        
        .font-option:hover {
            background: var(--primary);
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: var(--darker);
            padding: 30px;
            border-radius: 15px;
            max-width: 500px;
            width: 90%;
            max-height: 80%;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: var(--text);
            font-size: 24px;
            cursor: pointer;
        }
        
        /* Form Elements */
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-secondary);
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 10px;
            border-radius: 5px;
            background: var(--lighter);
            border: 1px solid var(--primary);
            color: var(--text);
        }
        
        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }
        
        /* Sliders */
        .slider-container {
            margin-bottom: 15px;
        }
        
        .slider-container label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .slider {
            width: 100%;
            height: 6px;
            background: var(--lighter);
            border-radius: 3px;
            outline: none;
            -webkit-appearance: none;
        }
        
        .slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            background: var(--primary);
            border-radius: 50%;
            cursor: pointer;
        }
        
        /* Toast Notifications */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            background: var(--primary);
            color: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            z-index: 1000;
            transform: translateX(150%);
            transition: transform 0.3s ease;
        }
        
        .toast.show {
            transform: translateX(0);
        }
        
        .toast.error {
            background: var(--secondary);
        }
        
        .toast.success {
            background: #4CAF50;
        }
        
        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Element Library */
        .element-category {
            margin-bottom: 20px;
        }
        
        .element-category h4 {
            margin-bottom: 10px;
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        /* Template Preview */
        .template-preview {
            background: var(--lighter);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .template-preview:hover {
            background: var(--primary);
            transform: translateY(-2px);
        }
        
        .template-preview img {
            width: 100%;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        
        .template-info h4 {
            margin-bottom: 5px;
        }
        
        .template-info p {
            color: var(--text-secondary);
            font-size: 12px;
        }
        
        /* Selection Handles */
        .selection-handle {
            position: absolute;
            width: 12px;
            height: 12px;
            background: var(--primary);
            border: 2px solid white;
            border-radius: 50%;
            z-index: 1000;
        }
        
        .selection-handle.nw { cursor: nw-resize; }
        .selection-handle.ne { cursor: ne-resize; }
        .selection-handle.sw { cursor: sw-resize; }
        .selection-handle.se { cursor: se-resize; }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .editor-main {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .sidebar.mobile-open {
                display: block;
                position: fixed;
                top: var(--header-height);
                left: 0;
                width: 100%;
                height: calc(100vh - var(--header-height) - var(--toolbar-height));
                z-index: 100;
            }
            
            .tools-panel {
                padding: 10px;
                gap: 10px;
            }
            
            .tool-btn {
                padding: 6px 12px;
                font-size: 12px;
            }
        }
        
        /* Enhanced UI Elements */
        .element-preview {
            width: 100%;
            height: 80px;
            background: var(--lighter);
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
        }
        
        .property-group {
            background: var(--lighter);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .property-group h4 {
            margin-bottom: 10px;
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .alignment-buttons {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 5px;
        }
        
        .alignment-btn {
            background: var(--light);
            border: none;
            color: var(--text);
            padding: 8px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .alignment-btn:hover {
            background: var(--primary);
        }
        
        .alignment-btn.active {
            background: var(--primary);
        }
        
        .layer-item {
            padding: 10px;
            background: var(--lighter);
            border-radius: 5px;
            margin-bottom: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .layer-item:hover {
            background: var(--light);
        }
        
        .layer-item.active {
            background: var(--primary);
        }
        
        .layer-controls {
            display: flex;
            gap: 5px;
        }
        
        .layer-control-btn {
            background: none;
            border: none;
            color: var(--text);
            cursor: pointer;
            padding: 2px 5px;
            border-radius: 3px;
            transition: background 0.3s ease;
        }
        
        .layer-control-btn:hover {
            background: rgba(255,255,255,0.1);
        }
        
        .context-menu {
            position: absolute;
            background: var(--darker);
            border: 1px solid var(--lighter);
            border-radius: 5px;
            padding: 5px 0;
            z-index: 1000;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            display: none;
        }
        
        .context-menu-item {
            padding: 8px 15px;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        
        .context-menu-item:hover {
            background: var(--primary);
        }
        
        .grid-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            background-image: 
                linear-gradient(rgba(0,0,0,0.1) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,0,0,0.1) 1px, transparent 1px);
            background-size: 20px 20px;
            display: none;
        }
        
        .snap-indicator {
            position: absolute;
            background: var(--primary);
            z-index: 1000;
            display: none;
        }
        
        .snap-indicator.horizontal {
            width: 100%;
            height: 1px;
        }
        
        .snap-indicator.vertical {
            width: 1px;
            height: 100%;
        }
    </style>
</head>
<body>
    <div class="editor-container">
        <!-- Header -->
        <div class="editor-header">
            <div class="logo">
                <i class="fas fa-palette fa-2x" style="color: var(--primary);"></i>
                <h1>Canva-Style Editor</h1>
            </div>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="window.history.back()">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button class="btn btn-secondary" onclick="saveProject()">
                    <i class="fas fa-save"></i> Save
                </button>
                <button class="btn btn-primary" onclick="openExportModal()">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="editor-main">
            <!-- Left Sidebar - Templates & Elements -->
            <div class="sidebar">
                <div class="sidebar-section">
                    <h3><i class="fas fa-layer-group"></i> Templates</h3>
                    <div class="template-categories">
                        <div class="template-preview" onclick="loadTemplate('social')">
                            <div style="background: linear-gradient(45deg, #667eea, #764ba2); height: 80px; border-radius: 5px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-share-alt fa-2x"></i>
                            </div>
                            <div class="template-info">
                                <h4>Social Media</h4>
                                <p>Instagram, Facebook, Twitter</p>
                            </div>
                        </div>
                        <div class="template-preview" onclick="loadTemplate('marketing')">
                            <div style="background: linear-gradient(45deg, #f093fb, #f5576c); height: 80px; border-radius: 5px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-bullhorn fa-2x"></i>
                            </div>
                            <div class="template-info">
                                <h4>Marketing</h4>
                                <p>Flyers, Posters, Banners</p>
                            </div>
                        </div>
                        <div class="template-preview" onclick="loadTemplate('presentation')">
                            <div style="background: linear-gradient(45deg, #4facfe, #00f2fe); height: 80px; border-radius: 5px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-desktop fa-2x"></i>
                            </div>
                            <div class="template-info">
                                <h4>Presentation</h4>
                                <p>Slides, Infographics</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sidebar-section">
                    <h3><i class="fas fa-shapes"></i> Elements</h3>
                    <div class="element-category">
                        <h4>Shapes</h4>
                        <div class="grid-3">
                            <div class="element-item" onclick="addShape('rectangle')">
                                <i class="fas fa-square"></i>
                                <span>Rectangle</span>
                            </div>
                            <div class="element-item" onclick="addShape('circle')">
                                <i class="fas fa-circle"></i>
                                <span>Circle</span>
                            </div>
                            <div class="element-item" onclick="addShape('triangle')">
                                <i class="fas fa-play"></i>
                                <span>Triangle</span>
                            </div>
                            <div class="element-item" onclick="addShape('line')">
                                <i class="fas fa-minus"></i>
                                <span>Line</span>
                            </div>
                            <div class="element-item" onclick="addShape('star')">
                                <i class="fas fa-star"></i>
                                <span>Star</span>
                            </div>
                            <div class="element-item" onclick="addShape('heart')">
                                <i class="fas fa-heart"></i>
                                <span>Heart</span>
                            </div>
                        </div>
                    </div>
                    <div class="element-category">
                        <h4>Text</h4>
                        <div class="grid-2">
                            <div class="element-item" onclick="addTextElement('Heading')">
                                <i class="fas fa-heading"></i>
                                <span>Heading</span>
                            </div>
                            <div class="element-item" onclick="addTextElement('Body')">
                                <i class="fas fa-paragraph"></i>
                                <span>Body Text</span>
                            </div>
                            <div class="element-item" onclick="addTextElement('Caption')">
                                <i class="fas fa-font"></i>
                                <span>Caption</span>
                            </div>
                            <div class="element-item" onclick="openTextModal()">
                                <i class="fas fa-plus"></i>
                                <span>Custom Text</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sidebar-section">
                    <h3><i class="fas fa-image"></i> Upload Images</h3>
                    <div class="upload-area" style="border: 2px dashed var(--primary); border-radius: 10px; padding: 20px; text-align: center; cursor: pointer;" onclick="document.getElementById('imageUpload').click()">
                        <i class="fas fa-cloud-upload-alt fa-2x" style="color: var(--primary); margin-bottom: 10px;"></i>
                        <p>Click to upload images</p>
                        <input type="file" id="imageUpload" style="display: none;" accept="image/*" multiple onchange="handleImageUpload(this.files)">
                    </div>
                </div>
            </div>

            <!-- Canvas Area -->
            <div class="canvas-area">
                <div class="canvas-container">
                    <canvas id="designCanvas" width="1200" height="1200"></canvas>
                    <div id="selectionHandles" style="position: absolute; pointer-events: none;"></div>
                    <div class="grid-overlay" id="gridOverlay"></div>
                    <div class="snap-indicator horizontal" id="horizontalSnap"></div>
                    <div class="snap-indicator vertical" id="verticalSnap"></div>
                </div>
                <div class="canvas-controls">
                    <button class="control-btn" onclick="toggleGrid()" id="gridToggle">
                        <i class="fas fa-th"></i>
                    </button>
                    <button class="control-btn" onclick="zoomOut()">
                        <i class="fas fa-search-minus"></i>
                    </button>
                    <span id="zoomLevel" style="color: white; font-size: 14px;">100%</span>
                    <button class="control-btn" onclick="zoomIn()">
                        <i class="fas fa-search-plus"></i>
                    </button>
                    <button class="control-btn" onclick="resetZoom()">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>

            <!-- Right Sidebar - Properties & Layers -->
            <div class="sidebar sidebar-right">
                <div class="sidebar-section">
                    <h3><i class="fas fa-sliders-h"></i> Properties</h3>
                    <div id="propertiesPanel">
                        <p style="color: var(--text-secondary); text-align: center; padding: 20px;">Select an element to edit properties</p>
                    </div>
                </div>

                <div class="sidebar-section">
                    <h3><i class="fas fa-layer-group"></i> Layers</h3>
                    <div id="layersPanel">
                        <div class="layer-item">
                            <div style="display: flex; justify-content: between; align-items: center;">
                                <span>Background</span>
                                <div>
                                    <button class="layer-control-btn">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="layer-control-btn">
                                        <i class="fas fa-lock"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sidebar-section">
                    <h3><i class="fas fa-palette"></i> Colors</h3>
                    <div class="color-palette">
                        <div class="color-item" style="background: #ff6b6b;" onclick="setColor('#ff6b6b')"></div>
                        <div class="color-item" style="background: #4ecdc4;" onclick="setColor('#4ecdc4')"></div>
                        <div class="color-item" style="background: #45b7d1;" onclick="setColor('#45b7d1')"></div>
                        <div class="color-item" style="background: #96ceb4;" onclick="setColor('#96ceb4')"></div>
                        <div class="color-item" style="background: #feca57;" onclick="setColor('#feca57')"></div>
                        <div class="color-item" style="background: #ff9ff3;" onclick="setColor('#ff9ff3')"></div>
                        <div class="color-item" style="background: #54a0ff;" onclick="setColor('#54a0ff')"></div>
                        <div class="color-item" style="background: #5f27cd;" onclick="setColor('#5f27cd')"></div>
                        <div class="color-item" style="background: #00d2d3;" onclick="setColor('#00d2d3')"></div>
                        <div class="color-item" style="background: #ff9f43;" onclick="setColor('#ff9f43')"></div>
                        <div class="color-item" style="background: #ee5253;" onclick="setColor('#ee5253')"></div>
                        <div class="color-item" style="background: #0abde3;" onclick="setColor('#0abde3')"></div>
                    </div>
                    <div style="margin-top: 10px;">
                        <input type="color" id="customColor" style="width: 100%; height: 40px; border: none; border-radius: 5px; cursor: pointer;" onchange="setColor(this.value)">
                    </div>
                </div>
            </div>
        </div>

        <!-- Tools Panel -->
        <div class="tools-panel">
            <div class="tool-group">
                <button class="tool-btn active" onclick="setTool('select')" id="selectTool">
                    <i class="fas fa-mouse-pointer"></i> Select
                </button>
                <button class="tool-btn" onclick="setTool('text')" id="textTool">
                    <i class="fas fa-font"></i> Text
                </button>
                <button class="tool-btn" onclick="setTool('shape')" id="shapeTool">
                    <i class="fas fa-square"></i> Shapes
                </button>
            </div>
            
            <div class="tool-group">
                <button class="tool-btn" onclick="openFiltersModal()">
                    <i class="fas fa-sliders-h"></i> Filters
                </button>
                <button class="tool-btn" onclick="openEffectsModal()">
                    <i class="fas fa-magic"></i> Effects
                </button>
                <button class="tool-btn" onclick="openCropModal()">
                    <i class="fas fa-crop"></i> Crop
                </button>
            </div>
            
            <div class="tool-group">
                <button class="tool-btn" onclick="openUploadModal()">
                    <i class="fas fa-upload"></i> Upload
                </button>
                <button class="tool-btn" onclick="openRemoveBgModal()">
                    <i class="fas fa-cut"></i> Remove BG
                </button>
                <button class="tool-btn" onclick="openTemplatesModal()">
                    <i class="fas fa-th-large"></i> Templates
                </button>
            </div>
            
            <div class="tool-group">
                <button class="tool-btn" onclick="undo()">
                    <i class="fas fa-undo"></i> Undo
                </button>
                <button class="tool-btn" onclick="redo()">
                    <i class="fas fa-redo"></i> Redo
                </button>
                <button class="tool-btn" onclick="clearCanvas()">
                    <i class="fas fa-trash"></i> Clear
                </button>
            </div>
            
            <div class="tool-group">
                <button class="tool-btn" onclick="toggleSnapToGrid()" id="snapToggle">
                    <i class="fas fa-magnet"></i> Snap
                </button>
                <button class="tool-btn" onclick="duplicateElement()">
                    <i class="fas fa-copy"></i> Duplicate
                </button>
                <button class="tool-btn" onclick="groupElements()">
                    <i class="fas fa-object-group"></i> Group
                </button>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Text Modal -->
    <div class="modal" id="textModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Text</h3>
                <button class="modal-close" onclick="closeModal('textModal')">&times;</button>
            </div>
            <div class="form-group">
                <label class="form-label">Text Content</label>
                <textarea class="form-textarea" id="textContent" placeholder="Enter your text here">Sample Text</textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Font Family</label>
                <select class="form-select" id="textFont">
                    <option value="Arial">Arial</option>
                    <option value="Helvetica">Helvetica</option>
                    <option value="Times New Roman">Times New Roman</option>
                    <option value="Georgia">Georgia</option>
                    <option value="Verdana">Verdana</option>
                    <option value="Courier New">Courier New</option>
                    <option value="Impact">Impact</option>
                    <option value="Comic Sans MS">Comic Sans MS</option>
                </select>
            </div>
            <div class="slider-container">
                <label>Font Size</label>
                <input type="range" class="slider" id="textSize" min="10" max="100" value="24">
                <span id="textSizeValue">24</span>
            </div>
            <div class="form-group">
                <label class="form-label">Text Color</label>
                <input type="color" id="textColor" value="#000000" style="width: 100%; height: 40px;">
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="textBackground"> Add Background
                </label>
            </div>
            <div class="form-group" id="textBgColorGroup" style="display: none;">
                <label class="form-label">Background Color</label>
                <input type="color" id="textBgColor" value="#ffffff" style="width: 100%; height: 40px;">
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="textShadow"> Add Shadow
                </label>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="textBold"> Bold
                </label>
                <label>
                    <input type="checkbox" id="textItalic"> Italic
                </label>
                <label>
                    <input type="checkbox" id="textUnderline"> Underline
                </label>
            </div>
            <button class="btn btn-primary" onclick="addTextToCanvas()" style="width: 100%;">
                <i class="fas fa-plus"></i> Add Text
            </button>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal" id="exportModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Export Design</h3>
                <button class="modal-close" onclick="closeModal('exportModal')">&times;</button>
            </div>
            <div class="form-group">
                <label class="form-label">Export Format</label>
                <select class="form-select" id="exportFormat">
                    <option value="png">PNG</option>
                    <option value="jpg">JPG</option>
                    <option value="webp">WebP</option>
                    <option value="svg">SVG</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Quality</label>
                <select class="form-select" id="exportQuality">
                    <option value="1">High</option>
                    <option value="0.8">Medium</option>
                    <option value="0.6">Low</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Size</label>
                <select class="form-select" id="exportSize">
                    <option value="original">Original Size</option>
                    <option value="1080">1080p</option>
                    <option value="720">720p</option>
                    <option value="480">480p</option>
                    <option value="custom">Custom</option>
                </select>
            </div>
            <div class="form-group" id="customSizeGroup" style="display: none;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <label>Width</label>
                        <input type="number" id="exportWidth" class="form-input" value="1200">
                    </div>
                    <div>
                        <label>Height</label>
                        <input type="number" id="exportHeight" class="form-input" value="1200">
                    </div>
                </div>
            </div>
            <button class="btn btn-primary" onclick="exportDesign()" style="width: 100%;">
                <i class="fas fa-download"></i> Export Design
            </button>
        </div>
    </div>

    <!-- Save Project Modal -->
    <div class="modal" id="saveProjectModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Save Project</h3>
                <button class="modal-close" onclick="closeModal('saveProjectModal')">&times;</button>
            </div>
            <div class="form-group">
                <label class="form-label">Project Name</label>
                <input type="text" class="form-input" id="projectName" placeholder="Enter project name" value="My Design">
            </div>
            <div class="form-group">
                <label class="form-label">Your Projects</label>
                <div id="projectList" style="max-height: 200px; overflow-y: auto;">
                    <?php foreach($userProjects as $project): ?>
                        <div class="project-item" style="padding: 10px; background: var(--lighter); border-radius: 5px; margin-bottom: 5px; cursor: pointer;" onclick="loadProject(<?php echo $project['id']; ?>)">
                            <div style="display: flex; justify-content: space-between;">
                                <span><?php echo htmlspecialchars($project['project_name']); ?></span>
                                <small><?php echo date('M j, Y', strtotime($project['created_at'])); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <button class="btn btn-primary" onclick="saveProjectToDB()" style="width: 100%;">
                <i class="fas fa-save"></i> Save Project
            </button>
        </div>
    </div>

    <!-- Context Menu -->
    <div class="context-menu" id="contextMenu">
        <div class="context-menu-item" onclick="duplicateElement()">
            <i class="fas fa-copy"></i> Duplicate
        </div>
        <div class="context-menu-item" onclick="deleteElement(selectedElement)">
            <i class="fas fa-trash"></i> Delete
        </div>
        <div class="context-menu-item" onclick="bringToFront()">
            <i class="fas fa-arrow-up"></i> Bring to Front
        </div>
        <div class="context-menu-item" onclick="sendToBack()">
            <i class="fas fa-arrow-down"></i> Send to Back
        </div>
        <div class="context-menu-item" onclick="lockElement()">
            <i class="fas fa-lock"></i> Lock
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast"></div>

    <script>
        // Global variables
        let canvas, ctx;
        let currentTool = 'select';
        let zoomLevel = 1;
        let elements = [];
        let selectedElement = null;
        let history = [];
        let historyIndex = -1;
        let isDragging = false;
        let isResizing = false;
        let resizeHandle = null;
        let dragStartX, dragStartY;
        let currentColor = '#000000';
        let images = {};
        let snapToGrid = true;
        let gridVisible = false;
        let snapThreshold = 10;

        // Initialize editor
        document.addEventListener('DOMContentLoaded', function() {
            initializeCanvas();
            initializeEventListeners();
            loadDefaultTemplate();
            
            // Load saved state from session
            const savedState = <?php echo json_encode($_SESSION['editor_state']); ?>;
            if (savedState.elements && savedState.elements.length > 0) {
                elements = savedState.elements;
                renderCanvas();
            }
        });

        function initializeCanvas() {
            canvas = document.getElementById('designCanvas');
            ctx = canvas.getContext('2d');
            
            // Set initial background
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            
            // Add initial background element
            elements.push({
                id: 'background',
                type: 'background',
                x: 0,
                y: 0,
                width: canvas.width,
                height: canvas.height,
                color: '#ffffff',
                zIndex: 0
            });
            
            saveState();
        }

        function initializeEventListeners() {
            // Canvas events
            canvas.addEventListener('mousedown', handleMouseDown);
            canvas.addEventListener('mousemove', handleMouseMove);
            canvas.addEventListener('mouseup', handleMouseUp);
            canvas.addEventListener('wheel', handleZoom);
            canvas.addEventListener('contextmenu', handleContextMenu);

            // Text background toggle
            document.getElementById('textBackground').addEventListener('change', function() {
                document.getElementById('textBgColorGroup').style.display = this.checked ? 'block' : 'none';
            });

            // Export size change
            document.getElementById('exportSize').addEventListener('change', function() {
                document.getElementById('customSizeGroup').style.display = 
                    this.value === 'custom' ? 'block' : 'none';
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey || e.metaKey) {
                    switch(e.key) {
                        case 'z':
                            e.preventDefault();
                            undo();
                            break;
                        case 'y':
                            e.preventDefault();
                            redo();
                            break;
                        case 's':
                            e.preventDefault();
                            saveProject();
                            break;
                        case 'd':
                            e.preventDefault();
                            duplicateElement();
                            break;
                        case 'g':
                            e.preventDefault();
                            groupElements();
                            break;
                    }
                }
                
                // Delete key
                if (e.key === 'Delete' && selectedElement) {
                    deleteElement(selectedElement);
                }
                
                // Arrow keys for fine positioning
                if (selectedElement && !e.ctrlKey && !e.metaKey) {
                    switch(e.key) {
                        case 'ArrowUp':
                            e.preventDefault();
                            selectedElement.y -= 1;
                            renderCanvas();
                            saveState();
                            break;
                        case 'ArrowDown':
                            e.preventDefault();
                            selectedElement.y += 1;
                            renderCanvas();
                            saveState();
                            break;
                        case 'ArrowLeft':
                            e.preventDefault();
                            selectedElement.x -= 1;
                            renderCanvas();
                            saveState();
                            break;
                        case 'ArrowRight':
                            e.preventDefault();
                            selectedElement.x += 1;
                            renderCanvas();
                            saveState();
                            break;
                    }
                }
            });

            // Auto-save state when leaving page
            window.addEventListener('beforeunload', saveEditorState);
        }

        function handleMouseDown(e) {
            const rect = canvas.getBoundingClientRect();
            const x = (e.clientX - rect.left) / zoomLevel;
            const y = (e.clientY - rect.top) / zoomLevel;
            
            dragStartX = x;
            dragStartY = y;
            
            // Check if clicking on resize handle
            const handle = getResizeHandleAt(x, y);
            if (handle && selectedElement) {
                isResizing = true;
                resizeHandle = handle;
                return;
            }
            
            // Check if clicking on an element
            selectedElement = getElementAt(x, y);
            
            if (selectedElement) {
                isDragging = true;
                updatePropertiesPanel(selectedElement);
                updateLayersPanel();
                showSelectionHandles(selectedElement);
            } else {
                hideSelectionHandles();
            }
            
            renderCanvas();
        }

        function handleMouseMove(e) {
            if (!isDragging && !isResizing) return;
            
            const rect = canvas.getBoundingClientRect();
            const x = (e.clientX - rect.left) / zoomLevel;
            const y = (e.clientY - rect.top) / zoomLevel;
            
            const dx = x - dragStartX;
            const dy = y - dragStartY;
            
            if (isDragging && selectedElement) {
                // Apply snap to grid if enabled
                if (snapToGrid) {
                    selectedElement.x = Math.round((selectedElement.x + dx) / snapThreshold) * snapThreshold;
                    selectedElement.y = Math.round((selectedElement.y + dy) / snapThreshold) * snapThreshold;
                } else {
                    selectedElement.x += dx;
                    selectedElement.y += dy;
                }
            } else if (isResizing && selectedElement) {
                resizeElement(selectedElement, resizeHandle, dx, dy);
            }
            
            dragStartX = x;
            dragStartY = y;
            
            renderCanvas();
            updatePropertiesPanel(selectedElement);
        }

        function handleMouseUp() {
            if (isDragging || isResizing) {
                saveState();
            }
            isDragging = false;
            isResizing = false;
            resizeHandle = null;
        }

        function handleContextMenu(e) {
            e.preventDefault();
            const rect = canvas.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            // Check if right-clicking on an element
            const element = getElementAt(x / zoomLevel, y / zoomLevel);
            if (element) {
                selectedElement = element;
                showContextMenu(e.clientX, e.clientY);
            }
        }

        function showContextMenu(x, y) {
            const contextMenu = document.getElementById('contextMenu');
            contextMenu.style.left = x + 'px';
            contextMenu.style.top = y + 'px';
            contextMenu.style.display = 'block';
            
            // Hide context menu when clicking elsewhere
            document.addEventListener('click', function hideContextMenu() {
                contextMenu.style.display = 'none';
                document.removeEventListener('click', hideContextMenu);
            });
        }

        function resizeElement(element, handle, dx, dy) {
            switch(handle) {
                case 'nw':
                    element.x += dx;
                    element.y += dy;
                    element.width = Math.max(10, element.width - dx);
                    element.height = Math.max(10, element.height - dy);
                    break;
                case 'ne':
                    element.y += dy;
                    element.width = Math.max(10, element.width + dx);
                    element.height = Math.max(10, element.height - dy);
                    break;
                case 'sw':
                    element.x += dx;
                    element.width = Math.max(10, element.width - dx);
                    element.height = Math.max(10, element.height + dy);
                    break;
                case 'se':
                    element.width = Math.max(10, element.width + dx);
                    element.height = Math.max(10, element.height + dy);
                    break;
            }
        }

        function getResizeHandleAt(x, y) {
            if (!selectedElement) return null;
            
            const handles = [
                { id: 'nw', x: selectedElement.x, y: selectedElement.y },
                { id: 'ne', x: selectedElement.x + selectedElement.width, y: selectedElement.y },
                { id: 'sw', x: selectedElement.x, y: selectedElement.y + selectedElement.height },
                { id: 'se', x: selectedElement.x + selectedElement.width, y: selectedElement.y + selectedElement.height }
            ];
            
            const handleSize = 12;
            for (let handle of handles) {
                if (x >= handle.x - handleSize/2 && x <= handle.x + handleSize/2 &&
                    y >= handle.y - handleSize/2 && y <= handle.y + handleSize/2) {
                    return handle.id;
                }
            }
            
            return null;
        }

        function showSelectionHandles(element) {
            const handlesContainer = document.getElementById('selectionHandles');
            handlesContainer.innerHTML = '';
            
            const handles = [
                { id: 'nw', x: element.x, y: element.y },
                { id: 'ne', x: element.x + element.width, y: element.y },
                { id: 'sw', x: element.x, y: element.y + element.height },
                { id: 'se', x: element.x + element.width, y: element.y + element.height }
            ];
            
            handles.forEach(handle => {
                const handleEl = document.createElement('div');
                handleEl.className = `selection-handle ${handle.id}`;
                handleEl.style.left = (handle.x * zoomLevel) + 'px';
                handleEl.style.top = (handle.y * zoomLevel) + 'px';
                handlesContainer.appendChild(handleEl);
            });
            
            handlesContainer.style.pointerEvents = 'auto';
        }

        function hideSelectionHandles() {
            document.getElementById('selectionHandles').innerHTML = '';
        }

        function getElementAt(x, y) {
            // Check elements in reverse order (top to bottom)
            for (let i = elements.length - 1; i >= 0; i--) {
                const element = elements[i];
                if (element.type === 'background') continue;
                
                if (x >= element.x && x <= element.x + element.width &&
                    y >= element.y && y <= element.y + element.height) {
                    return element;
                }
            }
            return null;
        }

        function renderCanvas() {
            // Clear canvas
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            
            // Apply zoom
            ctx.save();
            ctx.scale(zoomLevel, zoomLevel);
            
            // Draw all elements in z-index order
            elements.sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0));
            elements.forEach(element => {
                drawElement(element);
            });
            
            ctx.restore();
        }

        function drawElement(element) {
            switch(element.type) {
                case 'background':
                    ctx.fillStyle = element.color;
                    ctx.fillRect(element.x, element.y, element.width, element.height);
                    break;
                case 'text':
                    ctx.fillStyle = element.color;
                    ctx.font = `${element.bold ? 'bold ' : ''}${element.italic ? 'italic ' : ''}${element.fontSize}px ${element.fontFamily}`;
                    
                    // Draw background if enabled
                    if (element.background) {
                        ctx.fillStyle = element.backgroundColor;
                        ctx.fillRect(element.x - 5, element.y - element.fontSize, 
                                   ctx.measureText(element.text).width + 10, element.fontSize + 10);
                    }
                    
                    // Draw shadow if enabled
                    if (element.shadow) {
                        ctx.shadowColor = 'rgba(0,0,0,0.5)';
                        ctx.shadowBlur = 5;
                        ctx.shadowOffsetX = 2;
                        ctx.shadowOffsetY = 2;
                    }
                    
                    ctx.fillStyle = element.color;
                    
                    // Apply underline if enabled
                    if (element.underline) {
                        ctx.fillText(element.text, element.x, element.y);
                        ctx.strokeStyle = element.color;
                        ctx.lineWidth = 1;
                        ctx.beginPath();
                        ctx.moveTo(element.x, element.y + 2);
                        ctx.lineTo(element.x + ctx.measureText(element.text).width, element.y + 2);
                        ctx.stroke();
                    } else {
                        ctx.fillText(element.text, element.x, element.y);
                    }
                    
                    // Reset shadow
                    ctx.shadowColor = 'transparent';
                    ctx.shadowBlur = 0;
                    ctx.shadowOffsetX = 0;
                    ctx.shadowOffsetY = 0;
                    break;
                case 'rectangle':
                    ctx.fillStyle = element.color;
                    ctx.fillRect(element.x, element.y, element.width, element.height);
                    break;
                case 'circle':
                    ctx.fillStyle = element.color;
                    ctx.beginPath();
                    ctx.arc(element.x + element.width/2, element.y + element.height/2, element.width/2, 0, 2 * Math.PI);
                    ctx.fill();
                    break;
                case 'triangle':
                    ctx.fillStyle = element.color;
                    ctx.beginPath();
                    ctx.moveTo(element.x + element.width/2, element.y);
                    ctx.lineTo(element.x + element.width, element.y + element.height);
                    ctx.lineTo(element.x, element.y + element.height);
                    ctx.closePath();
                    ctx.fill();
                    break;
                case 'line':
                    ctx.strokeStyle = element.color;
                    ctx.lineWidth = element.lineWidth || 2;
                    ctx.beginPath();
                    ctx.moveTo(element.x, element.y);
                    ctx.lineTo(element.x + element.width, element.y + element.height);
                    ctx.stroke();
                    break;
                case 'star':
                    ctx.fillStyle = element.color;
                    drawStar(ctx, element.x + element.width/2, element.y + element.height/2, 5, element.width/2, element.width/4);
                    ctx.fill();
                    break;
                case 'heart':
                    ctx.fillStyle = element.color;
                    drawHeart(ctx, element.x + element.width/2, element.y + element.height/2, element.width/2);
                    ctx.fill();
                    break;
                case 'image':
                    if (images[element.src]) {
                        ctx.drawImage(images[element.src], element.x, element.y, element.width, element.height);
                    }
                    break;
            }
        }

        function drawStar(ctx, cx, cy, spikes, outerRadius, innerRadius) {
            let rot = Math.PI / 2 * 3;
            let x = cx;
            let y = cy;
            let step = Math.PI / spikes;

            ctx.beginPath();
            ctx.moveTo(cx, cy - outerRadius);
            for (let i = 0; i < spikes; i++) {
                x = cx + Math.cos(rot) * outerRadius;
                y = cy + Math.sin(rot) * outerRadius;
                ctx.lineTo(x, y);
                rot += step;

                x = cx + Math.cos(rot) * innerRadius;
                y = cy + Math.sin(rot) * innerRadius;
                ctx.lineTo(x, y);
                rot += step;
            }
            ctx.lineTo(cx, cy - outerRadius);
            ctx.closePath();
        }

        function drawHeart(ctx, x, y, size) {
            ctx.beginPath();
            const topCurveHeight = size * 0.3;
            ctx.moveTo(x, y + size/3);
            // left top curve
            ctx.bezierCurveTo(
                x, y, 
                x - size/2, y, 
                x - size/2, y + size/3
            );
            // left bottom curve
            ctx.bezierCurveTo(
                x - size/2, y + size/2, 
                x, y + size, 
                x, y + size
            );
            // right bottom curve
            ctx.bezierCurveTo(
                x, y + size, 
                x + size/2, y + size/2, 
                x + size/2, y + size/3
            );
            // right top curve
            ctx.bezierCurveTo(
                x + size/2, y, 
                x, y, 
                x, y + size/3
            );
            ctx.closePath();
        }

        // Tool functions
        function setTool(tool) {
            currentTool = tool;
            
            // Update active tool buttons
            document.querySelectorAll('.tool-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById(tool + 'Tool').classList.add('active');
            
            switch(tool) {
                case 'text':
                    openTextModal();
                    break;
                case 'select':
                    canvas.style.cursor = 'default';
                    break;
            }
        }

        function addTextToCanvas() {
            const text = document.getElementById('textContent').value;
            const fontSize = parseInt(document.getElementById('textSize').value);
            const fontFamily = document.getElementById('textFont').value;
            const color = document.getElementById('textColor').value;
            const hasBackground = document.getElementById('textBackground').checked;
            const backgroundColor = document.getElementById('textBgColor').value;
            const hasShadow = document.getElementById('textShadow').checked;
            const isBold = document.getElementById('textBold').checked;
            const isItalic = document.getElementById('textItalic').checked;
            const isUnderline = document.getElementById('textUnderline').checked;
            
            const textElement = {
                id: 'text_' + Date.now(),
                type: 'text',
                text: text,
                x: 100,
                y: 100,
                width: 200,
                height: fontSize,
                color: color,
                fontSize: fontSize,
                fontFamily: fontFamily,
                background: hasBackground,
                backgroundColor: backgroundColor,
                shadow: hasShadow,
                bold: isBold,
                italic: isItalic,
                underline: isUnderline,
                zIndex: elements.length
            };
            
            elements.push(textElement);
            selectedElement = textElement;
            saveState();
            renderCanvas();
            updateLayersPanel();
            showSelectionHandles(selectedElement);
            closeModal('textModal');
        }

        function addTextElement(type) {
            const textElement = {
                id: 'text_' + Date.now(),
                type: 'text',
                text: type === 'Heading' ? 'Your Heading' : (type === 'Caption' ? 'Your Caption' : 'Your text here'),
                x: 100,
                y: 100,
                width: 200,
                height: type === 'Heading' ? 40 : (type === 'Caption' ? 20 : 24),
                color: '#000000',
                fontSize: type === 'Heading' ? 32 : (type === 'Caption' ? 14 : 16),
                fontFamily: 'Arial',
                zIndex: elements.length
            };
            
            elements.push(textElement);
            selectedElement = textElement;
            saveState();
            renderCanvas();
            updateLayersPanel();
            showSelectionHandles(selectedElement);
            updatePropertiesPanel(selectedElement);
        }

        function addShape(shapeType) {
            const shapeElement = {
                id: 'shape_' + Date.now(),
                type: shapeType,
                x: 200,
                y: 200,
                width: 100,
                height: 100,
                color: currentColor,
                zIndex: elements.length
            };
            
            elements.push(shapeElement);
            selectedElement = shapeElement;
            saveState();
            renderCanvas();
            updateLayersPanel();
            showSelectionHandles(selectedElement);
            updatePropertiesPanel(selectedElement);
        }

        // Image upload functions
        function handleImageUpload(files) {
            Array.from(files).forEach(file => {
                const formData = new FormData();
                formData.append('ajax', 'true');
                formData.append('action', 'upload_image');
                formData.append('image', file);

                showToast('Uploading image...', 'info');

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        addImageToCanvas(data.url, file);
                        showToast('Image uploaded successfully!', 'success');
                    } else {
                        showToast('Failed to upload image', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('Failed to upload image', 'error');
                });
            });
        }

        function addImageToCanvas(imageUrl, file) {
            const img = new Image();
            img.onload = function() {
                images[imageUrl] = img;
                
                const imgElement = {
                    id: 'image_' + Date.now(),
                    type: 'image',
                    src: imageUrl,
                    x: 100,
                    y: 100,
                    width: img.width > 500 ? 500 : img.width,
                    height: img.height > 500 ? 500 : img.height,
                    zIndex: elements.length,
                    originalWidth: img.width,
                    originalHeight: img.height
                };
                
                elements.push(imgElement);
                selectedElement = imgElement;
                saveState();
                renderCanvas();
                updateLayersPanel();
                showSelectionHandles(selectedElement);
                updatePropertiesPanel(selectedElement);
            };
            img.src = imageUrl;
        }

        // Filter and effect functions
        function applyFilter(filterType) {
            if (!selectedElement || selectedElement.type !== 'image') {
                showToast('Please select an image element first', 'error');
                return;
            }
            
            const intensity = parseFloat(document.getElementById('filterIntensity').value);
            
            // Apply filter to the selected image
            const img = images[selectedElement.src];
            if (img) {
                const tempCanvas = document.createElement('canvas');
                const tempCtx = tempCanvas.getContext('2d');
                tempCanvas.width = img.width;
                tempCanvas.height = img.height;
                
                tempCtx.drawImage(img, 0, 0);
                
                // Apply filter based on type
                switch(filterType) {
                    case 'brightness':
                        tempCtx.filter = `brightness(${intensity})`;
                        break;
                    case 'contrast':
                        tempCtx.filter = `contrast(${intensity * 100}%)`;
                        break;
                    case 'saturation':
                        tempCtx.filter = `saturate(${intensity})`;
                        break;
                    case 'blur':
                        tempCtx.filter = `blur(${intensity}px)`;
                        break;
                    case 'grayscale':
                        tempCtx.filter = 'grayscale(1)';
                        break;
                    case 'sepia':
                        tempCtx.filter = 'sepia(1)';
                        break;
                    case 'invert':
                        tempCtx.filter = 'invert(1)';
                        break;
                    case 'hue-rotate':
                        tempCtx.filter = `hue-rotate(${intensity * 180}deg)`;
                        break;
                }
                
                tempCtx.drawImage(tempCanvas, 0, 0);
                
                // Update the image
                const filteredImg = new Image();
                filteredImg.onload = function() {
                    images[selectedElement.src] = filteredImg;
                    renderCanvas();
                    saveState();
                    showToast(`Applied ${filterType} filter`, 'success');
                };
                filteredImg.src = tempCanvas.toDataURL();
            }
            
            closeModal('filtersModal');
        }

        function applyCrop() {
            if (!selectedElement || selectedElement.type !== 'image') {
                showToast('Please select an image element first', 'error');
                return;
            }
            
            const cropX = parseInt(document.getElementById('cropX').value);
            const cropY = parseInt(document.getElementById('cropY').value);
            const cropWidth = parseInt(document.getElementById('cropWidth').value);
            const cropHeight = parseInt(document.getElementById('cropHeight').value);
            
            const img = images[selectedElement.src];
            if (img) {
                const tempCanvas = document.createElement('canvas');
                const tempCtx = tempCanvas.getContext('2d');
                tempCanvas.width = cropWidth;
                tempCanvas.height = cropHeight;
                
                tempCtx.drawImage(img, cropX, cropY, cropWidth, cropHeight, 0, 0, cropWidth, cropHeight);
                
                // Update the image
                const croppedImg = new Image();
                croppedImg.onload = function() {
                    images[selectedElement.src] = croppedImg;
                    selectedElement.width = cropWidth;
                    selectedElement.height = cropHeight;
                    renderCanvas();
                    saveState();
                    showToast('Image cropped successfully', 'success');
                };
                croppedImg.src = tempCanvas.toDataURL();
            }
            
            closeModal('cropModal');
        }

        // Properties panel
        function updatePropertiesPanel(element) {
            const panel = document.getElementById('propertiesPanel');
            let html = '';
            
            if (!element) {
                html = '<p style="color: var(--text-secondary); text-align: center; padding: 20px;">Select an element to edit properties</p>';
            } else {
                html = `
                    <div class="property-group">
                        <h4>Position & Size</h4>
                        <div class="form-group">
                            <label class="form-label">Position X</label>
                            <input type="number" class="form-input" value="${element.x}" onchange="updateElementProperty('x', parseInt(this.value))">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Position Y</label>
                            <input type="number" class="form-input" value="${element.y}" onchange="updateElementProperty('y', parseInt(this.value))">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Width</label>
                            <input type="number" class="form-input" value="${element.width}" onchange="updateElementProperty('width', parseInt(this.value))">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Height</label>
                            <input type="number" class="form-input" value="${element.height}" onchange="updateElementProperty('height', parseInt(this.value))">
                        </div>
                    </div>
                `;
                
                if (element.type === 'text') {
                    html += `
                        <div class="property-group">
                            <h4>Text Properties</h4>
                            <div class="form-group">
                                <label class="form-label">Text Content</label>
                                <textarea class="form-textarea" onchange="updateElementProperty('text', this.value)">${element.text}</textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Font Family</label>
                                <select class="form-select" onchange="updateElementProperty('fontFamily', this.value)">
                                    <option value="Arial" ${element.fontFamily === 'Arial' ? 'selected' : ''}>Arial</option>
                                    <option value="Helvetica" ${element.fontFamily === 'Helvetica' ? 'selected' : ''}>Helvetica</option>
                                    <option value="Times New Roman" ${element.fontFamily === 'Times New Roman' ? 'selected' : ''}>Times New Roman</option>
                                    <option value="Georgia" ${element.fontFamily === 'Georgia' ? 'selected' : ''}>Georgia</option>
                                    <option value="Verdana" ${element.fontFamily === 'Verdana' ? 'selected' : ''}>Verdana</option>
                                    <option value="Courier New" ${element.fontFamily === 'Courier New' ? 'selected' : ''}>Courier New</option>
                                </select>
                            </div>
                            <div class="slider-container">
                                <label>Font Size</label>
                                <input type="range" class="slider" value="${element.fontSize}" min="10" max="100" onchange="updateElementProperty('fontSize', parseInt(this.value))">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Text Color</label>
                                <input type="color" value="${element.color}" onchange="updateElementProperty('color', this.value)" style="width: 100%; height: 40px;">
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" ${element.bold ? 'checked' : ''} onchange="updateElementProperty('bold', this.checked)"> Bold
                                </label>
                                <label>
                                    <input type="checkbox" ${element.italic ? 'checked' : ''} onchange="updateElementProperty('italic', this.checked)"> Italic
                                </label>
                                <label>
                                    <input type="checkbox" ${element.underline ? 'checked' : ''} onchange="updateElementProperty('underline', this.checked)"> Underline
                                </label>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" ${element.background ? 'checked' : ''} onchange="updateElementProperty('background', this.checked)"> Text Background
                                </label>
                            </div>
                            ${element.background ? `
                            <div class="form-group">
                                <label class="form-label">Background Color</label>
                                <input type="color" value="${element.backgroundColor}" onchange="updateElementProperty('backgroundColor', this.value)" style="width: 100%; height: 40px;">
                            </div>
                            ` : ''}
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" ${element.shadow ? 'checked' : ''} onchange="updateElementProperty('shadow', this.checked)"> Text Shadow
                                </label>
                            </div>
                        </div>
                    `;
                } else if (element.type !== 'image') {
                    html += `
                        <div class="property-group">
                            <h4>Appearance</h4>
                            <div class="form-group">
                                <label class="form-label">Color</label>
                                <input type="color" value="${element.color}" onchange="updateElementProperty('color', this.value)" style="width: 100%; height: 40px;">
                            </div>
                        </div>
                    `;
                }
                
                html += `
                    <div class="property-group">
                        <h4>Actions</h4>
                        <button class="btn btn-danger" onclick="deleteElement(currentElement)" style="width: 100%;">
                            <i class="fas fa-trash"></i> Delete Element
                        </button>
                    </div>
                `;
            }
            
            panel.innerHTML = html;
            // Store reference to current element for delete function
            if (element) window.currentElement = element;
        }

        function updateElementProperty(property, value) {
            if (selectedElement) {
                selectedElement[property] = value;
                if (property === 'fontSize' && selectedElement.type === 'text') {
                    selectedElement.height = value;
                }
                saveState();
                renderCanvas();
                showSelectionHandles(selectedElement);
            }
        }

        function updateLayersPanel() {
            const layersPanel = document.getElementById('layersPanel');
            let html = '';
            
            // Add background layer
            const background = elements.find(el => el.type === 'background');
            if (background) {
                html += `
                    <div class="layer-item ${selectedElement === background ? 'active' : ''}" onclick="selectElementById('${background.id}')">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span>Background</span>
                            <div class="layer-controls">
                                <button class="layer-control-btn" onclick="event.stopPropagation();">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="layer-control-btn" onclick="event.stopPropagation();">
                                    <i class="fas fa-lock"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            // Add other elements
            elements.filter(el => el.type !== 'background')
                   .sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0))
                   .forEach((element, index) => {
                html += `
                    <div class="layer-item ${selectedElement === element ? 'active' : ''}" onclick="selectElementById('${element.id}')">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span>${element.type} ${index + 1}</span>
                            <div class="layer-controls">
                                <button class="layer-control-btn" onclick="event.stopPropagation(); moveElementUp('${element.id}')">
                                    <i class="fas fa-arrow-up"></i>
                                </button>
                                <button class="layer-control-btn" onclick="event.stopPropagation(); moveElementDown('${element.id}')">
                                    <i class="fas fa-arrow-down"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            layersPanel.innerHTML = html || '<p style="color: var(--text-secondary); text-align: center;">No elements</p>';
        }

        function selectElementById(id) {
            selectedElement = elements.find(el => el.id === id);
            renderCanvas();
            updatePropertiesPanel(selectedElement);
            showSelectionHandles(selectedElement);
        }

        function moveElementUp(id) {
            const element = elements.find(el => el.id === id);
            if (element) {
                element.zIndex = (element.zIndex || 0) + 1;
                saveState();
                renderCanvas();
                updateLayersPanel();
            }
        }

        function moveElementDown(id) {
            const element = elements.find(el => el.id === id);
            if (element && (element.zIndex || 0) > 0) {
                element.zIndex = (element.zIndex || 0) - 1;
                saveState();
                renderCanvas();
                updateLayersPanel();
            }
        }

        function bringToFront() {
            if (selectedElement) {
                selectedElement.zIndex = elements.length;
                saveState();
                renderCanvas();
                updateLayersPanel();
            }
        }

        function sendToBack() {
            if (selectedElement) {
                selectedElement.zIndex = 1; // Just above background
                saveState();
                renderCanvas();
                updateLayersPanel();
            }
        }

        function lockElement() {
            if (selectedElement) {
                selectedElement.locked = !selectedElement.locked;
                showToast(selectedElement.locked ? 'Element locked' : 'Element unlocked', 'info');
            }
        }

        function deleteElement(element) {
            const index = elements.indexOf(element);
            if (index > -1) {
                elements.splice(index, 1);
                selectedElement = null;
                saveState();
                renderCanvas();
                updateLayersPanel();
                hideSelectionHandles();
                updatePropertiesPanel(null);
            }
        }

        function duplicateElement() {
            if (selectedElement) {
                const duplicated = JSON.parse(JSON.stringify(selectedElement));
                duplicated.id = duplicated.type + '_' + Date.now();
                duplicated.x += 20;
                duplicated.y += 20;
                duplicated.zIndex = elements.length;
                
                elements.push(duplicated);
                selectedElement = duplicated;
                saveState();
                renderCanvas();
                updateLayersPanel();
                showSelectionHandles(selectedElement);
                updatePropertiesPanel(selectedElement);
            }
        }

        function groupElements() {
            // Simple grouping implementation
            // In a real implementation, you'd want to handle multiple selected elements
            showToast('Grouping feature would be implemented here', 'info');
        }

        // History management
        function saveState() {
            history = history.slice(0, historyIndex + 1);
            history.push(JSON.parse(JSON.stringify(elements)));
            historyIndex++;
            saveEditorState();
        }

        function saveEditorState() {
            const state = {
                elements: elements,
                current_project: null,
                history: history,
                history_index: historyIndex
            };
            
            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'save_state');
            formData.append('state', JSON.stringify(state));
            
            fetch('', {
                method: 'POST',
                body: formData
            });
        }

        function undo() {
            if (historyIndex > 0) {
                historyIndex--;
                elements = JSON.parse(JSON.stringify(history[historyIndex]));
                renderCanvas();
                updateLayersPanel();
                updatePropertiesPanel(selectedElement);
            }
        }

        function redo() {
            if (historyIndex < history.length - 1) {
                historyIndex++;
                elements = JSON.parse(JSON.stringify(history[historyIndex]));
                renderCanvas();
                updateLayersPanel();
                updatePropertiesPanel(selectedElement);
            }
        }

        function clearCanvas() {
            if (confirm('Are you sure you want to clear the canvas?')) {
                elements = elements.filter(el => el.type === 'background');
                selectedElement = null;
                saveState();
                renderCanvas();
                updateLayersPanel();
                updatePropertiesPanel(null);
                hideSelectionHandles();
            }
        }

        // Zoom functions
        function zoomIn() {
            zoomLevel = Math.min(5, zoomLevel * 1.2);
            updateZoomDisplay();
            renderCanvas();
        }

        function zoomOut() {
            zoomLevel = Math.max(0.1, zoomLevel / 1.2);
            updateZoomDisplay();
            renderCanvas();
        }

        function resetZoom() {
            zoomLevel = 1;
            updateZoomDisplay();
            renderCanvas();
        }

        function updateZoomDisplay() {
            document.getElementById('zoomLevel').textContent = Math.round(zoomLevel * 100) + '%';
        }

        function handleZoom(e) {
            e.preventDefault();
            
            const rect = canvas.getBoundingClientRect();
            const x = (e.clientX - rect.left) / zoomLevel;
            const y = (e.clientY - rect.top) / zoomLevel;
            
            const delta = e.deltaY > 0 ? 0.9 : 1.1;
            zoomLevel = Math.max(0.1, Math.min(5, zoomLevel * delta));
            
            updateZoomDisplay();
            renderCanvas();
        }

        // Grid and snap functions
        function toggleGrid() {
            gridVisible = !gridVisible;
            document.getElementById('gridOverlay').style.display = gridVisible ? 'block' : 'none';
            document.getElementById('gridToggle').style.background = gridVisible ? 'var(--primary)' : 'var(--light)';
        }

        function toggleSnapToGrid() {
            snapToGrid = !snapToGrid;
            document.getElementById('snapToggle').style.background = snapToGrid ? 'var(--primary)' : 'var(--light)';
            showToast(snapToGrid ? 'Snap to grid enabled' : 'Snap to grid disabled', 'info');
        }

        // Template functions
        function loadTemplate(type) {
            let template;
            
            switch(type) {
                case 'social':
                    template = {
                        width: 1080,
                        height: 1080,
                        backgroundColor: '#ffffff',
                        elements: [
                            {
                                id: 'bg_gradient',
                                type: 'rectangle',
                                x: 0,
                                y: 0,
                                width: 1080,
                                height: 1080,
                                color: 'linear-gradient(45deg, #667eea, #764ba2)',
                                zIndex: 1
                            },
                            {
                                id: 'title_text',
                                type: 'text',
                                text: 'Your Title Here',
                                x: 100,
                                y: 200,
                                width: 300,
                                height: 50,
                                color: '#ffffff',
                                fontSize: 48,
                                fontFamily: 'Arial',
                                bold: true,
                                zIndex: 2
                            }
                        ]
                    };
                    break;
                case 'marketing':
                    template = {
                        width: 1200,
                        height: 800,
                        backgroundColor: '#ffffff',
                        elements: [
                            {
                                id: 'header',
                                type: 'rectangle',
                                x: 0,
                                y: 0,
                                width: 1200,
                                height: 100,
                                color: '#4ecdc4',
                                zIndex: 1
                            },
                            {
                                id: 'title',
                                type: 'text',
                                text: 'Marketing Banner',
                                x: 100,
                                y: 30,
                                width: 400,
                                height: 40,
                                color: '#ffffff',
                                fontSize: 32,
                                fontFamily: 'Arial',
                                bold: true,
                                zIndex: 2
                            }
                        ]
                    };
                    break;
                case 'presentation':
                    template = {
                        width: 1920,
                        height: 1080,
                        backgroundColor: '#f8f9fa',
                        elements: [
                            {
                                id: 'title',
                                type: 'text',
                                text: 'Presentation Title',
                                x: 200,
                                y: 200,
                                width: 600,
                                height: 60,
                                color: '#333333',
                                fontSize: 48,
                                fontFamily: 'Arial',
                                bold: true,
                                zIndex: 1
                            },
                            {
                                id: 'subtitle',
                                type: 'text',
                                text: 'Subtitle or description',
                                x: 200,
                                y: 280,
                                width: 600,
                                height: 30,
                                color: '#666666',
                                fontSize: 24,
                                fontFamily: 'Arial',
                                zIndex: 1
                            }
                        ]
                    };
                    break;
            }
            
            applyTemplate(template);
        }

        function applyTemplate(template) {
            // Clear current elements except background
            elements = elements.filter(el => el.type === 'background');
            
            // Update background
            const background = elements.find(el => el.type === 'background');
            if (background) {
                background.color = template.backgroundColor;
                background.width = template.width;
                background.height = template.height;
            }
            
            // Update canvas size
            canvas.width = template.width;
            canvas.height = template.height;
            
            // Add template elements
            if (template.elements) {
                template.elements.forEach(element => {
                    elements.push({...element});
                });
            }
            
            saveState();
            renderCanvas();
            updateLayersPanel();
            showToast('Template applied successfully!', 'success');
        }

        function loadDefaultTemplate() {
            // Load a simple default template
            const defaultTemplate = {
                width: 1200,
                height: 1200,
                backgroundColor: '#ffffff',
                elements: []
            };
            
            applyTemplate(defaultTemplate);
        }

        // Export function
        function openExportModal() {
            document.getElementById('exportModal').style.display = 'flex';
        }

        function exportDesign() {
            const format = document.getElementById('exportFormat').value;
            const quality = parseFloat(document.getElementById('exportQuality').value);
            const sizeOption = document.getElementById('exportSize').value;
            
            let exportWidth, exportHeight;
            
            if (sizeOption === 'original') {
                exportWidth = canvas.width;
                exportHeight = canvas.height;
            } else if (sizeOption === 'custom') {
                exportWidth = parseInt(document.getElementById('exportWidth').value);
                exportHeight = parseInt(document.getElementById('exportHeight').value);
            } else {
                exportWidth = parseInt(sizeOption);
                exportHeight = Math.round((exportWidth / canvas.width) * canvas.height);
            }
            
            // Create a temporary canvas for export
            const exportCanvas = document.createElement('canvas');
            exportCanvas.width = exportWidth;
            exportCanvas.height = exportHeight;
            const exportCtx = exportCanvas.getContext('2d');
            
            // Scale context to match export size
            const scaleX = exportWidth / canvas.width;
            const scaleY = exportHeight / canvas.height;
            exportCtx.scale(scaleX, scaleY);
            
            // Draw background
            const background = elements.find(el => el.type === 'background');
            if (background) {
                exportCtx.fillStyle = background.color;
                exportCtx.fillRect(0, 0, canvas.width, canvas.height);
            }
            
            // Draw all elements except background in z-index order
            const exportElements = elements.filter(el => el.type !== 'background')
                                          .sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0));
            
            exportElements.forEach(element => {
                drawElementOnContext(exportCtx, element);
            });
            
            // Create download link
            const link = document.createElement('a');
            link.download = `design.${format}`;
            
            if (format === 'svg') {
                // For SVG export, we'd need a more complex implementation
                // This is a simplified version
                showToast('SVG export would require additional implementation', 'info');
                return;
            } else {
                link.href = exportCanvas.toDataURL(`image/${format}`, quality);
            }
            
            link.click();
            
            showToast('Design exported successfully!', 'success');
            closeModal('exportModal');
        }

        function drawElementOnContext(context, element) {
            switch(element.type) {
                case 'text':
                    context.fillStyle = element.color;
                    context.font = `${element.bold ? 'bold ' : ''}${element.italic ? 'italic ' : ''}${element.fontSize}px ${element.fontFamily}`;
                    
                    if (element.background) {
                        context.fillStyle = element.backgroundColor;
                        context.fillRect(element.x - 5, element.y - element.fontSize, 
                                       context.measureText(element.text).width + 10, element.fontSize + 10);
                    }
                    
                    if (element.shadow) {
                        context.shadowColor = 'rgba(0,0,0,0.5)';
                        context.shadowBlur = 5;
                        context.shadowOffsetX = 2;
                        context.shadowOffsetY = 2;
                    }
                    
                    context.fillStyle = element.color;
                    
                    if (element.underline) {
                        context.fillText(element.text, element.x, element.y);
                        context.strokeStyle = element.color;
                        context.lineWidth = 1;
                        context.beginPath();
                        context.moveTo(element.x, element.y + 2);
                        context.lineTo(element.x + context.measureText(element.text).width, element.y + 2);
                        context.stroke();
                    } else {
                        context.fillText(element.text, element.x, element.y);
                    }
                    
                    context.shadowColor = 'transparent';
                    context.shadowBlur = 0;
                    context.shadowOffsetX = 0;
                    context.shadowOffsetY = 0;
                    break;
                case 'rectangle':
                    context.fillStyle = element.color;
                    context.fillRect(element.x, element.y, element.width, element.height);
                    break;
                case 'circle':
                    context.fillStyle = element.color;
                    context.beginPath();
                    context.arc(element.x + element.width/2, element.y + element.height/2, element.width/2, 0, 2 * Math.PI);
                    context.fill();
                    break;
                case 'triangle':
                    context.fillStyle = element.color;
                    context.beginPath();
                    context.moveTo(element.x + element.width/2, element.y);
                    context.lineTo(element.x + element.width, element.y + element.height);
                    context.lineTo(element.x, element.y + element.height);
                    context.closePath();
                    context.fill();
                    break;
                case 'line':
                    context.strokeStyle = element.color;
                    context.lineWidth = element.lineWidth || 2;
                    context.beginPath();
                    context.moveTo(element.x, element.y);
                    context.lineTo(element.x + element.width, element.y + element.height);
                    context.stroke();
                    break;
                case 'star':
                    context.fillStyle = element.color;
                    drawStar(context, element.x + element.width/2, element.y + element.height/2, 5, element.width/2, element.width/4);
                    context.fill();
                    break;
                case 'heart':
                    context.fillStyle = element.color;
                    drawHeart(context, element.x + element.width/2, element.y + element.height/2, element.width/2);
                    context.fill();
                    break;
                case 'image':
                    if (images[element.src]) {
                        context.drawImage(images[element.src], element.x, element.y, element.width, element.height);
                    }
                    break;
            }
        }

        // Project management
        function saveProject() {
            document.getElementById('saveProjectModal').style.display = 'flex';
        }

        function saveProjectToDB() {
            const projectName = document.getElementById('projectName').value;
            const projectData = JSON.stringify({
                elements: elements,
                canvas_width: canvas.width,
                canvas_height: canvas.height
            });
            
            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'save_project');
            formData.append('project_name', projectName);
            formData.append('project_data', projectData);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Project saved successfully!', 'success');
                    closeModal('saveProjectModal');
                    // Refresh project list
                    location.reload();
                } else {
                    showToast('Failed to save project: ' + data.error, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to save project', 'error');
            });
        }

        function loadProject(projectId) {
            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'load_project');
            formData.append('project_id', projectId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const projectData = JSON.parse(data.data);
                    elements = projectData.elements;
                    canvas.width = projectData.canvas_width || 1200;
                    canvas.height = projectData.canvas_height || 1200;
                    
                    renderCanvas();
                    updateLayersPanel();
                    showToast('Project loaded successfully!', 'success');
                    closeModal('saveProjectModal');
                } else {
                    showToast('Failed to load project: ' + data.error, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to load project', 'error');
            });
        }

        // Utility functions
        function setColor(color) {
            currentColor = color;
            if (selectedElement && selectedElement.type !== 'image') {
                selectedElement.color = color;
                saveState();
                renderCanvas();
                updatePropertiesPanel(selectedElement);
            }
        }

        // Modal functions
        function openTextModal() {
            document.getElementById('textModal').style.display = 'flex';
        }

        function openFiltersModal() {
            document.getElementById('filtersModal').style.display = 'flex';
        }

        function openCropModal() {
            if (!selectedElement || selectedElement.type !== 'image') {
                showToast('Please select an image element first', 'error');
                return;
            }
            document.getElementById('cropModal').style.display = 'flex';
        }

        function openRemoveBgModal() {
            document.getElementById('removeBgModal').style.display = 'flex';
        }

        function openTemplatesModal() {
            // Templates modal would open here
            showToast('Templates feature would open here', 'info');
        }

        function openUploadModal() {
            document.getElementById('imageUpload').click();
        }

        function openEffectsModal() {
            showToast('Advanced effects panel would open here', 'info');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function removeBackground() {
            const fileInput = document.getElementById('removeBgImage');
            if (!fileInput.files.length) {
                showToast('Please select an image first', 'error');
                return;
            }

            showToast('Background removal would process here', 'info');
            closeModal('removeBgModal');
        }

        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast';
            
            if (type === 'error') {
                toast.classList.add('error');
            } else if (type === 'success') {
                toast.classList.add('success');
            }
            
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
    </script>
</body>
</html>