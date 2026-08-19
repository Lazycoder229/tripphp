<?php

declare(strict_types=1);

namespace Framework\Cli\Commands;

use Framework\Cli\CommandInterface;
use Framework\Cli\Output;

/**
 * ServeCommand
 * 
 * Runs the PHP built-in web server for local development.
 * 
 * @package Framework\Cli\Commands
 */
final class ServeCommand implements CommandInterface
{
    public function __construct(private readonly string $basePath = '')
    {
    }

    public function execute(array $args): int
    {
        $host = 'localhost';
        $port = 3000;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--host=')) {
                $host = substr($arg, 7);
            } elseif (str_starts_with($arg, '--port=')) {
                $port = (int) substr($arg, 7);
            }
        }

        $publicDir = rtrim($this->basePath, '/') . '/public';

        Output::line("\033[34m=========================================\033[0m");
        Output::line("\033[1m  Trip Development Server Started        \033[0m");
        Output::line("\033[34m=========================================\033[0m");
        Output::line("  Listening on : \033[32mhttp://{$host}:{$port}\033[0m");
        Output::line("  Document root: {$publicDir}");
        Output::line("  Press Ctrl+C to stop the server.\n");

        passthru("php -S {$host}:{$port} -t " . escapeshellarg($publicDir));
        return 0;
    }

    public function getDescription(): string
    {
        return 'Start the local development server (options: --host=127.0.0.1, --port=8000)';
    }
}
