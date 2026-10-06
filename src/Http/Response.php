<?php

declare(strict_types=1);

namespace Framework\Http;

use Framework\Exception\FileNotReadableException;

/**
 * This class handles setting HTTP response status codes, bodies, and outbound custom headers safely.
 *
 * Also provides named constructors for common response shapes (JSON, redirects, file downloads)
 * and a fluent, chainable API for headers and cookies.
 *
 * @package Framework\Http
 */
class Response
{
    /**
     * Cookies queued to be sent with this response, keyed by cookie name.
     * Each entry holds the arguments as accepted by PHP's native setcookie().
     */
    protected array $cookies = [];

    /**
     * @param mixed $content     The response body. String/scalar for normal responses;
     *                           for file downloads this instead holds the absolute file path
     *                           (see download()) and $isFileDownload distinguishes the two.
     * @param int   $statusCode  HTTP status code.
     * @param array $headers     Response headers, keyed by name (case-insensitive).
     * @param bool  $isFileDownload Internal flag: when true, send() streams $content as a file path
     *                           instead of echoing it as body text.
     */
    public function __construct(
        protected mixed $content,
        protected int $statusCode = 200,
        protected array $headers = [],
        protected bool $isFileDownload = false
    ) {
        // Ensure that header names are normalized to lowercase for consistent handling
        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[strtolower($name)] = $value;
        }

        // Plain strings are text; use json() for structured API payloads.
        if (!$isFileDownload && !isset($normalizedHeaders['content-type']) && !is_array($content) && !is_object($content)) {
            $normalizedHeaders['content-type'] = 'text/plain; charset=UTF-8';
        }

        $this->headers = $normalizedHeaders;
    }

    /**
     * Named constructor helper for fast structural JSON API outputs.
     *
     * @param array|object $data
     * @param int $statusCode
     * @return self
     */
    public static function json(array|object $data, int $statusCode = 200): self
    {
        return new self(
            json_encode($data),
            $statusCode,
            ['Content-Type' => 'application/json']
        );
    }

    /**
     * Named constructor helper for HTTP redirects.
     *
     * @param string $url Destination URL (relative or absolute).
     * @param int $statusCode Redirect status code — 302 (temporary) by default, use 301 for permanent.
     * @return self
     */
    public static function redirect(string $url, int $statusCode = 302): self
    {
        return new self('', $statusCode, ['Location' => $url]);
    }

    /**
     * Named constructor helper for streaming a file to the client as a download.
     *
     * @param string $filePath Absolute path to the file on disk.
     * @param ?string $downloadName Filename presented to the client; defaults to the file's own basename.
     * @return self
     *
     * @throws FileNotReadableException If the file does not exist or is not readable.
     */
    public static function download(string $filePath, ?string $downloadName = null): self
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new FileNotReadableException("500 File Not Readable: '{$filePath}' does not exist or is not readable.");
        }

        $downloadName ??= basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        return new self($filePath, 200, [
            'Content-Type'        => $mimeType,
            'Content-Length'      => (string) filesize($filePath),
            'Content-Disposition' => self::contentDispositionValue($downloadName),
        ], isFileDownload: true);
    }

    /**
     * Builds a safe Content-Disposition header value for a given download filename.
     *
     * $downloadName may end up being caller-supplied (e.g. an originally uploaded filename),
     * so it can't be trusted to already be a well-formed header value. This strips characters
     * that could break out of the quoted filename or inject header content (quotes, backslash,
     * CR/LF), and additionally emits an RFC 6266 filename* fallback so non-ASCII names still
     * come through correctly for clients that support it.
     *
     * @param string $downloadName
     * @return string
     */
    private static function contentDispositionValue(string $downloadName): string
    {
        $safeName = str_replace(["\r", "\n", '"', '\\'], '', $downloadName);
        $encoded  = rawurlencode($downloadName);

        return sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            $safeName,
            $encoded
        );
    }

    /**
     * Add a custom response header to the response.
     * This method allows you to set a specific header name and value for the HTTP response.
     * @param string $name The name of the header to set.
     * @param string $value The value of the header to set.
     * @return self Returns the current Response instance for method chaining.
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }

    /**
     * Remove a custom response header from the response.
     * This method allows you to remove a specific header from the HTTP response.
     * @param string $name The name of the header to remove.
     * @return self Returns the current Response instance for method chaining.
     */
    public function withoutHeader(string $name): self
    {
        unset($this->headers[strtolower($name)]);
        return $this;
    }

    /**
     * Queues a cookie to be sent with this response.
     *
     * @param string $name
     * @param string $value
     * @param int $expires Unix timestamp when the cookie expires; 0 means "until browser session ends".
     * @param string $path
     * @param string $domain
     * @param bool $secure Only send the cookie over HTTPS.
     * @param bool $httpOnly Hide the cookie from JavaScript (recommended for session/auth cookies).
     * @param string $sameSite 'Lax', 'Strict', or 'None'.
     * @return self Returns the current Response instance for method chaining.
     */
    public function withCookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
        $this->cookies[$name] = [
            'value'    => $value,
            'expires'  => $expires,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
        ];

        return $this;
    }

    /**
     * Queues a cookie for deletion by expiring it in the past.
     *
     * @param string $name
     * @param string $path
     * @param string $domain
     * @return self Returns the current Response instance for method chaining.
     */
    public function withoutCookie(string $name, string $path = '/', string $domain = ''): self
    {
        return $this->withCookie($name, '', time() - 3600, $path, $domain);
    }

    /**
     * Get the current HTTP status code of the response.
     * @return int The HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the current response content.
     * @return mixed The raw response content (string body, or file path for downloads).
     */
    public function getContent(): mixed
    {
        return $this->content;
    }

    /**
     * Get the current response headers.
     * @return array The response headers, keyed by lowercase name.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a single response header by name (case-insensitive).
     *
     * @param string $name
     * @param string|null $default
     * @return string|null
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        $normalized = str_replace('_', '-', strtolower($name));
        return $this->headers[$normalized] ?? $default;
    }

    /**
     * Public API method to send the response to the client.
     * This method sets the HTTP response code, sends all headers, and outputs the response content
     * @return void
     *
     */
    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            $formattedName = ucwords($name, '-');
            header("{$formattedName}: {$value}");
        }

        foreach ($this->cookies as $name => $cookie) {
            setcookie($name, $cookie['value'], [
                'expires'  => $cookie['expires'],
                'path'     => $cookie['path'],
                'domain'   => $cookie['domain'],
                'secure'   => $cookie['secure'],
                'httponly' => $cookie['httpOnly'],
                'samesite' => $cookie['sameSite'],
            ]);
        }

        if ($this->isFileDownload) {
            readfile($this->content);
            return;
        }

        echo $this->content;
    }
}
