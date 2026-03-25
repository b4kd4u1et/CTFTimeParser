<?php

declare(strict_types=1);

class CtftimeClient
{
    private const BASE_URL   = 'https://ctftime.org/api/v1';
    private const USER_AGENT = 'CTFTimeParser/1.0';

    private int $timeout;

    public function __construct(int $timeout = 10)
    {
        $this->timeout = $timeout;
    }

    /**
     * Fetch the list of event IDs for the given Unix time range.
     *
     * @return int[]  Array of event IDs; empty array on failure.
     */
    public function fetchEventIds(int $start, int $finish, int $limit = 100): array
    {
        $url = sprintf(
            '%s/events/?limit=%d&start=%d&finish=%d',
            self::BASE_URL,
            $limit,
            $start,
            $finish
        );

        $body = $this->get($url);
        if ($body === null) {
            return [];
        }

        $events = $this->decodeJson($body);
        if (!is_array($events)) {
            return [];
        }

        $ids = [];
        foreach ($events as $event) {
            if (isset($event['id']) && is_int($event['id']) && $event['id'] > 0) {
                $ids[] = $event['id'];
            }
        }

        return $ids;
    }

    /**
     * Fetch full details for a single event.
     *
     * @return array|null  Raw associative array from API, or null on failure.
     */
    public function fetchEventDetail(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $url  = sprintf('%s/events/%d/', self::BASE_URL, $id);
        $body = $this->get($url);
        if ($body === null) {
            return null;
        }

        $data = $this->decodeJson($body);
        if (!is_array($data) || empty($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Perform a GET request via cURL.
     * Redirects are disabled to prevent SSRF via open redirect.
     *
     * @return string|null  Response body, or null on any error.
     */
    private function get(string $url): ?string
    {
        // Verify the URL targets ctftime.org before making any request
        if (!$this->isAllowedUrl($url)) {
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => false,   // No redirects — prevents SSRF
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return null;
        }

        if ($status !== 200) {
            return null;
        }

        return is_string($body) ? $body : null;
    }

    /**
     * Ensure the URL is within the allowed domain (ctftime.org).
     * Guards against accidental SSRF if BASE_URL is ever changed.
     */
    private function isAllowedUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (!$parsed) {
            return false;
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        $host   = strtolower($parsed['host']   ?? '');

        if ($scheme !== 'https') {
            return false;
        }

        // Only ctftime.org (exact match or subdomain)
        return $host === 'ctftime.org' || str_ends_with($host, '.ctftime.org');
    }

    /**
     * Safely decode JSON with a bounded depth.
     */
    private function decodeJson(string $body): mixed
    {
        try {
            return json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
