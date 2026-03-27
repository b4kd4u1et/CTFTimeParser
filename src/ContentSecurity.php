<?php

declare(strict_types=1);

class ContentSecurity
{
    // Maximum field lengths
    private const MAX_TITLE_LEN    = 255;
    private const MAX_URL_LEN      = 512;
    private const MAX_FORMAT_LEN   = 64;
    private const MAX_LOCATION_LEN = 255;
    private const MAX_DESC_LEN     = 65535;

    // Allowed URL schemes
    private const ALLOWED_SCHEMES = ['http', 'https'];

    // SSTI pattern fragments to reject
    private const SSTI_PATTERNS = [
        '/\{\{.*?\}\}/s',    // Jinja2 / Twig
        '/\{%.*?%\}/s',      // Jinja2 / Twig tags
        '/<%.*?%>/s',        // ERB / ASP
        '/\$\{.*?\}/s',      // Freemarker / Thymeleaf
        '/#\{.*?\}/s',       // Groovy / Ruby
    ];

    // SQLi indicator patterns (extra guard; PDO prepared statements are the primary defence)
    private const SQLI_PATTERNS = [
        '/(\bunion\b.*\bselect\b|\bselect\b.*\bfrom\b|\bdrop\b.*\btable\b)/i',
        '/--\s*$/',
        '/;\s*(drop|insert|update|delete|truncate)\s/i',
    ];

    /**
     * Validate and sanitize a raw API event array.
     * Returns a clean array with `is_safe` flag, or null if the data is invalid.
     *
     * @param array $raw   Decoded JSON from CTFTime API
     * @return array|null
     */
    public static function sanitize(array $raw): ?array
    {
        // id must be a positive integer
        $id = filter_var($raw['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            return null;
        }

        // title is required
        $title = self::sanitizeString((string) ($raw['title'] ?? ''), self::MAX_TITLE_LEN);
        if ($title === '') {
            return null;
        }

        $isSafe = true;

        // Check string fields for SSTI / SQLi
        foreach (['title', 'description', 'location'] as $field) {
            $value = (string) ($raw[$field] ?? '');
            if (!self::isSafeString($value)) {
                $isSafe = false;
            }
        }

        // Sanitize optional string fields
        $description = self::sanitizeString((string) ($raw['description'] ?? ''), self::MAX_DESC_LEN);
        $format      = self::sanitizeString((string) ($raw['format']      ?? ''), self::MAX_FORMAT_LEN);
        $location    = self::sanitizeString((string) ($raw['location']    ?? ''), self::MAX_LOCATION_LEN);

        // Sanitize and validate URLs
        $url        = self::sanitizeUrl($raw['url']        ?? null, allowAnyDomain: true);
        $ctftimeUrl = self::sanitizeUrl($raw['ctftime_url'] ?? null, allowAnyDomain: false);
        $logoUrl    = self::sanitizeUrl($raw['logo']        ?? null, allowAnyDomain: true);

        // Flag suspicious URLs
        if (($raw['url'] ?? '') !== '' && $url === null) {
            $isSafe = false;
        }

        // Timestamps
        $startTime  = self::sanitizeTimestamp($raw['start']  ?? null);
        $finishTime = self::sanitizeTimestamp($raw['finish'] ?? null);

        // Numeric fields
        $weight = isset($raw['weight']) && is_numeric($raw['weight'])
            ? round((float) $raw['weight'], 5)
            : null;

        $onsite = isset($raw['onsite']) ? (bool) $raw['onsite'] : false;

        return [
            'id'          => $id,
            'title'       => $title,
            'url'         => $url,
            'ctftime_url' => $ctftimeUrl,
            'start_time'  => $startTime,
            'finish_time' => $finishTime,
            'format'      => $format ?: null,
            'weight'      => $weight,
            'onsite'      => $onsite,
            'location'    => $location ?: null,
            'description' => $description ?: null,
            'logo_url'    => $logoUrl,
            'is_safe'     => $isSafe,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Strip HTML/XSS, remove null bytes, enforce max length.
     */
    private static function sanitizeString(string $value, int $maxLen): string
    {
        // Reject non-UTF-8 or strings containing null bytes
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            return '';
        }

        // Strip all HTML tags (XSS)
        $value = strip_tags($value);

        // Collapse excessive whitespace
        $value = trim(preg_replace('/\s{3,}/', '  ', $value) ?? $value);

        return mb_substr($value, 0, $maxLen);
    }

    /**
     * Validate and return a safe URL, or null if it fails checks.
     *
     * @param bool $allowAnyDomain  When false, the domain must end with ctftime.org.
     */
    private static function sanitizeUrl(mixed $value, bool $allowAnyDomain): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        // Length guard
        if (strlen($value) > self::MAX_URL_LEN) {
            return null;
        }

        // Null bytes or whitespace tricks
        if (str_contains($value, "\0") || preg_match('/\s/', $value)) {
            return null;
        }

        // PHP URL validation
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parsed = parse_url($value);
        if (!$parsed) {
            return null;
        }

        // Scheme must be http or https
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return null;  // Blocks javascript:, data:, ftp:, etc.
        }

        // No credentials in URL (SSRF / info-leak guard)
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return null;
        }

        $host = strtolower($parsed['host'] ?? '');

        if (!$allowAnyDomain) {
            // Must be ctftime.org
            if ($host !== 'ctftime.org' && !str_ends_with($host, '.ctftime.org')) {
                return null;
            }
        } else {
            // Reject obviously dangerous/internal hosts
            if (self::isInternalHost($host)) {
                return null;
            }
        }

        return $value;
    }

    /**
     * Convert an ISO-8601 or RFC-3339 timestamp string to MySQL DATETIME string.
     */
    private static function sanitizeTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $ts = strtotime($value);
        if ($ts === false || $ts <= 0) {
            return null;
        }

        // Sanity range: 2000-01-01 to 2100-01-01
        if ($ts < 946684800 || $ts > 4102444800) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Check a string for SSTI and SQLi patterns.
     */
    private static function isSafeString(string $value): bool
    {
        foreach (self::SSTI_PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                return false;
            }
        }

        foreach (self::SQLI_PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Detect localhost, LAN ranges, and metadata endpoints (SSRF guard).
     *
     * A06:2025 — DNS resolution via gethostbyname() is intentionally omitted
     * for hostnames. It is a blocking call with no configurable timeout and can
     * stall the cron process for many seconds per event on slow/broken DNS.
     * Scheme + format validation performed earlier is sufficient for our threat
     * model; we only resolve literal IP strings that arrive in the host field.
     */
    private static function isInternalHost(string $host): bool
    {
        // localhost variations
        if (in_array($host, ['localhost', '::1', '[::1]'], true)) {
            return true;
        }

        // Cloud metadata endpoints (AWS IMDSv1/v2, GCP, Azure)
        if (in_array($host, ['169.254.169.254', 'metadata.google.internal'], true)) {
            return true;
        }

        // If the host is a literal IP address, check for private/reserved ranges
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        // Hostname (not a bare IP) — allow; no DNS lookup performed.
        return false;
    }
}
