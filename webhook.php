<?php
/**
 * Slack Event Subscription Webhook
 * 
 * Receives messages from the #website-network-status channel
 * and stores them for the status page.
 * 
 * Setup:
 * 1. In your Slack App settings > Event Subscriptions, enable events
 * 2. Set Request URL to: https://your-domain/slack-to-website/webhook.php
 * 3. Subscribe to bot events: message.channels, message.groups, reaction_added
 * 4. Install/reinstall the app to your workspace
 * 5. Invite the bot to #website-network-status
 */

require_once __DIR__ . '/config.php';

// Read raw POST body
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

// Log incoming requests for debugging
file_put_contents(__DIR__ . '/webhook.log', date('Y-m-d H:i:s') . " " . $payload . "\n", FILE_APPEND);

// Handle Slack URL verification challenge
if (isset($data['type']) && $data['type'] === 'url_verification') {
    header('Content-Type: application/json');
    echo json_encode(['challenge' => $data['challenge']]);
    exit;
}

// Verify the request is from Slack (signing secret)
$slackSignature = $_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? '';
$slackTimestamp = $_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '';

if (abs(time() - intval($slackTimestamp)) > 300) {
    http_response_code(403);
    exit('Request too old');
}

$sigBasestring = "v0:{$slackTimestamp}:{$payload}";
$mySignature = 'v0=' . hash_hmac('sha256', $sigBasestring, SLACK_SIGNING_SECRET);

if (!hash_equals($mySignature, $slackSignature)) {
    http_response_code(403);
    exit('Invalid signature');
}

/**
 * Download a file from Slack and store it locally.
 * Returns the local filename or null on failure.
 */
function downloadSlackFile(string $url, string $messageTs, string $originalName): ?string {
    if (!is_dir(IMAGES_DIR)) {
        mkdir(IMAGES_DIR, 0755, true);
    }
    
    // Create a safe filename: timestamp_originalname
    $ext = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'png';
    $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $filename = str_replace('.', '_', $messageTs) . '_' . $safeName . '.' . $ext;
    $filepath = IMAGES_DIR . '/' . $filename;
    
    // Download with bot token auth
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SLACK_BOT_TOKEN],
        CURLOPT_TIMEOUT => 30,
    ]);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $content) {
        file_put_contents($filepath, $content);
        return $filename;
    }
    
    return null;
}

/**
 * Download an external image URL and store it locally.
 * Returns the local filename or null on failure.
 */
function downloadExternalImage(string $url, string $messageTs): ?string {
    if (!is_dir(IMAGES_DIR)) {
        mkdir(IMAGES_DIR, 0755, true);
    }
    
    // Extract filename from URL
    $urlPath = parse_url($url, PHP_URL_PATH);
    $originalName = basename($urlPath) ?: 'image.png';
    $ext = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'png';
    $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $filename = str_replace('.', '_', $messageTs) . '_' . $safeName . '.' . $ext;
    $filepath = IMAGES_DIR . '/' . $filename;
    
    // Download without auth (public URL)
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; StatusBot/1.0)',
    ]);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    
    // Verify it's actually an image
    if ($httpCode === 200 && $content && strpos($contentType, 'image/') === 0) {
        file_put_contents($filepath, $content);
        return $filename;
    }
    
    return null;
}

/**
 * Extract image URLs from message text.
 * Matches URLs ending in common image extensions.
 */
function extractImageUrls(string $text): array {
    $pattern = '/https?:\/\/[^\s<>|]+\.(?:jpg|jpeg|png|gif|webp|svg)(?:\?[^\s<>|]*)?/i';
    preg_match_all($pattern, $text, $matches);
    return $matches[0] ?? [];
}

/**
 * Delete all images associated with a message timestamp.
 */
function deleteMessageImages(string $messageTs): void {
    if (!is_dir(IMAGES_DIR)) return;
    
    $prefix = str_replace('.', '_', $messageTs) . '_';
    $files = scandir(IMAGES_DIR);
    foreach ($files as $file) {
        if (strpos($file, $prefix) === 0) {
            unlink(IMAGES_DIR . '/' . $file);
        }
    }
}

// Process the event
if (isset($data['event'])) {
    $event = $data['event'];
    
    $messagesFile = __DIR__ . '/data/messages.json';
    
    // Handle reaction_added — remove message if :x: or :wastebasket: is used
    if ($event['type'] === 'reaction_added' && 
        in_array($event['reaction'], ['x', 'wastebasket']) &&
        ($event['item']['channel'] ?? '') === SLACK_CHANNEL_ID) {
        
        $targetTs = $event['item']['ts'];
        
        if (file_exists($messagesFile)) {
            $messages = json_decode(file_get_contents($messagesFile), true) ?: [];
            $messages = array_filter($messages, function($msg) use ($targetTs) {
                return $msg['id'] !== $targetTs;
            });
            $messages = array_values($messages); // re-index
            file_put_contents($messagesFile, json_encode($messages, JSON_PRETTY_PRINT));
            
            // Clean up associated images
            deleteMessageImages($targetTs);
        }
    }
    
    // Handle new messages from the target channel
    // Allow normal messages and file_share (image uploads), ignore other subtypes
    $allowedSubtypes = [null, 'file_share'];
    if ($event['type'] === 'message' && 
        ($event['channel'] ?? '') === SLACK_CHANNEL_ID &&
        in_array($event['subtype'] ?? null, $allowedSubtypes)) {
        
        // Download any attached images (Slack uploads)
        $images = [];
        if (!empty($event['files'])) {
            foreach ($event['files'] as $file) {
                // Only process image files
                if (strpos($file['mimetype'] ?? '', 'image/') === 0) {
                    $url = $file['url_private_download'] ?? $file['url_private'] ?? '';
                    if ($url) {
                        $localFile = downloadSlackFile($url, $event['ts'], $file['name'] ?? 'image.png');
                        if ($localFile) {
                            $images[] = $localFile;
                        }
                    }
                }
            }
        }
        
        // Download any image URLs referenced in the message text
        $textContent = $event['text'] ?? '';
        $imageUrls = extractImageUrls($textContent);
        foreach ($imageUrls as $imgUrl) {
            $localFile = downloadExternalImage($imgUrl, $event['ts']);
            if ($localFile) {
                $images[] = $localFile;
            }
        }
        
        $message = [
            'id' => $event['ts'],
            'text' => $event['text'] ?? '',
            'user' => $event['user'] ?? 'unknown',
            'timestamp' => floatval($event['ts']),
            'datetime' => date('Y-m-d H:i:s', intval($event['ts'])),
            'images' => $images,
        ];
        
        // Load existing messages
        $messages = [];
        if (file_exists($messagesFile)) {
            $messages = json_decode(file_get_contents($messagesFile), true) ?: [];
        }
        
        // Add new message at the top
        array_unshift($messages, $message);
        
        // If over 50, remove overflow and clean up their images
        if (count($messages) > 50) {
            $removed = array_slice($messages, 50);
            foreach ($removed as $old) {
                deleteMessageImages($old['id']);
            }
            $messages = array_slice($messages, 0, 50);
        }
        
        // Save
        if (!is_dir(__DIR__ . '/data')) {
            mkdir(__DIR__ . '/data', 0755, true);
        }
        file_put_contents($messagesFile, json_encode($messages, JSON_PRETTY_PRINT));
    }
}

// Respond 200 OK quickly (Slack requires response within 3 seconds)
http_response_code(200);
echo 'ok';
