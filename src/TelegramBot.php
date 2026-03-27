<?php

declare(strict_types=1);

class TelegramBot
{
    private const BASE_URL   = 'https://api.telegram.org/bot';
    private const TIMEOUT    = 10;
    private const MAX_RETRIES = 1;

    private string $token;
    private string $chatId;
    private int    $threadId;

    public function __construct(array $config)
    {
        $this->token    = (string) $config['bot_token'];
        $this->chatId   = (string) $config['chat_id'];
        $this->threadId = (int)    $config['thread_id'];
    }

    /**
     * Send an HTML message to the configured supergroup topic.
     *
     * On HTTP 429 (Telegram rate limit) reads `retry_after` from the response,
     * sleeps for that duration, then retries once.
     * All other errors return false — the caller logs and continues.
     *
     * @param string $text  Telegram HTML-formatted message text
     * @return bool         true on success, false on any unrecoverable error
     */
    public function sendMessage(string $text): bool
    {
        $params = [
            'chat_id'                  => $this->chatId,
            'message_thread_id'        => $this->threadId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => false,
        ];

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response = $this->post('sendMessage', $params);

            if ($response === null) {
                return false;
            }

            if (!empty($response['ok'])) {
                return true;
            }

            $errorCode = (int) ($response['error_code'] ?? 0);

            // Rate limited — honour the retry_after window and retry once
            if ($errorCode === 429) {
                $retryAfter = (int) ($response['parameters']['retry_after'] ?? 5);
                sleep($retryAfter + 1);
                continue;
            }

            // Any other Telegram API error — fail immediately
            return false;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Send an HTTPS POST request to the Telegram Bot API.
     *
     * Security:
     * - URL is assembled from a hardcoded constant; bot token is never logged
     * - CURLOPT_FOLLOWLOCATION=false: no open-redirect / SSRF via 3xx responses
     * - SSL_VERIFYPEER + SSL_VERIFYHOST: enforces full TLS certificate validation
     *
     * For HTTP 429 the body is parsed even on non-200 status, because the
     * rate-limit response contains the `retry_after` field we need.
     *
     * @return array|null  Decoded JSON response, or null on transport/parse error
     */
    private function post(string $method, array $params): ?array
    {
        $url = self::BASE_URL . $this->token . '/' . $method;

        $ch = curl_init();
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error !== '' || !is_string($body)) {
            return null;
        }

        // Parse body for 200 (success) and 429 (rate limit needs retry_after)
        if ($status !== 200 && $status !== 429) {
            return null;
        }

        try {
            return json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
