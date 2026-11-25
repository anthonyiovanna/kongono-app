<?php

declare(strict_types=1);

use App\Http\HomeController;
use Kongono\Ai\AiClient;
use Kongono\Ai\AiException;
use Kongono\Cli\Companions\ChieBuilder;
use Kongono\Cli\Companions\DiffRenderer;
use Kongono\Cli\Companions\HanteiDebugger;
use Kongono\Cli\Companions\OshieTutor;
use Kongono\Cli\Context\ProjectContext;
use Kongono\Core\Cache\CacheInterface;
use Kongono\Core\Cache\FileCache;
use Kongono\Core\Config;
use Kongono\Core\Diagnostics\SecretScrubber;
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
use Kongono\Security\Middleware\BearerAuthMiddleware;
use Kongono\Security\Middleware\CorsMiddleware;
use Kongono\Security\Middleware\CsrfMiddleware;
use Kongono\Security\Middleware\RateLimitMiddleware;
use Kongono\Security\Middleware\SecurityHeadersMiddleware;
use Kongono\Security\Principal;
use Kongono\Security\PrincipalKind;
use Kongono\Security\TokenSigner;
use Kongono\Storage\LocalStorage;
use Kongono\Storage\StorageInterface;
use Psr\Log\LoggerInterface;

$baseDir = dirname(__DIR__);
EnvLoader::load($baseDir . DIRECTORY_SEPARATOR . '.env');

$appName = Config::env('APP_NAME') ?? 'My Kongono App';
$appEnv = strtolower(Config::env('APP_ENV') ?? 'local');
$isProdEnv = in_array($appEnv, ['production', 'prod'], true);
$debugEnv = Config::env('APP_DEBUG');
$debug = filter_var(
    (null !== $debugEnv && '' !== trim($debugEnv)) ? $debugEnv : ($isProdEnv ? 'false' : 'true'),
    FILTER_VALIDATE_BOOL,
);

$configuredKey = Config::env('APP_KEY');
$trimmedKey = null !== $configuredKey ? trim($configuredKey) : '';
$decodedKeyBytes = str_starts_with($trimmedKey, 'base64:')
    ? base64_decode(substr($trimmedKey, 7), true)
    : $trimmedKey;
$keyIsPlaceholder = '' === $trimmedKey
    || str_contains(strtoupper($trimmedKey), 'CHANGE_ME')
    || str_contains(strtoupper($trimmedKey), 'REPLACE_WITH')
    || str_contains(strtolower($trimmedKey), 'example');
if ($isProdEnv && ($keyIsPlaceholder || !is_string($decodedKeyBytes) || strlen($decodedKeyBytes) < 32)) {
    throw new \RuntimeException('APP_KEY must be configured with at least 32 random bytes in production (run `php vendor/bin/kongo key:generate`).');
}
$appKey = !$keyIsPlaceholder && is_string($decodedKeyBytes) && strlen($decodedKeyBytes) >= 16
    ? $trimmedKey
    : 'ephemeral-' . bin2hex(random_bytes(24));

$secureCookies = filter_var(Config::env('SESSION_SECURE') ?? ($isProdEnv ? 'true' : 'false'), FILTER_VALIDATE_BOOL);
$corsEnv = Config::env('CORS_ALLOWED_ORIGINS') ?? '';
$corsOrigins = array_values(array_filter(
    array_map('trim', explode(',', $corsEnv)),
    static fn(string $origin): bool => '' !== $origin && '*' !== $origin,
));

$cache = new FileCache($baseDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'app');
$storage = new LocalStorage($baseDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'storage');
$logger = new Logger('app', retainInMemory: $debug);
$metrics = new Metrics();
$inspector = new DevInspector();
$eventBus = new EventBus();
$features = new FeatureManager($cache);
$encryptor = new Encryptor($appKey);
$signer = new TokenSigner($appKey);

$bearerAuth = new BearerAuthMiddleware(static function (string $token) use ($signer): ?Principal {
    $claims = $signer->verify($token);
    if (null === $claims || !is_string($claims['sub'] ?? null)) {
        return null;
    }

    /** @var list<string> $capabilities */
    $capabilities = [];
    if (is_array($claims['capabilities'] ?? null)) {
        $capabilities = array_values(array_filter(
            $claims['capabilities'],
            static fn(mixed $value): bool => is_string($value) && '' !== $value,
        ));
    }

    return new Principal($claims['sub'], PrincipalKind::Human, $capabilities, $claims);
});

$health = (new HealthCheck())
    ->check('cache', static function () use ($cache): bool {
        $probeKey = 'health:probe:' . bin2hex(random_bytes(8));
        try {
            $cache->set($probeKey, 'ok', 15);

            return 'ok' === $cache->get($probeKey);
        } finally {
            $cache->delete($probeKey);
        }
    })
    ->check('storage', static function () use ($storage): bool {
        $probeKey = '_health/probe-' . bin2hex(random_bytes(8)) . '.txt';
        try {
            $storage->put($probeKey, 'ok', 'text/plain');

            return 'ok' === $storage->get($probeKey);
        } finally {
            $storage->delete($probeKey);
        }
    });

$app = App::create($baseDir, name: $appName)
    ->config(['app' => ['name' => $appName, 'debug' => $debug]])
    ->views(autoReload: $debug)
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
    ->instance(BearerAuthMiddleware::class, $bearerAuth)
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
        chieHandler: static function (string $instruction) use ($app, $baseDir, $tryAi): array {
            $ai = $tryAi();
            if (null === $ai) {
                return [
                    'summary' => 'Chie preview requires an AI provider. Set OPENAI_API_KEY, ANTHROPIC_API_KEY, GEMINI_API_KEY, or OLLAMA_HOST in .env.',
                    'diffs' => [],
                    'apply_command' => sprintf('php vendor/bin/kongo chie %s --apply', escapeshellarg($instruction)),
                ];
            }
            $ctx = new ProjectContext($baseDir, $app->graph());
            $plan = (new ChieBuilder($ai))->build($instruction, $ctx, apply: false);
            $diffs = [];
            foreach ($plan['changes'] as $change) {
                $before = null;
                try {
                    $before = $ctx->readFileSafe($change['path']);
                } catch (\Throwable) {
                    $before = null;
                }
                $diffs[] = [
                    'path' => $change['path'],
                    'action' => $change['action'],
                    'explanation' => $change['explanation'],
                    'diff' => SecretScrubber::scrub(
                        DiffRenderer::render($change['path'], $before, SecretScrubber::scrub($change['content'])),
                    ),
                ];
            }

            return [
                'summary' => sprintf('Chie (%s / %s): %s', $plan['provider'], $plan['model'], $plan['summary']),
                'diffs' => $diffs,
                'next_steps' => $plan['next_steps'],
                'apply_command' => sprintf('php vendor/bin/kongo chie %s --apply', escapeshellarg($instruction)),
            ];
        },
    ),
    new CompressionMiddleware(),
    new StaticFilesMiddleware($baseDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets', '/assets/'),
    new SecurityHeadersMiddleware(),
    new CorsMiddleware(allowedOrigins: $corsOrigins),
    new RateLimitMiddleware($cache, maxRequests: 120, windowSeconds: 60),
    new SessionMiddleware(new CacheSessionStore($cache), secure: $secureCookies),
    new CsrfMiddleware($signer, exemptPrefixes: ['/api/', '/_kongono/', '/mcp']),
);

return $app;
