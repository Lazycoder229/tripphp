<?php

declare(strict_types=1);

namespace Framework\Exception;

use Framework\Exception\QueryException;
use Framework\Log\LoggerInterface;
use Throwable;
use ErrorException;

/**
 * Centralized Exception and Error Handler
 * Converts unhandled exceptions into structured JSON API responses.
 */
final class Handler
{
    private static ?self $instance = null;

    private ?LoggerInterface $logger = null;

    /**
     * Register global exception and error handlers.
     *
     * Runs before the Container/LoggerInterface exist (Application::run()
     * needs to catch errors from Env::load()/Config::setPath() too), so it
     * stays dependency-free here — logMisconfiguration()/logException() fall
     * back to raw error_log() until setLogger() is called.
     */
    public static function register(): void
    {
        self::$instance = new self();
        set_exception_handler([self::$instance, 'handleException']);
        set_error_handler([self::$instance, 'handleError']);
    }

    /**
     * Inject the framework's Logger once the Container has built one — call
     * this right after binding LoggerInterface in Application::run(). Every
     * exception logged from this point on gets structured, leveled entries
     * (critical/error) instead of a raw error_log() line.
     */
    public static function setLogger(LoggerInterface $logger): void
    {
        if (self::$instance !== null) {
            self::$instance->logger = $logger;
        }
    }

    /**
     * Inject the current Request once Application::run() has built one, so
     * handleException() can tell whether the client wants a JSON error
     * response (Accept: application/json, or a JSON request body) instead
     * of the HTML debug/production page.
     */
    /**
     * Convert PHP warnings/notices into proper ErrorExceptions.
     */
    public function handleError(int $severity, string $message, string $file, int $line): void
    {
        if (!(error_reporting() & $severity)) return;
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Handle any uncaught exception — determine status code and render debug page.
     */
    public function handleException(Throwable $e): void
    {
        // A misconfigured production environment (APP_ENV=production + APP_DEBUG=true) is a
        // fail-safe case: no matter what, never render the debug page — the file paths and
        // stack trace it shows would go straight to whoever happens to be visiting at the time,
        // not just the developer. Log the real detail server-side instead, and serve a generic
        // 503 to the client.
        if ($e instanceof MisconfiguredEnvException) {
            $this->logMisconfiguration($e);
            http_response_code(503);
            $this->renderJson($e, 503);
            exit;
        }

        $status = $e instanceof FrameworkException ? $e->getStatusCode() : 500;
        http_response_code($status);

        if (strtolower($_ENV['APP_ENV'] ?? 'production') === 'production') {
            $this->logException($e);
        }

        $this->renderJson($e, $status);
        exit;
    }

    /**
     * Renders a structured JSON error response instead of the HTML debug/
     * production page. ValidationException gets its full field => [messages]
     * map — that's the one case where the "detail" is meant for the client,
     * not just the developer. Everything else follows the same
     * debug-vs-production masking as render(): full detail (class, file,
     * line) outside production or with APP_DEBUG on, a generic message in
     * production.
     */
    private function renderJson(Throwable $e, int $status): void
    {
        header('Content-Type: application/json');

        $appMode = strtolower($_ENV['APP_ENV'] ?? 'production');
        $debug   = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $showDetail = $appMode !== 'production' || $debug;

        if ($e instanceof ValidationException) {
            echo json_encode([
                'message' => $e->getMessage(),
                'errors'  => $e->getErrors(),
            ], JSON_PRETTY_PRINT);
            return;
        }

        $payload = [
            'message' => $showDetail
                ? $e->getMessage()
                : ($status === 404 ? 'Not Found' : 'Something went wrong.'),
        ];

        if ($showDetail) {
            $payload['exception'] = get_class($e);
            $payload['file']      = $e->getFile();
            $payload['line']      = $e->getLine();
        }

        echo json_encode($payload, JSON_PRETTY_PRINT);
    }

    /**
     * Writes full misconfiguration detail (class, message, file, line, trace) to the
     * server-side error log — the only place this detail should ever end up, since the
     * client always gets the generic 503 page for this exception type.
     */
    private function logMisconfiguration(Throwable $e): void
    {
        $context = [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ];

        if ($this->logger !== null) {
            $this->logger->critical('CRITICAL MISCONFIGURATION: ' . $e->getMessage(), $context);
            return;
        }

        error_log(sprintf(
            "[CRITICAL MISCONFIGURATION] %s: %s in %s:%d\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
    }

    /**
     * Writes full exception detail (class, message, file, line, trace, and —
     * for a QueryException — the offending SQL/bindings) to the server-side
     * error log. This is what makes production errors debuggable at all:
     * the client only ever sees renderProductionPage()'s generic message,
     * this is where the real detail goes instead.
     */
    private function logException(Throwable $e): void
    {
        if ($this->logger !== null) {
            $context = [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ];

            if ($e instanceof QueryException) {
                $context['sql']      = $e->getSql();
                $context['bindings'] = $e->getBindings();
            }

            $this->logger->error($e->getMessage(), $context);
            return;
        }

        $detail = sprintf(
            "[ERROR] %s: %s in %s:%d\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        if ($e instanceof QueryException) {
            $detail .= sprintf(
                "\nSQL: %s\nBindings: %s",
                $e->getSql(),
                json_encode($e->getBindings())
            );
        }

        error_log($detail);
    }


}
