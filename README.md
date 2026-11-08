# AgentQ AI

**AI-Powered Telecommunications Platform**

AgentQ AI is a comprehensive cloud-based solution for managing automated calling campaigns, AI-powered voice agents, SMS communication, contact management, and customer engagement. Built for businesses of all sizes looking to streamline their outbound and inbound communications.

![Version](https://img.shields.io/badge/version-7.2.0-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.2+-purple.svg)
![Laravel](https://img.shields.io/badge/Laravel-12.x-red.svg)
![React](https://img.shields.io/badge/React-19.x-blue.svg)
![License](https://img.shields.io/badge/license-Proprietary-green.svg)

---

## Table of Contents

- [Features](#features)
- [System Requirements](#system-requirements)
- [Installation](#installation)
  - [Quick Install (composer setup)](#quick-install)
  - [Manual Installation](#manual-installation)
  - [Web Installer](#web-installer)
  - [Nginx / Apache Configuration](#web-server-configuration)
- [Environment Configuration](#environment-configuration)
- [Queue Workers & Background Processes](#queue-workers--background-processes)
- [Development Setup](#development-setup)
- [Deployment](#deployment)
  - [VPS / Dedicated Server](#vps--dedicated-server-deployment)
  - [cPanel Deployment](#cpanel-deployment)
- [Quick Start Guide](#quick-start-guide)
- [Technology Stack](#technology-stack)
- [Documentation](#documentation)
- [Troubleshooting](#troubleshooting)
- [Support](#support)

---

## Features

### Core Telemarketing

- **Automated Calling Campaigns** - Create and manage bulk calling campaigns with TTS or pre-recorded audio
- **Text-to-Speech (TTS)** - Multiple voice options powered by OpenAI TTS (6 voices)
- **Contact Management** - Organize contacts with lists, tags, custom variables, and CSV import/export
- **Campaign Templates** - Save and reuse campaign configurations
- **Real-time Analytics** - Track campaign performance with detailed charts and metrics
- **Built-in Softphone** - Make manual calls directly from the browser via WebRTC
- **Call Recording** - Record and playback calls for quality assurance
- **DTMF Support** - Interactive voice response with keypad input
- **AMD (Answering Machine Detection)** - Detect and handle voicemail automatically
- **Campaign Scheduling** - Schedule campaigns for future execution
- **Audio File Support** - Upload and use pre-recorded audio messages

### AI Agent Calling

- **AI Voice Agents** - Create intelligent voice agents for inbound and outbound calls
- **Real-time Conversations** - Sub-second latency AI responses via WebSocket streaming
- **Speech-to-Text** - Deepgram Nova-2 integration for accurate transcription
- **Multiple LLM Models** - OpenRouter integration (Gemini Flash, GPT-4o-mini, and more)
- **Live Call Monitoring** - Watch AI conversations in real-time with live transcripts
- **Call Transfer** - AI agents can transfer calls to human operators
- **Knowledge Base** - Upload documents to give AI agents domain-specific knowledge
- **Cost Tracking** - Per-call cost breakdown and analytics

### SMS & Communication

- **SMS Campaigns** - Send bulk SMS messages to contact lists
- **Two-way SMS** - Receive and reply to SMS messages
- **AI SMS Responses** - Automated AI-generated SMS replies
- **SMS Templates** - Pre-built message templates

### Follow-up Sequences

- **Automated Sequences** - Multi-step follow-up campaigns
- **Message Variants (A/B Testing)** - Test different messages for optimization
- **AI Scheduling** - Intelligent scheduling based on contact behavior
- **Sentiment Analysis** - AI-powered analysis of call outcomes

### Integrations

- **Twilio or Plivo** - Voice calls, SMS, phone number management. Pick one provider during installation; the app locks to it. A few Twilio-only features (SIP trunking, BYOC, Twilio account migration, softphone hold, answering-machine statistics) are hidden on a Plivo installation.
- **CRM Integration** - Connect with external CRM systems via webhooks
- **SIP Trunking & BYOC** - Bring Your Own Carrier support
- **Stripe & Razorpay** - Dual payment gateway support
- **Sentry** - Error tracking and performance monitoring
- **API (REST)** - Full REST API with Swagger documentation

### Platform Features

- **Multi-language (i18n)** - 10 languages: English, Spanish, French, Arabic, Chinese, Hindi, Portuguese, Russian, Japanese, German
- **Multi-User & Roles** - Admin, Customer, and Agent roles with granular permissions
- **KYC Verification** - Know Your Customer identity verification system
- **Credit System** - Prepaid credit-based billing with usage tracking
- **Pricing Rules** - Flexible pricing configuration for calls, SMS, and phone numbers
- **Theme Customization** - Customize landing page branding (hero, features, pricing, FAQ, footer)
- **Two-Factor Authentication** - Enhanced security with 2FA
- **Activity Logging** - Complete audit trail
- **Real-time WebSocket** - Live updates via Laravel Reverb
- **Web Installer** - Guided setup wizard for first-time installation

---

## System Requirements

### Server Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| **OS** | Ubuntu 20.04+, Windows Server, macOS | Ubuntu 22.04+ LTS |
| **PHP** | 8.2+ | 8.3+ |
| **Web Server** | Apache 2.4+ or Nginx 1.18+ | Nginx |
| **Database** | MySQL 8.0+ or MariaDB 10.5+ | MySQL 8.0+ |
| **Node.js** | 18.x+ | 20.x+ LTS |
| **Composer** | 2.x+ | Latest |
| **RAM** | 2 GB | 4 GB+ |
| **CPU** | 2 cores | 4+ cores |
| **Disk** | 20 GB | 50 GB+ SSD |

### Required PHP Extensions

```
BCMath, Ctype, cURL, DOM, Fileinfo, JSON, Mbstring, OpenSSL, PDO, PDO MySQL, Tokenizer, XML
```

Optional: Redis (recommended for production caching/queues)

### External Services

| Service | Purpose | Required? |
|---------|---------|-----------|
| [Twilio](https://www.twilio.com/) **or** [Plivo](https://www.plivo.com/) | Voice calls, SMS, phone numbers | One of the two (for calling features) |
| [Stripe](https://stripe.com/) | Payment processing | Optional (one payment gateway required) |
| [Razorpay](https://razorpay.com/) | Payment processing | Optional (one payment gateway required) |
| [Deepgram](https://deepgram.com/) | Speech-to-Text for AI agents | For AI Agent calling |
| [OpenAI](https://openai.com/) | Text-to-Speech for AI agents | For AI Agent calling |
| [OpenRouter](https://openrouter.ai/) | LLM models for AI agents | For AI Agent calling |
| SMTP Server | Email notifications | Recommended |
| Redis | Queue, cache, sessions | Recommended for production |

---

## Installation

### Quick Install

The fastest way to get started. This runs `composer install`, generates an app key, runs migrations, installs npm packages, and builds frontend assets:

```bash
cd /var/www/agentq-ai
composer setup
```

Then open your browser and navigate to your domain to complete the web installer.

### Manual Installation

#### Step 1: Download and Extract

```bash
cd /var/www/
# The zip holds the application at its root, so give it a folder of its own
unzip /path/to/the-download.zip -d agentq-ai
cd agentq-ai
```

The package already contains `vendor/` and the built front end, so Steps 2 and 4 are
only needed when you work from the source instead.

#### Step 2: Install PHP Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

#### Step 3: Configure Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your database credentials and other settings (see [Environment Configuration](#environment-configuration)).

#### Step 4: Install Frontend Dependencies and Build

```bash
npm install
npm run build
```

#### Step 5: Set File Permissions

```bash
# Linux/macOS
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Create storage symlink
php artisan storage:link
```

#### Step 6: Run the Web Installer

Open your browser and navigate to `https://your-domain.com/install`. The installation wizard will guide you through:

1. **Requirements Check** - Verifies PHP version (8.2+), extensions, and directory permissions
2. **Database Configuration** - Enter MySQL credentials, test connection, and run migrations + seeders
3. **Telephony Provider** - Choose Twilio or Plivo for this installation (the choice is permanent)
4. **Admin Account** - Create the first admin user
5. **Complete** - Installation finished, redirect to login

> **Note**: The web installer automatically runs all database migrations and seeds default data (roles, permissions, themes, pricing rules, campaign templates, and languages).

#### Step 7: Set Up Background Processes

Queue workers and the scheduler are **required** for campaigns to run. See [Queue Workers & Background Processes](#queue-workers--background-processes).

#### Step 8: Configure Cron Job (if not using PM2)

```bash
crontab -e
# Add this line:
* * * * * cd /var/www/agentq-ai && php artisan schedule:run >> /dev/null 2>&1
```

### Web Installer

The recommended path for shared hosting and anyone without shell access.

1. Upload the package to your server and point the domain at the `public/`
   folder. (If your host cannot change the document root, upload into
   `public_html` as-is — the included root `.htaccess` routes into `public/`.)
2. Open the domain in a browser. The installer creates `.env`, generates the
   application key and starts the setup wizard automatically — there is
   nothing to copy or edit by hand.
3. Follow the four steps: requirements → database → telephony provider →
   admin account. The database is created in short, resumable slices, so slow
   hosts and PHP time limits cannot break it; if a step is interrupted, click
   again and it continues where it stopped. The telephony step asks whether
   this installation will use Twilio or Plivo, and **that choice is permanent**
   — undoing it means the destructive reset described in the Quick Start Guide.
4. The final screen shows the two background commands the platform needs —
   a cron entry for the scheduler and a queue worker (a cron-driven variant
   is shown for shared hosts). Set both up; campaigns, imports and emails do
   not run without them.

On cPanel specifically: File Manager → upload and extract into
`public_html` → MultiPHP Manager → PHP 8.2+ → MySQL Database Wizard →
open the domain. Before installing on an unfamiliar server you can run
`php artisan install:selfcheck` to verify it can host the installer.
Install at a domain or subdomain root; installing into a sub-folder
(`public_html/app/`) is not supported.


## Environment Configuration

Copy `.env.example` to `.env` and configure the following sections:

### Application

```env
APP_NAME=AgentQ AI
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_TIMEZONE=UTC
```

### Database

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=agentq_ai
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### Session, Cache & Queue

```env
SESSION_DRIVER=database      # or redis
CACHE_STORE=database         # or redis
QUEUE_CONNECTION=database    # or redis
BROADCAST_CONNECTION=reverb
```

### Mail (for notifications)

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-email@example.com
MAIL_PASSWORD=your-password
MAIL_FROM_ADDRESS=noreply@your-domain.com
MAIL_FROM_NAME="${APP_NAME}"
```

### Payment Gateways

```env
# Stripe
STRIPE_SECRET_KEY=sk_live_...
STRIPE_PUBLIC_KEY=pk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_TEST_MODE=false
STRIPE_FEE_PERCENTAGE=2.9
STRIPE_FEE_FIXED=0.30

# Razorpay
RAZORPAY_API_KEY=rzp_live_...
RAZORPAY_SECRET_KEY=...
RAZORPAY_WEBHOOK_SECRET=...
RAZORPAY_TEST_MODE=false
RAZORPAY_FEE_PERCENTAGE=2.0
RAZORPAY_FEE_FIXED=0.0
```

### Telephony Provider (Twilio or Plivo)

Choose one provider during installation. The app locks to that choice; switching later
means running the destructive **Reset telephony** tool in the admin panel, which clears
every telephony setting on the installation (see the Quick Start Guide below).

```env
# twilio or plivo
TELEPHONY_PROVIDER=twilio
```

**Twilio**

```env
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=your_auth_token
TWILIO_PHONE_NUMBER=+1234567890
```

**Plivo**

```env
PLIVO_AUTH_ID=
PLIVO_AUTH_TOKEN=
```

Credentials can also be entered and verified from **Admin > Telephony** after
installation, which is the recommended route for most installations.

### AI Agent Services (for AI calling)

```env
# Deepgram (Speech-to-Text)
DEEPGRAM_API_KEY=your_deepgram_key

# OpenAI (Text-to-Speech)
OPENAI_API_KEY=sk-proj-...
OPENAI_TTS_MODEL=tts-1
OPENAI_TTS_VOICE=alloy

# OpenRouter (LLM)
OPENROUTER_API_KEY=sk-or-v1-...

# AI Defaults
AI_AGENT_DEFAULT_MODEL=gpt-3.5-turbo
AI_AGENT_MAX_TOKENS=500
AI_AGENT_TEMPERATURE=0.7
```

### Laravel Reverb (WebSocket)

```env
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-secure-key
REVERB_APP_SECRET=your-secure-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http       # https in production

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### Error Tracking (Sentry)

```env
SENTRY_LARAVEL_DSN=https://...@sentry.io/...
SENTRY_TRACES_SAMPLE_RATE=1.0

VITE_SENTRY_DSN="${SENTRY_LARAVEL_DSN}"
VITE_SENTRY_ENVIRONMENT="${APP_ENV}"
```

### API Configuration

```env
API_RATE_LIMIT_ENABLED=true
API_RATE_LIMIT_DEFAULT=60
API_RATE_LIMIT_HIGH=1000
API_RATE_LIMIT_ADMIN=10000
```

### Production Optimization

After configuring `.env`, cache the configuration for better performance:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

---

## Queue Workers & Background Processes

Queue workers are **essential** for AgentQ AI to function. Without them, campaigns won't execute, contacts won't import, and background jobs won't process.

### What Runs in the Background

| Process | Purpose |
|---------|---------|
| **Queue Worker** | Campaign calls, contact imports, SMS sending, AI responses, pricing sync |
| **Scheduler** | Launch scheduled campaigns, process running campaigns, follow-up sequences, monthly number charges |
| **Reverb** (optional) | Real-time WebSocket updates for live dashboards |

### Option 1: PM2 (Recommended)

PM2 provides automatic restarts, monitoring, and log management. No cron job needed.

```bash
# Install PM2 globally
npm install -g pm2

# Start all background processes
cd /var/www/agentq-ai
pm2 start ecosystem.config.cjs

# Verify everything is running
pm2 status

# Save and enable auto-start on reboot
pm2 save
pm2 startup
```

The `ecosystem.config.cjs` configures:
- **2 queue worker instances** (auto-restart, 500MB memory limit, daily restart at 3 AM)
- **1 scheduler instance** (replaces cron job via `schedule:work`)
- **1 Reverb instance** (optional, uncomment in config if needed)

### Option 2: Supervisor (Linux)

```bash
sudo apt-get install supervisor
sudo nano /etc/supervisor/conf.d/agentq-queue.conf
```

```ini
[program:agentq-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/agentq-ai/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
directory=/var/www/agentq-ai
autostart=true
autorestart=true
numprocs=2
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/agentq-ai/storage/logs/queue-worker.log
stdout_logfile_maxbytes=10MB
stopwaitsecs=60
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start agentq-queue-worker:*
```

With Supervisor, you also need the cron job for the scheduler:
```bash
* * * * * cd /var/www/agentq-ai && php artisan schedule:run >> /dev/null 2>&1
```

### Option 3: Systemd (Linux)

```bash
sudo nano /etc/systemd/system/agentq-queue.service
```

```ini
[Unit]
Description=AgentQ AI Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/agentq-ai
ExecStart=/usr/bin/php /var/www/agentq-ai/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable agentq-queue
sudo systemctl start agentq-queue
```

### Option 4: Manual (Development Only)

```bash
# Terminal 1: Queue worker
php artisan queue:work --tries=3

# Terminal 2: Scheduler
php artisan schedule:work
```

### Option 5: Windows

```powershell
.\scripts\start-queue-workers.ps1
# Or manually:
php artisan queue:work --tries=3
```

---

## Development Setup

### Quick Start

```bash
# Install all dependencies and build
composer setup

# Start development servers (Laravel + Queue + Vite concurrently)
composer dev
```

This starts three processes:
- Laravel development server at `http://localhost:8000`
- Queue listener for background jobs
- Vite dev server with HMR at `http://localhost:5173`

### Development Scripts

```bash
# Start dev environment
composer dev                   # Laravel + Queue + Vite
composer dev:ssr               # With SSR support

# Frontend
npm run dev                    # Vite dev server with HMR
npm run build                  # Production build
npm run build:ssr              # SSR build
npm run build:widget           # Widget build
npm run lint                   # ESLint
npm run format                 # Prettier
npm run types                  # TypeScript check

# Testing
composer test                  # PHPUnit tests
php artisan test               # Laravel tests

# Code quality
./vendor/bin/pint              # PHP code formatting (Laravel Pint)
```

### Development Environment (.env)

For local development, use these settings:

```env
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=sqlite           # Use SQLite for quick setup
MAIL_MAILER=log                # Logs emails to laravel.log
BROADCAST_CONNECTION=reverb
STRIPE_TEST_MODE=true
RAZORPAY_TEST_MODE=true
```

---

## SEO, AI discoverability & agentic crawling

The front end is an Inertia SPA, so the initial HTML response is generated
server-side explicitly (`resources/views/app.blade.php` + `App\Support\Seo`):

- title, description, keywords, canonical URL, robots directives, Open Graph
  and Twitter cards;
- a JSON-LD `@graph` — `Organization`, `WebSite`, `WebPage`/`Article`,
  `SoftwareApplication`, `FAQPage` and `BreadcrumbList` — built from the
  admin-editable theme settings, capabilities, FAQs and public pages;
- a `<noscript>` summary (title, description, capabilities, FAQ answers and
  links) so crawlers that never execute JavaScript — including most AI/agent
  crawlers — still receive the actual content.

Generated per installation, so there are no static files to keep in sync:

| URL | Purpose |
|-----|---------|
| `/robots.txt` | crawl rules, disallows for the authenticated app, explicit allow groups for GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot and friends, plus the sitemap URL |
| `/sitemap.xml` | home, documentation and every active public page, with `lastmod` |
| `/llms.txt` | concise markdown for LLMs and agents (the [llms.txt](https://llmstxt.org/) convention): summary, pages, capabilities, pricing, FAQ, API reference and sitemap/robots links |

Authenticated application pages (`/dashboard`, `/settings/*`, `/admin/*` and
the auth screens) send `X-Robots-Tag: noindex, nofollow`. `public/.htaccess`
adds gzip compression and long-lived caching for the fingerprinted Vite assets
(Core Web Vitals).

Set `APP_URL` in `.env` to the public **https** URL — canonical, sitemap and
robots URLs are built from it. Meta title/description/keywords, the OG image
and social links are editable under **Admin → Theme**.

Full server-side rendering (Inertia SSR) is intentionally not enabled: it
requires a long-running Node process that shared cPanel hosting does not
provide. The JSON-LD and `<noscript>` content cover crawlers without it.

---

## Deployment

### VPS / Dedicated Server Deployment

#### 1. Prepare the Server

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install PHP 8.3 and extensions
sudo apt install php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-bcmath php8.3-tokenizer php8.3-zip php8.3-redis -y

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Node.js 20.x
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install nodejs -y

# Install Nginx
sudo apt install nginx -y

# Install MySQL
sudo apt install mysql-server -y
```

#### 2. Deploy Application

```bash
# Clone or upload application
cd /var/www
# Extract your application to /var/www/agentq-ai

cd agentq-ai

# Install dependencies
composer install --no-dev --optimize-autoloader
npm install
npm run build

# Configure environment
cp .env.example .env
nano .env    # Set production values

# Generate key and run migrations
php artisan key:generate
php artisan storage:link

# Set permissions
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

#### 3. Configure Web Server

Set up Nginx with SSL (see [Web Server Configuration](#web-server-configuration)).

```bash
# Install SSL with Certbot
sudo apt install certbot python3-certbot-nginx -y
sudo certbot --nginx -d your-domain.com
```

#### 4. Start Background Processes

```bash
# Using PM2 (recommended)
npm install -g pm2
pm2 start ecosystem.config.cjs
pm2 save
pm2 startup
```

#### 5. Run Web Installer

Navigate to `https://your-domain.com/install` to complete the setup.

### cPanel Deployment

Key considerations for cPanel:
- Set document root to the `public/` directory
- Use cPanel's Cron Jobs for the Laravel scheduler
- Queue workers may need Supervisor or a VPS for long-running processes
- Shared hosting may not support WebSocket (Reverb); consider Pusher as an alternative

---

## Quick Start Guide

### 1. First Login

Navigate to your domain and log in with the admin credentials created during installation.

### 2. Configure your telephony provider

The provider — Twilio or Plivo — is chosen during installation and the app is locked to
it. An installation upgraded from an earlier version is locked to Twilio automatically.

**Twilio**

1. Go to **Settings > Twilio**
2. Enter your Twilio Account SID and Auth Token
3. Test connection and save

**Plivo**

1. Go to **Admin > Telephony**
2. Enter your Plivo Auth ID and Auth Token
3. Verify the credentials against Plivo and save

Changing provider afterwards requires **Admin > Telephony > Reset telephony**, which
deletes your provider credentials and settings, your SIP/BYOC trunk setup and your
call-queue records, and releases every phone number inside the app. Users, credits,
contacts, campaigns, call history and message history are kept — campaigns and AI agents
keep their settings but lose the number they were attached to, and pending number
requests are cancelled. Nothing is cancelled at the provider: those numbers stay on your
Twilio or Plivo account until you remove them there. It cannot be undone.

### 3. Get a Phone Number

1. Go to **Numbers > Purchase**
2. Search available numbers by country
3. Purchase a number (requires credit balance)

### 4. Add Credits

1. Go to **Credit > Buy Credits**
2. Choose an amount and pay via Stripe or Razorpay
3. Credits are added instantly

### 5. Import Contacts

1. Go to **Contacts > Import**
2. Upload a CSV file with phone numbers
3. Map columns and import

### 6. Create a Campaign

1. Go to **Campaigns > Create Campaign**
2. Enter campaign name and select a phone number
3. Write your message or use AI message generator
4. Select contact lists
5. Launch the campaign

### 7. Monitor Results

1. View real-time campaign progress on the **Dashboard**
2. Check detailed analytics under **Analytics**
3. Review call logs under **Calls**

---

## Technology Stack

### Backend

| Technology | Version | Purpose |
|-----------|---------|---------|
| PHP | 8.2+ | Server-side language |
| Laravel | 12.x | PHP framework |
| Laravel Sanctum | 4.x | API authentication |
| Laravel Fortify | 1.x | User authentication & 2FA |
| Laravel Reverb | 1.x | WebSocket server |
| Twilio SDK | 8.x | Voice & SMS integration (Twilio installations) |
| Plivo PHP SDK | 4.x | Voice & SMS integration (Plivo installations) |
| Stripe PHP | 18.x | Payment processing |
| Razorpay | 2.x | Payment processing |
| League CSV | 9.x | CSV import/export |
| Spatie Query Builder | 6.x | API query filtering |
| L5 Swagger | 9.x | API documentation |
| Sentry Laravel | 4.x | Error tracking |

### Frontend

| Technology | Version | Purpose |
|-----------|---------|---------|
| React | 19.x | UI framework |
| TypeScript | 5.x | Type-safe JavaScript |
| Inertia.js | 2.x | SPA without API |
| Tailwind CSS | 4.x | Utility-first CSS |
| Shadcn/ui (Radix UI) | Latest | Component library |
| Vite | 7.x | Build tool & dev server |
| Recharts | 3.x | Charts & analytics |
| react-i18next | 16.x | Internationalization |
| Lucide React | Latest | Icons |
| Twilio Voice SDK | 2.x | Browser-based calling (Twilio installations) |
| Plivo Browser SDK | 2.x | Browser-based calling (Plivo installations) |
| Sentry React | 10.x | Frontend error tracking |

### Infrastructure

| Technology | Purpose |
|-----------|---------|
| Nginx / Apache | Web server |
| MySQL / MariaDB | Database |
| PM2 / Supervisor / Systemd | Process management |
| Redis (optional) | Cache, queue, sessions |
| Let's Encrypt | SSL certificates |

---

## Documentation

### What ships with the application

| Where | What it covers |
|-------|----------------|
| `CHANGELOG.md` | Release notes, newest first. **Read this before every upgrade** — it is where a release says what changes your billing, your reported figures, or anything you have already configured. |
| `docs/telephony-live-verification.md` | The checklist to run against your own installation, with a real phone and a real telephony account, before it carries customer traffic. Covers both Twilio and Plivo: outbound and inbound calls, recordings, SMS both directions, AI voice agents, the call queue, KYC phone verification, and changing provider. |
| `docs/documentation.html` | The full product guide — features, setup and day-to-day use. Open it in a browser, or read it in the running application at `/documentation`. |
| `README.md` | This file: requirements, installation, configuration, background processes, deployment and troubleshooting. |

### Inside the application

Most of what an operator needs day to day is in the application itself rather than in a file:

| Page | What it is for |
|------|----------------|
| `/documentation` | The product guide, served by the application. |
| **Admin → Telephony** | Which provider this installation uses, and the one place to change it. On a Plivo installation it also holds the account credentials and checks them against Plivo before saving; Twilio's own credentials live under **Settings → Twilio**. |
| **Phone Management → Number Inventory** | Syncing numbers from your provider account, and assigning them to accounts. On Twilio it is also where a number's webhooks are pointed at this installation. |
| **Admin → Pricing** | The per-country rates your customers are charged, and importing the provider's published rates. |
| **Admin → Cron Monitor** | Whether the scheduler and queue workers are actually running. Check here first when something asynchronous has stopped happening. |
| **Admin → Error Logs** and **Call Logs** | What the application recorded, without needing shell access to the server. |
| **Admin → System Configuration** | Application-wide settings, alongside the integrations covered under [Environment Configuration](#environment-configuration). |

### API Documentation (Swagger)

The interactive API documentation is available at:

```
https://your-domain.com/api/documentation
```

Generate/update Swagger docs:
```bash
php artisan l5-swagger:generate
```

---

## Troubleshooting

### Common Issues

#### Campaigns Not Running

1. Verify queue workers are running: `pm2 status` or `ps aux | grep queue:work`
2. Check for pending jobs: `php artisan queue:monitor database`
3. Check for failed jobs: `php artisan queue:failed`
4. Review logs: `tail -f storage/logs/laravel.log`

#### Permission Issues

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

#### 500 Server Error

```bash
# Check Laravel logs
tail -50 storage/logs/laravel.log

# Clear all caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Rebuild caches
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

#### WebSocket Not Connecting

1. Verify Reverb is running: `pm2 status` or `curl http://localhost:8080`
2. Check firewall: ensure port 8080 is open
3. Verify `.env` Reverb settings match between backend and frontend (VITE_REVERB_*)
4. For HTTPS, ensure Reverb is behind a reverse proxy with SSL

#### Queue Jobs Failing

```bash
# View failed jobs
php artisan queue:failed

# Retry all failed
php artisan queue:retry all

# Retry specific job
php artisan queue:retry {id}

# Clear all failed jobs
php artisan queue:flush
```

### System Health Check

```bash
# Application info
php artisan about

# Queue status
php artisan queue:monitor database

# Failed jobs
php artisan queue:failed

# Test AI Agent system
php artisan ai-agent:test

# View scheduled tasks
php artisan schedule:list

# Check routes
php artisan route:list
```

### Log Files

| Log | Location |
|-----|----------|
| Laravel | `storage/logs/laravel.log` |
| PM2 Queue | `storage/logs/pm2-queue-combined.log` |
| PM2 Scheduler | `storage/logs/pm2-scheduler-combined.log` |
| PM2 Reverb | `storage/logs/pm2-reverb-combined.log` |

---

## Security

- **APP_DEBUG=false** in production (never expose debug info)
- **Encrypted Credentials** - Telephony and API keys stored in `.env` or the admin panel (never committed to version control). Rotate any credential that was ever written into a `.env` backup under `storage/backups/`, and delete those backups — they hold the values in plain text.
- **Two-Factor Authentication** - Optional 2FA for all users
- **Role-Based Access Control** - Granular permission system (100+ permissions)
- **KYC Verification** - Identity verification for users
- **CSRF Protection** - Built-in Laravel CSRF tokens
- **Rate Limiting** - Configurable API rate limits (default/high/admin tiers)
- **Sanctum Authentication** - Token-based API authentication
- **HTTPS Required** - SSL certificate recommended (required for provider webhooks)
- **Signed Webhooks** - Incoming Twilio and Plivo webhooks are signature-verified before the app acts on them
- **Input Validation** - Server-side validation on all endpoints
- **SQL Injection Prevention** - Eloquent ORM parameterized queries

---

## Browser Support

| Browser | Supported |
|---------|-----------|
| Google Chrome (latest) | Yes |
| Mozilla Firefox (latest) | Yes |
| Microsoft Edge (latest) | Yes |
| Safari (latest) | Yes |
| Internet Explorer | No |

Recommended: Google Chrome for best WebRTC softphone experience.

---

## Performance

### Capacity

- **Concurrent Campaigns**: Up to 20 simultaneously
- **Contacts per Campaign**: Up to 100,000
- **System-wide Concurrent Calls**: Up to 1,000
- **Contact Import**: 10,000+ contacts per minute

### Optimization Features

- Database indexing for fast queries
- Queue-based job processing with configurable worker count
- Frontend code splitting and lazy loading
- Configuration and route caching
- Redis support for high-performance caching
- Vite-optimized asset bundling

---

## Support

### Getting Help

1. **Documentation**: [`CHANGELOG.md`](CHANGELOG.md) for what a release changed, `docs/documentation.html` (or `/documentation` in the running app) for the product guide, and [Documentation](#documentation) above for the rest
2. **Logs**: Review `storage/logs/laravel.log`
3. **Provider console**: [Twilio Debugger](https://www.twilio.com/console/debugger) or [Plivo Logs](https://console.plivo.com/dashboard/logs/)
4. **Sentry Dashboard**: Check your Sentry project for errors

### Useful Commands

```bash
php artisan about                    # Application info
php artisan queue:monitor database   # Queue status
php artisan queue:failed             # Failed jobs
php artisan schedule:list            # Scheduled tasks
php artisan route:list               # All routes
php artisan ai-agent:test            # Test AI agent system
```

---

## License

This software is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.

**Copyright 2025-2026 AgentQ AI. All rights reserved.**

---

## Acknowledgments

Built with:
- [Laravel](https://laravel.com/) - PHP Framework
- [React](https://react.dev/) - Frontend Library
- [Inertia.js](https://inertiajs.com/) - Modern Monolith
- [Shadcn/ui](https://ui.shadcn.com/) - UI Components
- [Tailwind CSS](https://tailwindcss.com/) - CSS Framework
- [Twilio](https://www.twilio.com/) - Communications Platform
- [Plivo](https://www.plivo.com/) - Communications Platform
- [Deepgram](https://deepgram.com/) - Speech-to-Text
- [OpenAI](https://openai.com/) - Text-to-Speech
- [OpenRouter](https://openrouter.ai/) - LLM Access

---

**Version**: 7.2.0
**Last Updated**: November 4, 2026
