# CTFTimeParser

A lightweight PHP + MySQL tool that fetches upcoming CTF event announcements from [CTFTime](https://ctftime.org/) and publishes them to a Telegram supergroup topic.

---

## Features

- Fetches events via the [CTFTime public API](https://ctftime.org/api)
- Two-stage buffer pipeline — deduplication before fetching details
- Content security checks: XSS, SSTI, SQLi, SSRF, suspicious URLs, anomalous strings
- Unsafe events are stored with `is_safe=0` and held back from publication
- **Weekly digest** every Monday at 07:00 — compact list of all events for the next 14 days
- **Daily updates** every day at 07:00 — individual full-detail posts for new events
- No external dependencies — pure PHP 8 + PDO + cURL
- Atomic lock files prevent overlapping cron runs
- File-based logging with automatic rotation at 5 MB

---

## Requirements

- PHP 8.0+
- MySQL 8.0+ (or MariaDB 10.5+)
- PHP extensions: `pdo_mysql`, `curl`, `mbstring`

---

## Setup

### 1. Create the database and user

```sql
CREATE DATABASE ctftimeparser CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Use a dedicated user with minimum required privileges, not root
CREATE USER 'ctfparser'@'localhost' IDENTIFIED BY 'strong_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON ctftimeparser.* TO 'ctfparser'@'localhost';
```

Apply the schema:

```bash
mysql -u ctfparser -p ctftimeparser < schema.sql
```

### 2. Create a Telegram bot

1. Open [@BotFather](https://t.me/BotFather) in Telegram and send `/newbot`
2. Copy the **bot token** you receive
3. Add the bot to your supergroup as an **administrator** with *Post Messages* permission
4. Enable **Topics** in the group settings, then create the announcements topic
5. Forward any message from that topic to [@JsonDumpBot](https://t.me/JsonDumpBot) — find `message_thread_id` in the output

### 3. Configure

```bash
cp config.php.sample config.php
```

Fill in `config.php` with your database credentials and Telegram settings:

```php
'db' => [
    'user' => 'ctfparser',
    'pass' => 'strong_password',
    ...
],
'telegram' => [
    'bot_token' => '123456:ABC-your-token',
    'chat_id'   => '-1001234567890',
    'thread_id' => 42,
    ...
],
```

### 4. Schedule via cron

```
# Fetch new events from CTFTime every 6 hours
0 */6 * * * /usr/bin/php /path/to/parser.php

# Publish to Telegram every day at 07:00
0 7   * * * /usr/bin/php /path/to/publisher.php
```

---

## Project Structure

```
CTFTimeParser/
├── parser.php                 # CTFTime → MySQL pipeline (run via cron)
├── publisher.php              # MySQL → Telegram publisher (run via cron)
├── config.php                 # Credentials and settings (gitignored)
├── config.php.sample          # Configuration template
├── schema.sql                 # Database schema
├── src/
│   ├── CtftimeClient.php      # CTFTime API client (cURL)
│   ├── ContentSecurity.php    # Input sanitization and threat detection
│   ├── Database.php           # PDO wrapper, all queries
│   ├── Formatter.php          # Telegram HTML message formatter
│   └── TelegramBot.php        # Telegram Bot API client (cURL)
└── logs/
    ├── parser.log             # Parser log (auto-created, rotates at 5 MB)
    └── publisher.log          # Publisher log (auto-created, rotates at 5 MB)
```

---

## How It Works

### Parser (`parser.php`)

Runs every 6 hours. Follows a three-step pipeline:

**Step 1 — Collect IDs**
Fetches the event list from CTFTime API for the next 14 days and writes all event IDs into `parser_buffer` (`INSERT IGNORE`).

**Step 2 — Deduplicate**
Removes IDs from `parser_buffer` that already exist in `ctf_events`.

**Step 3 — Fetch and store**
For each remaining ID, fetches full event details, runs security checks, and inserts the sanitised record into `ctf_events`. A 1-second pause between requests avoids hammering the API.

### Publisher (`publisher.php`)

Runs every day at 07:00. Behaviour depends on the day of the week:

**Monday — weekly digest**
Sends one compact summary message (or more if the list exceeds 4096 characters) listing all upcoming events in the next 14 days. `posted_at` is **not** set — events will still appear in daily updates.

```
📋 CTF Events — next 14 days
26 Mar – 09 Apr 2026

• SomeCTF 2026
  📅 28 Mar — 30 Mar | Jeopardy | Online

• AnotherCTF 2026
  📅 02 Apr | Attack-Defense | Online
```

**Tuesday – Sunday — daily updates**
Sends a full-detail message for every event where `posted_at IS NULL AND is_safe = 1`, then sets `posted_at = NOW()`. Failed sends are left unpublished and retried on the next run.

```
🚩 SomeCTF 2026

📅 28 Mar — 30 Mar 2026 (UTC)
🏆 Jeopardy | Weight: 25.50
🌐 Online

Brief description of the event…

🔗 Event site  ·  CTFTime
```

---

## Database Schema

### `parser_buffer`

| Column | Type | Description |
|---|---|---|
| `event_id` | INT UNSIGNED PK | CTFTime event ID |
| `created_at` | DATETIME | Row creation time |

### `ctf_events`

| Column | Type | Description |
|---|---|---|
| `id` | INT UNSIGNED PK | CTFTime event ID |
| `title` | VARCHAR(255) | Event name |
| `url` | VARCHAR(512) | Official event website |
| `ctftime_url` | VARCHAR(512) | CTFTime event page |
| `start_time` | DATETIME | Start (UTC) |
| `finish_time` | DATETIME | End (UTC) |
| `format` | VARCHAR(64) | Jeopardy / Attack-Defense / etc. |
| `weight` | FLOAT | CTFTime rating weight |
| `onsite` | TINYINT(1) | 1 = on-site event |
| `location` | VARCHAR(255) | City/country for on-site events |
| `description` | TEXT | Event description |
| `logo_url` | VARCHAR(512) | Event logo URL |
| `is_safe` | TINYINT(1) | 0 = flagged by security checks |
| `posted_at` | DATETIME | NULL = not yet published to Telegram |
| `created_at` | DATETIME | Row creation time |

Events with `is_safe = 0` are stored but **not published** until manually reviewed.

---

## Security

Audited against **[OWASP Top 10:2025](https://owasp.org/Top10/2025/)**.

| OWASP | Threat | Defence |
|---|---|---|
| A01 | Broken Access Control / SSRF | `CURLOPT_FOLLOWLOCATION=false` in all cURL calls; HTTPS-only; API base URL hardcoded; credentials in URL rejected; private IP range check on literal IPs |
| A02 | Security Misconfiguration | Dedicated DB user with minimum privileges; `config.php` excluded from VCS; bot token never written to logs |
| A05 | Injection — SQLi | PDO prepared statements throughout; no user-data interpolation; supplementary regex detection in stored content |
| A05 | Injection — SSTI | Regex detection of `{{ }}`, `{% %}`, `<% %>`, `${}`, `#{}` patterns |
| A05 | Injection — XSS | `strip_tags()` + `htmlspecialchars()` on all string fields before storage and before output |
| A06 | Insecure Design | No blocking DNS resolution; URL scheme whitelist (`http`/`https`); field length limits |
| A08 | Data Integrity | Event ID overridden from request path, not response body; JSON depth limited |
| A09 | Security Logging Failures | Structured log with level + timestamp; automatic 5 MB rotation; separate logs per component |
| A10 | Mishandling of Exceptional Conditions | Atomic lock via `fopen('c')+flock(LOCK_EX\|LOCK_NB)` — no TOCTOU race, OS auto-releases on crash; Telegram 429 handled with `retry_after` back-off |

Events that fail any content check are stored with `is_safe=0` and withheld from publication pending manual review.

---

## Logging

Each component writes to its own log file, rotated automatically at 5 MB:

**`logs/parser.log`**
```
[2026-03-26 12:00:00] [INFO] Fetching event list [2026-03-26 – 2026-04-02]
[2026-03-26 12:00:01] [INFO] Received 14 event IDs from API.
[2026-03-26 12:00:01] [INFO] Buffer cleaned (removed already-known events).
[2026-03-26 12:00:01] [INFO] 3 new event(s) to process.
[2026-03-26 12:00:02] [INFO] Event #2345 saved: "SomeCTF 2026" (safe=1)
[2026-03-26 12:00:04] [INFO] Done. Saved: 3 | Unsafe (stored): 0 | Skipped: 0
```

**`logs/publisher.log`**
```
[2026-03-30 07:00:00] [INFO] Daily update: 3 event(s) to publish.
[2026-03-30 07:00:01] [INFO] Published event #2345: "SomeCTF 2026"
[2026-03-30 07:00:03] [INFO] Published event #2346: "AnotherCTF 2026"
[2026-03-30 07:00:05] [INFO] Daily update done. Sent: 3 | Failed: 0
```

---

## Troubleshooting

**`Permission denied` writing to `logs/`**
```bash
chmod 755 logs/
```

**`PDO connection failed` / `Access denied for user`**
- Verify `config.php` credentials match your MySQL user
- Confirm grants: `SHOW GRANTS FOR 'ctfparser'@'localhost';`

**`Could not create lock file`**
- Check that `/tmp` is writable by the PHP process user

**No events appear after running the parser**
- CTFTime API returns an empty list if no events are scheduled in the next 7 days — this is normal
- Check `logs/parser.log` for API errors

**Publisher sends nothing**
- Run the parser first so `ctf_events` is populated
- Check that `is_safe = 1` and `posted_at IS NULL` for at least one row
- Verify the bot token and chat/thread IDs in `config.php`

**`Another instance is already running`**
- `flock()` releases automatically when the process exits — wait for the current run to finish or verify no `php parser.php` / `php publisher.php` process is running
