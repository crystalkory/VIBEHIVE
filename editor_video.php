<?php
// editor_video.php - PROFESSIONAL VIDEO EDITOR (CAPCUT + INSHOT CLONE)
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";


// Function to save edited video to database
function saveEditedVideoToDatabase($pdo, $userId, $videoPath, $filename) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO edited_videos (user_id, video_path, filename, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $videoPath, $filename]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error saving edited video: " . $e->getMessage());
        return false;
    }
}

// Fetch user info
$currentUserId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT profile_pic_url, username FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$username = $userData['username'];

// Initialize video info cache
if (!isset($_SESSION['video_info_cache'])) {
    $_SESSION['video_info_cache'] = [];
}

// Video Editor Class
class ProfessionalVideoEditor {
    private $uploadDir;
    private $tempDir;
    private $ffmpegPath;
    
    public function __construct() {
        $this->uploadDir = __DIR__ . '/video_uploads/';
        $this->tempDir = __DIR__ . '/video_temp/';
        $this->ffmpegPath = 'ffmpeg'; // Update this path if needed
        
        if (!file_exists($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
        if (!file_exists($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }
    
    // Extract frames from video - ONLY USED DURING INITIAL UPLOAD
    public function extractFrames($videoPath, $outputDir, $frameRate = 10) {
        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -r {$frameRate} \"{$outputDir}/frame_%04d.jpg\"";
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            $frames = glob("{$outputDir}/frame_*.jpg");
            return ['success' => true, 'frames' => $frames, 'frame_count' => count($frames)];
        }
        
        return ['success' => false, 'error' => 'Failed to extract frames'];
    }
    
    // Get video information with caching
    public function getVideoInfo($videoPath) {
        // Check cache first
        if (isset($_SESSION['video_info_cache'][$videoPath])) {
            return $_SESSION['video_info_cache'][$videoPath];
        }
        
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" 2>&1";
        exec($command, $output, $returnCode);
        
        $info = [
            'duration' => 0,
            'resolution' => '0x0',
            'fps' => 0,
            'bitrate' => 0
        ];
        
        foreach ($output as $line) {
            if (preg_match('/Duration: (\d+):(\d+):(\d+\.\d+)/', $line, $matches)) {
                $info['duration'] = ($matches[1] * 3600) + ($matches[2] * 60) + $matches[3];
            }
            if (preg_match('/(\d+)x(\d+)/', $line, $matches)) {
                $info['resolution'] = "{$matches[1]}x{$matches[2]}";
            }
            if (preg_match('/(\d+(\.\d+)?) fps/', $line, $matches)) {
                $info['fps'] = floatval($matches[1]);
            }
            if (preg_match('/bitrate: (\d+) kb\/s/', $line, $matches)) {
                $info['bitrate'] = intval($matches[1]);
            }
        }
        
        // Cache the result
        $_SESSION['video_info_cache'][$videoPath] = $info;
        
        return $info;
    }
    
    // Quick video info without caching (for operations that modify video)
    public function quickVideoInfo($videoPath) {
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" 2>&1";
        exec($command, $output, $returnCode);
        
        $info = [
            'duration' => 0,
            'resolution' => '0x0'
        ];
        
        foreach ($output as $line) {
            if (preg_match('/Duration: (\d+):(\d+):(\d+\.\d+)/', $line, $matches)) {
                $info['duration'] = ($matches[1] * 3600) + ($matches[2] * 60) + $matches[3];
                break; // Only get duration for quick info
            }
        }
        
        return $info;
    }
    
    // Trim video
    public function trimVideo($videoPath, $outputPath, $startTime, $duration) {
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -ss {$startTime} -t {$duration} -c copy \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    // Split video at specific time
    public function splitVideo($videoPath, $outputPath1, $outputPath2, $splitTime) {
        $command1 = "{$this->ffmpegPath} -i \"{$videoPath}\" -t {$splitTime} -c copy \"{$outputPath1}\"";
        $command2 = "{$this->ffmpegPath} -i \"{$videoPath}\" -ss {$splitTime} -c copy \"{$outputPath2}\"";
        
        exec($command1, $output1, $returnCode1);
        exec($command2, $output2, $returnCode2);
        
        return $returnCode1 === 0 && $returnCode2 === 0;
    }
    
    // Adjust video speed
    public function adjustSpeed($videoPath, $outputPath, $speed) {
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -filter:v \"setpts=" . (1/$speed) . "*PTS\" -filter:a \"atempo={$speed}\" \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    // Reverse video
    public function reverseVideo($videoPath, $outputPath) {
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -vf reverse -af areverse \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    // Add audio to video
    public function addAudio($videoPath, $audioPath, $outputPath, $audioVolume = 1.0) {
        // Create a temporary file for the adjusted audio
        $tempAudioPath = $this->tempDir . 'temp_audio_' . uniqid() . '.mp3';
        
        // Adjust audio volume first
        $volumeCommand = "{$this->ffmpegPath} -i \"{$audioPath}\" -filter:a \"volume={$audioVolume}\" \"{$tempAudioPath}\"";
        exec($volumeCommand, $volumeOutput, $volumeReturnCode);
        
        if ($volumeReturnCode !== 0) {
            return false;
        }
        
        // Mix audio with video
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -i \"{$tempAudioPath}\" -c:v copy -map 0:v:0 -map 1:a:0 -shortest \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        // Clean up temporary file
        if (file_exists($tempAudioPath)) {
            unlink($tempAudioPath);
        }
        
        return $returnCode === 0;
    }
    
    // Remove audio from video
    public function removeAudio($videoPath, $outputPath) {
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -c copy -an \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    // Extract audio from video
    public function extractAudio($videoPath, $outputPath) {
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -q:a 0 -map a \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    // Add text overlay
    public function addTextOverlay($videoPath, $outputPath, $text, $options = []) {
        $defaultOptions = [
            'x' => 10,
            'y' => 10,
            'fontsize' => 24,
            'fontcolor' => 'white',
            'start_time' => 0,
            'duration' => 10,
            'box' => 0,
            'boxcolor' => 'black@0.5',
            'boxborderw' => 5
        ];
        $options = array_merge($defaultOptions, $options);
        
        // Escape text properly for FFmpeg
        $text = str_replace("'", "'\\\\\\''", $text);
        $text = str_replace(':', '\\\\:', $text);
        $text = str_replace('[', '\\[', $text);
        $text = str_replace(']', '\\]', $text);
        
        $boxFilter = '';
        if ($options['box']) {
            $boxFilter = ":box=1:boxcolor={$options['boxcolor']}:boxborderw={$options['boxborderw']}";
        }
        
        $filter = "drawtext=text='{$text}':x={$options['x']}:y={$options['y']}:fontsize={$options['fontsize']}:fontcolor={$options['fontcolor']}{$boxFilter}";
        
        if ($options['duration'] > 0) {
            $filter .= ":enable='between(t,{$options['start_time']},{$options['start_time']}+{$options['duration']})'";
        }
        
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -vf \"{$filter}\" -c:a copy \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    // Add watermark to video - ONLY USED DURING EXPORT/FINAL SAVE
    public function addWatermark($videoPath, $outputPath, $watermarkText = "Tech Titans") {
        // Get video duration
        $videoInfo = $this->getVideoInfo($videoPath);
        $duration = $videoInfo['duration'];
        
        // Create watermark filter
        $filter = "drawtext=text='{$watermarkText}':x=w-text_w-10:y=h-text_h-10:fontsize=36:fontcolor=white@0.8:box=1:boxcolor=black@0.5:boxborderw=8";
        
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -vf \"{$filter}\" -c:a copy \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    // Apply filter to video
    public function applyFilter($videoPath, $outputPath, $filter) {
        $filterMap = [
            'grayscale' => 'colorchannelmixer=.3:.4:.3:0:.3:.4:.3:0:.3:.4:.3',
            'sepia' => 'colorchannelmixer=.393:.769:.189:0:.349:.686:.168:0:.272:.534:.131',
            'vintage' => 'curves=preset=vintage',
            'cool' => 'colorbalance=rs=-0.3:gs=0.1:bs=0.3',
            'warm' => 'colorbalance=rs=0.3:gs=-0.1:bs=-0.3',
            'bright' => 'eq=brightness=0.2',
            'contrast' => 'eq=contrast=1.5',
            'saturation' => 'eq=saturation=1.5',
            'hue' => 'hue=h=30',
            'invert' => 'negate',
            'posterize' => 'eq=gamma=0.6:contrast=1.5',
            'edge' => 'edgedetect=low=0.1:high=0.4',
            'emboss' => 'convolution="0 -1 0 -1 5 -1 0 -1 0:0 -1 0 -1 5 -1 0 -1 0:0 -1 0 -1 5 -1 0 -1 0:0 -1 0 -1 5 -1 0 -1 0"',
            'sharpen' => 'unsharp=5:5:1.0:5:5:0.0',
            'blur' => 'boxblur=2:1',
            'pixelate' => 'scale=iw/10:ih/10,scale=iw*10:ih*10:flags=neighbor',
            'nightvision' => 'curves=green="0/0 0.5/0 1/1":blue="0/0 0.5/0 1/1"',
            'noir' => 'colorchannelmixer=.3:.4:.3:0:.3:.4:.3:0:.3:.4:.3,eq=contrast=1.5',
            'dramatic' => 'eq=contrast=1.7:brightness=-0.1:saturation=0.8',
            'cinematic' => 'curves=preset=strong_contrast',
            'vibrant' => 'eq=saturation=1.7:contrast=1.1',
            'pastel' => 'eq=saturation=0.5:brightness=0.1',
            'sunset' => 'colorbalance=rs=0.3:gs=0.1:bs=-0.1',
            'moonlight' => 'colorbalance=rs=-0.1:gs=0:bs=0.3,eq=brightness=-0.05',
            'golden' => 'colorbalance=rs=0.2:gs=0.1:bs=-0.1,eq=contrast=1.1',
            'silver' => 'colorchannelmixer=.3:.3:.3:0:.3:.3:.3:0:.3:.3:.3',
            'lomo' => 'vignette=PI/4,eq=contrast=1.2:brightness=0.05:saturation=0.8',
            'clarity' => 'unsharp=3:3:0.5:3:3:0.0',
            'dramatic_bw' => 'colorchannelmixer=.3:.4:.3:0:.3:.4:.3:0:.3:.4:.3,eq=contrast=2.0:brightness=0.1'
        ];
        
        $filterCmd = $filterMap[$filter] ?? '';
        if ($filterCmd) {
            $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -vf \"{$filterCmd}\" \"{$outputPath}\"";
            exec($command, $output, $returnCode);
            return $returnCode === 0;
        }
        
        return false;
    }
    
    // Create video from images
    public function createVideoFromImages($images, $outputPath, $fps = 24, $duration = 5) {
        $tempListFile = $this->tempDir . 'image_list_' . uniqid() . '.txt';
        $listContent = '';
        
        // Calculate duration per image
        $durationPerImage = $duration / count($images);
        
        foreach ($images as $image) {
            $listContent .= "file '" . realpath($image) . "'\n";
            $listContent .= "duration " . $durationPerImage . "\n";
        }
        
        file_put_contents($tempListFile, $listContent);
        
        // Create the video
        $command = "{$this->ffmpegPath} -f concat -safe 0 -i \"{$tempListFile}\" -r {$fps} -pix_fmt yuv420p \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        unlink($tempListFile);
        
        return $returnCode === 0;
    }
    
    // Merge multiple videos
    public function mergeVideos($videos, $outputPath) {
        $tempListFile = $this->tempDir . 'video_list_' . uniqid() . '.txt';
        $listContent = '';
        
        foreach ($videos as $video) {
            $listContent .= "file '" . realpath($video) . "'\n";
        }
        
        file_put_contents($tempListFile, $listContent);
        
        $command = "{$this->ffmpegPath} -f concat -safe 0 -i \"{$tempListFile}\" -c copy \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        unlink($tempListFile);
        
        return $returnCode === 0;
    }
    
    // Add transition between clips
    public function addTransition($video1, $video2, $outputPath, $transition = 'fade', $duration = 1) {
        $tempDir = $this->tempDir;
        
        // This is a simplified implementation - real transitions require complex FFmpeg filters
        $command = "{$this->ffmpegPath} -i \"{$video1}\" -i \"{$video2}\" -filter_complex \"[0:v][1:v]xfade=transition={$transition}:duration={$duration}:offset=5[outv]\" -map \"[outv]\" \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    // Stabilize video
    public function stabilizeVideo($videoPath, $outputPath) {
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -vf deshake \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    // Export final video WITH WATERMARK
    public function exportVideo($videoPath, $outputPath, $quality = 'high') {
        $qualitySettings = [
            'low' => '-crf 28 -preset fast',
            'medium' => '-crf 23 -preset medium', 
            'high' => '-crf 18 -preset slow',
            '4k' => '-crf 18 -preset slow'
        ];
        
        $settings = $qualitySettings[$quality] ?? $qualitySettings['high'];
        
        // Add watermark during export
        $watermarkFilter = "drawtext=text='Tech Titans':x=w-text_w-10:y=h-text_h-10:fontsize=36:fontcolor=white@0.8:box=1:boxcolor=black@0.5:boxborderw=8";
        
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -vf \"{$watermarkFilter}\" {$settings} \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    // Apply final watermark before saving (for done editing)
    public function applyFinalWatermark($videoPath, $outputPath) {
        return $this->addWatermark($videoPath, $outputPath);
    }
    
    // Duplicate frame at specific position
    public function duplicateFrame($videoPath, $outputPath, $frameTime) {
        // Extract frames around the target time
        $frameDir = $this->tempDir . 'dup_frames_' . uniqid();
        mkdir($frameDir, 0755, true);
        
        // Extract frame at target time
        $frameCommand = "{$this->ffmpegPath} -i \"{$videoPath}\" -ss {$frameTime} -vframes 1 \"{$frameDir}/target_frame.jpg\"";
        exec($frameCommand, $frameOutput, $frameReturnCode);
        
        if ($frameReturnCode !== 0) {
            return false;
        }
        
        // Create a new video with duplicated frame
        $tempVideo1 = $this->tempDir . 'temp1_' . uniqid() . '.mp4';
        $tempVideo2 = $this->tempDir . 'temp2_' . uniqid() . '.mp4';
        
        // Split video at frameTime
        $split1 = $this->trimVideo($videoPath, $tempVideo1, 0, $frameTime);
        $split2 = $this->trimVideo($videoPath, $tempVideo2, $frameTime, $this->getVideoInfo($videoPath)['duration'] - $frameTime);
        
        if ($split1 && $split2) {
            // Create a short clip from the target frame
            $frameClip = $this->tempDir . 'frame_clip_' . uniqid() . '.mp4';
            $frameClipCommand = "{$this->ffmpegPath} -loop 1 -i \"{$frameDir}/target_frame.jpg\" -t 0.1 -c:v libx264 -pix_fmt yuv420p \"{$frameClip}\"";
            exec($frameClipCommand, $frameClipOutput, $frameClipReturnCode);
            
            if ($frameClipReturnCode === 0) {
                // Merge all parts
                $videosToMerge = [$tempVideo1, $frameClip, $tempVideo2];
                $result = $this->mergeVideos($videosToMerge, $outputPath);
                
                // Clean up
                array_map('unlink', glob("{$frameDir}/*"));
                rmdir($frameDir);
                unlink($tempVideo1);
                unlink($tempVideo2);
                unlink($frameClip);
                
                return $result;
            }
        }
        
        return false;
    }
    
    // NEW: Create video from frames directory
    public function createVideoFromFrames($framePaths, $outputPath, $fps = 10) {
        $tempListFile = $this->tempDir . 'frame_list_' . uniqid() . '.txt';
        $listContent = '';
        
        // Calculate duration per frame (1/fps)
        $durationPerFrame = 1 / $fps;
        
        foreach ($framePaths as $frame) {
            $listContent .= "file '" . realpath($frame) . "'\n";
            $listContent .= "duration " . $durationPerFrame . "\n";
        }
        
        // Add the last frame again to ensure it displays properly
        if (!empty($framePaths)) {
            $listContent .= "file '" . realpath(end($framePaths)) . "'\n";
        }
        
        file_put_contents($tempListFile, $listContent);
        
        // Get resolution from first frame
        $firstFrame = !empty($framePaths) ? $framePaths[0] : '';
        $resolution = '1920x1080';
        if ($firstFrame && file_exists($firstFrame)) {
            $size = getimagesize($firstFrame);
            if ($size) {
                $resolution = $size[0] . 'x' . $size[1];
            }
        }
        
        // Create the video from frames
        $command = "{$this->ffmpegPath} -f concat -safe 0 -i \"{$tempListFile}\" -r {$fps} -s {$resolution} -c:v libx264 -pix_fmt yuv420p \"{$outputPath}\"";
        exec($command, $output, $returnCode);
        
        // Add silent audio track
        if ($returnCode === 0) {
            $tempVideo = $this->tempDir . 'temp_video_' . uniqid() . '.mp4';
            $addAudioCommand = "{$this->ffmpegPath} -f lavfi -i anullsrc=channel_layout=stereo:sample_rate=44100 -i \"{$outputPath}\" -shortest -c:v copy -c:a aac \"{$tempVideo}\"";
            exec($addAudioCommand, $audioOutput, $audioReturnCode);
            
            if ($audioReturnCode === 0) {
                rename($tempVideo, $outputPath);
            }
        }
        
        unlink($tempListFile);
        
        return $returnCode === 0;
    }
}

// Initialize video editor
$videoEditor = new ProfessionalVideoEditor();
$uploadDir = __DIR__ . '/video_uploads/';

// Initialize session for undo/redo functionality
if (!isset($_SESSION['editor_history'])) {
    $_SESSION['editor_history'] = [];
    $_SESSION['editor_history_index'] = -1;
}

// Function to save state to history
function saveToHistory($mediaData) {
    $_SESSION['editor_history'][] = $mediaData;
    $_SESSION['editor_history_index'] = count($_SESSION['editor_history']) - 1;
    
    // Limit history size to prevent memory issues
    if (count($_SESSION['editor_history']) > 20) {
        array_shift($_SESSION['editor_history']);
        $_SESSION['editor_history_index']--;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false];
    
    try {
        switch ($_POST['action']) {
            case 'upload_media':
                if (!empty($_FILES['media']['name'])) {
                    $file = $_FILES['media'];
                    $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = uniqid('video_') . '.' . $fileExt;
                    $filePath = $uploadDir . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $filePath)) {
                        $mediaType = strpos($file['type'], 'video') !== false ? 'video' : 'image';
                        
                        if ($mediaType === 'video') {
                            // Get video info with caching
                            $videoInfo = $videoEditor->getVideoInfo($filePath);
                            
                            // Extract frames ONLY during initial upload
                            $frameDir = $uploadDir . 'frames/' . pathinfo($filename, PATHINFO_FILENAME);
                            $frameResult = $videoEditor->extractFrames($filePath, $frameDir);
                            
                            $mediaData = [
                                'type' => 'video',
                                'path' => $filePath,
                                'url' => 'video_uploads/' . $filename,
                                'filename' => $filename,
                                'frames' => $frameResult['success'] ? array_map(function($frame) {
                                    return str_replace(__DIR__ . '/', '', $frame);
                                }, $frameResult['frames']) : [],
                                'frame_count' => $frameResult['frame_count'] ?? 0,
                                'duration' => $videoInfo['duration'],
                                'resolution' => $videoInfo['resolution'],
                                'fps' => $videoInfo['fps']
                            ];
                            
                            $_SESSION['current_media'] = $mediaData;
                            saveToHistory($mediaData);
                        } else {
                            // For images, create a video
                            $outputFilename = 'image_video_' . uniqid() . '.mp4';
                            $outputPath = $uploadDir . $outputFilename;
                            
                            if ($videoEditor->createVideoFromImages([$filePath], $outputPath, 1, 5)) {
                                $videoInfo = $videoEditor->getVideoInfo($outputPath);
                                
                                // Extract frames for the created video
                                $frameDir = $uploadDir . 'frames/' . pathinfo($outputFilename, PATHINFO_FILENAME);
                                $frameResult = $videoEditor->extractFrames($outputPath, $frameDir);
                                
                                $mediaData = [
                                    'type' => 'video',
                                    'path' => $outputPath,
                                    'url' => 'video_uploads/' . $outputFilename,
                                    'filename' => $outputFilename,
                                    'frames' => $frameResult['success'] ? array_map(function($frame) {
                                        return str_replace(__DIR__ . '/', '', $frame);
                                    }, $frameResult['frames']) : [],
                                    'frame_count' => $frameResult['frame_count'] ?? 0,
                                    'duration' => $videoInfo['duration'],
                                    'resolution' => $videoInfo['resolution'],
                                    'fps' => $videoInfo['fps'],
                                    'source_image' => 'video_uploads/' . $filename
                                ];
                                
                                $_SESSION['current_media'] = $mediaData;
                                saveToHistory($mediaData);
                                $mediaType = 'video';
                            }
                        }
                        
                        $response = [
                            'success' => true,
                            'mediaType' => $mediaType,
                            'url' => $_SESSION['current_media']['url'],
                            'frames' => $_SESSION['current_media']['frames'] ?? [],
                            'duration' => $_SESSION['current_media']['duration'] ?? 0
                        ];
                    }
                }
                break;
                
            case 'upload_multiple_images':
                if (!empty($_FILES['images']['name'][0])) {
                    $uploadedImages = [];
                    $imagePaths = [];
                    
                    foreach ($_FILES['images']['tmp_name'] as $key => $tmpName) {
                        if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                            $filename = uniqid('image_') . '_' . $_FILES['images']['name'][$key];
                            $filePath = $uploadDir . $filename;
                            
                            if (move_uploaded_file($tmpName, $filePath)) {
                                $uploadedImages[] = 'video_uploads/' . $filename;
                                $imagePaths[] = $filePath;
                            }
                        }
                    }
                    
                    if (!empty($imagePaths)) {
                        // Create video from images
                        $outputFilename = 'slideshow_' . uniqid() . '.mp4';
                        $outputPath = $uploadDir . $outputFilename;
                        
                        $frameDuration = floatval($_POST['frame_duration'] ?? 3);
                        $result = $videoEditor->createVideoFromImages($imagePaths, $outputPath, 1, count($imagePaths) * $frameDuration);
                        
                        if ($result) {
                            $videoInfo = $videoEditor->getVideoInfo($outputPath);
                            
                            // Extract frames ONLY during initial creation
                            $frameDir = $uploadDir . 'frames/' . pathinfo($outputFilename, PATHINFO_FILENAME);
                            $frameResult = $videoEditor->extractFrames($outputPath, $frameDir);
                            
                            $mediaData = [
                                'type' => 'video',
                                'path' => $outputPath,
                                'url' => 'video_uploads/' . $outputFilename,
                                'filename' => $outputFilename,
                                'frames' => $frameResult['success'] ? array_map(function($frame) {
                                    return str_replace(__DIR__ . '/', '', $frame);
                                }, $frameResult['frames']) : [],
                                'frame_count' => $frameResult['frame_count'] ?? 0,
                                'duration' => $videoInfo['duration'],
                                'resolution' => $videoInfo['resolution'],
                                'fps' => $videoInfo['fps'],
                                'source_images' => $uploadedImages
                            ];
                            
                            $_SESSION['current_media'] = $mediaData;
                            saveToHistory($mediaData);
                            
                            $response = [
                                'success' => true,
                                'mediaType' => 'video',
                                'url' => 'video_uploads/' . $outputFilename,
                                'frames' => $_SESSION['current_media']['frames'],
                                'duration' => $_SESSION['current_media']['duration']
                            ];
                        } else {
                            $response['error'] = 'Failed to create video from images. Please check if FFmpeg is installed and the images are valid.';
                        }
                    }
                }
                break;
                
            case 'trim_video':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $videoPath = $_SESSION['current_media']['path'];
                    $outputFilename = 'trimmed_' . uniqid() . '.mp4';
                    $outputPath = $uploadDir . $outputFilename;
                    
                    $startTime = floatval($_POST['start_time'] ?? 0);
                    $duration = floatval($_POST['duration'] ?? $_SESSION['current_media']['duration']);
                    
                    $result = $videoEditor->trimVideo($videoPath, $outputPath, $startTime, $duration);
                    
                    if ($result) {
                        // Use quick video info to avoid caching issues
                        $videoInfo = $videoEditor->quickVideoInfo($outputPath);
                        
                        // Extract frames for trimmed video
                        $frameDir = $uploadDir . 'frames/' . pathinfo($outputFilename, PATHINFO_FILENAME);
                        $frameResult = $videoEditor->extractFrames($outputPath, $frameDir);
                        
                        $mediaData = [
                            'type' => 'video',
                            'path' => $outputPath,
                            'url' => 'video_uploads/' . $outputFilename,
                            'filename' => $outputFilename,
                            'frames' => $frameResult['success'] ? array_map(function($frame) {
                                return str_replace(__DIR__ . '/', '', $frame);
                            }, $frameResult['frames']) : [],
                            'frame_count' => $frameResult['frame_count'] ?? 0,
                            'duration' => $videoInfo['duration'],
                            'resolution' => $_SESSION['current_media']['resolution'],
                            'fps' => $_SESSION['current_media']['fps']
                        ];
                        
                        $_SESSION['current_media'] = $mediaData;
                        saveToHistory($mediaData);
                        
                        $response = [
                            'success' => true,
                            'url' => 'video_uploads/' . $outputFilename,
                            'frames' => $_SESSION['current_media']['frames'],
                            'duration' => $_SESSION['current_media']['duration']
                        ];
                    }
                }
                break;
                
            case 'split_video':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $videoPath = $_SESSION['current_media']['path'];
                    $splitTime = floatval($_POST['split_time'] ?? $_SESSION['current_media']['duration'] / 2);
                    
                    $outputFilename1 = 'split1_' . uniqid() . '.mp4';
                    $outputFilename2 = 'split2_' . uniqid() . '.mp4';
                    $outputPath1 = $uploadDir . $outputFilename1;
                    $outputPath2 = $uploadDir . $outputFilename2;
                    
                    $result = $videoEditor->splitVideo($videoPath, $outputPath1, $outputPath2, $splitTime);
                    
                    if ($result) {
                        // Store both videos in session for timeline
                        $_SESSION['split_videos'] = [
                            [
                                'url' => 'video_uploads/' . $outputFilename1,
                                'duration' => $splitTime
                            ],
                            [
                                'url' => 'video_uploads/' . $outputFilename2,
                                'duration' => $_SESSION['current_media']['duration'] - $splitTime
                            ]
                        ];
                        
                        $response = [
                            'success' => true,
                            'videos' => $_SESSION['split_videos']
                        ];
                    }
                }
                break;
                
            case 'adjust_speed':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $videoPath = $_SESSION['current_media']['path'];
                    $outputFilename = 'speed_' . uniqid() . '.mp4';
                    $outputPath = $uploadDir . $outputFilename;
                    $speed = floatval($_POST['speed'] ?? 1.0);
                    
                    $result = $videoEditor->adjustSpeed($videoPath, $outputPath, $speed);
                    
                    if ($result) {
                        // Use quick video info
                        $videoInfo = $videoEditor->quickVideoInfo($outputPath);
                        
                        // Extract frames for speed adjusted video
                        $frameDir = $uploadDir . 'frames/' . pathinfo($outputFilename, PATHINFO_FILENAME);
                        $frameResult = $videoEditor->extractFrames($outputPath, $frameDir);
                        
                        $mediaData = [
                            'type' => 'video',
                            'path' => $outputPath,
                            'url' => 'video_uploads/' . $outputFilename,
                            'filename' => $outputFilename,
                            'frames' => $frameResult['success'] ? array_map(function($frame) {
                                return str_replace(__DIR__ . '/', '', $frame);
                            }, $frameResult['frames']) : [],
                            'frame_count' => $frameResult['frame_count'] ?? 0,
                            'duration' => $videoInfo['duration'],
                            'resolution' => $_SESSION['current_media']['resolution'],
                            'fps' => $_SESSION['current_media']['fps']
                        ];
                        
                        $_SESSION['current_media'] = $mediaData;
                        saveToHistory($mediaData);
                        
                        $response = [
                            'success' => true,
                            'url' => 'video_uploads/' . $outputFilename,
                            'frames' => $_SESSION['current_media']['frames'],
                            'duration' => $_SESSION['current_media']['duration']
                        ];
                    }
                }
                break;
                
            case 'add_text_overlay':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $videoPath = $_SESSION['current_media']['path'];
                    $outputFilename = 'text_' . uniqid() . '.mp4';
                    $outputPath = $uploadDir . $outputFilename;
                    
                    $textOptions = [
                        'text' => $_POST['text'] ?? 'Sample Text',
                        'x' => intval($_POST['x'] ?? 10),
                        'y' => intval($_POST['y'] ?? 10),
                        'fontsize' => intval($_POST['fontsize'] ?? 24),
                        'fontcolor' => $_POST['fontcolor'] ?? 'white',
                        'start_time' => floatval($_POST['start_time'] ?? 0),
                        'duration' => floatval($_POST['duration'] ?? 5),
                        'box' => intval($_POST['box'] ?? 1),
                        'boxcolor' => $_POST['boxcolor'] ?? 'black@0.5'
                    ];
                    
                    $result = $videoEditor->addTextOverlay($videoPath, $outputPath, $textOptions['text'], $textOptions);
                    
                    if ($result) {
                        // Use quick video info
                        $videoInfo = $videoEditor->quickVideoInfo($outputPath);
                        
                        // Extract frames for text overlay video
                        $frameDir = $uploadDir . 'frames/' . pathinfo($outputFilename, PATHINFO_FILENAME);
                        $frameResult = $videoEditor->extractFrames($outputPath, $frameDir);
                        
                        $mediaData = [
                            'type' => 'video',
                            'path' => $outputPath,
                            'url' => 'video_uploads/' . $outputFilename,
                            'filename' => $outputFilename,
                            'frames' => $frameResult['success'] ? array_map(function($frame) {
                                return str_replace(__DIR__ . '/', '', $frame);
                            }, $frameResult['frames']) : [],
                            'frame_count' => $frameResult['frame_count'] ?? 0,
                            'duration' => $videoInfo['duration'],
                            'resolution' => $_SESSION['current_media']['resolution'],
                            'fps' => $_SESSION['current_media']['fps']
                        ];
                        
                        $_SESSION['current_media'] = $mediaData;
                        saveToHistory($mediaData);
                        
                        $response = [
                            'success' => true,
                            'url' => 'video_uploads/' . $outputFilename,
                            'frames' => $_SESSION['current_media']['frames'],
                            'duration' => $_SESSION['current_media']['duration']
                        ];
                    } else {
                        $response['error'] = 'Failed to add text overlay. Check FFmpeg installation and text formatting.';
                    }
                }
                break;
                
            case 'apply_filter':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $videoPath = $_SESSION['current_media']['path'];
                    $outputFilename = 'filtered_' . uniqid() . '.mp4';
                    $outputPath = $uploadDir . $outputFilename;
                    $filter = $_POST['filter'] ?? 'grayscale';
                    
                    $result = $videoEditor->applyFilter($videoPath, $outputPath, $filter);
                    
                    if ($result) {
                        // Use quick video info
                        $videoInfo = $videoEditor->quickVideoInfo($outputPath);
                        
                        // Extract frames for filtered video
                        $frameDir = $uploadDir . 'frames/' . pathinfo($outputFilename, PATHINFO_FILENAME);
                        $frameResult = $videoEditor->extractFrames($outputPath, $frameDir);
                        
                        $mediaData = [
                            'type' => 'video',
                            'path' => $outputPath,
                            'url' => 'video_uploads/' . $outputFilename,
                            'filename' => $outputFilename,
                            'frames' => $frameResult['success'] ? array_map(function($frame) {
                                return str_replace(__DIR__ . '/', '', $frame);
                            }, $frameResult['frames']) : [],
                            'frame_count' => $frameResult['frame_count'] ?? 0,
                            'duration' => $videoInfo['duration'],
                            'resolution' => $_SESSION['current_media']['resolution'],
                            'fps' => $_SESSION['current_media']['fps']
                        ];
                        
                        $_SESSION['current_media'] = $mediaData;
                        saveToHistory($mediaData);
                        
                        $response = [
                            'success' => true,
                            'url' => 'video_uploads/' . $outputFilename,
                            'frames' => $_SESSION['current_media']['frames'],
                            'duration' => $_SESSION['current_media']['duration']
                        ];
                    }
                }
                break;
                
            case 'remove_audio':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $videoPath = $_SESSION['current_media']['path'];
                    $outputFilename = 'no_audio_' . uniqid() . '.mp4';
                    $outputPath = $uploadDir . $outputFilename;
                    
                    $result = $videoEditor->removeAudio($videoPath, $outputPath);
                    
                    if ($result) {
                        // Use quick video info
                        $videoInfo = $videoEditor->quickVideoInfo($outputPath);
                        
                        // Extract frames for audio-removed video
                        $frameDir = $uploadDir . 'frames/' . pathinfo($outputFilename, PATHINFO_FILENAME);
                        $frameResult = $videoEditor->extractFrames($outputPath, $frameDir);
                        
                        $mediaData = [
                            'type' => 'video',
                            'path' => $outputPath,
                            'url' => 'video_uploads/' . $outputFilename,
                            'filename' => $outputFilename,
                            'frames' => $frameResult['success'] ? array_map(function($frame) {
                                return str_replace(__DIR__ . '/', '', $frame);
                            }, $frameResult['frames']) : [],
                            'frame_count' => $frameResult['frame_count'] ?? 0,
                            'duration' => $videoInfo['duration'],
                            'resolution' => $_SESSION['current_media']['resolution'],
                            'fps' => $_SESSION['current_media']['fps']
                        ];
                        
                        $_SESSION['current_media'] = $mediaData;
                        saveToHistory($mediaData);
                        
                        $response = [
                            'success' => true,
                            'url' => 'video_uploads/' . $outputFilename,
                            'frames' => $_SESSION['current_media']['frames'],
                            'duration' => $_SESSION['current_media']['duration']
                        ];
                    }
                }
                break;
                
            case 'add_audio':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video' && !empty($_FILES['audio']['name'])) {
                    $videoPath = $_SESSION['current_media']['path'];
                    $audioFile = $_FILES['audio'];
                    $audioFilename = uniqid('audio_') . '.' . pathinfo($audioFile['name'], PATHINFO_EXTENSION);
                    $audioPath = $uploadDir . $audioFilename;
                    
                    if (move_uploaded_file($audioFile['tmp_name'], $audioPath)) {
                        $outputFilename = 'with_audio_' . uniqid() . '.mp4';
                        $outputPath = $uploadDir . $outputFilename;
                        $volume = floatval($_POST['volume'] ?? 1.0);
                        
                        $result = $videoEditor->addAudio($videoPath, $audioPath, $outputPath, $volume);
                        
                        if ($result) {
                            // Use quick video info
                            $videoInfo = $videoEditor->quickVideoInfo($outputPath);
                            
                            // Extract frames for audio-added video
                            $frameDir = $uploadDir . 'frames/' . pathinfo($outputFilename, PATHINFO_FILENAME);
                            $frameResult = $videoEditor->extractFrames($outputPath, $frameDir);
                            
                            $mediaData = [
                                'type' => 'video',
                                'path' => $outputPath,
                                'url' => 'video_uploads/' . $outputFilename,
                                'filename' => $outputFilename,
                                'frames' => $frameResult['success'] ? array_map(function($frame) {
                                    return str_replace(__DIR__ . '/', '', $frame);
                                }, $frameResult['frames']) : [],
                                'frame_count' => $frameResult['frame_count'] ?? 0,
                                'duration' => $videoInfo['duration'],
                                'resolution' => $_SESSION['current_media']['resolution'],
                                'fps' => $_SESSION['current_media']['fps']
                            ];
                            
                            $_SESSION['current_media'] = $mediaData;
                            saveToHistory($mediaData);
                            
                            $response = [
                                'success' => true,
                                'url' => 'video_uploads/' . $outputFilename,
                                'frames' => $_SESSION['current_media']['frames'],
                                'duration' => $_SESSION['current_media']['duration']
                            ];
                        } else {
                            $response['error'] = 'Failed to add audio. Check if the audio file is compatible.';
                        }
                        
                        // Clean up uploaded audio file
                        unlink($audioPath);
                    }
                }
                break;
                
            case 'export_video':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $videoPath = $_SESSION['current_media']['path'];
                    $outputFilename = 'export_' . uniqid() . '.mp4';
                    $outputPath = $uploadDir . $outputFilename;
                    $quality = $_POST['quality'] ?? 'high';
                    
                    $result = $videoEditor->exportVideo($videoPath, $outputPath, $quality);
                    
                    if ($result) {
                        $response = [
                            'success' => true,
                            'url' => 'video_uploads/' . $outputFilename,
                            'filename' => $outputFilename
                        ];
                    }
                }
                break;
                
            case 'delete_frame':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $frameIndex = intval($_POST['frame_index'] ?? 0);
                    $frames = $_SESSION['current_media']['frames'] ?? [];
                    
                    if (isset($frames[$frameIndex])) {
                        $framePath = __DIR__ . '/' . $frames[$frameIndex];
                        if (file_exists($framePath)) {
                            unlink($framePath);
                        }
                        
                        // Remove frame from array
                        array_splice($frames, $frameIndex, 1);
                        
                        // IMPORTANT: Re-create the video from remaining frames
                        $originalPath = $_SESSION['current_media']['path'];
                        $outputFilename = 'reconstructed_' . uniqid() . '.mp4';
                        $outputPath = $uploadDir . $outputFilename;
                        
                        // Convert frame paths to full paths
                        $framePaths = array_map(function($frame) {
                            return __DIR__ . '/' . $frame;
                        }, $frames);
                        
                        // Recreate video from remaining frames
                        if ($videoEditor->createVideoFromFrames($framePaths, $outputPath, 10)) {
                            $videoInfo = $videoEditor->quickVideoInfo($outputPath);
                            
                            // Update session with new video
                            $mediaData = [
                                'type' => 'video',
                                'path' => $outputPath,
                                'url' => 'video_uploads/' . $outputFilename,
                                'filename' => $outputFilename,
                                'frames' => $frames,
                                'frame_count' => count($frames),
                                'duration' => $videoInfo['duration'],
                                'resolution' => $_SESSION['current_media']['resolution'],
                                'fps' => $_SESSION['current_media']['fps']
                            ];
                            
                            $_SESSION['current_media'] = $mediaData;
                            saveToHistory($mediaData);
                            
                            $response = [
                                'success' => true,
                                'frames' => $frames,
                                'frame_count' => count($frames),
                                'url' => 'video_uploads/' . $outputFilename,
                                'duration' => $videoInfo['duration']
                            ];
                        } else {
                            $response['error'] = 'Failed to reconstruct video after frame deletion.';
                        }
                    } else {
                        $response['error'] = 'Frame not found';
                    }
                }
                break;
                
            case 'delete_multiple_frames':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $frameIndices = json_decode($_POST['frame_indices'] ?? '[]', true);
                    $frames = $_SESSION['current_media']['frames'] ?? [];
                    
                    if (!empty($frameIndices)) {
                        // Sort indices in descending order for safe deletion
                        rsort($frameIndices);
                        
                        // Delete frames
                        foreach ($frameIndices as $index) {
                            if (isset($frames[$index])) {
                                $framePath = __DIR__ . '/' . $frames[$index];
                                if (file_exists($framePath)) {
                                    unlink($framePath);
                                }
                                array_splice($frames, $index, 1);
                            }
                        }
                        
                        // Re-create the video from remaining frames
                        $originalPath = $_SESSION['current_media']['path'];
                        $outputFilename = 'reconstructed_' . uniqid() . '.mp4';
                        $outputPath = $uploadDir . $outputFilename;
                        
                        // Convert frame paths to full paths
                        $framePaths = array_map(function($frame) {
                            return __DIR__ . '/' . $frame;
                        }, $frames);
                        
                        // Recreate video from remaining frames
                        if ($videoEditor->createVideoFromFrames($framePaths, $outputPath, 10)) {
                            $videoInfo = $videoEditor->quickVideoInfo($outputPath);
                            
                            // Update session with new video
                            $mediaData = [
                                'type' => 'video',
                                'path' => $outputPath,
                                'url' => 'video_uploads/' . $outputFilename,
                                'filename' => $outputFilename,
                                'frames' => $frames,
                                'frame_count' => count($frames),
                                'duration' => $videoInfo['duration'],
                                'resolution' => $_SESSION['current_media']['resolution'],
                                'fps' => $_SESSION['current_media']['fps']
                            ];
                            
                            $_SESSION['current_media'] = $mediaData;
                            saveToHistory($mediaData);
                            
                            $response = [
                                'success' => true,
                                'frames' => $frames,
                                'frame_count' => count($frames),
                                'url' => 'video_uploads/' . $outputFilename,
                                'duration' => $videoInfo['duration']
                            ];
                        } else {
                            $response['error'] = 'Failed to reconstruct video after frame deletion.';
                        }
                    } else {
                        $response['error'] = 'No frames selected for deletion';
                    }
                }
                break;
                
            case 'duplicate_frame':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $videoPath = $_SESSION['current_media']['path'];
                    $outputFilename = 'duplicated_' . uniqid() . '.mp4';
                    $outputPath = $uploadDir . $outputFilename;
                    $frameTime = floatval($_POST['frame_time'] ?? 0);
                    
                    $result = $videoEditor->duplicateFrame($videoPath, $outputPath, $frameTime);
                    
                    if ($result) {
                        // Use quick video info
                        $videoInfo = $videoEditor->quickVideoInfo($outputPath);
                        
                        // Extract frames for duplicated frame video
                        $frameDir = $uploadDir . 'frames/' . pathinfo($outputFilename, PATHINFO_FILENAME);
                        $frameResult = $videoEditor->extractFrames($outputPath, $frameDir);
                        
                        $mediaData = [
                            'type' => 'video',
                            'path' => $outputPath,
                            'url' => 'video_uploads/' . $outputFilename,
                            'filename' => $outputFilename,
                            'frames' => $frameResult['success'] ? array_map(function($frame) {
                                return str_replace(__DIR__ . '/', '', $frame);
                            }, $frameResult['frames']) : [],
                            'frame_count' => $frameResult['frame_count'] ?? 0,
                            'duration' => $videoInfo['duration'],
                            'resolution' => $_SESSION['current_media']['resolution'],
                            'fps' => $_SESSION['current_media']['fps']
                        ];
                        
                        $_SESSION['current_media'] = $mediaData;
                        saveToHistory($mediaData);
                        
                        $response = [
                            'success' => true,
                            'url' => 'video_uploads/' . $outputFilename,
                            'frames' => $_SESSION['current_media']['frames'],
                            'duration' => $_SESSION['current_media']['duration']
                        ];
                    }
                }
                break;
                
            case 'undo':
                if (!empty($_SESSION['editor_history']) && $_SESSION['editor_history_index'] > 0) {
                    $_SESSION['editor_history_index']--;
                    $_SESSION['current_media'] = $_SESSION['editor_history'][$_SESSION['editor_history_index']];
                    
                    $response = [
                        'success' => true,
                        'url' => $_SESSION['current_media']['url'],
                        'frames' => $_SESSION['current_media']['frames'],
                        'duration' => $_SESSION['current_media']['duration']
                    ];
                } else {
                    $response['error'] = 'No more undo steps available';
                }
                break;
                
            case 'redo':
                if (!empty($_SESSION['editor_history']) && $_SESSION['editor_history_index'] < count($_SESSION['editor_history']) - 1) {
                    $_SESSION['editor_history_index']++;
                    $_SESSION['current_media'] = $_SESSION['editor_history'][$_SESSION['editor_history_index']];
                    
                    $response = [
                        'success' => true,
                        'url' => $_SESSION['current_media']['url'],
                        'frames' => $_SESSION['current_media']['frames'],
                        'duration' => $_SESSION['current_media']['duration']
                    ];
                } else {
                    $response['error'] = 'No more redo steps available';
                }
                break;

            case 'save_and_redirect':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $videoPath = $_SESSION['current_media']['path'];
                    $filename = $_SESSION['current_media']['filename'];
                    
                    // Apply watermark before saving
                    $watermarkedFilename = 'final_' . uniqid() . '.mp4';
                    $watermarkedPath = $uploadDir . $watermarkedFilename;
                    
                    if ($videoEditor->applyFinalWatermark($videoPath, $watermarkedPath)) {
                        $url = 'video_uploads/' . $watermarkedFilename;
                        
                        // Save to database
                        $videoId = saveEditedVideoToDatabase($pdo, $_SESSION['user_id'], $url, $watermarkedFilename);
                        
                        if ($videoId) {
                            // Store in session for make_post.php
                            $_SESSION['edited_video'] = [
                                'type' => 'video',
                                'path' => $url,
                                'filename' => $watermarkedFilename,
                                'video_id' => $videoId
                            ];
                            
                            $response = [
                                'success' => true,
                                'redirect_url' => 'make_post.php'
                            ];
                        } else {
                            $response = ['success' => false, 'error' => 'Failed to save video to database'];
                        }
                    } else {
                        $response = ['success' => false, 'error' => 'Failed to apply watermark'];
                    }
                } else {
                    $response = ['success' => false, 'error' => 'No video available to save'];
                }
                break;

            case 'reverse_video':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $videoPath = $_SESSION['current_media']['path'];
                    $outputFilename = 'reversed_' . uniqid() . '.mp4';
                    $outputPath = $uploadDir . $outputFilename;
                    
                    $result = $videoEditor->reverseVideo($videoPath, $outputPath);
                    
                    if ($result) {
                        // Use quick video info
                        $videoInfo = $videoEditor->quickVideoInfo($outputPath);
                        
                        // Extract frames for reversed video
                        $frameDir = $uploadDir . 'frames/' . pathinfo($outputFilename, PATHINFO_FILENAME);
                        $frameResult = $videoEditor->extractFrames($outputPath, $frameDir);
                        
                        $mediaData = [
                            'type' => 'video',
                            'path' => $outputPath,
                            'url' => 'video_uploads/' . $outputFilename,
                            'filename' => $outputFilename,
                            'frames' => $frameResult['success'] ? array_map(function($frame) {
                                return str_replace(__DIR__ . '/', '', $frame);
                            }, $frameResult['frames']) : [],
                            'frame_count' => $frameResult['frame_count'] ?? 0,
                            'duration' => $videoInfo['duration'],
                            'resolution' => $_SESSION['current_media']['resolution'],
                            'fps' => $_SESSION['current_media']['fps']
                        ];
                        
                        $_SESSION['current_media'] = $mediaData;
                        saveToHistory($mediaData);
                        
                        $response = [
                            'success' => true,
                            'url' => 'video_uploads/' . $outputFilename,
                            'frames' => $_SESSION['current_media']['frames'],
                            'duration' => $_SESSION['current_media']['duration']
                        ];
                    }
                }
                break;

            case 'extract_audio':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $videoPath = $_SESSION['current_media']['path'];
                    $outputFilename = 'audio_' . uniqid() . '.mp3';
                    $outputPath = $uploadDir . $outputFilename;
                    
                    $result = $videoEditor->extractAudio($videoPath, $outputPath);
                    
                    if ($result) {
                        $response = [
                            'success' => true,
                            'url' => 'video_uploads/' . $outputFilename,
                            'filename' => $outputFilename
                        ];
                    }
                }
                break;

            case 'stabilize_video':
                if (!empty($_SESSION['current_media']) && $_SESSION['current_media']['type'] === 'video') {
                    $videoPath = $_SESSION['current_media']['path'];
                    $outputFilename = 'stabilized_' . uniqid() . '.mp4';
                    $outputPath = $uploadDir . $outputFilename;
                    
                    $result = $videoEditor->stabilizeVideo($videoPath, $outputPath);
                    
                    if ($result) {
                        // Use quick video info
                        $videoInfo = $videoEditor->quickVideoInfo($outputPath);
                        
                        // Extract frames for stabilized video
                        $frameDir = $uploadDir . 'frames/' . pathinfo($outputFilename, PATHINFO_FILENAME);
                        $frameResult = $videoEditor->extractFrames($outputPath, $frameDir);
                        
                        $mediaData = [
                            'type' => 'video',
                            'path' => $outputPath,
                            'url' => 'video_uploads/' . $outputFilename,
                            'filename' => $outputFilename,
                            'frames' => $frameResult['success'] ? array_map(function($frame) {
                                return str_replace(__DIR__ . '/', '', $frame);
                            }, $frameResult['frames']) : [],
                            'frame_count' => $frameResult['frame_count'] ?? 0,
                            'duration' => $videoInfo['duration'],
                            'resolution' => $_SESSION['current_media']['resolution'],
                            'fps' => $_SESSION['current_media']['fps']
                        ];
                        
                        $_SESSION['current_media'] = $mediaData;
                        saveToHistory($mediaData);
                        
                        $response = [
                            'success' => true,
                            'url' => 'video_uploads/' . $outputFilename,
                            'frames' => $_SESSION['current_media']['frames'],
                            'duration' => $_SESSION['current_media']['duration']
                        ];
                    }
                }
                break;
        }
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Pro Video Editor - CapCut + InShot Clone</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
       :root {
    --primary: #7b68ee;
    --secondary: #ff6b6b;
    --success: #48bb78;
    --dark: #1e1e2f;
    --darker: #151521;
    --light: #2c2c3d;
    --lighter: #3a3a4d;
    --text: #ffffff;
    --text-secondary: #b0b0b0;
    --timeline-height: 180px;
    --tools-height: 80px;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: var(--text);
    overflow: hidden;
    height: 100vh;
}

.editor-container {
    display: flex;
    flex-direction: column;
    height: 100vh;
    max-height: 100vh;
}

/* Header */
.editor-header {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 12px 20px;
    border-bottom: 2px solid var(--primary);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
    min-height: 70px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.logo {
    display: flex;
    align-items: center;
    gap: 12px;
}

.logo h1 {
    background: linear-gradient(45deg, var(--primary), var(--secondary));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-size: 20px;
    white-space: nowrap;
    font-weight: 800;
}

.header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 20px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    white-space: nowrap;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary), #6a5acd);
    color: white;
}

.btn-secondary {
    background: rgba(255, 255, 255, 0.9);
    color: var(--primary);
    border: 2px solid var(--primary);
}

.btn-success {
    background: linear-gradient(135deg, var(--success), #38a169);
    color: white;
}

.btn-danger {
    background: linear-gradient(135deg, var(--secondary), #ee5a52);
    color: white;
}

.btn-small {
    padding: 6px 12px;
    font-size: 12px;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(123, 104, 238, 0.4);
}

.btn-success:hover {
    background: linear-gradient(135deg, #38a169, #2f855a);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(72, 187, 120, 0.4);
}

/* Main Content */
.editor-main {
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
    min-height: 0;
}

/* Preview Area */
.preview-area {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 15px;
    position: relative;
    flex: 1;
    min-height: 0;
    border: 2px solid var(--primary);
    margin: 10px;
    border-radius: 15px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.preview-container {
    background: black;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 8px 25px rgba(0,0,0,0.5);
    max-width: 100%;
    max-height: 100%;
    width: 100%;
    height: 100%;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.preview-media {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.preview-controls {
    position: absolute;
    bottom: 15px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,0.8);
    padding: 8px 16px;
    border-radius: 20px;
    display: flex;
    gap: 12px;
    align-items: center;
    backdrop-filter: blur(10px);
}

.play-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--primary);
    border: none;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.play-btn:hover {
    background: #6a5acd;
    transform: scale(1.1);
}

/* Timeline & Frames */
.timeline-section {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-top: 1px solid var(--primary);
    height: var(--timeline-height);
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    margin: 0 10px 10px 10px;
    border-radius: 15px;
    border: 2px solid var(--primary);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.timeline-header {
    background: rgba(123, 104, 238, 0.1);
    padding: 8px 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid rgba(123, 104, 238, 0.3);
    min-height: 40px;
    border-radius: 15px 15px 0 0;
}

.timeline-tracks {
    flex: 1;
    overflow-x: auto;
    padding: 8px;
    position: relative;
    min-height: 60px;
}

.track {
    height: 50px;
    margin-bottom: 4px;
    background: rgba(123, 104, 238, 0.1);
    border-radius: 8px;
    display: flex;
    align-items: center;
    padding: 4px;
    position: relative;
    min-width: min-content;
    border: 1px solid rgba(123, 104, 238, 0.3);
}

.track-label {
    width: 80px;
    padding: 0 8px;
    font-size: 11px;
    color: var(--primary);
    flex-shrink: 0;
    font-weight: 600;
}

.track-content {
    flex: 1;
    height: 100%;
    position: relative;
    overflow: visible;
    min-width: 200px;
}

.clip {
    height: 42px;
    background: var(--primary);
    border-radius: 8px;
    position: absolute;
    cursor: grab;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 11px;
    min-width: 60px;
    padding: 4px;
    user-select: none;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(123, 104, 238, 0.3);
}

.clip:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.4);
}

.video-clip { background: linear-gradient(135deg, var(--primary), #6a5acd); }
.audio-clip { background: linear-gradient(135deg, #4CAF50, #45a049); }
.text-clip { background: linear-gradient(135deg, #FF9800, #f57c00); }
.effect-clip { background: linear-gradient(135deg, #9C27B0, #8e24aa); }

.frames-container {
    display: flex;
    height: 70px;
    overflow-x: auto;
    background: rgba(255, 255, 255, 0.9);
    border-top: 1px solid rgba(123, 104, 238, 0.3);
    padding: 8px;
    gap: 4px;
    flex-shrink: 0;
    border-radius: 0 0 15px 15px;
}

.frame {
    flex-shrink: 0;
    width: 80px;
    height: 100%;
    background: rgba(123, 104, 238, 0.1);
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
    position: relative;
    transition: all 0.2s ease;
    border: 2px solid transparent;
}

.frame:hover {
    transform: scale(1.05);
    border: 2px solid var(--primary);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.frame.selected {
    border: 2px solid var(--secondary);
    box-shadow: 0 4px 12px rgba(255, 107, 107, 0.4);
}

.frame img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.frame-time {
    position: absolute;
    bottom: 2px;
    right: 2px;
    background: rgba(0,0,0,0.7);
    color: white;
    font-size: 9px;
    padding: 1px 3px;
    border-radius: 2px;
}

.frame-actions {
    position: absolute;
    top: 2px;
    right: 2px;
    display: none;
}

.frame:hover .frame-actions {
    display: flex;
    gap: 2px;
}

.frame-btn {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: rgba(0,0,0,0.7);
    border: none;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    transition: all 0.2s ease;
}

.frame-btn:hover {
    background: var(--primary);
    transform: scale(1.1);
}

/* Tools Panel */
.tools-panel {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-top: 1px solid var(--primary);
    display: flex;
    overflow-x: auto;
    padding: 12px;
    gap: 8px;
    height: var(--tools-height);
    flex-shrink: 0;
    margin: 0 10px 10px 10px;
    border-radius: 15px;
    border: 2px solid var(--primary);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.tool-section {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 12px;
    padding: 12px;
    min-width: 160px;
    flex-shrink: 0;
    border: 1px solid rgba(123, 104, 238, 0.3);
}

.tool-section h3 {
    margin-bottom: 8px;
    color: var(--primary);
    font-size: 13px;
    white-space: nowrap;
    font-weight: 700;
}

.tool-options {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.tool-btn {
    padding: 6px 10px;
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid rgba(123, 104, 238, 0.3);
    border-radius: 6px;
    color: var(--primary);
    cursor: pointer;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    white-space: nowrap;
    transition: all 0.3s ease;
    font-weight: 600;
}

.tool-btn:hover {
    background: var(--primary);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.3);
}

.slider-container {
    margin-bottom: 8px;
}

.slider-container label {
    display: block;
    margin-bottom: 4px;
    color: var(--primary);
    font-size: 11px;
    font-weight: 600;
}

.slider {
    width: 100%;
    height: 5px;
    background: rgba(123, 104, 238, 0.2);
    border-radius: 3px;
    outline: none;
    -webkit-appearance: none;
}

.slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 14px;
    height: 14px;
    background: var(--primary);
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(123, 104, 238, 0.4);
}

/* Upload Area */
.upload-area {
    border: 3px dashed var(--primary);
    border-radius: 12px;
    padding: 30px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: rgba(123, 104, 238, 0.1);
    max-width: 400px;
    width: 100%;
}

.upload-area:hover {
    background: rgba(123, 104, 238, 0.2);
    transform: scale(1.02);
    border-color: #6a5acd;
}

.upload-icon {
    font-size: 36px;
    margin-bottom: 12px;
    color: var(--primary);
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.9);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-content {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    padding: 20px;
    max-width: 500px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    border: 2px solid var(--primary);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid rgba(123, 104, 238, 0.3);
}

.modal-header h3 {
    color: var(--primary);
    font-weight: 700;
}

.close-modal {
    background: none;
    border: none;
    color: var(--primary);
    font-size: 20px;
    cursor: pointer;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.3s ease;
}

.close-modal:hover {
    background: rgba(123, 104, 238, 0.1);
    transform: scale(1.1);
}

/* Loading Animation */
.loading {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255,255,255,.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Toast Notifications */
.toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 12px 18px;
    background: linear-gradient(135deg, var(--primary), #6a5acd);
    color: white;
    border-radius: 8px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    z-index: 1000;
    transform: translateX(150%);
    transition: transform 0.3s ease;
    max-width: 300px;
    font-weight: 600;
}

.toast.show {
    transform: translateX(0);
}

.toast.error {
    background: linear-gradient(135deg, var(--secondary), #ee5a52);
}

.toast.success {
    background: linear-gradient(135deg, var(--success), #38a169);
}

/* Color Picker */
.color-picker {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}

.color-swatch {
    width: 25px;
    height: 25px;
    border-radius: 4px;
    border: 2px solid rgba(123, 104, 238, 0.3);
    cursor: pointer;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.color-swatch:hover {
    transform: scale(1.1);
    border-color: var(--primary);
}

/* Text Input */
.text-input {
    width: 100%;
    padding: 8px;
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid var(--primary);
    color: #2d3748;
    margin-bottom: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.text-input:focus {
    outline: none;
    border-color: #6a5acd;
    box-shadow: 0 4px 12px rgba(123, 104, 238, 0.2);
}

.form-group {
    margin-bottom: 12px;
}

.form-group label {
    display: block;
    margin-bottom: 4px;
    color: var(--primary);
    font-size: 13px;
    font-weight: 600;
}

/* Draggable Text Overlay */
.text-overlay {
    position: absolute;
    background: rgba(0,0,0,0.7);
    color: white;
    padding: 5px 10px;
    border-radius: 5px;
    cursor: move;
    z-index: 100;
    user-select: none;
    max-width: 200px;
    word-wrap: break-word;
    border: 1px solid var(--primary);
}

.text-overlay:hover {
    background: rgba(0,0,0,0.9);
}

/* Loading States */
.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none !important;
}

.btn.loading {
    position: relative;
    color: transparent;
}

.btn.loading::after {
    content: '';
    position: absolute;
    width: 16px;
    height: 16px;
    top: 50%;
    left: 50%;
    margin: -8px 0 0 -8px;
    border: 2px solid rgba(255,255,255,.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 1s ease-in-out infinite;
}

.processing-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    flex-direction: column;
    gap: 20px;
}

.processing-spinner {
    width: 60px;
    height: 60px;
    border: 4px solid rgba(255,255,255,0.3);
    border-radius: 50%;
    border-top-color: var(--primary);
    animation: spin 1s ease-in-out infinite;
}

.processing-text {
    color: white;
    font-size: 18px;
    text-align: center;
    font-weight: 600;
}

/* Scrollbar Styling */
::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

::-webkit-scrollbar-track {
    background: rgba(123, 104, 238, 0.1);
    border-radius: 3px;
}

::-webkit-scrollbar-thumb {
    background: var(--primary);
    border-radius: 3px;
}

::-webkit-scrollbar-thumb:hover {
    background: #6a5acd;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .editor-header {
        padding: 10px 15px;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .logo h1 {
        font-size: 18px;
    }
    
    .header-actions {
        gap: 8px;
    }
    
    .btn {
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .preview-area {
        padding: 10px;
        margin: 5px;
    }
    
    .preview-controls {
        bottom: 10px;
        padding: 6px 12px;
    }
    
    .play-btn {
        width: 32px;
        height: 32px;
    }
    
    .timeline-section {
        height: 160px;
        margin: 0 5px 5px 5px;
    }
    
    .frames-container {
        height: 60px;
    }
    
    .frame {
        width: 70px;
    }
    
    .tools-panel {
        height: 70px;
        padding: 8px;
        margin: 0 5px 5px 5px;
    }
    
    .tool-section {
        min-width: 140px;
        padding: 8px;
    }
    
    .tool-btn {
        padding: 5px 8px;
        font-size: 10px;
    }
    
    .upload-area {
        padding: 20px 15px;
    }
    
    .upload-icon {
        font-size: 28px;
    }
}

@media (max-width: 480px) {
    .editor-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .header-actions {
        width: 100%;
        justify-content: space-between;
    }
    
    .logo h1 {
        font-size: 16px;
    }
    
    .timeline-section {
        height: 140px;
    }
    
    .track-label {
        width: 60px;
        font-size: 10px;
    }
    
    .frames-container {
        height: 50px;
    }
    
    .frame {
        width: 60px;
    }
    
    .tools-panel {
        height: 60px;
    }
    
    .tool-section {
        min-width: 120px;
    }
}

/* Animation for page load */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.preview-area, .timeline-section, .tools-panel {
    animation: fadeInUp 0.6s ease-out;
}
    </style>
</head>
<body>
    <!-- Processing Overlay -->
    <div class="processing-overlay" id="processingOverlay">
        <div class="processing-spinner"></div>
        <div class="processing-text" id="processingText">Processing your video...</div>
    </div>

    <div class="editor-container">
        <!-- Header -->
        <div class="editor-header">
            <div class="logo">
                <i class="fas fa-video" style="color: var(--primary); font-size: 24px;"><img src="images/IMG-20251018-WA0017 (2).jpg" alt="" style="width: 50px;height: 50px;border-radius: 25px;"></i>
                <h1>Pro Video Editor</h1>
            </div>
            <div class="header-actions">
              
                    <!-- ADD THIS UPLOAD BUTTON -->
    <button class="btn btn-primary" onclick="openFilePicker()" style="background: linear-gradient(135deg, #4CAF50, #45a049);">
        <i class="fas fa-upload"></i> Upload Video/Image
    </button>
                <button class="btn btn-secondary" id="undoBtn" onclick="undoAction()" disabled>
                    <i class="fas fa-undo"></i> Undo
                </button>
                <button class="btn btn-secondary" id="redoBtn" onclick="redoAction()" disabled>
                    <i class="fas fa-redo"></i> Redo
                </button>
                <button class="btn btn-danger" onclick="removeCurrentMedia()" id="removeMediaBtn" style="display: none;">
                    <i class="fas fa-trash"></i> Remove Media
                </button>
                <button class="btn btn-secondary" onclick="history.back()">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                                <button class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> <a href="make_post.php">post </a>
                </button>
                <button class="btn btn-success" id="doneBtn" onclick="confirmSaveAndRedirect()" style="display: none;">
                    <i class="fas fa-check"></i> Done Editing
                </button>
                <button class="btn btn-primary" onclick="showExportModal()">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="editor-main">
            <!-- Preview Area -->
            <div class="preview-area">
                <div class="preview-container" id="previewContainer">
                    <div id="uploadArea" class="upload-area">
                        <div class="upload-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <h3>Drag & Drop Video Files</h3>
                        <p>or click to browse</p>
                        <input type="file" id="mediaInput" style="display: none;" accept="video/*,image/*" multiple>
                        <div style="margin-top: 15px;">
                            <button class="btn btn-secondary" onclick="showImageUploadModal()">
                                <i class="fas fa-images"></i> Create Video from Images
                            </button>
                        </div>
                    </div>
                    <video id="videoPreview" class="preview-media" style="display: none;" controls></video>
                    <img id="imagePreview" class="preview-media" style="display: none;">
                    
                    <div class="preview-controls" id="previewControls" style="display: none;">
                        <button class="play-btn" id="playBtn">
                            <i class="fas fa-play"></i>
                        </button>
                        <span id="timeDisplay">0:00 / 0:00</span>
                    </div>
                </div>
            </div>

            <!-- Timeline & Frames -->
            <div class="timeline-section">
                <div class="timeline-header">
                    <h3>Timeline</h3>
                    <div>
                        <button class="btn btn-secondary btn-small" onclick="addTextTrack()">
                            <i class="fas fa-font"></i> Add Text
                        </button>
                        <button class="btn btn-secondary btn-small" onclick="showAudioModal()">
                            <i class="fas fa-music"></i> Add Audio
                        </button>
                        <button class="btn btn-secondary btn-small" onclick="duplicateCurrentFrame()">
                            <i class="fas fa-copy"></i> Duplicate Frame
                        </button>
                    </div>
                </div>
                
                <div class="timeline-tracks" id="timelineTracks">
                    <div class="track">
                        <div class="track-label">Video Track</div>
                        <div class="track-content" id="videoTrack">
                            <!-- Video clips will be added here -->
                        </div>
                    </div>
                    <div class="track">
                        <div class="track-label">Audio Track</div>
                        <div class="track-content" id="audioTrack">
                            <!-- Audio clips will be added here -->
                        </div>
                    </div>
                </div>
                
                <div class="frames-container" id="framesContainer">
                    <!-- Frames will be added here -->
                </div>
            </div>
        </div>

        <!-- Tools Panel -->
        <div class="tools-panel">
            <div class="tool-section">
                <h3><i class="fas fa-scissors"></i> Edit Tools</h3>
                <div class="tool-options">
                    <button class="tool-btn" onclick="showTrimModal()">
                        <i class="fas fa-cut"></i> Trim Video
                    </button>
                    <button class="tool-btn" onclick="showSplitModal()">
                        <i class="fas fa-code-branch"></i> Split Video
                    </button>
                    <button class="tool-btn" onclick="showSpeedModal()">
                        <i class="fas fa-tachometer-alt"></i> Adjust Speed
                    </button>
                    <button class="tool-btn" onclick="reverseVideo()">
                        <i class="fas fa-redo"></i> Reverse Video
                    </button>
                </div>
            </div>
            
            <div class="tool-section">
                <h3><i class="fas fa-magic"></i> Effects & Filters</h3>
                <div class="tool-options">
                    <button class="tool-btn" onclick="showTextModal()">
                        <i class="fas fa-font"></i> Add Text
                    </button>
                    <button class="tool-btn" onclick="applyFilter('grayscale')">
                        <i class="fas fa-moon"></i> Grayscale
                    </button>
                    <button class="tool-btn" onclick="applyFilter('sepia')">
                        <i class="fas fa-umbrella"></i> Sepia
                    </button>
                    <button class="tool-btn" onclick="applyFilter('vintage')">
                        <i class="fas fa-camera-retro"></i> Vintage
                    </button>
                    <button class="tool-btn" onclick="applyFilter('bright')">
                        <i class="fas fa-sun"></i> Brighten
                    </button>
                    <button class="tool-btn" onclick="showFiltersModal()">
                        <i class="fas fa-plus"></i> More Filters
                    </button>
                </div>
            </div>
            
            <div class="tool-section">
                <h3><i class="fas fa-sliders-h"></i> Audio Tools</h3>
                <div class="tool-options">
                    <button class="tool-btn" onclick="extractAudio()">
                        <i class="fas fa-volume-up"></i> Extract Audio
                    </button>
                    <button class="tool-btn" onclick="removeAudio()">
                        <i class="fas fa-volume-mute"></i> Remove Audio
                    </button>
                    <button class="tool-btn" onclick="showAudioModal()">
                        <i class="fas fa-music"></i> Add Audio
                    </button>
                </div>
            </div>
            
            <div class="tool-section">
                <h3><i class="fas fa-cog"></i> Advanced</h3>
                <div class="tool-options">
                    <button class="tool-btn" onclick="stabilizeVideo()">
                        <i class="fas fa-shield-alt"></i> Stabilize Video
                    </button>
                    <button class="tool-btn" onclick="showExportModal()">
                        <i class="fas fa-download"></i> Export Settings
                    </button>
                    <button class="tool-btn" onclick="showFrameManager()">
                        <i class="fas fa-layer-group"></i> Manage Frames
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Image Upload Modal -->
    <div class="modal" id="imageUploadModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create Video from Images</h3>
                <button class="close-modal" onclick="closeModal('imageUploadModal')">&times;</button>
            </div>
            <div class="upload-area" onclick="document.getElementById('imagesInput').click()">
                <div class="upload-icon">
                    <i class="fas fa-images"></i>
                </div>
                <p>Select Multiple Images</p>
                <input type="file" id="imagesInput" style="display: none;" accept="image/*" multiple>
            </div>
            <div class="form-group">
                <label>Frame Duration (seconds per image):</label>
                <input type="range" class="slider" id="frameDuration" min="1" max="10" value="3">
                <span id="frameDurationValue">3 seconds</span>
            </div>
            <button class="btn btn-primary" style="width: 100%;" onclick="uploadMultipleImages()">
                <i class="fas fa-film"></i> Create Video
            </button>
        </div>
    </div>

    <!-- Trim Modal -->
    <div class="modal" id="trimModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Trim Video</h3>
                <button class="close-modal" onclick="closeModal('trimModal')">&times;</button>
            </div>
            <div class="form-group">
                <label>Start Time (seconds):</label>
                <input type="range" class="slider" id="trimStart" min="0" max="100" value="0" step="0.1">
                <span id="trimStartValue">0s</span>
            </div>
            <div class="form-group">
                <label>Duration (seconds):</label>
                <input type="range" class="slider" id="trimDuration" min="1" max="100" value="10" step="0.1">
                <span id="trimDurationValue">10s</span>
            </div>
            <button class="btn btn-primary" style="width: 100%;" onclick="trimVideo()">
                <i class="fas fa-cut"></i> Trim Video
            </button>
        </div>
    </div>

    <!-- Audio Modal -->
    <div class="modal" id="audioModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Audio to Video</h3>
                <button class="close-modal" onclick="closeModal('audioModal')">&times;</button>
            </div>
            <div class="upload-area" onclick="document.getElementById('audioInput').click()">
                <div class="upload-icon">
                    <i class="fas fa-music"></i>
                </div>
                <p>Select Audio File</p>
                <input type="file" id="audioInput" style="display: none;" accept="audio/*">
            </div>
            <div class="form-group">
                <label>Audio Volume:</label>
                <input type="range" class="slider" id="audioVolume" min="0" max="2" value="1" step="0.1">
                <span id="audioVolumeValue">100%</span>
            </div>
            <button class="btn btn-primary" style="width: 100%;" onclick="addAudioToVideo()">
                <i class="fas fa-plus"></i> Add Audio
            </button>
        </div>
    </div>

    <!-- Text Modal -->
    <div class="modal" id="textModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Text to Video</h3>
                <button class="close-modal" onclick="closeModal('textModal')">&times;</button>
            </div>
            <div class="form-group">
                <label>Text Content:</label>
                <input type="text" class="text-input" id="textContent" placeholder="Enter your text here" value="Sample Text">
            </div>
            <div class="form-group">
                <label>Font Size:</label>
                <input type="range" class="slider" id="textFontSize" min="10" max="100" value="24">
                <span id="textFontSizeValue">24px</span>
            </div>
            <div class="form-group">
                <label>Text Color:</label>
                <div class="color-picker">
                    <div class="color-swatch" style="background: #ffffff;" data-color="#ffffff" onclick="selectTextColor('#ffffff')"></div>
                    <div class="color-swatch" style="background: #000000;" data-color="#000000" onclick="selectTextColor('#000000')"></div>
                    <div class="color-swatch" style="background: #ff0000;" data-color="#ff0000" onclick="selectTextColor('#ff0000')"></div>
                    <div class="color-swatch" style="background: #00ff00;" data-color="#00ff00" onclick="selectTextColor('#00ff00')"></div>
                    <div class="color-swatch" style="background: #0000ff;" data-color="#0000ff" onclick="selectTextColor('#0000ff')"></div>
                </div>
            </div>
            <div class="form-group">
                <label>Duration (seconds):</label>
                <input type="range" class="slider" id="textDuration" min="1" max="30" value="5">
                <span id="textDurationValue">5s</span>
            </div>
            <div class="form-group">
                <label>Position (drag the text in preview to adjust):</label>
                <div class="color-picker">
                    <button class="tool-btn" onclick="resetTextPosition()">
                        <i class="fas fa-sync-alt"></i> Reset Position
                    </button>
                </div>
            </div>
            <button class="btn btn-primary" style="width: 100%;" onclick="addTextToVideo()">
                <i class="fas fa-plus"></i> Add Text
            </button>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal" id="exportModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Export Video</h3>
                <button class="close-modal" onclick="closeModal('exportModal')">&times;</button>
            </div>
            <div class="form-group">
                <label>Quality:</label>
                <select id="exportQuality" class="text-input">
                    <option value="low">Low (360p)</option>
                    <option value="medium" selected>Medium (720p)</option>
                    <option value="high">High (1080p)</option>
                    <option value="4k">4K (2160p)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Format:</label>
                <select id="exportFormat" class="text-input">
                    <option value="mp4" selected>MP4</option>
                    <option value="mov">MOV</option>
                    <option value="avi">AVI</option>
                </select>
            </div>
            <button class="btn btn-primary" style="width: 100%;" onclick="exportVideo()">
                <i class="fas fa-download"></i> Export Video
            </button>
        </div>
    </div>

    <!-- Frame Manager Modal -->
    <div class="modal" id="frameManagerModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Manage Frames</h3>
                <button class="close-modal" onclick="closeModal('frameManagerModal')">&times;</button>
            </div>
            <div class="form-group">
                <p>Select frames to delete:</p>
                <div id="frameManagerList" style="max-height: 300px; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 5px; margin-top: 10px;">
                    <!-- Frames will be added here -->
                </div>
            </div>
            <div class="form-group">
                <button class="btn btn-danger" style="width: 100%;" onclick="deleteSelectedFrames()">
                    <i class="fas fa-trash"></i> Delete Selected Frames
                </button>
            </div>
        </div>
    </div>

    <!-- Filters Modal -->
    <div class="modal" id="filtersModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Apply Filter</h3>
                <button class="close-modal" onclick="closeModal('filtersModal')">&times;</button>
            </div>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; max-height: 400px; overflow-y: auto;">
                <button class="tool-btn" onclick="applyFilterFromModal('grayscale')">
                    <i class="fas fa-moon"></i> Grayscale
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('sepia')">
                    <i class="fas fa-umbrella"></i> Sepia
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('vintage')">
                    <i class="fas fa-camera-retro"></i> Vintage
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('cool')">
                    <i class="fas fa-snowflake"></i> Cool
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('warm')">
                    <i class="fas fa-fire"></i> Warm
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('bright')">
                    <i class="fas fa-sun"></i> Bright
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('contrast')">
                    <i class="fas fa-adjust"></i> Contrast
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('saturation')">
                    <i class="fas fa-palette"></i> Saturation
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('hue')">
                    <i class="fas fa-rainbow"></i> Hue Shift
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('invert')">
                    <i class="fas fa-inbox"></i> Invert
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('posterize')">
                    <i class="fas fa-th-large"></i> Posterize
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('edge')">
                    <i class="fas fa-drafting-compass"></i> Edge Detect
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('emboss')">
                    <i class="fas fa-mountain"></i> Emboss
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('sharpen')">
                    <i class="fas fa-crosshairs"></i> Sharpen
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('blur')">
                    <i class="fas fa-cloud"></i> Blur
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('pixelate')">
                    <i class="fas fa-th"></i> Pixelate
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('nightvision')">
                    <i class="fas fa-eye"></i> Night Vision
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('noir')">
                    <i class="fas fa-user-secret"></i> Noir
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('dramatic')">
                    <i class="fas fa-theater-masks"></i> Dramatic
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('cinematic')">
                    <i class="fas fa-film"></i> Cinematic
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('vibrant')">
                    <i class="fas fa-fill-drip"></i> Vibrant
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('pastel')">
                    <i class="fas fa-pastafarianism"></i> Pastel
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('sunset')">
                    <i class="fas fa-sunset"></i> Sunset
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('moonlight')">
                    <i class="fas fa-moon"></i> Moonlight
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('golden')">
                    <i class="fas fa-crown"></i> Golden
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('silver')">
                    <i class="fas fa-ring"></i> Silver
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('lomo')">
                    <i class="fas fa-camera"></i> Lomo
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('clarity')">
                    <i class="fas fa-search"></i> Clarity
                </button>
                <button class="tool-btn" onclick="applyFilterFromModal('dramatic_bw')">
                    <i class="fas fa-star"></i> Dramatic B&W
                </button>
            </div>
        </div>
    </div>

    <!-- Duplicate Frame Modal -->
    <div class="modal" id="duplicateFrameModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Duplicate Frame</h3>
                <button class="close-modal" onclick="closeModal('duplicateFrameModal')">&times;</button>
            </div>
            <div class="form-group">
                <label>Frame Time (seconds):</label>
                <input type="range" class="slider" id="duplicateFrameTime" min="0" max="100" value="0" step="0.1">
                <span id="duplicateFrameTimeValue">0s</span>
            </div>
            <button class="btn btn-primary" style="width: 100%;" onclick="duplicateFrame()">
                <i class="fas fa-copy"></i> Duplicate Frame
            </button>
        </div>
    </div>

    <!-- Split Video Modal -->
    <div class="modal" id="splitModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Split Video</h3>
                <button class="close-modal" onclick="closeModal('splitModal')">&times;</button>
            </div>
            <div class="form-group">
                <label>Split Time (seconds):</label>
                <input type="range" class="slider" id="splitTime" min="0" max="100" value="0" step="0.1">
                <span id="splitTimeValue">0s</span>
            </div>
            <button class="btn btn-primary" style="width: 100%;" onclick="splitVideo()">
                <i class="fas fa-code-branch"></i> Split Video
            </button>
        </div>
    </div>

    <!-- Speed Modal -->
    <div class="modal" id="speedModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Adjust Video Speed</h3>
                <button class="close-modal" onclick="closeModal('speedModal')">&times;</button>
            </div>
            <div class="form-group">
                <label>Speed:</label>
                <input type="range" class="slider" id="speedValue" min="0.25" max="4" value="1" step="0.25">
                <span id="speedValueDisplay">1x</span>
            </div>
            <button class="btn btn-primary" style="width: 100%;" onclick="adjustSpeed()">
                <i class="fas fa-tachometer-alt"></i> Adjust Speed
            </button>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast"></div>

    <script>
        // Global variables
        let currentMediaType = null;
        let currentVideo = null;
        let currentFrames = [];
        let videoDuration = 0;
        let isPlaying = false;
        let timelineClips = [];
        let selectedTextColor = '#ffffff';
        let selectedFrames = new Set();
        let textOverlay = null;
        let textPosition = { x: 10, y: 10 };

        // Initialize editor
        document.addEventListener('DOMContentLoaded', function() {
            initializeEventListeners();
            setupDragAndDrop();
            updateUndoRedoButtons();
            updateDoneButton();
        });

        function initializeEventListeners() {
            // File input
            document.getElementById('mediaInput').addEventListener('change', handleFileSelect);
            document.getElementById('imagesInput').addEventListener('change', updateImageUploadPreview);
            document.getElementById('audioInput').addEventListener('change', updateAudioPreview);

            // Video controls
            const playBtn = document.getElementById('playBtn');
            const videoPreview = document.getElementById('videoPreview');
            
            if (playBtn && videoPreview) {
                playBtn.addEventListener('click', togglePlayback);
                videoPreview.addEventListener('timeupdate', updateTimeDisplay);
                videoPreview.addEventListener('loadedmetadata', function() {
                    videoDuration = videoPreview.duration;
                    updateTimeDisplay();
                    // Update duplicate frame time slider max
                    const duplicateFrameTimeSlider = document.getElementById('duplicateFrameTime');
                    if (duplicateFrameTimeSlider) {
                        duplicateFrameTimeSlider.max = Math.floor(videoDuration);
                    }
                    // Update split time slider max
                    const splitTimeSlider = document.getElementById('splitTime');
                    if (splitTimeSlider) {
                        splitTimeSlider.max = Math.floor(videoDuration);
                        splitTimeSlider.value = Math.floor(videoDuration / 2);
                        document.getElementById('splitTimeValue').textContent = Math.floor(videoDuration / 2) + 's';
                    }
                    // Update trim sliders
                    const trimStart = document.getElementById('trimStart');
                    const trimDuration = document.getElementById('trimDuration');
                    if (trimStart && trimDuration) {
                        trimStart.max = Math.floor(videoDuration);
                        trimDuration.max = Math.floor(videoDuration);
                        trimDuration.value = Math.floor(videoDuration);
                        document.getElementById('trimDurationValue').textContent = Math.floor(videoDuration) + 's';
                    }
                });
            }

            // Real-time slider updates
            const sliders = ['frameDuration', 'trimStart', 'trimDuration', 'audioVolume', 'textFontSize', 'textDuration', 'duplicateFrameTime', 'splitTime', 'speedValue'];
            sliders.forEach(slider => {
                const element = document.getElementById(slider);
                if (element) {
                    element.addEventListener('input', function() {
                        const valueElement = document.getElementById(slider + 'Value') || document.getElementById(slider + 'Display');
                        if (valueElement) {
                            if (slider === 'frameDuration') {
                                valueElement.textContent = this.value + ' seconds';
                            } else if (slider === 'audioVolume') {
                                valueElement.textContent = Math.round(this.value * 100) + '%';
                            } else if (slider === 'textFontSize') {
                                valueElement.textContent = this.value + 'px';
                            } else if (slider === 'speedValue') {
                                valueElement.textContent = this.value + 'x';
                            } else {
                                valueElement.textContent = this.value + 's';
                            }
                        }
                    });
                }
            });
        }

        function handleFileSelect(event) {
            const files = event.target.files;
            if (files.length > 0) {
                const file = files[0];
                uploadMedia(file);
            }
        }

        function uploadMedia(file) {
            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'upload_media');
            formData.append('media', file);

            showProcessing('Uploading media...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Media uploaded successfully!', 'success');
                    displayMediaPreview(data.url, data.mediaType, data.frames, data.duration);
                    currentMediaType = data.mediaType;
                    
                    // Show remove media button
                    document.getElementById('removeMediaBtn').style.display = 'block';
                    updateUndoRedoButtons();
                    updateDoneButton();
                } else {
                    showToast('Upload failed: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Upload failed', 'error');
            });
        }

        function uploadMultipleImages() {
            const files = document.getElementById('imagesInput').files;
            if (files.length === 0) {
                showToast('Please select images first', 'error');
                return;
            }

            const frameDuration = document.getElementById('frameDuration').value;
            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'upload_multiple_images');
            formData.append('frame_duration', frameDuration);

            for (let i = 0; i < files.length; i++) {
                formData.append('images[]', files[i]);
            }

            showProcessing('Creating video from images...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Video created successfully!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    currentMediaType = 'video';
                    document.getElementById('removeMediaBtn').style.display = 'block';
                    closeModal('imageUploadModal');
                    updateUndoRedoButtons();
                    updateDoneButton();
                } else {
                    showToast('Failed to create video: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to create video', 'error');
            });
        }

        function displayMediaPreview(url, mediaType, frames = [], duration = 0) {
            const uploadArea = document.getElementById('uploadArea');
            const videoPreview = document.getElementById('videoPreview');
            const imagePreview = document.getElementById('imagePreview');
            const previewControls = document.getElementById('previewControls');

            uploadArea.style.display = 'none';
            previewControls.style.display = 'flex';

            if (mediaType === 'video') {
                videoPreview.style.display = 'block';
                imagePreview.style.display = 'none';
                videoPreview.src = url;
                currentVideo = videoPreview;
                videoDuration = duration;
                
                // Display frames
                displayFrames(frames, duration);
                
                // Add to timeline
                addClipToTimeline('video', url, duration);
            } else {
                videoPreview.style.display = 'none';
                imagePreview.style.display = 'block';
                imagePreview.src = url;
            }
        }

        function displayFrames(frames, duration) {
            const framesContainer = document.getElementById('framesContainer');
            framesContainer.innerHTML = '';
            currentFrames = frames;
            selectedFrames.clear();

            frames.forEach((frame, index) => {
                const frameTime = (index / frames.length) * duration;
                const frameElement = document.createElement('div');
                frameElement.className = 'frame';
                frameElement.dataset.index = index;
                frameElement.innerHTML = `
                    <img src="${frame}" alt="Frame ${index + 1}">
                    <div class="frame-time">${formatTime(frameTime)}</div>
                    <div class="frame-actions">
                        <button class="frame-btn" onclick="deleteSingleFrame(${index})" title="Delete Frame">
                            <i class="fas fa-times"></i>
                        </button>
                        <button class="frame-btn" onclick="duplicateFrameAtTime(${frameTime})" title="Duplicate Frame">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                `;
                frameElement.addEventListener('click', (e) => {
                    if (e.target.classList.contains('frame-btn')) return;
                    
                    const index = parseInt(frameElement.dataset.index);
                    if (selectedFrames.has(index)) {
                        selectedFrames.delete(index);
                        frameElement.classList.remove('selected');
                    } else {
                        selectedFrames.add(index);
                        frameElement.classList.add('selected');
                    }
                });
                frameElement.addEventListener('dblclick', () => seekToTime(frameTime));
                framesContainer.appendChild(frameElement);
            });
        }

        function deleteSingleFrame(frameIndex) {
            if (!confirm('Are you sure you want to delete this frame?')) {
                return;
            }

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'delete_frame');
            formData.append('frame_index', frameIndex);

            showProcessing('Deleting frame and regenerating video...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Frame deleted and video regenerated successfully!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    updateUndoRedoButtons();
                } else {
                    showToast('Failed to delete frame: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to delete frame', 'error');
            });
        }

        function deleteSelectedFrames() {
            if (selectedFrames.size === 0) {
                showToast('Please select frames to delete', 'error');
                return;
            }

            if (!confirm(`Are you sure you want to delete ${selectedFrames.size} frame(s)? This will regenerate the video.`)) {
                return;
            }

            const frameIndices = Array.from(selectedFrames);
            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'delete_multiple_frames');
            formData.append('frame_indices', JSON.stringify(frameIndices));

            showProcessing(`Deleting ${frameIndices.length} frame(s) and regenerating video...`);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast(`${frameIndices.length} frame(s) deleted and video regenerated successfully!`, 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    selectedFrames.clear();
                    closeModal('frameManagerModal');
                    updateUndoRedoButtons();
                } else {
                    showToast('Failed to delete frames: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to delete frames', 'error');
            });
        }

        function duplicateFrameAtTime(frameTime) {
            document.getElementById('duplicateFrameTime').value = frameTime;
            document.getElementById('duplicateFrameTimeValue').textContent = frameTime + 's';
            document.getElementById('duplicateFrameModal').style.display = 'flex';
        }

        function duplicateCurrentFrame() {
            if (!currentVideo) {
                showToast('Please play the video first to select a frame', 'error');
                return;
            }
            
            const currentTime = currentVideo.currentTime;
            duplicateFrameAtTime(currentTime);
        }

        function duplicateFrame() {
            const frameTime = document.getElementById('duplicateFrameTime').value;

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'duplicate_frame');
            formData.append('frame_time', frameTime);

            showProcessing('Duplicating frame...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Frame duplicated successfully!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    closeModal('duplicateFrameModal');
                    updateUndoRedoButtons();
                } else {
                    showToast('Failed to duplicate frame: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to duplicate frame', 'error');
            });
        }

        function seekToTime(time) {
            if (currentVideo) {
                currentVideo.currentTime = time;
            }
        }

        function togglePlayback() {
            if (currentVideo) {
                if (isPlaying) {
                    currentVideo.pause();
                    document.getElementById('playBtn').innerHTML = '<i class="fas fa-play"></i>';
                } else {
                    currentVideo.play();
                    document.getElementById('playBtn').innerHTML = '<i class="fas fa-pause"></i>';
                }
                isPlaying = !isPlaying;
            }
        }

        function updateTimeDisplay() {
            if (currentVideo) {
                const currentTime = currentVideo.currentTime;
                const timeDisplay = document.getElementById('timeDisplay');
                timeDisplay.textContent = `${formatTime(currentTime)} / ${formatTime(videoDuration)}`;
            }
        }

        function formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        }

        function showImageUploadModal() {
            document.getElementById('imageUploadModal').style.display = 'flex';
        }

        function showAudioModal() {
            if (!currentMediaType) {
                showToast('Please upload a video first', 'error');
                return;
            }
            document.getElementById('audioModal').style.display = 'flex';
        }

        function showTextModal() {
            if (!currentMediaType) {
                showToast('Please upload a video first', 'error');
                return;
            }
            // Create draggable text overlay
            createDraggableTextOverlay();
            document.getElementById('textModal').style.display = 'flex';
        }

        function showTrimModal() {
            if (!currentMediaType) {
                showToast('Please upload a video first', 'error');
                return;
            }
            
            // Set max values for trim sliders based on video duration
            const trimStart = document.getElementById('trimStart');
            const trimDuration = document.getElementById('trimDuration');
            
            if (trimStart && trimDuration) {
                trimStart.max = Math.floor(videoDuration);
                trimDuration.max = Math.floor(videoDuration);
                trimDuration.value = Math.floor(videoDuration);
                
                document.getElementById('trimDurationValue').textContent = Math.floor(videoDuration) + 's';
            }
            
            document.getElementById('trimModal').style.display = 'flex';
        }

        function showSplitModal() {
            if (!currentMediaType) {
                showToast('Please upload a video first', 'error');
                return;
            }
            document.getElementById('splitModal').style.display = 'flex';
        }

        function showSpeedModal() {
            if (!currentMediaType) {
                showToast('Please upload a video first', 'error');
                return;
            }
            document.getElementById('speedModal').style.display = 'flex';
        }

        function showExportModal() {
            if (!currentMediaType) {
                showToast('Please upload media first', 'error');
                return;
            }
            document.getElementById('exportModal').style.display = 'flex';
        }

        function showFiltersModal() {
            if (!currentMediaType) {
                showToast('Please upload a video first', 'error');
                return;
            }
            document.getElementById('filtersModal').style.display = 'flex';
        }

        function showFrameManager() {
            if (!currentMediaType || currentMediaType !== 'video') {
                showToast('Please upload a video first', 'error');
                return;
            }
            
            const frameManagerList = document.getElementById('frameManagerList');
            frameManagerList.innerHTML = '';
            
            currentFrames.forEach((frame, index) => {
                const frameElement = document.createElement('div');
                frameElement.className = `frame ${selectedFrames.has(index) ? 'selected' : ''}`;
                frameElement.innerHTML = `
                    <img src="${frame}" alt="Frame ${index + 1}">
                    <div class="frame-time">${formatTime((index / currentFrames.length) * videoDuration)}</div>
                `;
                frameElement.addEventListener('click', () => {
                    if (selectedFrames.has(index)) {
                        selectedFrames.delete(index);
                        frameElement.classList.remove('selected');
                    } else {
                        selectedFrames.add(index);
                        frameElement.classList.add('selected');
                    }
                });
                frameManagerList.appendChild(frameElement);
            });
            
            document.getElementById('frameManagerModal').style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            // Remove text overlay when closing text modal
            if (modalId === 'textModal' && textOverlay) {
                textOverlay.remove();
                textOverlay = null;
            }
        }

        function updateImageUploadPreview() {
            // Could add preview of selected images here
        }

        function updateAudioPreview() {
            // Could add preview of selected audio here
        }

        function trimVideo() {
            const startTime = document.getElementById('trimStart').value;
            const duration = document.getElementById('trimDuration').value;

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'trim_video');
            formData.append('start_time', startTime);
            formData.append('duration', duration);

            showProcessing('Trimming video...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Video trimmed successfully!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    closeModal('trimModal');
                    updateUndoRedoButtons();
                } else {
                    showToast('Failed to trim video: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to trim video', 'error');
            });
        }

        function splitVideo() {
            const splitTime = document.getElementById('splitTime').value;

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'split_video');
            formData.append('split_time', splitTime);

            showProcessing('Splitting video...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Video split successfully!', 'success');
                    closeModal('splitModal');
                    // Display the first split video
                    if (data.videos && data.videos.length > 0) {
                        // For simplicity, we'll just display the first split video
                        // In a real application, you'd want to handle both videos
                        displayMediaPreview(data.videos[0].url, 'video', [], data.videos[0].duration);
                    }
                } else {
                    showToast('Failed to split video: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to split video', 'error');
            });
        }

        function adjustSpeed() {
            const speed = document.getElementById('speedValue').value;

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'adjust_speed');
            formData.append('speed', speed);

            showProcessing('Adjusting video speed...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Video speed adjusted successfully!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    closeModal('speedModal');
                    updateUndoRedoButtons();
                } else {
                    showToast('Failed to adjust speed: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to adjust speed', 'error');
            });
        }

        function addAudioToVideo() {
            const audioFile = document.getElementById('audioInput').files[0];
            if (!audioFile) {
                showToast('Please select an audio file first', 'error');
                return;
            }

            const volume = document.getElementById('audioVolume').value;

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'add_audio');
            formData.append('audio', audioFile);
            formData.append('volume', volume);

            showProcessing('Adding audio to video...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Audio added successfully!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    closeModal('audioModal');
                    updateUndoRedoButtons();
                } else {
                    showToast('Failed to add audio: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to add audio', 'error');
            });
        }

        function createDraggableTextOverlay() {
            // Remove existing text overlay if any
            if (textOverlay) {
                textOverlay.remove();
            }
            
            const previewContainer = document.getElementById('previewContainer');
            textOverlay = document.createElement('div');
            textOverlay.className = 'text-overlay';
            textOverlay.textContent = document.getElementById('textContent').value || 'Sample Text';
            textOverlay.style.left = textPosition.x + 'px';
            textOverlay.style.top = textPosition.y + 'px';
            
            // Make text overlay draggable
            let isDragging = false;
            let offsetX, offsetY;
            
            textOverlay.addEventListener('mousedown', startDrag);
            
            function startDrag(e) {
                isDragging = true;
                offsetX = e.clientX - textOverlay.getBoundingClientRect().left;
                offsetY = e.clientY - textOverlay.getBoundingClientRect().top;
                textOverlay.style.cursor = 'grabbing';
                
                document.addEventListener('mousemove', drag);
                document.addEventListener('mouseup', stopDrag);
            }
            
            function drag(e) {
                if (!isDragging) return;
                
                const containerRect = previewContainer.getBoundingClientRect();
                let x = e.clientX - containerRect.left - offsetX;
                let y = e.clientY - containerRect.top - offsetY;
                
                // Keep within container bounds
                x = Math.max(0, Math.min(x, containerRect.width - textOverlay.offsetWidth));
                y = Math.max(0, Math.min(y, containerRect.height - textOverlay.offsetHeight));
                
                textOverlay.style.left = x + 'px';
                textOverlay.style.top = y + 'px';
                
                // Update text position for the actual video processing
                textPosition.x = x;
                textPosition.y = y;
            }
            
            function stopDrag() {
                isDragging = false;
                textOverlay.style.cursor = 'move';
                document.removeEventListener('mousemove', drag);
                document.removeEventListener('mouseup', stopDrag);
            }
            
            previewContainer.appendChild(textOverlay);
        }

        function resetTextPosition() {
            textPosition = { x: 10, y: 10 };
            if (textOverlay) {
                textOverlay.style.left = textPosition.x + 'px';
                textOverlay.style.top = textPosition.y + 'px';
            }
        }

        function addTextToVideo() {
            const text = document.getElementById('textContent').value;
            const fontSize = document.getElementById('textFontSize').value;
            const duration = document.getElementById('textDuration').value;

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'add_text_overlay');
            formData.append('text', text);
            formData.append('fontsize', fontSize);
            formData.append('fontcolor', selectedTextColor);
            formData.append('duration', duration);
            formData.append('x', textPosition.x);
            formData.append('y', textPosition.y);

            showProcessing('Adding text to video...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Text added successfully!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    closeModal('textModal');
                    updateUndoRedoButtons();
                } else {
                    showToast('Failed to add text: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to add text', 'error');
            });
        }

        function removeAudio() {
            if (!currentMediaType) {
                showToast('Please upload a video first', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'remove_audio');

            showProcessing('Removing audio...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Audio removed successfully!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    updateUndoRedoButtons();
                } else {
                    showToast('Failed to remove audio: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to remove audio', 'error');
            });
        }

        function exportVideo() {
            const quality = document.getElementById('exportQuality').value;

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'export_video');
            formData.append('quality', quality);

            showProcessing('Exporting video...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Video exported successfully!', 'success');
                    // Create download link
                    const downloadLink = document.createElement('a');
                    downloadLink.href = data.url;
                    downloadLink.download = data.filename;
                    downloadLink.click();
                    closeModal('exportModal');
                } else {
                    showToast('Failed to export video: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to export video', 'error');
            });
        }

        function applyFilter(filter) {
            if (!currentMediaType) {
                showToast('Please upload a video first', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'apply_filter');
            formData.append('filter', filter);

            showProcessing(`Applying ${filter} filter...`);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Filter applied successfully!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    updateUndoRedoButtons();
                } else {
                    showToast('Failed to apply filter: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to apply filter', 'error');
            });
        }

        function applyFilterFromModal(filter) {
            applyFilter(filter);
            closeModal('filtersModal');
        }

        function selectTextColor(color) {
            selectedTextColor = color;
            document.querySelectorAll('.color-swatch').forEach(swatch => {
                swatch.style.borderColor = swatch.getAttribute('data-color') === color ? '#7b68ee' : '#3a3a4d';
            });
        }

        function addClipToTimeline(type, url, duration) {
            const trackId = type + 'Track';
            const track = document.getElementById(trackId);
            
            const clip = document.createElement('div');
            clip.className = `clip ${type}-clip`;
            clip.style.width = (duration * 50) + 'px'; // 50px per second
            clip.innerHTML = `<i class="fas fa-${getClipIcon(type)}"></i> ${type.toUpperCase()}`;
            
            track.appendChild(clip);
            timelineClips.push({ type, url, duration, element: clip });
        }

        function getClipIcon(type) {
            const icons = {
                'video': 'video',
                'audio': 'music',
                'text': 'font',
                'effect': 'magic'
            };
            return icons[type] || 'file';
        }

        // Done button functionality
        function confirmSaveAndRedirect() {
            if (!currentMediaType) {
                showToast('Please upload and edit a video first', 'error');
                return;
            }

            // Create a simple confirmation modal
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.style.display = 'flex';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Finish Editing</h3>
                        <button class="close-modal" onclick="this.parentElement.parentElement.parentElement.remove()">&times;</button>
                    </div>
                    <p>Are you sure you want to save this video and proceed to sharing?</p>
                    <p><small>The watermark will be applied automatically before saving.</small></p>
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button class="btn btn-secondary" onclick="this.parentElement.parentElement.parentElement.remove()" style="flex: 1;">
                            Cancel
                        </button>
                        <button class="btn btn-success" onclick="this.parentElement.parentElement.parentElement.remove(); saveAndRedirect();" style="flex: 1;">
                            <i class="fas fa-check"></i> Yes, Save & Share
                        </button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        function saveAndRedirect() {
            if (!currentMediaType) {
                showToast('Please upload and edit a video first', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'save_and_redirect');

            showProcessing('Applying watermark and saving video...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Video saved successfully! Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = data.redirect_url;
                    }, 1500);
                } else {
                    showToast('Failed to save video: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to save video', 'error');
            });
        }

        function updateDoneButton() {
            const doneBtn = document.getElementById('doneBtn');
            if (currentMediaType) {
                doneBtn.style.display = 'block';
            } else {
                doneBtn.style.display = 'none';
            }
        }

        // Undo/Redo functionality
        function undoAction() {
            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'undo');

            showProcessing('Undoing...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Undo successful!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    updateUndoRedoButtons();
                } else {
                    showToast('Undo failed: ' + (data.error || 'No more undo steps'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Undo failed', 'error');
            });
        }

        function redoAction() {
            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'redo');

            showProcessing('Redoing...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Redo successful!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    updateUndoRedoButtons();
                } else {
                    showToast('Redo failed: ' + (data.error || 'No more redo steps'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Redo failed', 'error');
            });
        }

        function updateUndoRedoButtons() {
            // These would ideally be updated based on the actual history state
            // For now, we'll enable them if we have a current media
            const undoBtn = document.getElementById('undoBtn');
            const redoBtn = document.getElementById('redoBtn');
            
            if (currentMediaType) {
                undoBtn.disabled = false;
                redoBtn.disabled = false;
            } else {
                undoBtn.disabled = true;
                redoBtn.disabled = true;
            }
        }

        // Drag and drop functionality
        function setupDragAndDrop() {
            const dropArea = document.getElementById('uploadArea');
            
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, preventDefaults, false);
            });
            
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            ['dragenter', 'dragover'].forEach(eventName => {
                dropArea.addEventListener(eventName, highlight, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, unhighlight, false);
            });
            
            function highlight() {
                dropArea.style.background = 'rgba(123, 104, 238, 0.3)';
            }
            
            function unhighlight() {
                dropArea.style.background = 'rgba(123, 104, 238, 0.1)';
            }
            
            dropArea.addEventListener('drop', handleDrop, false);
            
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                
                if (files.length > 0) {
                    const file = files[0];
                    if (file.type.startsWith('video/') || file.type.startsWith('image/')) {
                        uploadMedia(file);
                    } else {
                        showToast('Please upload a video or image file', 'error');
                    }
                }
            }
        }

        // Processing overlay functions
        function showProcessing(message = 'Processing your video...') {
            const overlay = document.getElementById('processingOverlay');
            const text = document.getElementById('processingText');
            text.textContent = message;
            overlay.style.display = 'flex';
        }

        function hideProcessing() {
            const overlay = document.getElementById('processingOverlay');
            overlay.style.display = 'none';
        }

        // Toast notification function
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

        // Placeholder functions for additional features
        function reverseVideo() {
            if (!currentMediaType) {
                showToast('Please upload a video first', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'reverse_video');

            showProcessing('Reversing video...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Video reversed successfully!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    updateUndoRedoButtons();
                } else {
                    showToast('Failed to reverse video: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to reverse video', 'error');
            });
        }

        function extractAudio() {
            if (!currentMediaType) {
                showToast('Please upload a video first', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'extract_audio');

            showProcessing('Extracting audio...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Audio extracted successfully!', 'success');
                    // Create download link for audio
                    const downloadLink = document.createElement('a');
                    downloadLink.href = data.url;
                    downloadLink.download = data.filename;
                    downloadLink.click();
                } else {
                    showToast('Failed to extract audio: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to extract audio', 'error');
            });
        }

        function stabilizeVideo() {
            if (!currentMediaType) {
                showToast('Please upload a video first', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('ajax', 'true');
            formData.append('action', 'stabilize_video');

            showProcessing('Stabilizing video...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideProcessing();
                if (data.success) {
                    showToast('Video stabilized successfully!', 'success');
                    displayMediaPreview(data.url, 'video', data.frames, data.duration);
                    updateUndoRedoButtons();
                } else {
                    showToast('Failed to stabilize video: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                hideProcessing();
                console.error('Error:', error);
                showToast('Failed to stabilize video', 'error');
            });
        }

        function addTextTrack() {
            showTextModal();
        }

        function removeCurrentMedia() {
            if (!confirm('Are you sure you want to remove the current media?')) {
                return;
            }
            resetPreview();
            document.getElementById('removeMediaBtn').style.display = 'none';
            currentMediaType = null;
            updateUndoRedoButtons();
            updateDoneButton();
            showToast('Media removed successfully!', 'success');
        }

        function resetPreview() {
            const uploadArea = document.getElementById('uploadArea');
            const videoPreview = document.getElementById('videoPreview');
            const imagePreview = document.getElementById('imagePreview');
            const previewControls = document.getElementById('previewControls');
            const framesContainer = document.getElementById('framesContainer');
            const timelineTracks = document.getElementById('timelineTracks');

            uploadArea.style.display = 'block';
            videoPreview.style.display = 'none';
            imagePreview.style.display = 'none';
            previewControls.style.display = 'none';
            framesContainer.innerHTML = '';
            
            // Clear timeline
            document.querySelectorAll('.clip').forEach(clip => clip.remove());
            timelineClips = [];
            
            currentVideo = null;
            currentFrames = [];
            videoDuration = 0;
            isPlaying = false;
            selectedFrames.clear();
            
            // Remove text overlay
            if (textOverlay) {
                textOverlay.remove();
                textOverlay = null;
            }
        }
        // Function to open file picker
function openFilePicker() {
    // Create a hidden file input
    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'video/*,image/*';
    fileInput.style.display = 'none';
    
    // Add it to the document
    document.body.appendChild(fileInput);
    
    // Trigger click event
    fileInput.click();
    
    // Handle file selection
    fileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            
            // Validate file
            if (file.size > 500 * 1024 * 1024) { // 500MB limit
                showToast('File size must be less than 500MB', 'error');
                return;
            }
            
            if (!file.type.match('video/*') && !file.type.match('image/*')) {
                showToast('Please select a video or image file', 'error');
                return;
            }
            
            // Upload the file
            uploadMedia(file);
        }
        
        // Remove the input element
        document.body.removeChild(fileInput);
    });
    
    // Clean up if user cancels
    fileInput.addEventListener('cancel', function() {
        setTimeout(() => {
            if (document.body.contains(fileInput)) {
                document.body.removeChild(fileInput);
            }
        }, 1000);
    });
}
    </script>
</body>
</html>