<?php

declare(strict_types=1);

namespace Framework\Cli;

use Framework\Config\Env;
use Framework\Config\Config;
use Framework\Cli\Commands\RouteCacheCommand;
use Framework\Cli\Commands\RouteClearCommand;
use Framework\Cli\Commands\RouteListCommand;
use Framework\Cli\Commands\ConfigCacheCommand;
use Framework\Cli\Commands\ConfigClearCommand;
use Framework\Cli\Commands\KeyGenerateCommand;
use Framework\Cli\Commands\JwtSecretCommand;
use Framework\Cli\Commands\MakeControllerCommand;
use Framework\Cli\Commands\MakeModelCommand;
use Framework\Cli\Commands\MakeServiceCommand;
use Framework\Cli\Commands\MakeMiddlewareCommand;
use Framework\Cli\Commands\MakeViewCommand;
use Framework\Cli\Commands\ViewClearCommand;
use Framework\Cli\Commands\MakeMigrationCommand;
use Framework\Cli\Commands\MigrateCommand;
use Framework\Cli\Commands\MigrateRollbackCommand;
use Framework\Cli\Commands\MigrateStatusCommand;
use Framework\Cli\Commands\MigrateFreshCommand;
use Framework\Cli\Commands\MakeSeederCommand;
use Framework\Cli\Commands\DbSeedCommand;
use Framework\Cli\Commands\CacheClearCommand;
use Framework\Cli\Commands\LogClearCommand;
use Framework\Cli\Commands\OptimizeCommand;
use Framework\Cli\Commands\OptimizeClearCommand;
use Framework\Cli\Commands\DownCommand;
use Framework\Cli\Commands\UpCommand;
use Framework\Cli\Commands\ServeCommand;
use Throwable;

/**
 * Console
 * 
 * Command dispatcher and manager for the Trip Framework CLI.
 * 
 * @package Framework\Cli
 */
final class Console
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    public function __construct(private readonly string $basePath = '')
    {
        Env::load($this->basePath);
        Config::setPath($this->basePath . 'config');

        $this->registerDefaultCommands();
    }

    public function register(string $name, CommandInterface $command): self
    {
        $this->commands[$name] = $command;
        return $this;
    }

    private function registerDefaultCommands(): void
    {
        // Routing & Optimization
        $this->register('route:list',        new RouteListCommand($this->basePath));
        $this->register('route:cache',       new RouteCacheCommand($this->basePath));
        $this->register('route:clear',       new RouteClearCommand($this->basePath));
        $this->register('config:cache',      new ConfigCacheCommand($this->basePath));
        $this->register('config:clear',      new ConfigClearCommand($this->basePath));
        $this->register('optimize',          new OptimizeCommand($this->basePath));
        $this->register('optimize:clear',    new OptimizeClearCommand($this->basePath));

        // Code Generators
        $this->register('make:controller',   new MakeControllerCommand($this->basePath));
        $this->register('make:model',        new MakeModelCommand($this->basePath));
        $this->register('make:service',      new MakeServiceCommand($this->basePath));
        $this->register('make:middleware',   new MakeMiddlewareCommand($this->basePath));
        $this->register('make:view',         new MakeViewCommand($this->basePath));
        $this->register('make:migration',    new MakeMigrationCommand($this->basePath));
        $this->register('make:seeder',       new MakeSeederCommand($this->basePath));

        // Database & Migrations
        $this->register('migrate',           new MigrateCommand($this->basePath));
        $this->register('migrate:rollback',  new MigrateRollbackCommand($this->basePath));
        $this->register('migrate:status',    new MigrateStatusCommand($this->basePath));
        $this->register('migrate:fresh',     new MigrateFreshCommand($this->basePath));
        $this->register('db:seed',           new DbSeedCommand($this->basePath));

        // Security & Configuration
        $this->register('key:generate',      new KeyGenerateCommand($this->basePath));
        $this->register('jwt:secret',        new JwtSecretCommand($this->basePath));

        // Maintenance & Cache
        $this->register('down',              new DownCommand($this->basePath));
        $this->register('up',                new UpCommand($this->basePath));
        $this->register('cache:clear',       new CacheClearCommand($this->basePath));
        $this->register('view:clear',        new ViewClearCommand($this->basePath));
        $this->register('log:clear',         new LogClearCommand($this->basePath));

        // Development
        $this->register('run',             new ServeCommand($this->basePath));
    }

    public function run(array $argv): int
    {
        $commandName = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        if ($commandName === 'help' || $commandName === '--help' || $commandName === '-h') {
            $this->printHelp();
            return 0;
        }

        if (!isset($this->commands[$commandName])) {
            Output::error("Unknown command: '{$commandName}'. Run 'php trip help' for available commands.");
            return 1;
        }

        try {
            return $this->commands[$commandName]->execute($args);
        } catch (Throwable $e) {
            Output::error("Command failed: " . $e->getMessage());
            Output::line($e->getTraceAsString());
            return 1;
        }
    }

    private function printHelp(): void
    {
        Output::line("\033[34m==================================================\033[0m");
        Output::line("\033[1m           TRIP PHP FRAMEWORK CONSOLE             \033[0m");
        Output::line("\033[34m==================================================\033[0m");
        Output::line("Trip Framework v1.0.0\n");
        Output::line("Usage:");
        Output::line("  php trip <command> [arguments] [options]\n");

        $categories = [
            'Routing & Optimization'  => ['route:list', 'route:cache', 'route:clear', 'config:cache', 'config:clear', 'optimize', 'optimize:clear'],
            'Database & Migrations'   => ['migrate', 'migrate:rollback', 'migrate:status', 'migrate:fresh', 'db:seed'],
            'Code Generators'         => ['make:controller', 'make:model', 'make:service', 'make:middleware', 'make:view', 'make:migration', 'make:seeder'],
            'Security & Secrets'      => ['key:generate', 'jwt:secret'],
            'Maintenance Mode'        => ['down', 'up'],
            'Cache & Logging'         => ['cache:clear', 'view:clear', 'log:clear'],
            'Development Server'      => ['serve'],
        ];

        $maxLen = 0;
        foreach (array_keys($this->commands) as $name) {
            $maxLen = max($maxLen, strlen($name));
        }

        foreach ($categories as $category => $cmdNames) {
            Output::line("\033[33m" . $category . ":\033[0m");
            foreach ($cmdNames as $cmdName) {
                if (isset($this->commands[$cmdName])) {
                    $cmd = $this->commands[$cmdName];
                    Output::line("  \033[32m" . str_pad($cmdName, $maxLen + 4) . "\033[0m" . $cmd->getDescription());
                }
            }
            Output::line();
        }
    }
}
