<?php
/**
 * Image Cleanup Script
 * 
 * Deletes images older than 180 days as a safety net.
 * Messages/images are normally cleaned up when removed via reaction
 * or when they exceed the 50-message cap.
 * 
 * Add to cron (runs daily):
 * 0 3 * * * php /var/www/cliffhanger/slack-to-website/cleanup.php
 */

require_once __DIR__ . '/config.php';

$maxAge = 180 * 24 * 60 * 60; // 180 days in seconds
$now = time();
$deleted = 0;

if (is_dir(IMAGES_DIR)) {
    $files = scandir(IMAGES_DIR);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filepath = IMAGES_DIR . '/' . $file;
        if (is_file($filepath) && ($now - filemtime($filepath)) > $maxAge) {
            unlink($filepath);
            $deleted++;
        }
    }
}

// Also clean up references to deleted images in messages.json
$messagesFile = __DIR__ . '/data/messages.json';
if (file_exists($messagesFile)) {
    $messages = json_decode(file_get_contents($messagesFile), true) ?: [];
    $changed = false;
    
    foreach ($messages as &$msg) {
        if (!empty($msg['images'])) {
            $msg['images'] = array_filter($msg['images'], function($img) {
                return file_exists(IMAGES_DIR . '/' . $img);
            });
            $msg['images'] = array_values($msg['images']);
            $changed = true;
        }
    }
    unset($msg);
    
    if ($changed) {
        file_put_contents($messagesFile, json_encode($messages, JSON_PRETTY_PRINT));
    }
}

echo date('Y-m-d H:i:s') . " Cleanup complete. Deleted {$deleted} old image(s).\n";
