<?php

declare(strict_types=1);

namespace Framework;

use Framework\Routing\Router;
use Framework\Container\Container; 
use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Http\Middleware\Pipelines;
use Framework\Http\Middleware\Attribute\Middleware;
use Framework\Exception\Handler;
use Framework\Config\Env;
use Framework\Config\Config;
use Framework\Exception\MisconfiguredEnvException; 
use Framework\Database\ConnectionInterface;
use Framework\Database\MySQLConnection;
use Framework\Database\ConnectionConfig;
use Framework\Session\SessionInterface;
use Framework\Session\NativeSession;
use Framework\Security\Csrf;
use Framework\Security\Jwt;
use Framework\Security\Encrypt;
use Framework\Session\CacheInterface;
use Framework\Session\FileCache;
use Framework\Log\LoggerInterface;
use Framework\Log\FileLogger;
use Framework\Storage\FileStorage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Exception;
use ReflectionClass;

/**
 * Application class
 * 
 * This class serves as the entry point for the application. It handles the initialization of the environment,
 * automatic discovery of controllers and middlewares, route caching, and manages the request-response lifecycle.
 * 
 * @package Framework
 */
final class Application
{
    private static array $middlewareGroups = [];

    private function __construct(){}

    /**
     * Automatically scans and registers Middlewares based on Attributes
     */
    private static function autoDiscoverMiddlewares(string $directory, string $namespacePrefix): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        $basePath = str_replace('\\', '/', realpath($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $className = $file->getBasename('.php');
                $currentFilePath = str_replace('\\', '/', $file->getRealPath());
                $subPath = str_replace([$basePath, '/' . $file->getBasename()], ['', ''], $currentFilePath);
                $subNamespace = trim(str_replace('/', '\\', $subPath), '\\');

                $middlewareClass = $subNamespace !== '' 
                    ? $namespacePrefix . '\\' . $subNamespace . '\\' . $className 
                    : $namespacePrefix . '\\' . $className;

                if (class_exists($middlewareClass)) {
                    $reflection = new ReflectionClass($middlewareClass);
                    $attributes = $reflection->getAttributes(Middleware::class);

                    foreach ($attributes as $attribute) {
                        /** @var Middleware $middlewareAttr */
                        $middlewareAttr = $attribute->newInstance();
                        $alias = $middlewareAttr->getAlias();

                        self::$middlewareGroups[$alias] = [$middlewareClass];

                        foreach ($middlewareAttr->getGroups() as $group) {
                            if (!isset(self::$middlewareGroups[$group])) {
                                self::$middlewareGroups[$group] = [];
                            }
                            self::$middlewareGroups[$group][] = $middlewareClass;
                        }
                    }
                }
            }
        }
    }

    /**
     * Automatically discovers and registers all Controllers
     */
    private static function autoDiscoverControllers(string $directory, string $namespacePrefix, Router $router): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        $basePath = str_replace('\\', '/', realpath($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $className = $file->getBasename('.php');
                $currentFilePath = str_replace('\\', '/', $file->getRealPath());
                $subPath = str_replace([$basePath, '/' . $file->getBasename()], ['', ''], $currentFilePath);
                $subNamespace = trim(str_replace('/', '\\', $subPath), '\\');

                $controllerClass = $subNamespace !== '' 
                    ? $namespacePrefix . '\\' . $subNamespace . '\\' . $className 
                    : $namespacePrefix . '\\' . $className;

                if (class_exists($controllerClass)) {
                    $router->registerController($controllerClass);
                }
            }
        }
    }

    /**
     * Registers a middleware group with its associated middleware classes.
     * 
     * @param string $groupName The name of the middleware group.
     * @param array $middlewareClasses An array of fully qualified middleware class names.
     */
    public static function getMiddlewareGroups(): array
    {
        return self::$middlewareGroups;
    }

    /**
     * Resolves the absolute path to the route cache file.
     */
    public static function getRouteCachePath(string $basePath = ''): string
    {
        $customPath = Config::get('cache.route_cache_path');
        if (!empty($customPath)) {
            return (string) $customPath;
        }
        return rtrim($basePath, '/') . '/storage/cache/routes.php';
    }

    /**
     * Checks if a valid compiled route cache file exists.
     */
    public static function hasRouteCache(string $basePath = ''): bool
    {
        return is_file(self::getRouteCachePath($basePath));
    }

    /**
     * Loads middleware groups and routes from the compiled route cache file.
     */
    public static function loadRouteCache(string $basePath = '', ?Router $router = null): bool
    {
        $cacheFile = self::getRouteCachePath($basePath);
        if (!is_file($cacheFile)) {
            return false;
        }

        $cached = require $cacheFile;
        if (!is_array($cached)) {
            return false;
        }

        self::$middlewareGroups = $cached['middleware_groups'] ?? [];
        if ($router !== null) {
            $router->loadFromCache($cached['router'] ?? $cached);
        }

        return true;
    }

    /**
     * Discovers and compiles all routes and middleware groups into an exportable array.
     */
    public static function compileRouteCache(
        string $controllersPath,
        string $controllersNamespace,
        string $middlewaresPath,
        string $middlewaresNamespace,
        string $basePath = ''
    ): array {
        self::$middlewareGroups = [];
        self::autoDiscoverMiddlewares($middlewaresPath, $middlewaresNamespace);

        $container = new Container();
        $router = new Router($container);
        self::autoDiscoverControllers($controllersPath, $controllersNamespace, $router);

        return [
            'generated_at'      => date('c'),
            'middleware_groups' => self::$middlewareGroups,
            'router'            => $router->getCompiledData(),
        ];
    }

    /**
     * Compiles and persists the route cache to disk as an OPcache-ready PHP file.
     */
    public static function cacheRoutes(
        string $controllersPath,
        string $controllersNamespace,
        string $middlewaresPath,
        string $middlewaresNamespace,
        string $basePath = ''
    ): string {
        $data = self::compileRouteCache(
            $controllersPath,
            $controllersNamespace,
            $middlewaresPath,
            $middlewaresNamespace,
            $basePath
        );

        $cacheFile = self::getRouteCachePath($basePath);
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $content = "<?php\n\n// Auto-generated Route Cache - DO NOT EDIT MANUALLY\n// Generated at: " . date('Y-m-d H:i:s') . "\n\nreturn " . var_export($data, true) . ";\n";

        if (file_put_contents($cacheFile, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write route cache file: {$cacheFile}");
        }

        return $cacheFile;
    }

    /**
     * Clears the compiled route cache file from storage.
     */
    public static function clearRouteCache(string $basePath = ''): bool
    {
        $cacheFile = self::getRouteCachePath($basePath);
        if (is_file($cacheFile)) {
            return @unlink($cacheFile);
        }
        return true;
    }

    /**
     * Runs the application lifecycle processes
     */
    public static function run(
        string $controllersPath, 
        string $controllersNamespace,
        string $middlewaresPath,       
        string $middlewaresNamespace,
        string $basePath = ''
    ): void {
        // 1. Build the Exception Handler and register it to catch all uncaught exceptions
        Handler::register();

        // 2. Load the environment variables from the .env file
        Env::load($basePath);

        // 2.1 Load compiled config cache or set config directory path
        if (Config::hasCache($basePath)) {
            Config::loadCache($basePath);
        } else {
            Config::setPath($basePath . 'config');
        }

        // 2.2 Build the Logger and hand it to Handler right away — BEFORE the
        //     MisconfiguredEnvException check below, which throws immediately on a bad
        //     .env. If the Logger were bound later (as part of the Container in step 4),
        //     that specific exception would still be null-logger at catch time and silently
        //     fall back to raw error_log() instead of landing in storage/log/ like every
        //     other exception. Building it standalone here (no Container needed yet) closes
        //     that gap — every exception this Handler ever sees, including this one, now
        //     goes through the same FileLogger.
        $logger = new FileLogger(
            directory: rtrim($basePath, '/') . '/storage/log',
            minLevel: (string) Config::get('logging.min_level', 'debug'),
        );
        Handler::setLogger($logger);

        // 3. The application should not run in production mode with debug enabled. This is a security risk.
        $appEnv   = strtolower($_ENV['APP_ENV'] ?? 'production');
        $appDebug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Check if the application is running in production mode with debug enabled. If so, throw a MisconfiguredEnvException to prevent sensitive data leaks.
        if ($appEnv === 'production' && $appDebug === true) {
            throw new MisconfiguredEnvException(
                "CRITICAL SECURITY ERROR: You are not allowed to set APP_DEBUG=true while APP_ENV is set to production. Please fix your .env file immediately to prevent sensitive source code and credential data leaks."
            );
        }

        // 4. Instantiate Container and Router
        $container = new Container();
        $router = new Router($container);

        // 4.1 Scan modules or load pre-compiled route cache
        if (self::hasRouteCache($basePath)) {
            self::loadRouteCache($basePath, $router);
        } else {
            self::autoDiscoverMiddlewares($middlewaresPath, $middlewaresNamespace);
            self::autoDiscoverControllers($controllersPath, $controllersNamespace, $router);
        }

        // 4.2 Bind the database connection: any controller/middleware that type-hints
        //     ConnectionInterface will get this MySQLConnection instance auto-wired in.
        //     Wrapped in a closure so the actual PDO connection is only opened on first use,
        //     not on every request even for routes that never touch the database.
        $container->set(ConnectionInterface::class, function () {
            return new MySQLConnection(ConnectionConfig::fromConfig());
        });

        // 4.3 Bind Session: any controller/middleware that type-hints SessionInterface
        //     gets this NativeSession auto-wired in. Actual session_start() only fires
        //     on first ->get()/->set() call, not on every request.
        $container->set(SessionInterface::class, function () {
            return new NativeSession(
                lifetimeMinutes: (int) Config::get('session.lifetime', 120),
                secure: Config::get('session.secure'), // null = auto-detect (see NativeSession)
            );
        });

        // 4.3.1 Bind Csrf: depends on SessionInterface above, so resolved through it.
        $container->set(Csrf::class, function ($c) {
            return new Csrf($c->get(SessionInterface::class));
        });

        // 4.3.2 Bind Jwt: reads its secret/ttl/issuer from config/jwt.php (JWT_SECRET
        //     etc. in .env) via its own constructor defaults, same idiom as every other
        //     Config::get()-backed service here. Throws at first resolution if JWT_SECRET
        //     isn't set — only routes/controllers that actually type-hint Jwt trigger that
        //     check, so apps that don't use JWT auth never pay for it.
        $container->set(Jwt::class, function () {
            return new Jwt();
        });

        // 4.3.3 Bind Encrypt: reads its key from Env::appKey() (APP_KEY, already required
        //     at boot regardless — see step 3 above) via its own constructor default.
        //     Throws at first resolution if APP_KEY doesn't decode to exactly 32 bytes,
        //     same fail-fast posture as Jwt.
        $container->set(Encrypt::class, function () {
            return new Encrypt();
        });

        // 4.4 Bind Cache: any controller/middleware that type-hints CacheInterface
        //     gets this FileCache auto-wired in.
        $container->set(CacheInterface::class, function () use ($basePath) {
            return new FileCache(
                directory: rtrim($basePath, '/') . '/storage/cache',
                defaultTtl: (int) Config::get('cache.ttl', 3600),
            );
        });

        // 4.4.1 Bind FileStorage: two named instances so a controller/service can
        //     ask for whichever it needs — 'storage.private' (storage/app/uploads,
        //     outside the document root, never web-accessible on its own) or
        //     'storage.public' (public/uploads, served directly by the web server).
        //     FileStorage::class itself resolves to whichever driver
        //     config/filesystem.php names as 'default', so a plain
        //     `FileStorage $fileStorage` type-hint keeps working without a controller
        //     having to know or care which driver is active.
        $container->set('storage.private', function () use ($basePath) {
            return new FileStorage(
                rtrim($basePath, '/') . '/' . trim((string) Config::get('filesystem.private_path', 'storage/app/uploads'), '/')
            );
        });
        $container->set('storage.public', function () use ($basePath) {
            return new FileStorage(
                rtrim($basePath, '/') . '/' . trim((string) Config::get('filesystem.public_path', 'public/uploads'), '/')
            );
        });
        $container->set(FileStorage::class, function ($c) {
            $driver = Config::get('filesystem.default', 'private') === 'public' ? 'storage.public' : 'storage.private';
            return $c->get($driver);
        });

        // 4.5 Bind Logger
        $container->set(LoggerInterface::class, $logger);

        // 4.6 Initialize View Engine
        \Framework\View\View::init($basePath, $container);

        // 5. Normalize input channels from global states
        $request = Request::createFromGlobals();

        // 5.1 Give Handler the current Request so a thrown exception can be rendered as
        //     JSON (Accept: application/json, or a JSON body) instead of the HTML
        //     debug/production page — see Handler::wantsJson().
        Handler::setRequest($request);

        // 5.2 Check for Maintenance Mode (503)
        $downFile = rtrim($basePath, '/') . '/storage/framework/down';
        if (is_file($downFile)) {
            $downData = json_decode((string) file_get_contents($downFile), true) ?: [];
            $secret = $downData['secret'] ?? null;
            $bypass = false;

            if ($secret !== null && $secret !== '') {
                if ($request->query('secret') === $secret) {
                    $bypass = true;
                    setcookie('trip_maintenance', (string) $secret, time() + 86400, '/');
                } elseif ($request->cookie('trip_maintenance') === $secret) {
                    $bypass = true;
                }
            }

            if (!$bypass) {
                $retry = (int) ($downData['retry'] ?? 60);
                $message = (string) ($downData['message'] ?? 'The application is under scheduled maintenance.');

                if ($request->wantsJson()) {
                    $resp = Response::json(['error' => $message], 503)
                        ->withHeader('Retry-After', (string) $retry);
                } else {
                    $content = \Framework\View\View::render('errors.503', [
                        'status'  => 503,
                        'message' => $message,
                        'retry'   => $retry,
                    ]);
                    $resp = new Response($content, 503, [
                        'Retry-After'  => (string) $retry,
                        'Content-Type' => 'text/html; charset=UTF-8',
                    ]);
                }
                $resp->send();
                exit;
            }
        }

        // 6. Run the middleware pipeline and dispatch the request to the router.
        //    Middlewares auto-discovered under the reserved 'global' group
        //    (via #[Middleware(alias: '...', groups: ['global'])]) run on
        //    every request, before route matching — same auto-wiring idiom
        //    as controllers/route-level middleware, no manual registration.
        $pipeline = new Pipelines($container);
        $pipeline->pipe(self::$middlewareGroups['global'] ?? []);

        $response = $pipeline->process($request, function (Request $request) use ($router): Response {
            return $router->dispatch($request);
        });

        // 7. Fire the response payload safely back to the user
        $response->send();
    }
}