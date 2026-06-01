<?php
/**
 * Network Status Page
 * Displays messages posted to #website-network-status in Slack
 */
require_once __DIR__ . '/config.php';

$messagesFile = __DIR__ . '/data/messages.json';
$messages = [];
if (file_exists($messagesFile)) {
    $messages = json_decode(file_get_contents($messagesFile), true) ?: [];
}

// Determine current status from most recent message
$currentStatus = 'operational'; // default
$statusText = 'All systems operational';
$statusClass = 'status-ok';

if (!empty($messages)) {
    $latest = strtolower($messages[0]['text']);
    if (preg_match('/\b(outage|down|critical|emergency)\b/', $latest)) {
        $currentStatus = 'outage';
        $statusText = 'Service disruption detected';
        $statusClass = 'status-outage';
    } elseif (preg_match('/\b(degraded|slow|issue|investigating|maintenance)\b/', $latest)) {
        $currentStatus = 'degraded';
        $statusText = 'Degraded performance';
        $statusClass = 'status-degraded';
    } elseif (preg_match('/\b(resolved|restored|fixed|operational|clear)\b/', $latest)) {
        $currentStatus = 'operational';
        $statusText = 'All systems operational';
        $statusClass = 'status-ok';
    } else {
        $currentStatus = 'info';
        $statusText = 'Status update available';
        $statusClass = 'status-info';
    }
}

// Slack emoji shortcodes to Unicode mapping (common ones)
function convertEmojis(string $text): string {
    $emojiMap = [
        ':white_check_mark:' => '✅', ':heavy_check_mark:' => '✔️',
        ':x:' => '❌', ':warning:' => '⚠️', ':rotating_light:' => '🚨',
        ':fire:' => '🔥', ':boom:' => '💥', ':zap:' => '⚡',
        ':red_circle:' => '🔴', ':large_orange_circle:' => '🟠',
        ':large_yellow_circle:' => '🟡', ':large_green_circle:' => '🟢',
        ':green_circle:' => '🟢', ':orange_circle:' => '🟠',
        ':yellow_circle:' => '🟡',
        ':thumbsup:' => '👍', ':thumbsdown:' => '👎', ':+1:' => '👍', ':-1:' => '👎',
        ':rocket:' => '🚀', ':wrench:' => '🔧', ':hammer_and_wrench:' => '🛠️',
        ':gear:' => '⚙️', ':link:' => '🔗', ':lock:' => '🔒', ':unlock:' => '🔓',
        ':bell:' => '🔔', ':no_bell:' => '🔕',
        ':clock1:' => '🕐', ':clock2:' => '🕑', ':clock3:' => '🕒',
        ':hourglass:' => '⌛', ':hourglass_flowing_sand:' => '⏳',
        ':arrow_up:' => '⬆️', ':arrow_down:' => '⬇️',
        ':chart_with_upwards_trend:' => '📈', ':chart_with_downwards_trend:' => '📉',
        ':globe_with_meridians:' => '🌐', ':earth_americas:' => '🌎',
        ':cloud:' => '☁️', ':sunny:' => '☀️', ':umbrella:' => '☂️',
        ':construction:' => '🚧', ':no_entry:' => '⛔', ':stop_sign:' => '🛑',
        ':information_source:' => 'ℹ️', ':question:' => '❓', ':exclamation:' => '❗',
        ':mega:' => '📣', ':loudspeaker:' => '📢',
        ':eyes:' => '👀', ':point_right:' => '👉', ':point_left:' => '👈',
        ':tada:' => '🎉', ':party_popper:' => '🎉',
        ':memo:' => '📝', ':clipboard:' => '📋', ':page_facing_up:' => '📄',
        ':computer:' => '💻', ':desktop_computer:' => '🖥️', ':electric_plug:' => '🔌',
        ':satellite:' => '📡', ':signal_strength:' => '📶',
        ':heavy_minus_sign:' => '➖', ':heavy_plus_sign:' => '➕',
        ':wastebasket:' => '🗑️', ':trash:' => '🗑️',
    ];
    
    // Replace known emoji shortcodes
    $text = str_replace(array_keys($emojiMap), array_values($emojiMap), $text);
    
    // For any remaining :emoji_name: patterns, just strip the colons (graceful fallback)
    // This avoids showing raw shortcodes for unmapped emojis
    $text = preg_replace('/:([a-z0-9_+-]+):/', '[$1]', $text);
    
    return $text;
}

// Slack markdown to HTML (with emoji and link support)
function slackToHtml(string $text, array $images = []): string {
    // First, handle Slack-formatted image URLs: <https://...image.jpg>
    // Replace them with placeholders before any escaping
    $imageReplacements = [];
    
    // Match Slack-wrapped image URLs: <url> format
    $slackImagePattern = '/<(https?:\/\/[^>|]+\.(?:jpg|jpeg|png|gif|webp|svg)(?:\?[^>|]*)?)>/i';
    $text = preg_replace_callback($slackImagePattern, function($match) use ($images, &$imageReplacements) {
        $url = $match[1];
        $placeholder = '%%IMG_' . count($imageReplacements) . '%%';
        
        $localImg = findLocalImage($url, $images);
        
        if ($localImg) {
            $imgSrc = htmlspecialchars(IMAGES_URL . '/' . $localImg, ENT_QUOTES, 'UTF-8');
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $imageReplacements[$placeholder] = '<a href="' . $safeUrl . '" target="_blank" rel="noopener"><img src="' . $imgSrc . '" alt="Referenced image" loading="lazy" class="inline-image"></a>';
        } else {
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $imageReplacements[$placeholder] = '<a href="' . $safeUrl . '" target="_blank" rel="noopener">' . $safeUrl . '</a>';
        }
        
        return $placeholder;
    }, $text);
    
    // Also match bare image URLs (not wrapped in <>)
    $bareImagePattern = '/(?<!<)(https?:\/\/[^\s<>|]+\.(?:jpg|jpeg|png|gif|webp|svg)(?:\?[^\s<>|]*)?)(?!>)/i';
    $text = preg_replace_callback($bareImagePattern, function($match) use ($images, &$imageReplacements) {
        $url = $match[1];
        $placeholder = '%%IMG_' . count($imageReplacements) . '%%';
        
        $localImg = findLocalImage($url, $images);
        
        if ($localImg) {
            $imgSrc = htmlspecialchars(IMAGES_URL . '/' . $localImg, ENT_QUOTES, 'UTF-8');
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $imageReplacements[$placeholder] = '<a href="' . $safeUrl . '" target="_blank" rel="noopener"><img src="' . $imgSrc . '" alt="Referenced image" loading="lazy" class="inline-image"></a>';
        } else {
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $imageReplacements[$placeholder] = '<a href="' . $safeUrl . '" target="_blank" rel="noopener">' . $safeUrl . '</a>';
        }
        
        return $placeholder;
    }, $text);
    
    // Now escape the remaining text
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $text = convertEmojis($text);
    
    // Slack links: <url|label> or <url>
    $text = preg_replace('/&lt;(https?:\/\/[^|&]+)\|([^&]+)&gt;/', '<a href="$1" target="_blank" rel="noopener">$2</a>', $text);
    $text = preg_replace('/&lt;(https?:\/\/[^&]+)&gt;/', '<a href="$1" target="_blank" rel="noopener">$1</a>', $text);
    
    // Plain URLs not already handled
    $text = preg_replace('/(?<!href="|">)(https?:\/\/[^\s<]+)(?![^<]*<\/a>)/', '<a href="$1" target="_blank" rel="noopener">$1</a>', $text);
    
    $text = preg_replace('/\*([^*]+)\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/_([^_]+)_/', '<em>$1</em>', $text);
    $text = preg_replace('/~([^~]+)~/', '<del>$1</del>', $text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    $text = nl2br($text);
    
    // Put the image HTML back in
    $text = str_replace(array_keys($imageReplacements), array_values($imageReplacements), $text);
    
    return $text;
}

/**
 * Find the local image file matching a URL.
 */
function findLocalImage(string $url, array $images): ?string {
    $urlPath = parse_url($url, PHP_URL_PATH);
    $urlFilename = basename($urlPath ?: '');
    $safeUrlName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', pathinfo($urlFilename, PATHINFO_FILENAME));
    
    foreach ($images as $img) {
        if ($safeUrlName && strpos($img, $safeUrlName) !== false) {
            return $img;
        }
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_TITLE ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }
        .container { max-width: 800px; margin: 0 auto; padding: 2rem 1rem; }
        h1 { font-size: 1.8rem; margin-bottom: 1.5rem; }
        
        .status-banner {
            padding: 1.2rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .status-ok { background: #d4edda; color: #155724; border-left: 5px solid #28a745; }
        .status-degraded { background: #fff3cd; color: #856404; border-left: 5px solid #ffc107; }
        .status-outage { background: #f8d7da; color: #721c24; border-left: 5px solid #dc3545; }
        .status-info { background: #d1ecf1; color: #0c5460; border-left: 5px solid #17a2b8; }
        
        .updates { list-style: none; }
        .update-item {
            background: #fff;
            border: 1px solid #e1e4e8;
            border-radius: 6px;
            padding: 1rem 1.2rem;
            margin-bottom: 0.75rem;
        }
        .update-time {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 0.3rem;
        }
        .update-text { font-size: 0.95rem; }
        .update-text code {
            background: #f1f3f5;
            padding: 0.1rem 0.3rem;
            border-radius: 3px;
            font-size: 0.85em;
        }
        .update-images {
            margin-top: 0.75rem;
        }
        .update-images img {
            max-width: 100%;
            max-height: 400px;
            border-radius: 4px;
            border: 1px solid #e1e4e8;
            margin-top: 0.5rem;
            display: block;
        }
        .update-text img.inline-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: 4px;
            border: 1px solid #e1e4e8;
            margin: 0.5rem 0;
            display: block;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }
        
        .refresh-note {
            text-align: center;
            margin-top: 2rem;
            font-size: 0.8rem;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><?= SITE_TITLE ?></h1>
        
        <div class="status-banner <?= $statusClass ?>">
            <?= $statusText ?>
        </div>
        
        <?php if (empty($messages)): ?>
            <div class="empty-state">
                <p>No status updates yet.</p>
            </div>
        <?php else: ?>
            <ul class="updates">
                <?php foreach ($messages as $msg): ?>
                    <li class="update-item">
                        <div class="update-time"><?= htmlspecialchars($msg['datetime']) ?></div>
                        <div class="update-text"><?= slackToHtml($msg['text'], $msg['images'] ?? []) ?></div>
                        <?php
                        // Show Slack-uploaded images that aren't referenced in text as URL images
                        $textImages = [];
                        if (preg_match_all('/https?:\/\/[^\s<>]+\.(?:jpg|jpeg|png|gif|webp|svg)(\?[^\s<>]*)?/i', $msg['text'] ?? '', $m)) {
                            foreach ($m[0] as $url) {
                                $urlFilename = basename(parse_url($url, PHP_URL_PATH));
                                $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', pathinfo($urlFilename, PATHINFO_FILENAME));
                                foreach ($msg['images'] ?? [] as $img) {
                                    if (strpos($img, $safeName) !== false) {
                                        $textImages[] = $img;
                                    }
                                }
                            }
                        }
                        $attachedImages = array_diff($msg['images'] ?? [], $textImages);
                        ?>
                        <?php if (!empty($attachedImages)): ?>
                            <div class="update-images">
                                <?php foreach ($attachedImages as $img): ?>
                                    <img src="<?= htmlspecialchars(IMAGES_URL . '/' . $img) ?>" alt="Status update image" loading="lazy">
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        
        <p class="refresh-note">This page updates when new messages are posted. Refresh to check for updates.</p>
    </div>
</body>
</html>
