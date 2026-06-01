<?php
/**
 * Configuration for Slack-to-Website status page
 * 
 * Copy this to config.php and fill in your values.
 * 
 * Find these in your Slack App settings:
 * - Signing Secret: App Settings > Basic Information > App Credentials
 * - Channel ID: Right-click channel name in Slack > "View channel details" > copy the ID at bottom
 */

// Slack App Signing Secret (used to verify webhook requests are from Slack)
define('SLACK_SIGNING_SECRET', 'your-signing-secret-here');

// Bot User OAuth Token (OAuth & Permissions > Bot User OAuth Token, starts with xoxb-)
define('SLACK_BOT_TOKEN', 'xoxb-your-bot-token-here');

// Channel ID for #website-network-status (not the name, the C-prefixed ID)
// Right-click the channel > View channel details > scroll to bottom for the ID
define('SLACK_CHANNEL_ID', 'C0000000000');

// Status page settings
define('SITE_TITLE', 'Network Status');
define('TIMEZONE', 'Pacific/Auckland');

// Image storage
define('IMAGES_DIR', __DIR__ . '/data/images');
define('IMAGES_URL', 'data/images'); // relative URL for display

date_default_timezone_set(TIMEZONE);
