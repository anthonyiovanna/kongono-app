<?php

declare(strict_types=1);

use App\Http\HomeController;
use Kongono\Ai\AiClient;
use Kongono\Ai\AiException;
use Kongono\Cli\Companions\HanteiDebugger;
use Kongono\Cli\Companions\OshieTutor;
use Kongono\Cli\Context\ProjectContext;
use Kongono\Core\Cache\CacheInterface;
use Kongono\Core\Cache\FileCache;
use Kongono\Core\Config;
use Kongono\Core\EnvLoader;
use Kongono\Core\Features\FeatureManager;
use Kongono\Core\Health\HealthCheck;
use Kongono\Core\Log\Logger;
use Kongono\Core\Telemetry\Metrics;
use Kongono\Http\App;
use Kongono\Http\Dev\DevDashboardMiddleware;
use Kongono\Http\Dev\DevInspector;
use Kongono\Http\Middleware\CompressionMiddleware;
use Kongono\Http\Middleware\StaticFilesMiddleware;
use Kongono\Http\Realtime\EventBus;
use Kongono\Http\Session\CacheSessionStore;
use Kongono\Http\Session\SessionMiddleware;
use Kongono\Security\Encryptor;
use Kongono\Security\Middleware\CorsMiddleware;
use Kongono\Security\Middleware\SecurityHeadersMiddleware;
use Kongono\Security\TokenSigner;
use Kongono\Storage\LocalStorage;
use Kongono\Storage\StorageInterface;
use Psr\Log\LoggerInterface;

$baseDir = dirname(__DIR__);
EnvLoader::load($baseDir . DIRECTORY_SEPARATOR . '.env');

$appName = Config::env('APP_NAME') ?? 'My Kongono App';
$debug = filter_var(Config::env('APP_DEBUG') ?? 'true', FILTER_VALIDATE_BOOL);
$envKey = Config::env('APP_KEY');
$appKey = (null !== $envKey && strlen($envKey) >= 16) ? $envKey : 'kongono-local-dev-key-change-me-in-prod';

$cache = new FileCache($baseDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'app');
$storage = new LocalStorage($baseDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'storage');
$logger = new Logger('app', retainInMemory: $debug);
$metrics = new Metrics();
$inspector = new DevInspector();
$eventBus = new EventBus();
$features = new FeatureManager($cache);
$encryptor = new Encryptor($appKey);
$signer = new TokenSigner($appKey);

$health = (new HealthCheck())
    ->check('cache', static function () use ($cache): bool {
        $cache->set('health:ping', 'ok', 15);
        $ok = 'ok' === $cache->get('health:ping');
        $cache->delete('health:ping');

        return $ok;
    })
    ->check('storage', static function () use ($storage): bool {
        $storage->put('_health/probe.txt', 'ok', 'text/plain');
        $ok = 'ok' === $storage->get('_health/probe.txt');
        $storage->delete('_health/probe.txt');

        return $ok;
    });

$app = App::create($baseDir, name: $appName)
    ->config(['app' => ['name' => $appName, 'debug' => $debug]])
    ->views()
    ->instance(CacheInterface::class, $cache)
    ->instance(StorageInterface::class, $storage)
    ->instance(LoggerInterface::class, $logger)
    ->instance(Metrics::class, $metrics)
    ->instance(HealthCheck::class, $health)
    ->instance(DevInspector::class, $inspector)
    ->instance(EventBus::class, $eventBus)
    ->instance(FeatureManager::class, $features)
    ->instance(Encryptor::class, $encryptor)
    ->instance(TokenSigner::class, $signer)
    ->controller(HomeController::class);

$tryAi = static function (): ?AiClient {
    try {
        return AiClient::fromEnv();
    } catch (AiException) {
        return null;
    }
};

$app->middleware(
    new DevDashboardMiddleware(
        app: $app,
        inspector: $inspector,
        oshieHandler: static function (string $question, ?string $lesson) use ($app, $baseDir, $tryAi): string {
            $ctx = new ProjectContext($baseDir, $app->graph());
            $oshie = new OshieTutor($tryAi());

            return null !== $lesson
                ? $oshie->teachLesson($lesson, $ctx)
                : $oshie->ask('' !== $question ? $question : 'Explain routing in Kongono.', $ctx);
        },
        hanteiHandler: static function (string $diagnostic) use ($app, $baseDir, $tryAi): string {
            $ctx = new ProjectContext($baseDir, $app->graph());

            return (new HanteiDebugger($tryAi()))->diagnose($diagnostic, $ctx);
        },
    ),
    new CompressionMiddleware(),
    new StaticFilesMiddleware($baseDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets', '/assets/'),
    new SecurityHeadersMiddleware(),
    new CorsMiddleware(allowedOrigins: ['*']),
    new SessionMiddleware(new CacheSessionStore($cache)),
);

return $app;
