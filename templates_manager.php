<?php
class WebsiteTemplateManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // Get all templates with filtering
    public function getTemplates($category = null, $isPremium = null) {
        $sql = "SELECT * FROM website_templates WHERE 1=1";
        $params = [];
        
        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        if ($isPremium !== null) {
            $sql .= " AND is_premium = ?";
            $params[] = $isPremium;
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get template with all sections
    public function getTemplateWithSections($templateId) {
        $sql = "SELECT t.*, 
                       ts.section_type, ts.section_order, ts.html_content, 
                       ts.css_styles, ts.javascript_code, ts.configuration
                FROM website_templates t
                LEFT JOIN template_sections ts ON t.id = ts.template_id
                WHERE t.id = ?
                ORDER BY ts.section_order";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$templateId]);
        
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($result)) return null;
        
        $template = $result[0];
        $template['sections'] = [];
        
        foreach ($result as $row) {
            if ($row['section_type']) {
                $template['sections'][] = [
                    'type' => $row['section_type'],
                    'order' => $row['section_order'],
                    'html' => $row['html_content'],
                    'css' => $row['css_styles'],
                    'js' => $row['javascript_code'],
                    'config' => json_decode($row['configuration'], true)
                ];
            }
        }
        
        return $template;
    }
    
    // Save user's custom design
    public function saveUserDesign($userId, $templateId, $projectName, $customizations) {
        $sql = "INSERT INTO user_website_designs 
                (user_id, template_id, project_name, custom_html, custom_css, custom_js, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                RETURNING id";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $userId, 
            $templateId, 
            $projectName,
            $customizations['html'] ?? '',
            $customizations['css'] ?? '',
            $customizations['js'] ?? ''
        ]);
        
        return $stmt->fetchColumn();
    }
    
    // Publish user design
    public function publishDesign($designId, $publishedUrl) {
        $sql = "UPDATE user_website_designs 
                SET is_published = true, published_url = ?, updated_at = NOW()
                WHERE id = ?";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$publishedUrl, $designId]);
    }
}
?>