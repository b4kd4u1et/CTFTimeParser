# CTFTimeParser

> [Қазақша](#қазақша) · [Русский](#русский) · [English](#english)

---

## Қазақша

**CTFTimeParser** — [CTFTime](https://ctftime.org/) сайтынан алдағы CTF жарыстары туралы хабарландыруларды жинап, MySQL дерекқорына сақтайтын және Telegram супертопқа жариялайтын жеңіл PHP + MySQL құралы.

### Мүмкіндіктер

- CTFTime API арқылы оқиғаларды автоматты жинау
- Екі сатылы буфер: деректер қайталанбауы үшін алдын ала сүзгіден өтеді
- XSS, SSTI, SQLi, SSRF және күдікті URL-дерге қарсы мазмұн қауіпсіздігі тексерулері
- Қауіпті оқиғалар `is_safe=0` белгісімен сақталып, жариялаудан ұсталады
- **Апталық дайджест** — дүйсенбі сайын 07:00-де келесі 14 күннің барлық оқиғалары
- **Күнделікті жаңартулар** — жаңа оқиғаларды толық форматта жариялау
- Сыртқы тәуелділіктер жоқ — таза PHP 8 + PDO + cURL
- Атомдық блокировка файлдары cron-процестерінің қабаттасуын болдырмайды
- Файлдық логтау, 5 МБ-тан асқанда автоматты ротация

### Талаптар

- PHP 8.0+
- MySQL 8.0+ (немесе MariaDB 10.5+)
- PHP кеңейтімдері: `pdo_mysql`, `curl`, `mbstring`

### Орнату

#### 1. Дерекқор және пайдаланушы жасау

```sql
CREATE DATABASE ctftimeparser CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'ctfparser'@'localhost' IDENTIFIED BY 'strong_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON ctftimeparser.* TO 'ctfparser'@'localhost';
```

Схеманы қолдану:

```bash
mysql -u ctfparser -p ctftimeparser < schema.sql
```

#### 2. Telegram боты жасау

1. Telegram-да [@BotFather](https://t.me/BotFather) ашып, `/newbot` жіберіңіз
2. Алынған **бот токенін** көшіріңіз
3. Ботты супертопқа *хабар жариялау* рұқсатымен **әкімші** ретінде қосыңыз
4. Топ параметрлерінде **Topics** қосып, хабарландырулар тақырыбын жасаңыз
5. Сол тақырыптан кез келген хабарды [@JsonDumpBot](https://t.me/JsonDumpBot)-қа жіберіңіз — `message_thread_id` мәнін табыңыз

#### 3. Конфигурация

```bash
cp config.php.sample config.php
```

`config.php` файлын толтырыңыз:

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

#### 4. Cron арқылы жоспарлау

```
# CTFTime-дан оқиғаларды әр 6 сағат сайын жинау
0 */6 * * * /usr/bin/php /path/to/parser.php

# Telegram-ға күн сайын 07:00-де жариялау
0 7   * * * /usr/bin/php /path/to/publisher.php
```

### Жоба құрылымы

```
CTFTimeParser/
├── parser.php                 # CTFTime → MySQL (cron арқылы іске қосылады)
├── publisher.php              # MySQL → Telegram (cron арқылы іске қосылады)
├── config.php                 # Баптаулар мен кіру деректері (gitignored)
├── config.php.sample          # Конфигурация үлгісі
├── schema.sql                 # Дерекқор схемасы
├── src/
│   ├── CtftimeClient.php      # CTFTime API клиенті (cURL)
│   ├── ContentSecurity.php    # Мазмұн қауіпсіздігі тексерулері
│   ├── Database.php           # PDO орауышы, барлық сұраулар
│   ├── Formatter.php          # Telegram HTML хабар форматтаушы
│   └── TelegramBot.php        # Telegram Bot API клиенті (cURL)
└── logs/
    ├── parser.log             # Парсер логы (5 МБ кезінде ротация)
    └── publisher.log          # Паблишер логы (5 МБ кезінде ротация)
```

### Жалпы алгоритм

```
Cron (әр 6 сағат)
    │
    ▼
parser.php іске қосылады
    │
    ├─► Блокировка тексерісі (/tmp/ctftimeparser.lock)
    │       Басқа процесс жұмыс жасаса → дереу шығу
    │
    ├─► Инициализация: config.php → Database (PDO) + CtftimeClient (cURL)
    │
    ├─► 1-қадам: ID жинау
    │       CtftimeClient → CTFTime API (келесі N күн)
    │       Алынған ID-лар → parser_buffer (INSERT IGNORE)
    │
    ├─► 2-қадам: Дедупликация
    │       ctf_events-те бар ID-ларды parser_buffer-дан жою
    │
    ├─► 3-қадам: Деректерді жүктеп сақтау (әр жаңа ID үшін)
    │       │
    │       ├─► CtftimeClient → толық оқиға деректері (JSON)
    │       │
    │       ├─► ID жолдан алынады (response body-дан емес) — спуфинг қорғанысы
    │       │
    │       ├─► ContentSecurity::sanitize()
    │       │       strip_tags + htmlspecialchars (XSS)
    │       │       SSTI/SQLi үлгілерін тексеру
    │       │       URL схемасы + SSRF тексерісі
    │       │       Өріс ұзындығы шектеулері
    │       │
    │       ├─► Санитизация сәтсіз → буферден жою, өткізіп жіберу
    │       │
    │       ├─► is_safe=0 → ctf_events-ке сақтау (жарияланбайды)
    │       │
    │       ├─► is_safe=1 → ctf_events-ке сақтау (жариялауға дайын)
    │       │
    │       └─► Сұраулар арасында 1 секунд үзіліс
    │
    ├─► Лог жазылады (5 МБ-тан асса — ротация)
    │
    └─► Блокировка босатылады (бұзылу болса — OS автоматты босатады)

publisher.php (cron, күн сайын 07:00)
    │
    ├─► Блокировка тексерісі (/tmp/ctftimepublisher.lock)
    │
    ├─► Инициализация: config.php → Database + TelegramBot
    │
    ├─► Дүйсенбі → Апталық дайджест
    │       Database::getUpcomingEvents(14)
    │       Formatter::digest() → 1..N бөлік (≤4096 таңба)
    │       TelegramBot::sendMessage() → барлық бөліктер жіберіледі
    │       posted_at БЕЛГІЛЕНБЕЙДІ (оқиғалар күнделікті жаңартуда да шығады)
    │
    └─► Сейсенбі–Жексенбі → Күнделікті жаңартулар
            ctf_events WHERE is_safe=1 AND posted_at IS NULL
            Formatter::event() → толық Telegram HTML хабары
            TelegramBot::sendMessage() → жіберу → posted_at = NOW()
            Сәтсіз жіберулер → келесі іске қосуда қайталанады
```

**Дүйсенбілік дайджест форматы:**
```
📋 CTF Events — next 14 days
26 Mar – 09 Apr 2026

• SomeCTF 2026
  📅 28 Mar — 30 Mar | Jeopardy | Online
```

**Күнделікті жаңарту форматы:**
```
🚩 SomeCTF 2026

📅 28 Mar — 30 Mar 2026 (UTC)
🏆 Jeopardy | Weight: 25.50
🌐 Online

Оқиға сипаттамасы…

🔗 Event site  ·  CTFTime
```

### Ақаулықтарды жою

**`logs/` каталогына жазу кезінде `Permission denied`**
```bash
chmod 755 logs/
```

**`PDO connection failed` / `Access denied for user`**
- `config.php` деректерін тексеріңіз
- Пайдаланушы артықшылықтарын растаңыз: `SHOW GRANTS FOR 'ctfparser'@'localhost';`

**`Could not create lock file`**
- `/tmp` каталогының PHP процесіне жазу рұқсаты бар екенін тексеріңіз

**Парсер іске қосылғаннан кейін оқиғалар пайда болмайды**
- CTFTime API келесі 14 күнде оқиға жоқ болса бос жауап қайтарады — бұл қалыпты
- `logs/parser.log` файлындағы API қателерін тексеріңіз

**Publisher ештеңе жібермейді**
- Алдымен парсерді іске қосыңыз (`ctf_events` кестесін толтыру үшін)
- `is_safe = 1` және `posted_at IS NULL` бар жолдардың бар екенін тексеріңіз
- `config.php`-тегі бот токені мен chat/thread ID дұрыстығын тексеріңіз

**`Another instance is already running`**
- `flock()` процесс аяқталғанда автоматты босатылады — ағымдағы іске қосу аяқталғанша күтіңіз немесе `php parser.php` / `php publisher.php` процесі жоқ екенін тексеріңіз

---

## Русский

**CTFTimeParser** — лёгкий PHP + MySQL инструмент, который собирает объявления о предстоящих CTF-соревнованиях с [CTFTime](https://ctftime.org/), сохраняет их в базу данных и публикует в Telegram-супергруппе.

### Возможности

- Автоматический сбор событий через CTFTime API
- Двухэтапный буфер: дедупликация перед загрузкой деталей
- Проверки безопасности контента: XSS, SSTI, SQLi, SSRF, подозрительные URL
- Небезопасные события сохраняются с флагом `is_safe=0` и не публикуются
- **Еженедельный дайджест** — каждый понедельник в 07:00, список всех событий на 14 дней
- **Ежедневные обновления** — каждый день в 07:00, полный пост для каждого нового события
- Без внешних зависимостей — чистый PHP 8 + PDO + cURL
- Атомарные блокировки предотвращают параллельный запуск cron-задач
- Файловое логирование с автоматической ротацией при 5 МБ

### Требования

- PHP 8.0+
- MySQL 8.0+ (или MariaDB 10.5+)
- PHP-расширения: `pdo_mysql`, `curl`, `mbstring`

### Установка

#### 1. Создание базы данных и пользователя

```sql
CREATE DATABASE ctftimeparser CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Выделенный пользователь с минимальными привилегиями, не root
CREATE USER 'ctfparser'@'localhost' IDENTIFIED BY 'strong_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON ctftimeparser.* TO 'ctfparser'@'localhost';
```

Применить схему:

```bash
mysql -u ctfparser -p ctftimeparser < schema.sql
```

#### 2. Создание Telegram-бота

1. Откройте [@BotFather](https://t.me/BotFather) в Telegram и отправьте `/newbot`
2. Скопируйте полученный **токен бота**
3. Добавьте бота в супергруппу как **администратора** с правом публикации сообщений
4. Включите **Topics** в настройках группы, создайте тему для анонсов
5. Перешлите любое сообщение из этой темы боту [@JsonDumpBot](https://t.me/JsonDumpBot) — найдите значение `message_thread_id`

#### 3. Конфигурация

```bash
cp config.php.sample config.php
```

Заполните `config.php`:

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

#### 4. Запуск через cron

```
# Собирать события с CTFTime каждые 6 часов
0 */6 * * * /usr/bin/php /path/to/parser.php

# Публиковать в Telegram каждый день в 07:00
0 7   * * * /usr/bin/php /path/to/publisher.php
```

### Структура проекта

```
CTFTimeParser/
├── parser.php                 # CTFTime → MySQL (запускается через cron)
├── publisher.php              # MySQL → Telegram (запускается через cron)
├── config.php                 # Настройки и учётные данные (gitignored)
├── config.php.sample          # Шаблон конфигурации
├── schema.sql                 # Схема базы данных
├── src/
│   ├── CtftimeClient.php      # Клиент CTFTime API (cURL)
│   ├── ContentSecurity.php    # Проверки безопасности контента
│   ├── Database.php           # Обёртка PDO, все запросы
│   ├── Formatter.php          # Форматтер Telegram HTML-сообщений
│   └── TelegramBot.php        # Клиент Telegram Bot API (cURL)
└── logs/
    ├── parser.log             # Лог парсера (ротация при 5 МБ)
    └── publisher.log          # Лог паблишера (ротация при 5 МБ)
```

### Общий алгоритм

```
Cron (каждые 6 часов)
    │
    ▼
parser.php запускается
    │
    ├─► Проверка блокировки (/tmp/ctftimeparser.lock)
    │       Другой процесс работает → немедленный выход
    │
    ├─► Инициализация: config.php → Database (PDO) + CtftimeClient (cURL)
    │
    ├─► Шаг 1: Сбор ID
    │       CtftimeClient → CTFTime API (следующие N дней)
    │       Полученные ID → parser_buffer (INSERT IGNORE)
    │
    ├─► Шаг 2: Дедупликация
    │       Удалить из parser_buffer ID, уже есть в ctf_events
    │
    ├─► Шаг 3: Загрузка и сохранение (для каждого нового ID)
    │       │
    │       ├─► CtftimeClient → полные данные события (JSON)
    │       │
    │       ├─► ID берётся из пути запроса (не из тела ответа) — защита от спуфинга
    │       │
    │       ├─► ContentSecurity::sanitize()
    │       │       strip_tags + htmlspecialchars (XSS)
    │       │       Проверка паттернов SSTI/SQLi
    │       │       Валидация схемы URL + проверка SSRF
    │       │       Ограничения длины полей
    │       │
    │       ├─► Санитизация не прошла → удалить из буфера, пропустить
    │       │
    │       ├─► is_safe=0 → сохранить в ctf_events (не публикуется)
    │       │
    │       ├─► is_safe=1 → сохранить в ctf_events (готово к публикации)
    │       │
    │       └─► Пауза 1 секунда между запросами
    │
    ├─► Запись в лог (ротация при превышении 5 МБ)
    │
    └─► Блокировка снимается (при краше — OS снимает автоматически)

publisher.php (cron, каждый день в 07:00)
    │
    ├─► Проверка блокировки (/tmp/ctftimepublisher.lock)
    │
    ├─► Инициализация: config.php → Database + TelegramBot
    │
    ├─► Понедельник → Еженедельный дайджест
    │       Database::getUpcomingEvents(14)
    │       Formatter::digest() → 1..N частей (≤4096 символов каждая)
    │       TelegramBot::sendMessage() → отправка всех частей
    │       posted_at НЕ устанавливается (события появятся в ежедневных обновлениях)
    │
    └─► Вт–Вс → Ежедневные обновления
            ctf_events WHERE is_safe=1 AND posted_at IS NULL
            Formatter::event() → полное HTML-сообщение для Telegram
            TelegramBot::sendMessage() → отправка → posted_at = NOW()
            Неудачные отправки → остаются неопубликованными, повторяются при следующем запуске
```

**Формат дайджеста (понедельник):**
```
📋 CTF Events — next 14 days
26 Mar – 09 Apr 2026

• SomeCTF 2026
  📅 28 Mar — 30 Mar | Jeopardy | Online
```

**Формат ежедневного обновления:**
```
🚩 SomeCTF 2026

📅 28 Mar — 30 Mar 2026 (UTC)
🏆 Jeopardy | Weight: 25.50
🌐 Online

Краткое описание события…

🔗 Event site  ·  CTFTime
```

### Схема базы данных

#### `parser_buffer`

| Столбец | Тип | Описание |
|---------|-----|----------|
| `event_id` | INT UNSIGNED PK | ID события CTFTime |
| `created_at` | DATETIME | Время создания записи |

#### `ctf_events`

| Столбец | Тип | Описание |
|---------|-----|----------|
| `id` | INT UNSIGNED PK | ID события CTFTime |
| `title` | VARCHAR(255) | Название события |
| `url` | VARCHAR(512) | Официальный сайт события |
| `ctftime_url` | VARCHAR(512) | Страница события на CTFTime |
| `start_time` | DATETIME | Начало (UTC) |
| `finish_time` | DATETIME | Конец (UTC) |
| `format` | VARCHAR(64) | Jeopardy / Attack-Defense / и др. |
| `weight` | DECIMAL(8,5) | Рейтинговый вес CTFTime |
| `onsite` | TINYINT(1) | 1 = очное мероприятие |
| `location` | VARCHAR(255) | Город/страна для очных событий |
| `description` | TEXT | Описание события |
| `logo_url` | VARCHAR(512) | URL логотипа |
| `is_safe` | TINYINT(1) | 0 = помечено проверкой безопасности |
| `posted_at` | DATETIME | NULL = ещё не опубликовано в Telegram |
| `created_at` | DATETIME | Время создания записи |

### Безопасность

Аудит по **[OWASP Top 10:2025](https://owasp.org/Top10/2025/)**.

| OWASP | Угроза | Защита |
|---|---|---|
| A01 | Broken Access Control / SSRF | `CURLOPT_FOLLOWLOCATION=false` во всех cURL-запросах; только HTTPS; URL API захардкожен; учётные данные в URL отклоняются; проверка приватных IP-диапазонов |
| A02 | Security Misconfiguration | Выделенный пользователь БД с минимальными привилегиями; `config.php` исключён из VCS; токен бота не пишется в логи |
| A05 | Injection — SQLi | PDO prepared statements повсюду; никакой интерполяции пользовательских данных |
| A05 | Injection — SSTI | Regex-детекция паттернов `{{ }}`, `{% %}`, `<% %>`, `${}`, `#{}` |
| A05 | Injection — XSS | `strip_tags()` + `htmlspecialchars()` на всех строковых полях до сохранения и вывода |
| A06 | Insecure Design | Нет блокирующего DNS; белый список схем URL (`http`/`https`); ограничения длины полей |
| A08 | Data Integrity | ID события берётся из пути запроса, а не из тела ответа; лимит глубины JSON |
| A09 | Security Logging Failures | Структурированный лог с уровнем + временной меткой; ротация при 5 МБ; отдельные логи для каждого компонента |
| A10 | Mishandling of Exceptional Conditions | Атомарная блокировка `fopen('c')+flock(LOCK_EX\|LOCK_NB)` — нет гонки TOCTOU; OS снимает блокировку при краше; обработка Telegram 429 с `retry_after` |

### Логирование

Каждый компонент пишет в свой лог-файл, ротация при 5 МБ:

**`logs/parser.log`**
```
[2026-03-26 12:00:00] [INFO] Fetching event list [2026-03-26 – 2026-04-09]
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

### Устранение неполадок

**`Permission denied` при записи в `logs/`**
```bash
chmod 755 logs/
```

**`PDO connection failed` / `Access denied for user`**
- Проверьте учётные данные в `config.php`
- Убедитесь в правах пользователя: `SHOW GRANTS FOR 'ctfparser'@'localhost';`

**`Could not create lock file`**
- Проверьте права на запись в `/tmp` для PHP-процесса

**После запуска парсера события не появляются**
- CTFTime API возвращает пустой список, если нет событий на 14 дней — это нормально
- Проверьте `logs/parser.log` на наличие ошибок API

**Publisher ничего не отправляет**
- Сначала запустите парсер, чтобы заполнить `ctf_events`
- Убедитесь, что есть строки с `is_safe = 1` и `posted_at IS NULL`
- Проверьте токен бота и chat/thread IDs в `config.php`

**`Another instance is already running`**
- `flock()` снимается автоматически при завершении процесса — дождитесь окончания текущего запуска или убедитесь, что `php parser.php` / `php publisher.php` не запущен

---

## English

**CTFTimeParser** is a lightweight PHP + MySQL parser that fetches upcoming CTF event announcements from [CTFTime](https://ctftime.org/) and stores them for publication in a Telegram supergroup topic.

### Features

- Fetches events via the CTFTime public API
- Two-stage buffer pipeline — deduplication before fetching details
- Content security checks: XSS, SSTI, SQLi, SSRF, suspicious URLs
- Unsafe events are stored with `is_safe=0` and held back from publication
- **Weekly digest** every Monday at 07:00 — compact list of all events for the next 14 days
- **Daily updates** every day at 07:00 — individual full-detail posts for new events
- No external dependencies — pure PHP 8 + PDO + cURL
- Atomic lock files prevent overlapping cron runs
- File-based logging with automatic rotation at 5 MB

### Requirements

- PHP 8.0+
- MySQL 8.0+ (or MariaDB 10.5+)
- PHP extensions: `pdo_mysql`, `curl`, `mbstring`

### Setup

#### 1. Create the database and user

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

### Project Structure

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

### Overall Algorithm

```
Cron (every 6 hours)
    │
    ▼
parser.php runs
    │
    ├─► Lock check (/tmp/ctftimeparser.lock)
    │       Another instance running → exit immediately
    │
    ├─► Bootstrap: config.php → Database (PDO) + CtftimeClient (cURL)
    │
    ├─► Step 1: Collect IDs
    │       CtftimeClient → CTFTime API (next N days)
    │       Received IDs → parser_buffer (INSERT IGNORE)
    │
    ├─► Step 2: Deduplicate
    │       Remove from parser_buffer any IDs already in ctf_events
    │
    ├─► Step 3: Fetch and store (for each new ID)
    │       │
    │       ├─► CtftimeClient → full event details (JSON)
    │       │
    │       ├─► Event ID taken from request path (not response body) — anti-spoofing
    │       │
    │       ├─► ContentSecurity::sanitize()
    │       │       strip_tags + htmlspecialchars (XSS)
    │       │       SSTI / SQLi pattern detection
    │       │       URL scheme validation + SSRF checks
    │       │       Field length limits
    │       │
    │       ├─► Sanitization failed → delete from buffer, skip
    │       │
    │       ├─► is_safe=0 → store in ctf_events (withheld from publication)
    │       │
    │       ├─► is_safe=1 → store in ctf_events (ready to publish)
    │       │
    │       └─► 1-second pause between requests
    │
    ├─► Write to log (rotate when file exceeds 5 MB)
    │
    └─► Lock released (OS releases automatically on crash)

publisher.php (cron daily at 07:00)
    │
    ├─► Lock check (/tmp/ctftimepublisher.lock)
    │
    ├─► Bootstrap: config.php → Database + TelegramBot
    │
    ├─► Monday → Weekly digest
    │       Database::getUpcomingEvents(14)
    │       Formatter::digest() → 1..N message parts (≤4096 chars each)
    │       TelegramBot::sendMessage() → send all parts
    │       posted_at is NOT set (events still appear in daily updates)
    │
    └─► Tue–Sun → Daily updates
            ctf_events WHERE is_safe=1 AND posted_at IS NULL
            Formatter::event() → full Telegram HTML message
            TelegramBot::sendMessage() → send → posted_at = NOW()
            Failed sends → left unpublished, retried next run
```

**Monday digest format:**
```
📋 CTF Events — next 14 days
26 Mar – 09 Apr 2026

• SomeCTF 2026
  📅 28 Mar — 30 Mar | Jeopardy | Online
```

**Daily update format:**
```
🚩 SomeCTF 2026

📅 28 Mar — 30 Mar 2026 (UTC)
🏆 Jeopardy | Weight: 25.50
🌐 Online

Brief description…

🔗 Event site  ·  CTFTime
```

### Database Schema

#### `parser_buffer`

| Column | Type | Description |
|--------|------|-------------|
| `event_id` | INT UNSIGNED PK | CTFTime event ID |
| `created_at` | DATETIME | Row creation time |

#### `ctf_events`

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT UNSIGNED PK | CTFTime event ID |
| `title` | VARCHAR(255) | Event name |
| `url` | VARCHAR(512) | Official event website |
| `ctftime_url` | VARCHAR(512) | CTFTime event page |
| `start_time` | DATETIME | Start (UTC) |
| `finish_time` | DATETIME | End (UTC) |
| `format` | VARCHAR(64) | Jeopardy / Attack-Defense / etc. |
| `weight` | DECIMAL(8,5) | CTFTime rating weight |
| `onsite` | TINYINT(1) | 1 = on-site event |
| `location` | VARCHAR(255) | City/country for on-site events |
| `description` | TEXT | Event description |
| `logo_url` | VARCHAR(512) | Event logo URL |
| `is_safe` | TINYINT(1) | 0 = flagged by security checks |
| `posted_at` | DATETIME | NULL = not yet published to Telegram |
| `created_at` | DATETIME | Row creation time |

Events with `is_safe = 0` are stored but **not published** until manually reviewed.

### Security

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

### Logging

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

### Troubleshooting

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
- CTFTime API returns an empty list if no events are scheduled in the next 14 days — this is normal
- Check `logs/parser.log` for API errors

**Publisher sends nothing**
- Run the parser first so `ctf_events` is populated
- Check that `is_safe = 1` and `posted_at IS NULL` for at least one row
- Verify the bot token and chat/thread IDs in `config.php`

**`Another instance is already running`**
- `flock()` releases automatically when the process exits — wait for the current run to finish or verify no `php parser.php` / `php publisher.php` process is running
