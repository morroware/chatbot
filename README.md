# Atlas - AI Chatbot Platform

A self-hosted, feature-rich AI chatbot platform built with PHP, vanilla JavaScript (ES6 modules), and SQLite. Atlas provides a modern, responsive chat interface with persistent conversations, long-term memory, emotion-aware theming, streaming responses, and a full admin dashboard.

---

## Table of Contents

- [Features](#features)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Admin Panel](#admin-panel)
- [API Reference](#api-reference)
- [Customization](#customization)
- [Security](#security)
- [Project Structure](#project-structure)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Features

### Core
- **Multi-model support** - Switch between Claude Sonnet, Haiku, and Opus models mid-conversation
- **Streaming responses** - Real-time Server-Sent Events (SSE) streaming with token-by-token display
- **Persistent conversations** - All chats saved to SQLite with full message history
- **Context window management** - Automatic summarization of older messages to stay within context limits
- **Token tracking** - Per-message and per-session token usage monitoring

### Intelligence
- **Long-term memory** - Automatic extraction and retention of user facts across conversations
- **Emotion system** - 11 emotions with visual indicators, avatar effects, and header animations
- **Dynamic theming** - 10 color themes that can auto-switch based on the bot's emotional state
- **Suggested replies** - Context-aware reply suggestions after each response

### Interface
- **Responsive design** - Full mobile, tablet, and desktop support
- **Dark/light mode** - Persistent toggle with smooth transitions
- **Keyboard shortcuts** - 11 shortcuts for power users (Ctrl+N, Ctrl+B, Ctrl+K, etc.)
- **Image support** - Upload, compress (WebP/JPEG auto-selection), and send images to Claude
- **Voice input** - Web Speech API integration for voice-to-text
- **Text-to-speech** - Read bot responses aloud
- **Drag and drop** - Drop images directly into the chat
- **Markdown rendering** - Full GFM support with syntax-highlighted code blocks
- **PWA support** - Installable as a Progressive Web App with offline caching

### Management
- **Conversation sidebar** - Searchable, grouped by date (Today/Yesterday/This Week/Older)
- **Pin, rename, delete** conversations
- **Bookmark messages** for later reference
- **Export conversations** as JSON, Markdown, or plain text
- **Admin dashboard** - Full configuration management with live preview

---

## Architecture

```
Browser (Vanilla JS ES6 Modules)
    |
    |-- js/app.js          Entry point, initialization
    |-- js/api.js          All server communication
    |-- js/chat.js         Message display, streaming, actions
    |-- js/sidebar.js      Conversation list, search
    |-- js/memory.js       Long-term memory panel
    |-- js/media.js        Image upload, voice input
    |-- js/ui.js           Themes, emotions, dark mode
    |-- js/config.js       Configuration loading
    |-- js/state.js        Global state store
    |-- js/shortcuts.js    Keyboard shortcuts
    |
    v
PHP Backend (Apache + mod_rewrite)
    |
    |-- api-stream.php     SSE streaming chat endpoint
    |-- api.php            Non-streaming chat endpoint
    |-- api-conversations.php   Conversation CRUD
    |-- api-memory.php     Memory extraction & management
    |-- get-config.php     Public config (secrets stripped)
    |-- db.php             SQLite database layer
    |
    v
SQLite Database (data/chatbot.db)
    |-- conversations      Chat sessions
    |-- messages           Individual messages with metadata
    |-- memory             Long-term user facts
    |-- bookmarks          Saved messages
    |-- settings           Key-value app settings
```

### Technology Stack

| Layer | Technology |
|-------|-----------|
| Frontend | Vanilla JavaScript (ES6 modules), HTML5, CSS3 |
| CSS Framework | Bootstrap 5.3 (layout utilities only) |
| Markdown | marked.js 11.x |
| Syntax Highlighting | highlight.js 11.x |
| Backend | PHP 7.4+ / 8.x |
| Database | SQLite3 with WAL mode |
| AI Provider | Anthropic Claude API |
| Web Server | Apache with mod_rewrite |

---

## Requirements

- **PHP 7.4+** (8.x recommended) with extensions:
  - `sqlite3`
  - `curl`
  - `json`
  - `session`
  - `mbstring`
- **Apache 2.4+** with `mod_rewrite` and `mod_headers` enabled
- **Anthropic API key** ([console.anthropic.com](https://console.anthropic.com))
- **20-50 MB disk space** (database grows with usage)

---

## Installation

### 1. Clone the Repository

```bash
git clone https://github.com/morroware/chatbot.git
cd chatbot
```

### 2. Set File Permissions

```bash
# Ensure the data directory is writable by the web server
mkdir -p data
chmod 755 data
chown www-data:www-data data

# Protect configuration files
chmod 644 config.ini emotions.ini themes.ini
```

### 3. Configure Apache

Ensure `mod_rewrite` and `mod_headers` are enabled:

```bash
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

Your Apache virtual host should allow `.htaccess` overrides:

```apache
<Directory /var/www/chatbot>
    AllowOverride All
    Require all granted
</Directory>
```

### 4. Set Your API Key

Edit `config.ini` and replace the placeholder API key:

```ini
[api]
api_key = "sk-ant-your-actual-api-key-here"
```

Alternatively, set the API key through the admin panel after first login.

### 5. Access the Application

Navigate to your server's URL. The database is created automatically on first request.

- **Chat interface:** `https://your-domain.com/`
- **Admin panel:** `https://your-domain.com/admin-login.html`
- **Default admin credentials:** `admin` / `password`

**Important:** Change the default admin password immediately after first login.

---

## Configuration

### config.ini

The primary configuration file with the following sections:

#### [general]

| Key | Default | Description |
|-----|---------|-------------|
| `bot_name` | Atlas | Display name of the bot |
| `bot_title` | AI Assistant | Page title and header text |
| `bot_description` | (see file) | Short description used in system prompt |
| `welcome_title` | Welcome! | Welcome screen heading |
| `welcome_message` | (see file) | Welcome screen body text |
| `footer_text` | Powered by AI | Footer credit line |
| `avatar_image` | avatar.svg | Bot avatar filename |
| `default_emotion` | neutral | Starting emotion state |
| `default_theme` | default | Starting color theme |
| `max_tokens` | 4096 | Maximum response tokens |
| `model` | claude-sonnet-4-6 | Default model ID |
| `temperature` | 0.7 | Response randomness (0.0-1.0) |
| `link_emotions_to_themes` | true | Auto-switch themes with emotions |
| `enable_memory` | true | Enable long-term memory extraction |
| `enable_tts` | true | Show text-to-speech buttons |
| `enable_suggested_replies` | true | Show suggested reply buttons |
| `memory_extract_interval` | 6 | Messages between memory extractions |
| `max_context_messages` | 20 | Max messages before summarization |
| `recent_messages_to_keep` | 10 | Recent messages preserved in full |

#### [models]

Define available models. Format: `key = "Display Name|provider|endpoint|api_key_field"`

```ini
claude_sonnet = "Claude Sonnet 4.6|anthropic|https://api.anthropic.com/v1/messages|api_key"
claude_haiku = "Claude Haiku 4.5|anthropic|https://api.anthropic.com/v1/messages|api_key"
claude_opus = "Claude Opus 4.6|anthropic|https://api.anthropic.com/v1/messages|api_key"
```

#### [model_ids]

Maps model keys to actual API model identifiers:

```ini
claude_sonnet = "claude-sonnet-4-6"
claude_haiku = "claude-haiku-4-5-20251001"
claude_opus = "claude-opus-4-6"
```

#### [personality]

Controls the bot's behavior through the system prompt. Supports `{bot_name}` and `{bot_description}` placeholders. Fields include `base_description`, `speaking_style`, `special_trait`, `trait_examples` (pipe-delimited), `formatting_note`, and `brevity_note`.

#### [emotion_theme_map]

Maps emotions to themes for automatic switching:

```ini
neutral = "default"
happy = "sunrise"
curious = "ocean"
focused = "midnight"
creative = "aurora"
```

#### [admin]

Admin panel credentials. Password is stored as a bcrypt hash.

#### [api]

API connection settings: `api_key`, `endpoint`, and `anthropic_version`.

### emotions.ini

Defines available emotions with visual properties:

```ini
[happy]
label = "HAPPY"
description = "Upbeat and enthusiastic"
color = "#f59e0b"
emoji = "😄"
filter = "brightness(1.1) saturate(1.2)"
shake = false
glow = false
```

| Property | Description |
|----------|-------------|
| `label` | Uppercase display label |
| `description` | Shown in system prompt for AI context |
| `color` | Accent color for the emotion indicator |
| `emoji` | Emoji displayed next to the emotion name |
| `filter` | CSS filter applied to the bot avatar |
| `shake` | Whether the header shakes on emotion change |
| `glow` | Whether the avatar gets a glow effect |
| `intense` | Triggers screen flash and notification popup |

### themes.ini

Defines color themes:

```ini
[ocean]
name = "Ocean"
primary_color = "#164e63"
secondary_color = "#155e75"
accent_color = "#22d3ee"
background_color = "#0c4a6e"
header_gradient = "linear-gradient(135deg, #164e63 0%, #0e7490 100%)"
avatar_filter = "brightness(1.05) hue-rotate(180deg)"
description = "Calm ocean depths"
```

---

## Usage

### Chat Interface

- **Send messages** - Type in the input box and press Enter (Shift+Enter for new line)
- **Upload images** - Click the camera button or drag and drop an image onto the chat
- **Voice input** - Click the microphone button to dictate (Chrome/Edge)
- **Switch models** - Use the dropdown in the header to change AI models
- **Toggle dark mode** - Click the moon/sun icon in the header
- **Change themes** - Click the palette icon to browse and select themes
- **Enable auto-theming** - Toggle "Auto-theme with emotion" in the theme menu

### Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Ctrl+N` | New chat |
| `Ctrl+B` | Toggle sidebar |
| `Ctrl+D` | Toggle dark/light mode |
| `Ctrl+K` | Focus search |
| `Ctrl+U` | Upload image |
| `Ctrl+M` | Voice input |
| `Ctrl+E` | Export current chat |
| `Ctrl+/` | Show shortcuts modal |
| `Enter` | Send message |
| `Shift+Enter` | New line in message |
| `Escape` | Close open panels/modals |

### Message Actions

Hover over any message to reveal action buttons:
- **Copy** - Copy message text to clipboard
- **Read aloud** - Text-to-speech (bot messages only)
- **Regenerate** - Re-generate the bot's response (bot messages only)
- **Bookmark** - Star/unstar a message
- **Delete** - Remove the message

### Long-Term Memory

The bot automatically extracts facts about you every 6 messages (configurable). Access the memory panel from the sidebar to:
- View all remembered facts grouped by category
- Manually add facts
- Delete incorrect memories

Memory categories: General, Preference, Personal, Interest, Context, Style.

---

## Admin Panel

Access at `/admin-login.html`. Default credentials: `admin` / `password`.

### Tabs

| Tab | Purpose |
|-----|---------|
| **General** | Bot name, title, description, defaults, emotion-theme linking |
| **Personality** | System prompt components, speaking style, behavioral examples |
| **Emotions** | Add/edit/remove emotions with color pickers and emoji selector |
| **Themes** | Add/edit/remove themes with live color preview |
| **Advanced** | API key, endpoint, admin credentials |
| **Emotion-Theme** | Map each emotion to a theme for auto-switching |

All changes create timestamped backups before saving (last 5 backups retained).

---

## API Reference

### Chat Endpoints

#### `POST /api-stream.php` (Primary - SSE Streaming)

Request:
```json
{
  "messages": [{"role": "user", "content": [{"type": "text", "text": "Hello"}]}],
  "conversation_id": "abc123",
  "model": "claude_sonnet",
  "temperature": 0.7,
  "max_tokens": 4096
}
```

Response: Server-Sent Events stream with events:
- `chunk` - `{"text": "partial response text"}`
- `done` - `{"fullText": "...", "emotion": "happy", "theme": "sunrise", "conversation_id": "...", "tokens": {"input": 100, "output": 200}}`
- `error` - `{"error": "message", "code": "ERROR_CODE"}`

#### `POST /api.php` (Non-streaming fallback)

Same request format, returns complete JSON response.

### Conversation Endpoints

All via `GET/POST /api-conversations.php?action=<action>`:

| Action | Method | Description |
|--------|--------|-------------|
| `list` | GET | List conversations (supports `search`, `limit`, `offset` params) |
| `create` | POST | Create new conversation (`{title, model}`) |
| `get` | GET | Get conversation + messages (`?id=<id>`) |
| `update` | POST | Update title/pinned status (`{id, title?, pinned?}`) |
| `delete` | POST | Delete conversation and all messages (`{id}`) |
| `messages` | GET | Get messages for a conversation (`?id=<id>`) |
| `search` | GET | Full-text search across messages (`?q=<query>`) |
| `export` | GET | Export conversation (`?id=<id>&format=json|markdown|txt`) |
| `edit_message` | POST | Edit a message (`{message_id, content}`) |
| `delete_message` | POST | Delete a message (`{message_id}`) |
| `bookmark_message` | POST | Toggle bookmark (`{message_id, bookmarked}`) |

### Memory Endpoints

All via `GET/POST /api-memory.php?action=<action>`:

| Action | Method | Description |
|--------|--------|-------------|
| `list` | GET | Get all active memories |
| `add` | POST | Add a fact (`{fact, fact_type}`) |
| `update` | POST | Update a memory (`{id, fact?, fact_type?}`) |
| `delete` | POST | Delete a memory (`{id}`) |
| `extract` | POST | Auto-extract facts from messages (`{messages, existing_memories}`) |

### Configuration Endpoint

#### `GET /get-config.php`

Returns public configuration with sensitive data (API keys, admin credentials, model endpoints) stripped. Used by the frontend to initialize.

### Rate Limiting

All chat API endpoints are rate-limited to **15 requests per 60 seconds** per session.

---

## Customization

### Adding a New Emotion

1. Edit `emotions.ini` and add a new section:
```ini
[amused]
label = "AMUSED"
description = "Finding something genuinely funny"
color = "#f59e0b"
emoji = "😂"
filter = "brightness(1.15) saturate(1.2)"
shake = false
glow = false
```

2. Optionally map it to a theme in `config.ini`:
```ini
[emotion_theme_map]
amused = "sunrise"
```

### Adding a New Theme

Add a section to `themes.ini`:
```ini
[cyber]
name = "Cyberpunk"
primary_color = "#0d0221"
secondary_color = "#1a0a3e"
accent_color = "#ff00ff"
background_color = "#090114"
header_gradient = "linear-gradient(135deg, #0d0221 0%, #541388 100%)"
avatar_filter = "brightness(1.1) hue-rotate(300deg)"
description = "Neon cyberpunk vibes"
```

### Adding a New Model

Add entries to both `[models]` and `[model_ids]` in `config.ini`:
```ini
[models]
my_model = "My Model Name|anthropic|https://api.anthropic.com/v1/messages|api_key"

[model_ids]
my_model = "actual-model-id-string"
```

### Customizing the Bot Personality

Edit the `[personality]` section in `config.ini`. The system prompt is constructed from:

1. `base_description` - Core identity (supports `{bot_name}` and `{bot_description}` placeholders)
2. `speaking_style` - Communication tone and approach
3. `special_trait` - Key personality qualities
4. `trait_examples` - Pipe-delimited behavioral examples
5. `formatting_note` - Response formatting preferences
6. `brevity_note` - Verbosity preferences

### Customizing the Avatar

Replace `avatar.svg` with your own SVG or image file. Update `avatar_image` in `config.ini` if using a different filename.

---

## Security

### Built-in Protections

- **API key isolation** - Keys are stored server-side in `config.ini` and never exposed to the frontend
- **`.htaccess` protection** - Blocks direct access to `data/`, `.ini`, `.db`, `.sqlite`, and backup files
- **Prepared statements** - All database queries use parameterized statements (SQLite3 prepared statements)
- **Input validation** - JSON parsing validation, allowed file type checking for uploads
- **Session-based admin auth** - Admin endpoints require active PHP session
- **bcrypt password hashing** - Admin passwords stored as bcrypt hashes
- **Security headers** - X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy, Content-Security-Policy, Permissions-Policy
- **Rate limiting** - 15 requests/minute per session on chat endpoints
- **Config backup rotation** - Admin saves create timestamped backups, keeping only the last 5
- **XSS prevention** - User content escaped via DOM-based `textContent` assignment

### Recommendations for Production

- Use HTTPS (TLS) for all traffic
- Change the default admin password immediately
- Consider placing `config.ini` outside the web root
- Set restrictive file permissions (`644` for files, `755` for directories)
- Configure PHP `session.cookie_secure = true` and `session.cookie_httponly = true`
- Add CSRF tokens to admin form submissions for enhanced security
- Monitor the `data/` directory size as the SQLite database grows

---

## Project Structure

```
chatbot/
├── index.html                 Main chat interface
├── config.ini                 Primary configuration (bot, models, personality, API)
├── emotions.ini               Emotion definitions (11 emotions)
├── themes.ini                 Theme definitions (10 themes)
├── avatar.svg                 Bot avatar image
├── manifest.json              PWA manifest
├── sw.js                      Service worker for offline caching
├── styles.css                 CSS module imports
├── .htaccess                  Security headers, access rules, rewrites
├── .gitignore                 Git ignore rules
│
├── js/                        Frontend JavaScript (ES6 modules)
│   ├── app.js                 Entry point, initialization, event wiring
│   ├── api.js                 Server communication layer
│   ├── chat.js                Message display, streaming, TTS, actions
│   ├── sidebar.js             Conversation list, search, export
│   ├── memory.js              Memory panel, auto-extraction
│   ├── media.js               Image upload/compression, voice input
│   ├── ui.js                  Themes, emotions, dark mode, notifications
│   ├── state.js               Global state store and constants
│   ├── config.js              Configuration loading and validation
│   └── shortcuts.js           Keyboard shortcut handler
│
├── css/                       Modular CSS
│   ├── variables.css          Theme colors, CSS custom properties
│   ├── base.css               Reset, layout, animations
│   ├── sidebar.css            Sidebar styling
│   ├── header.css             Header, model selector, theme menu
│   ├── chat.css               Messages, typing indicator, markdown
│   ├── input.css              Message input, image preview, drag/drop
│   ├── components.css         Memory panel, shortcuts modal, notifications
│   └── responsive.css         Mobile/tablet breakpoints
│
├── data/                      SQLite database (auto-created)
│   ├── .htaccess              Blocks all direct access
│   └── chatbot.db             SQLite database file
│
├── api-stream.php             SSE streaming chat endpoint
├── api.php                    Non-streaming chat endpoint
├── api-conversations.php      Conversation CRUD API
├── api-memory.php             Memory management API
├── get-config.php             Public config endpoint (secrets stripped)
├── db.php                     Database layer (schema, queries, helpers)
│
├── admin.html                 Admin dashboard
├── admin.js                   Admin dashboard logic
├── admin-login.html           Admin login page
├── admin-auth.php             Session authentication
├── admin-get-config.php       Admin config retrieval (full, with masked secrets)
├── admin-save.php             Config file writer (with backup rotation)
├── admin-get-stored.php       Retrieve stored sensitive values from session
├── admin-hash-password.php    Password hashing utility
└── admin-logout.php           Session termination
```

### Database Schema

| Table | Purpose |
|-------|---------|
| `conversations` | Chat sessions with title, timestamps, token totals, pin state |
| `messages` | Individual messages with role, content (JSON), emotion, theme, tokens |
| `memory` | Long-term facts with type, confidence score, soft-delete flag |
| `bookmarks` | Saved message references with optional notes |
| `settings` | Key-value store for app settings |

---

## Troubleshooting

### "Configuration files not found"
- Ensure `config.ini`, `emotions.ini`, and `themes.ini` exist in the project root
- Check file permissions are readable by the web server user

### "API key not configured"
- Edit `config.ini` and set a valid Anthropic API key in the `[api]` section
- Or set it via the admin panel under Advanced > API Configuration

### Database not creating
- Ensure the `data/` directory exists and is writable by the web server
- Check PHP has the `sqlite3` extension enabled: `php -m | grep sqlite3`

### Streaming not working
- Ensure `output_buffering` is disabled or set to `0` in `php.ini`
- Verify Apache is not buffering responses (the `X-Accel-Buffering: no` header handles Nginx)
- Check that no reverse proxy is buffering SSE responses

### Voice input not available
- Voice input requires HTTPS (or localhost) and a Chromium-based browser
- The microphone button hides automatically if the Web Speech API is unavailable

### Service worker serving stale content
- The cache version increments with updates; hard refresh (Ctrl+Shift+R) forces a fresh load
- Or unregister the service worker in browser DevTools > Application > Service Workers

### Admin panel login fails
- Default credentials: username `admin`, password `password`
- If you've lost access, manually set a new bcrypt hash in `config.ini`:
  ```bash
  php -r "echo password_hash('newpassword', PASSWORD_BCRYPT);"
  ```
  Replace the `password` value in the `[admin]` section with the output

---

## License

This project is provided as-is for personal and educational use.
