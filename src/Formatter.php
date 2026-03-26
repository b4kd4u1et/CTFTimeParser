<?php

declare(strict_types=1);

class Formatter
{
    // Maximum description length shown in the message
    private const DESC_PREVIEW_LEN = 300;

    // Telegram HTML message character limit
    private const TELEGRAM_MAX_LEN = 4096;

    /**
     * Format a ctf_events row into an HTML string for Telegram.
     *
     * Telegram supports a limited HTML subset:
     * <b>, <i>, <u>, <s>, <code>, <pre>, <a href="...">.
     * All user-supplied strings must be passed through e() before output.
     *
     * @param  array  $event  Row from ctf_events table
     * @return string         Ready-to-send HTML message
     */
    public static function event(array $event): string
    {
        $lines = [];

        // --- Title ---
        $lines[] = '🚩 <b>' . self::e((string) ($event['title'] ?? '')) . '</b>';
        $lines[] = '';

        // --- Dates ---
        $dates = self::formatDates($event['start_time'], $event['finish_time']);
        if ($dates !== null) {
            $lines[] = '📅 ' . $dates;
        }

        // --- Format + Weight ---
        $meta = self::formatMeta($event['format'], $event['weight']);
        if ($meta !== null) {
            $lines[] = '🏆 ' . $meta;
        }

        // --- Location ---
        if (!empty($event['onsite']) && !empty($event['location'])) {
            $lines[] = '📍 ' . self::e($event['location']);
        } else {
            $lines[] = '🌐 Online';
        }

        // --- Description preview ---
        if (!empty($event['description'])) {
            $preview = self::truncate((string) $event['description'], self::DESC_PREVIEW_LEN);
            if ($preview !== '') {
                $lines[] = '';
                $lines[] = self::e($preview);
            }
        }

        // --- Links ---
        $links = self::formatLinks($event['url'], $event['ctftime_url']);
        if ($links !== null) {
            $lines[] = '';
            $lines[] = $links;
        }

        return implode("\n", $lines);
    }

    /**
     * Format a list of ctf_events rows into a compact weekly digest.
     *
     * Returns an array of Telegram-ready HTML strings. If the combined text
     * would exceed Telegram's 4096-character message limit the digest is split
     * into multiple parts; each continuation part gets its own header.
     *
     * posted_at is NOT touched — the digest is a read-only summary.
     *
     * @param  array  $events  Rows from ctf_events, ordered by start_time ASC
     * @param  int    $days    Lookahead window (used only for the header label)
     * @return string[]        One or more ready-to-send HTML message parts
     */
    public static function digest(array $events, int $days = 14): array
    {
        if (empty($events)) {
            return [];
        }

        $now   = time();
        $until = $now + ($days * 86400);

        $header = sprintf(
            "📋 <b>CTF Events — next %d days</b>\n<i>%s – %s</i>",
            $days,
            gmdate('d M Y', $now),
            gmdate('d M Y', $until)
        );
        $contHeader = '📋 <b>CTF Events (cont.)</b>';

        // Build compact per-event line
        $items = [];
        foreach ($events as $event) {
            $title = self::e((string) ($event['title'] ?? ''));
            $link  = !empty($event['ctftime_url'])
                ? '<a href="' . self::e((string) $event['ctftime_url']) . '">' . $title . '</a>'
                : $title;

            $meta = [];
            $dates = self::formatCompactDates($event['start_time'], $event['finish_time']);
            if ($dates !== null) {
                $meta[] = $dates;
            }
            if (!empty($event['format'])) {
                $meta[] = self::e((string) $event['format']);
            }
            if (!empty($event['onsite']) && !empty($event['location'])) {
                $meta[] = self::e((string) $event['location']);
            } else {
                $meta[] = 'Online';
            }

            $items[] = '• ' . $link . "\n  📅 " . implode(' | ', $meta);
        }

        // Pack items into Telegram message parts (≤ TELEGRAM_MAX_LEN chars each)
        $parts         = [];
        $currentHeader = $header;
        $currentBody   = '';

        foreach ($items as $item) {
            $candidate = $currentBody === ''
                ? $currentHeader . "\n\n" . $item
                : $currentHeader . "\n\n" . $currentBody . "\n\n" . $item;

            if (mb_strlen($candidate) > self::TELEGRAM_MAX_LEN && $currentBody !== '') {
                // Current part is full — flush and start a new one
                $parts[]       = $currentHeader . "\n\n" . $currentBody;
                $currentHeader = $contHeader;
                $currentBody   = $item;
            } else {
                $currentBody = $currentBody === ''
                    ? $item
                    : $currentBody . "\n\n" . $item;
            }
        }

        if ($currentBody !== '') {
            $parts[] = $currentHeader . "\n\n" . $currentBody;
        }

        return $parts;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Escape a string for Telegram HTML mode.
     * Telegram requires &, <, > to be escaped; other entities are optional.
     */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Format start and finish datetimes into a human-readable date range.
     * Dates are stored as UTC in MySQL DATETIME format.
     *
     * Examples:
     *   "25 Mar — 27 Mar 2025 (UTC)"
     *   "25 Mar 2025 (UTC)"           ← if same day or finish missing
     */
    private static function formatDates(?string $start, ?string $finish): ?string
    {
        if ($start === null) {
            return null;
        }

        $startTs = strtotime($start);
        if ($startTs === false) {
            return null;
        }

        $startStr = gmdate('d M Y', $startTs);

        if ($finish !== null) {
            $finishTs = strtotime($finish);
            if ($finishTs !== false && $finishTs > $startTs) {
                $finishStr = gmdate('d M Y', $finishTs);
                if ($finishStr !== $startStr) {
                    return $startStr . ' — ' . $finishStr . ' (UTC)';
                }
            }
        }

        return $startStr . ' (UTC)';
    }

    /**
     * Build the format/weight meta line.
     *
     * Examples:
     *   "Jeopardy | Weight: 74.85"
     *   "Jeopardy"
     *   "Weight: 25.00"
     */
    private static function formatMeta(?string $format, mixed $weight): ?string
    {
        $parts = [];

        if (!empty($format)) {
            $parts[] = self::e($format);
        }

        if ($weight !== null && $weight > 0) {
            $parts[] = 'Weight: ' . number_format((float) $weight, 2);
        }

        return $parts ? implode(' | ', $parts) : null;
    }

    /**
     * Build the links line with HTML anchors.
     *
     * Example: "🔗 <a href="...">Event site</a>  ·  <a href="...">CTFTime</a>"
     */
    private static function formatLinks(?string $url, ?string $ctftimeUrl): ?string
    {
        $parts = [];

        if (!empty($url)) {
            $parts[] = '<a href="' . self::e($url) . '">Event site</a>';
        }

        if (!empty($ctftimeUrl)) {
            $parts[] = '<a href="' . self::e($ctftimeUrl) . '">CTFTime</a>';
        }

        return $parts ? '🔗 ' . implode('  ·  ', $parts) : null;
    }

    /**
     * Compact date range without year, used in digest lines.
     *
     * Examples:
     *   "28 Mar — 30 Mar"
     *   "02 Apr"
     */
    private static function formatCompactDates(?string $start, ?string $finish): ?string
    {
        if ($start === null) {
            return null;
        }

        $startTs = strtotime($start);
        if ($startTs === false) {
            return null;
        }

        $startStr = gmdate('d M', $startTs);

        if ($finish !== null) {
            $finishTs = strtotime($finish);
            if ($finishTs !== false && $finishTs > $startTs) {
                $finishStr = gmdate('d M', $finishTs);
                if ($finishStr !== $startStr) {
                    return $startStr . ' — ' . $finishStr;
                }
            }
        }

        return $startStr;
    }

    /**
     * Truncate a string to $maxLen characters, appending "…" if cut.
     */
    private static function truncate(string $text, int $maxLen): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxLen) {
            return $text;
        }

        return mb_substr($text, 0, $maxLen) . '…';
    }
}
