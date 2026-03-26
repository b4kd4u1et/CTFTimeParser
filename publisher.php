<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Formatter.php';
require_once __DIR__ . '/src/TelegramBot.php';
require_once __DIR__ . '/src/Logger.php';

$config = require __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Logger — same pattern as parser.php (rotation at 5 MB, A09:2025)
// ---------------------------------------------------------------------------

$logFile = $config['publisher_log_file'];

$log = fn(string $level, string $msg) => log_msg($level, $msg, $logFile);

// ---------------------------------------------------------------------------
// Lock file — atomic, no TOCTOU (A10:2025), same pattern as parser.php
// ---------------------------------------------------------------------------

$lockFile   = sys_get_temp_dir() . '/ctftimepublisher.lock';
$lockHandle = fopen($lockFile, 'c');

if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $log('warn', 'Another instance is already running. Exiting.');
    if (is_resource($lockHandle)) {
        fclose($lockHandle);
    }
    exit(0);
}

ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) getmypid());
fflush($lockHandle);

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$exitCode = 0;

try {
    $db  = new Database($config['db']);
    $bot = new TelegramBot($config['telegram']);
    $cfg = $config['telegram'];

    // ISO-8601 day number: 1 = Monday … 7 = Sunday
    $isMonday = (int) date('N') === 1;

    if ($isMonday) {
        // -----------------------------------------------------------------
        // Monday — weekly digest: one compact summary for the next 14 days.
        // posted_at is NOT set; events will still appear in daily updates.
        // -----------------------------------------------------------------

        $events = $db->getUpcomingEvents(14);
        $count  = count($events);

        if ($count === 0) {
            $log('info', 'Weekly digest: no upcoming events in the next 14 days.');
        } else {
            $parts = Formatter::digest($events, 14);
            $total = count($parts);

            foreach ($parts as $i => $part) {
                if (!$bot->sendMessage($part)) {
                    $log('warn', sprintf(
                        'Weekly digest: failed to send part %d/%d.',
                        $i + 1,
                        $total
                    ));
                }

                // Brief pause between digest parts when message was split
                if ($i < $total - 1) {
                    sleep(1);
                }
            }

            $log('info', sprintf(
                'Weekly digest sent: %d event(s) in %d message(s).',
                $count,
                $total
            ));
        }
    } else {
        // -----------------------------------------------------------------
        // Tue–Sun — daily updates: one full message per unpublished event.
        // posted_at is set after each successful send.
        // Failed events are left unpublished and retried on the next run.
        // -----------------------------------------------------------------

        $events = $db->getUnpublishedEvents();
        $total  = count($events);

        if ($total === 0) {
            $log('info', 'Daily update: no unpublished events.');
        } else {
            $log('info', sprintf('Daily update: %d event(s) to publish.', $total));

            $sent   = 0;
            $failed = 0;

            foreach ($events as $i => $event) {
                $text = Formatter::event($event);

                if ($bot->sendMessage($text)) {
                    $db->markAsPosted((int) $event['id']);
                    $log('info', sprintf(
                        'Published event #%d: "%s"',
                        $event['id'],
                        $event['title']
                    ));
                    $sent++;
                } else {
                    $log('warn', sprintf(
                        'Failed to send event #%d ("%s") — will retry on next run.',
                        $event['id'],
                        $event['title']
                    ));
                    $failed++;
                }

                if ($i < $total - 1) {
                    sleep($cfg['sleep_between_messages']);
                }
            }

            $log('info', sprintf(
                'Daily update done. Sent: %d | Failed: %d',
                $sent,
                $failed
            ));
        }
    }

} catch (PDOException $e) {
    $log('error', 'Database error: ' . $e->getMessage());
    $exitCode = 1;
} catch (Throwable $e) {
    $log('error', 'Unexpected error: ' . $e->getMessage());
    $exitCode = 1;
} finally {
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @unlink($lockFile);
    }
}

exit($exitCode);
