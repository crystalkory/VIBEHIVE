<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "templates_manager.php";

require_once "config.php";
$templateManager = new WebsiteTemplateManager($pdo);
$templateId = $_GET['template_id'] ?? null;
$template = null;

if ($templateId) {
    $template = $templateManager->getTemplateWithSections($templateId);
}

if (!$template) {
    die("Template not found");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Builder - <?= htmlspecialchars($template['name']) ?></title>
    <style>
        .builder-container {
            display: flex;
            height: 100vh;
            background: #1a1a1a;
        }
        
        .toolbar {
            width: 60px;
            background: #2d2d2d;
            border-right: 1px solid #444;
            display: flex;
            flex-direction: column;
            padding: 10px 0;
        }
        
        .toolbar-btn {
            padding: 12px;
            background: none;
            border: none;
            color: #fff;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .toolbar-btn:hover {
            background: #3d3d3d;
        }
        
        .properties-panel {
            width: 300px;
            background: #2d2d2d;
            border-left: 1px solid #444;
            padding: 20px;
            color: white;
        }
        
        .workspace {
            flex: 1;
            background: #f8f9fa;
            overflow: auto;
            position: relative;
        }
        
        .website-preview {
            width: 100%;
            min-height: 100%;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        .section {
            border: 2px dashed transparent;
            transition: border-color 0.3s;
            position: relative;
        }
        
        .section:hover {
            border-color: #667eea;
        }
        
        .section-controls {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(0,0,0,0.8);
            padding: 5px;
            border-radius: 3px;
            display: none;
        }
        
        .section:hover .section-controls {
            display: block;
        }
        
        .template-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            padding: 20px;
        }
        
        .template-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            cursor: pointer;
        }
        
        .template-card:hover {
            transform: translateY(-5px);
        }
        
        .template-thumbnail {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
        
        .template-info {
            padding: 15px;
        }
    </style>
</head>
<body>
    <div class="builder-container">
        <!-- Left Toolbar -->
        <div class="toolbar">
            <button class="toolbar-btn" onclick="saveDesign()" title="Save">💾</button>
            <button class="toolbar-btn" onclick="previewDesign()" title="Preview">👁️</button>
            <button class="toolbar-btn" onclick="publishDesign()" title="Publish">🚀</button>
            <button class="toolbar-btn" onclick="addSection()" title="Add Section">➕</button>
        </div>
        
        <!-- Main Workspace -->
        <div class="workspace">
            <div class="website-preview" id="websitePreview">
                <!-- Template sections will be loaded here -->
                <?php foreach ($template['sections'] as $section): ?>
                    <div class="section" data-section-type="<?= $section['type'] ?>">
                        <?= $section['html'] ?>
                        <div class="section-controls">
                            <button onclick="editSection(this)">✏️</button>
                            <button onclick="deleteSection(this)">🗑️</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Right Properties Panel -->
        <div class="properties-panel">
            <h3>Properties</h3>
            <div id="propertiesContent">
                <p>Select an element to edit its properties</p>
            </div>
        </div>
    </div>

    <script>
        let currentDesign = {
            templateId: <?= $templateId ?>,
            sections: <?= json_encode($template['sections']) ?>,
            customizations: {}
        };

        // Initialize builder
        document.addEventListener('DOMContentLoaded', function() {
            initializeDragAndDrop();
            initializeTextEditing();
        });

        function initializeDragAndDrop() {
            const sections = document.querySelectorAll('.section');
            sections.forEach(section => {
                section.setAttribute('draggable', 'true');
                
                section.addEventListener('dragstart', (e) => {
                    e.dataTransfer.setData('text/plain', section.dataset.sectionType);
                });
                
                section.addEventListener('dragover', (e) => {
                    e.preventDefault();
                });
                
                section.addEventListener('drop', (e) => {
                    e.preventDefault();
                    const sectionType = e.dataTransfer.getData('text/plain');
                    // Handle section reordering
                });
            });
        }

        function initializeTextEditing() {
            document.addEventListener('dblclick', (e) => {
                if (e.target.classList.contains('editable')) {
                    makeElementEditable(e.target);
                }
            });
        }

        function makeElementEditable(element) {
            const originalContent = element.innerHTML;
            element.contentEditable = true;
            element.focus();
            
            element.addEventListener('blur', function() {
                element.contentEditable = false;
                if (element.innerHTML !== originalContent) {
                    saveContentChange(element);
                }
            });
            
            element.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    element.blur();
                }
            });
        }

        function saveContentChange(element) {
            const section = element.closest('.section');
            const sectionType = section.dataset.sectionType;
            
            if (!currentDesign.customizations[sectionType]) {
                currentDesign.customizations[sectionType] = {};
            }
            
            currentDesign.customizations[sectionType].content = element.innerHTML;
        }

        function saveDesign() {
            const projectName = prompt('Enter project name:');
            if (!projectName) return;

            const formData = new FormData();
            formData.append('action', 'save_design');
            formData.append('template_id', currentDesign.templateId);
            formData.append('project_name', projectName);
            formData.append('customizations', JSON.stringify(currentDesign.customizations));

            fetch('template_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Design saved successfully!');
                } else {
                    alert('Error saving design: ' + data.message);
                }
            });
        }

        function previewDesign() {
            const previewWindow = window.open('', '_blank');
            const designHtml = generateFullHTML();
            previewWindow.document.write(designHtml);
            previewWindow.document.close();
        }

        function publishDesign() {
            if (confirm('Publish this website? It will be live at your custom URL.')) {
                const formData = new FormData();
                formData.append('action', 'publish_design');
                formData.append('template_id', currentDesign.templateId);
                formData.append('customizations', JSON.stringify(currentDesign.customizations));

                fetch('template_actions.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Website published! URL: ' + data.url);
                    } else {
                        alert('Error publishing: ' + data.message);
                    }
                });
            }
        }

        function generateFullHTML() {
            return `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Website</title>
    <style>
        ${currentDesign.template.css}
        ${Object.values(currentDesign.customizations).map(c => c.css || '').join('\n')}
    </style>
</head>
<body>
    ${document.getElementById('websitePreview').innerHTML}
    <script>
        ${currentDesign.template.js}
        ${Object.values(currentDesign.customizations).map(c => c.js || '').join('\n')}
    <\/script>
</body>
</html>`;
        }
    </script>
</body>
</html>