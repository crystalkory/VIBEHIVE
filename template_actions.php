<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once "templates_manager.php";

// Database connection
require_once "config.php";

$templateManager = new WebsiteTemplateManager($pdo);
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'save_design':
        $templateId = $_POST['template_id'];
        $projectName = $_POST['project_name'];
        $customizations = json_decode($_POST['customizations'], true);
        
        $designId = $templateManager->saveUserDesign(
            $_SESSION['user_id'],
            $templateId,
            $projectName,
            $customizations
        );
        
        echo json_encode(['success' => true, 'design_id' => $designId]);
        break;
        
    case 'publish_design':
        $templateId = $_POST['template_id'];
        $customizations = json_decode($_POST['customizations'], true);
        
        // Generate unique URL
        $publishedUrl = 'user-' . $_SESSION['user_id'] . '-' . time() . '.yourdomain.com';
        
        // First save the design
        $designId = $templateManager->saveUserDesign(
            $_SESSION['user_id'],
            $templateId,
            'Published Website',
            $customizations
        );
        
        // Then publish it
        $success = $templateManager->publishDesign($designId, $publishedUrl);
        
        echo json_encode([
            'success' => $success, 
            'url' => $publishedUrl,
            'design_id' => $designId
        ]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>