# Slack channel t -Website Network Status Page

Simple PHP status page that displays messages including images from a Slack channel. Post in Slack, it appears on your website.  React with ❌ or 🗑️ to remove it.

## How it works

1. Someone posts to your Slack channel (public or private)
2. Slack sends an event to `webhook.php`
3. The message (and any images) are saved locally
4. `index.php` displays the messages as a public status page

![Example output](/example_output.jpg?raw=true "Screenshot")

## Features

- **Auto status detection** — banner colour changes based on keywords in the latest message
- **Emoji support** — Slack `:emoji:` shortcodes converted to Unicode
- **Image uploads** — images posted in Slack are downloaded and displayed
- **Linked images** — image URLs in message text are downloaded and rendered inline
- **Hyperlinks** — Slack-formatted and plain URLs become clickable links
- **React to remove** — add ❌ or 🗑️ reaction to remove a message from the website
- **Auto cleanup** — images deleted when messages are removed or fall off the 50-message cap
- **180-day cron** — safety net to remove orphaned images

## Status detection

The banner automatically changes based on keywords in the latest message:

| Status | Colour | Keywords |
|--------|--------|----------|
| Outage | Red | outage, down, critical, emergency |
| Degraded | Yellow | degraded, slow, issue, investigating, maintenance |
| Operational | Green | resolved, restored, fixed, operational, clear |
| Info | Blue | anything else |

## Setup

### 1. Create a Slack App

At [api.slack.com/apps](https://api.slack.com/apps):

1. Create New App → From scratch
2. **Basic Information** → note the Signing Secret
3. **OAuth & Permissions** → add Bot Token Scopes:
   - `channels:history` (public channels)
   - `channels:read`
   - `groups:history` (private channels)
   - `groups:read`
   - `reactions:read`
   - `files:read`
4. **Event Subscriptions** → Enable Events
   - Request URL: `https://your-domain/slack-to-website/webhook.php`
   - Subscribe to bot events: `message.channels`, `message.groups`, `reaction_added`
5. **Install App** → Install to Workspace
6. Copy the Bot User OAuth Token (`xoxb-...`)
7. Invite the bot to your channel: `@your-bot-name`

### 2. Configure this app

```bash
cp config.example.php config.php
```

Edit `config.php`:

| Setting | Where to find it |
|---------|-----------------|
| `SLACK_SIGNING_SECRET` | App Settings → Basic Information → App Credentials |
| `SLACK_BOT_TOKEN` | App Settings → OAuth & Permissions → Bot User OAuth Token |
| `SLACK_CHANNEL_ID` | Right-click channel → View channel details → ID at bottom |

### 3. Server setup

```bash
# Create data directories with web server write access
mkdir -p data/images
chown www-data:www-data data data/images
chmod 755 data data/images

# Create log file
touch webhook.log
chown www-data:www-data webhook.log
```

### 4. Cron job (optional safety net)

Deletes orphaned images older than 180 days:

```bash
sudo crontab -u www-data -e
```

Add:

```
0 3 * * * php /path/to/slack-to-website/cleanup.php
```

## Removing messages

Add an ❌ (`:x:`) or 🗑️ (`:wastebasket:`) reaction to any message in the Slack channel. It will be removed from the website and any associated images will be deleted.

## Files

| File | Purpose |
|------|---------|
| `index.php` | Public status page |
| `webhook.php` | Receives Slack events, downloads images |
| `cleanup.php` | Cron script for 180-day image cleanup |
| `config.php` | Your credentials (not in git) |
| `config.example.php` | Template for config |
| `data/messages.json` | Stored messages (not in git) |
| `data/images/` | Downloaded images (not in git) |
| `webhook.log` | Debug log (not in git) |

## Requirements

- PHP 7.4+ with curl extension
- Web server (Apache/Nginx) with PHP
- Write access to `data/` directory

## License

MIT
