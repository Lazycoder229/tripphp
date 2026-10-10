<?php

declare(strict_types=1);

namespace Framework\Http;

use Framework\Config\Env;
use Framework\Exception\PayloadTooLargeException;
use Framework\Exception\InvalidJsonException;

/**
 * This class encapsulates HTTP request data.
 *
 * Wraps PHP's superglobals ($_GET, $_POST, $_SERVER, $_COOKIE, $_FILES) into
 * an immutable, testable object. All state is captured once via
 * createFromGlobals() and never mutated afterward.
 *
 * @package Framework\Http
 */
final class Request
{
    /**
     * IP addresses of reverse proxies allowed to set X-Forwarded-* headers.
     * Empty by default — meaning X-Forwarded-Proto/Host/For are ignored entirely and
     * REMOTE_ADDR/HTTP_HOST/HTTPS are used as-is, since those headers are otherwise
     * fully client-controllable and could be used to spoof scheme/host/IP.
     *
     * Call Request::setTrustedProxies() once at boot (e.g. from Application::run(),
     * reading a TRUSTED_PROXIES env var) if requests actually pass through a reverse
     * proxy/load balancer that sets these headers.
     *
     * Deliberately NOT readonly/instance state — this is static, boot-time configuration
     * shared across all Request instances, set once via setTrustedProxies() before any
     * Request is constructed. (It also can't be readonly: PHP disallows a default value
     * on a readonly property, and static properties can't be readonly at all.)
     *
     * @var string[]
     */
    private static array $trustedProxies = [];

    /**
     * @param array $query        Parsed query string parameters ($_GET).
     * @param array $body         Parsed request body (POST fields merged with JSON body, if any).
     * @param array $server       Raw $_SERVER data.
     * @param array $headers      Normalized (lowercase, dash-separated) request headers.
     * @param array $cookies      Raw $_COOKIE data.
     * @param array $files        Normalized $_FILES data (one entry per field, never PHP's raw nested-array shape for multi-uploads).
     * @param ?string $rawContent Raw request body content, only populated for JSON requests.
     */
    public function __construct(
        private readonly array $query,
        private readonly array $body,
        private readonly array $server,
        private readonly array $headers = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly ?string $rawContent = null
    ) {
    }

    /**
     * Registers the IP addresses of reverse proxies whose X-Forwarded-* headers should
     * be trusted. Call once at application boot. Passing an empty array (the default)
     * means no proxy is trusted and forwarded headers are always ignored.
     *
     * @param string[] $proxies
     */
    public static function setTrustedProxies(array $proxies): void
    {
        self::$trustedProxies = $proxies;
    }

    /**
     * Builds a Request instance by capturing PHP's current superglobal state.
     *
     * @return self
     */
    public static function createFromGlobals(): self
    {
        $body = $_POST;
        $server = $_SERVER;
        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');

        // 1. Extract headers FIRST so we can use them for security checks
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $name = str_replace('_', '-', strtolower($key));
                $headers[$name] = $value;
            }
        }

        // 2. Safely handle Method Spoofing
        $allowedSpoofMethods = ['PUT', 'PATCH', 'DELETE'];
        if ($method === 'POST' && isset($_POST['_method'])) {
            $spoofed = strtoupper($_POST['_method']);
            if (in_array($spoofed, $allowedSpoofMethods, true)) {
                $server['REQUEST_METHOD'] = $spoofed;
                $method = $spoofed;
            }
        }

        // 3. Harden JSON Parsing
        $rawContent = null;
        $contentType = $headers['content-type'] ?? '';

        // Only parse if the client explicitly states they are sending JSON
        if ($method !== 'GET' && str_contains($contentType, 'application/json')) {

            // DoS Prevention: Reject massive payloads (e.g., > 5MB)
            $contentLength = (int) ($headers['content-length'] ?? 0);
            if ($contentLength > 5242880) {
                throw new PayloadTooLargeException('413 Payload Too Large: request body exceeds the 5MB limit.');
            }

            $rawContent = file_get_contents('php://input');

            if (!empty($rawContent)) {
                // Catch decoding errors properly
                try {
                    $jsonData = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($jsonData)) {
                        $body = array_merge($body, $jsonData);
                    }
                } catch (\JsonException $e) {
                    throw new InvalidJsonException('400 Invalid JSON Payload: ' . $e->getMessage());
                }
            }
        }

        return new self(
            $_GET,
            $body,
            $server,
            $headers,
            $_COOKIE,
            self::normalizeFiles($_FILES),
            $rawContent ?: null
        );
    }

    /**
     * Normalizes PHP's awkward native $_FILES structure into a flat, predictable shape.
     *
     * PHP nests multi-file inputs (e.g. `<input type="file[]">`) as parallel arrays
     * keyed by property (name[], type[], size[], ...) instead of one array per file.
     * This flattens both single and multi-file inputs into a consistent
     * `field => ['name' => ..., 'type' => ..., 'tmp_name' => ..., 'error' => ..., 'size' => ...]`
     * or `field => [ [...], [...] ]` shape.
     *
     * @param array $files Raw $_FILES superglobal.
     * @return array Normalized file upload data, keyed by field name.
     */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $data) {
            if (!is_array($data['name'])) {
                $normalized[$field] = $data;
                continue;
            }

            // Multi-file input: transpose the parallel arrays into one array per file.
            $count = count($data['name']);
            $entries = [];
            for ($i = 0; $i < $count; $i++) {
                $entries[] = [
                    'name'     => $data['name'][$i],
                    'type'     => $data['type'][$i],
                    'tmp_name' => $data['tmp_name'][$i],
                    'error'    => $data['error'][$i],
                    'size'     => $data['size'][$i],
                ];
            }
            $normalized[$field] = $entries;
        }

        return $normalized;
    }

    /**
     * Returns the request path, stripped of query string and leading/trailing slashes normalized to one leading slash.
     *
     * @return string
     */
    public function getPath(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $parsedUrl = parse_url($uri, PHP_URL_PATH);
        return '/' . trim($parsedUrl ?: '/', '/');
    }

    /**
     * Returns the HTTP method, already normalized for spoofing (see createFromGlobals()).
     *
     * @return string
     */
    public function getMethod(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Retrieves a single normalized request header.
     *
     * @param string $key Header name, case-insensitive, underscore/dash-insensitive.
     * @param ?string $default Fallback value if the header is not present.
     * @return ?string
     */
    public function header(string $key, ?string $default = null): ?string
    {
        $normalizedKey = str_replace('_', '-', strtolower($key));
        return $this->headers[$normalizedKey] ?? $default;
    }

    /**
     * Returns all normalized request headers.
     *
     * @return array
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Retrieves a single query string parameter.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Retrieves a single value from the parsed request body (POST fields or JSON).
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Returns the merged query string and body parameters (body takes precedence on key collisions).
     *
     * @return array
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * Retrieves a single cookie value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Returns all cookies sent with the request.
     *
     * @return array
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Retrieves a single normalized uploaded file entry (or list of entries for multi-file fields).
     *
     * @param string $key
     * @return array|null
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Returns all normalized uploaded files, keyed by field name.
     *
     * @return array
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Checks whether the request declared a JSON content type.
     *
     * @return bool
     */
    public function isJson(): bool
    {
        return str_contains($this->header('content-type', ''), 'application/json');
    }

    /**
     * Checks whether the client expects a JSON response, based on the Accept header
     * or an already-JSON request body.
     *
     * @return bool
     */
    public function wantsJson(): bool
    {
        $accept = $this->header('accept', '');
        return str_contains($accept, 'application/json') || $this->isJson();
    }

    /**
     * Returns the raw, unparsed request body content (only populated for JSON requests).
     *
     * @return ?string
     */
    public function rawContent(): ?string
    {
        return $this->rawContent;
    }

    /**
     * Returns whether this request arrived via a proxy explicitly registered through
     * setTrustedProxies(). X-Forwarded-* headers are only honored when this is true.
     *
     * @return bool
     */
    private function isFromTrustedProxy(): bool
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '';
        return $remoteAddr !== '' && self::ipMatchesAny($remoteAddr, self::$trustedProxies);
    }

    /** True if $ip equals, or falls inside, any entry of $ranges (exact IPs or CIDR such as 10.0.0.0/8). */
    private static function ipMatchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::ipInRange($ip, (string) $range)) {
                return true;
            }
        }

        return false;
    }

    private static function ipInRange(string $ip, string $range): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        if (!str_contains($range, '/')) {
            $rangeBin = @inet_pton($range);
            return $rangeBin !== false && $rangeBin === $ipBin;
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $subnetBin = @inet_pton($subnet);

        if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin) || !ctype_digit($bits)) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits > strlen($ipBin) * 8) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    /**
     * Returns the best-effort originating client IP address.
     *
     * Only trusts X-Forwarded-For/X-Real-IP when the request came from a proxy registered
     * via setTrustedProxies() — otherwise those headers are fully client-controllable and
     * would let any visitor claim to be any IP. Falls back to REMOTE_ADDR in all other cases.
     *
     * @return ?string
     */
    public function getClientIp(): ?string
    {
        if ($this->isFromTrustedProxy()) {
            $forwarded = $this->header('x-forwarded-for');
            if ($forwarded !== null) {
                // Each proxy APPENDS the address it received the request from, so the left
                // side is whatever the client chose to claim. Walk from the right and take the
                // first address that is not one of our own proxies.
                $chain = array_map('trim', explode(',', $forwarded));
                $fallback = null;

                foreach (array_reverse($chain) as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                        continue;
                    }

                    $fallback = $candidate;

                    if (!self::ipMatchesAny($candidate, self::$trustedProxies)) {
                        return $candidate;
                    }
                }

                if ($fallback !== null) {
                    return $fallback;
                }
            }

            $realIp = $this->header('x-real-ip');
            if ($realIp !== null && filter_var(trim($realIp), FILTER_VALIDATE_IP) !== false) {
                return trim($realIp);
            }
        }

        return $this->server['REMOTE_ADDR'] ?? null;
    }

    /**
     * Returns the request scheme ('http' or 'https'), honoring a trusted proxy's
     * X-Forwarded-Proto header only when the request came from a proxy registered
     * via setTrustedProxies().
     *
     * @return string
     */
    public function getScheme(): string
    {
        if ($this->isFromTrustedProxy() && $this->header('x-forwarded-proto') === 'https') {
            return 'https';
        }

        $https = $this->server['HTTPS'] ?? '';
        return ($https !== '' && $https !== 'off') ? 'https' : 'http';
    }

    /**
     * Returns the request host, honoring a trusted proxy's X-Forwarded-Host header only
     * when the request came from a proxy registered via setTrustedProxies().
     *
     * @return string
     */
    public function getHost(): string
    {
        $host = null;

        if ($this->isFromTrustedProxy()) {
            $forwardedHost = $this->header('x-forwarded-host');
            if ($forwardedHost !== null) {
                $host = trim(explode(',', $forwardedHost)[0]);
            }
        }

        $host ??= $this->server['HTTP_HOST']
            ?? $this->server['SERVER_NAME']
            ?? 'localhost';

        return $this->validatedHost((string) $host);
    }

    /**
     * The Host header is attacker-controlled, and anything built from it (password-reset
     * links, absolute redirects) is open to host-header poisoning. A malformed host, or — in
     * production — a host that isn't APP_URL's host or listed in TRUSTED_HOSTS
     * (comma-separated), is replaced by APP_URL's host.
     */
    private function validatedHost(string $host): string
    {
        $host    = strtolower(trim($host));
        $appUrl  = Env::appUrl();
        $appHost = (string) parse_url($appUrl, PHP_URL_HOST);
        $appPort = parse_url($appUrl, PHP_URL_PORT);
        $default = $appHost !== '' ? $appHost . ($appPort !== null ? ':' . $appPort : '') : 'localhost';

        $wellFormed = preg_match('/^(?:[a-z0-9](?:[a-z0-9.\-]*[a-z0-9])?|\[[0-9a-f:.]+\])(?::\d{1,5})?$/', $host) === 1;
        if (!$wellFormed) {
            return $default;
        }

        if (Env::isProduction()) {
            $bare    = preg_replace('/:\d{1,5}$/', '', $host);
            $allowed = array_filter(array_map(
                'trim',
                explode(',', strtolower((string) Env::get('TRUSTED_HOSTS', '')))
            ));
            $allowed[] = strtolower($appHost);

            if (!in_array($bare, $allowed, true)) {
                return $default;
            }
        }

        return $host;
    }

    /**
     * Returns the full absolute URL of the current request (scheme + host + path + query string).
     *
     * @return string
     */
    public function fullUrl(): string
    {
        $queryString = $this->server['QUERY_STRING'] ?? '';
        $suffix = $queryString !== '' ? '?' . $queryString : '';

        return $this->getScheme() . '://' . $this->getHost() . $this->getPath() . $suffix;
    }
}