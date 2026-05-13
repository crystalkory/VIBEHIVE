<?php
// check_dependencies.php
session_start();

function checkDependency($command, $name) {
    $output = [];
    $returnCode = 0;
    
    // Windows-compatible command to check if executable exists
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows command
        exec("where {$command} 2>nul", $output, $returnCode);
    } else {
        // Linux/Mac command
        exec("which {$command}", $output, $returnCode);
    }
    
    return [
        'name' => $name,
        'installed' => $returnCode === 0,
        'command' => $command,
        'path' => !empty($output[0]) ? $output[0] : 'Not found'
    ];
}

function checkWindowsDependency($name, $possibleCommands) {
    $output = [];
    $returnCode = 0;
    $foundPath = '';
    
    foreach ($possibleCommands as $command) {
        exec("where {$command} 2>nul", $output, $returnCode);
        if ($returnCode === 0 && !empty($output[0])) {
            $foundPath = $output[0];
            break;
        }
    }
    
    return [
        'name' => $name,
        'installed' => !empty($foundPath),
        'command' => implode(' or ', $possibleCommands),
        'path' => $foundPath ?: 'Not found'
    ];
}

// Check dependencies with Windows compatibility
$dependencies = [
    checkDependency('ffmpeg', 'FFmpeg'),
    checkWindowsDependency('ImageMagick', ['magick', 'convert', 'composite']),
    checkDependency('composite', 'ImageMagick Tools')
];

// Check PHP extensions
$phpExtensions = [
    'name' => 'PHP Extensions',
    'extensions' => [
        'imagick' => extension_loaded('imagick'),
        'gd' => extension_loaded('gd'),
        'fileinfo' => extension_loaded('fileinfo'),
        'mbstring' => extension_loaded('mbstring'),
        'json' => extension_loaded('json')
    ]
];

// Check if we can execute FFmpeg commands
function testFFmpegExecution() {
    $output = [];
    $returnCode = 0;
    exec('ffmpeg -version 2>&1', $output, $returnCode);
    
    return [
        'success' => $returnCode === 0,
        'output' => $output,
        'return_code' => $returnCode
    ];
}

$ffmpegTest = testFFmpegExecution();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Dependencies Check</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        pre { background: #f8f8f8; padding: 10px; border-radius: 5px; overflow-x: auto; }
        .system-info { background: #e8f4fd; padding: 15px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Server Dependencies Check</h1>
        
        <div class='system-info'>
            <h3>System Information</h3>
            <p><strong>Operating System:</strong> " . PHP_OS . "</p>
            <p><strong>PHP Version:</strong> " . PHP_VERSION . "</p>
            <p><strong>Web Server:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "</p>
            <p><strong>Document Root:</strong> " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "</p>
        </div>";

echo "<h2>FFmpeg Test</h2>";
if ($ffmpegTest['success']) {
    echo "<p class='success'>✅ FFmpeg is working correctly!</p>";
    echo "<pre>" . implode("\n", array_slice($ffmpegTest['output'], 0, 3)) . "</pre>";
} else {
    echo "<p class='error'>❌ FFmpeg command execution failed</p>";
    echo "<p>Return code: " . $ffmpegTest['return_code'] . "</p>";
    if (!empty($ffmpegTest['output'])) {
        echo "<pre>Error output:\n" . implode("\n", $ffmpegTest['output']) . "</pre>";
    }
}

echo "<h2>System Dependencies</h2>";
foreach ($dependencies as $dep) {
    $status = $dep['installed'] ? "✅ Installed" : "❌ Missing";
    $colorClass = $dep['installed'] ? 'success' : 'error';
    
    echo "<p class='$colorClass'><strong>{$dep['name']}</strong>: $status</p>";
    echo "<p><small>Command: {$dep['command']}</small></p>";
    if ($dep['installed']) {
        echo "<p><small>Path: {$dep['path']}</small></p>";
    } else {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            echo "<p><small>Installation help: Download from https://www.gyan.dev/ffmpeg/builds/</small></p>";
        } else {
            echo "<p><small>Install with: <code>sudo apt install {$dep['command']}</code></small></p>";
        }
    }
    echo "<hr>";
}

echo "<h2>PHP Extensions</h2>";
foreach ($phpExtensions['extensions'] as $ext => $loaded) {
    $status = $loaded ? "✅ Loaded" : "❌ Missing";
    $colorClass = $loaded ? 'success' : 'error';
    echo "<p class='$colorClass'><strong>{$ext}</strong>: $status</p>";
}

// Additional Windows-specific checks
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    echo "<h2>Windows-Specific Checks</h2>";
    
    // Check PATH environment variable
    $path = getenv('PATH');
    $ffmpegInPath = stripos($path, 'ffmpeg') !== false;
    
    echo "<p><strong>FFmpeg in PATH:</strong> " . ($ffmpegInPath ? "✅ Yes" : "❌ No") . "</p>";
    
    // Check common FFmpeg installation directories
    $commonPaths = [
        'C:\\ffmpeg\\bin',
        'C:\\Program Files\\ffmpeg\\bin',
        'C:\\Program Files (x86)\\ffmpeg\\bin',
        'C:\\tools\\ffmpeg\\bin'
    ];
    
    echo "<p><strong>Common FFmpeg paths:</strong></p>";
    foreach ($commonPaths as $path) {
        $exists = file_exists($path);
        echo "<p>" . ($exists ? "✅" : "❌") . " $path</p>";
    }
}

// Test file permissions
echo "<h2>File Permissions</h2>";
$uploadDir = __DIR__ . '/uploads';
$tempDir = sys_get_temp_dir();

echo "<p><strong>Uploads directory:</strong> " . (is_writable($uploadDir) ? "✅ Writable" : "❌ Not writable") . " ($uploadDir)</p>";
echo "<p><strong>Temp directory:</strong> " . (is_writable($tempDir) ? "✅ Writable" : "❌ Not writable") . " ($tempDir)</p>";

// Test exec() function
echo "<h2>PHP Function Availability</h2>";
echo "<p><strong>exec():</strong> " . (function_exists('exec') ? "✅ Available" : "❌ Disabled") . "</p>";
echo "<p><strong>shell_exec():</strong> " . (function_exists('shell_exec') ? "✅ Available" : "❌ Disabled") . "</p>";

echo "</div></body></html>";
?>