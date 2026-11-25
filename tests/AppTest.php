<?php

declare(strict_types=1);

namespace App\Tests;

use Kongono\Http\App;
use Kongono\Http\Testing\TestClient;
use Kongono\Security\TokenSigner;
use Kongono\View\ViewEngine;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    protected function setUp(): void
    {
        $this->removeDir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var');
    }

    protected function tearDown(): void
    {
        $this->removeDir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var');
    }

    private function bootApp(): App
    {
        /** @var App $app */
        $app = require dirname(__DIR__) . '/app/bootstrap.php';

        return $app;
    }

    public function testHomeHealthDocsOpenApiDevStudioAndMcpWork(): void
    {
        $app = $this->bootApp();
        $client = new TestClient($app);
        $signer = $app->container()->make(TokenSigner::class);

        $client->get('/')
            ->assertOk()
            ->assertSee('Kongono Framework')
            ->assertSee('/_kongono');

        $client->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('checks.cache.ok', true)
            ->assertJsonPath('checks.storage.ok', true);

        $openApi = $client->get('/openapi.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0')
            ->assertSee('EchoInput');
        $etag = $openApi->header('ETag');
        self::assertNotSame('', $etag);
        $client->get('/openapi.json', ['If-None-Match' => $etag])->assertStatus(304);

        $client->get('/docs')
            ->assertOk()
            ->assertSee('OpenAPI 3.1.0')
            ->assertSee('/api/echo')
            ->assertSee('/docs/explorer.js');

        $client->get('/docs/explorer.js')
            ->assertOk();

        $client->get('/_kongono')
            ->assertOk()
            ->assertSee('Kongono Dev Studio');

        $client->postJson('/_kongono/api/oshie', ['lesson' => 'routing'])
            ->assertOk()
            ->assertSee('Routing & Controllers');

        $client->postJson('/_kongono/api/chie', ['instruction' => 'Add a /api/stats endpoint'])
            ->assertOk()
            ->assertSee('Chie');

        // Sensitive operational & MCP endpoints fail closed without a Bearer token
        $client->get('/metrics')->assertStatus(401);
        $client->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertStatus(401);

        $opsToken = $signer->sign(['sub' => 'ops-monitor', 'capabilities' => ['ops.view']]);
        $client->withHeader('Authorization', 'Bearer ' . $opsToken)
            ->get('/metrics')
            ->assertOk()
            ->assertSee('app_http_requests_total');

        $mcpToken = $signer->sign(['sub' => 'dev-ide', 'capabilities' => ['mcp.access']]);
        $mcp = $client->withHeader('Authorization', 'Bearer ' . $mcpToken)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ])->assertOk();
        $toolNames = array_column($mcp->json()['result']['tools'], 'name');
        self::assertContains('kongono_graph', $toolNames);
    }

    public function testEchoValidatesDtoAndEnforcesIdempotency(): void
    {
        $client = new TestClient($this->bootApp());

        $client->postJson('/api/echo', ['message' => ''])->assertStatus(422);

        $first = $client->withHeader('Idempotency-Key', 'echo-1')
            ->postJson('/api/echo', ['message' => 'Hello Kongono'])
            ->assertCreated()
            ->assertJsonPath('message', 'Hello Kongono')
            ->assertJsonPath('length', 13);

        $replay = $client->withHeader('Idempotency-Key', 'echo-1')
            ->postJson('/api/echo', ['message' => 'Hello Kongono'])
            ->assertCreated();
        self::assertSame('true', $replay->header('Idempotency-Replayed'));
        self::assertSame($first->body(), $replay->body());

        $client->withHeader('Idempotency-Key', 'echo-1')
            ->postJson('/api/echo', ['message' => 'Different payload'])
            ->assertStatus(409);
    }

    public function testViewsAutoReloadTracksAppDebugSetting(): void
    {
        $this->withEnv(['APP_DEBUG' => 'false'], function (): void {
            $prodApp = $this->bootApp();
            self::assertFalse($prodApp->isDebug());
            self::assertFalse($prodApp->container()->make(ViewEngine::class)->isAutoReload());
        });
    }

    public function testProductionFailsClosedWithoutStrongAppKeyAndDefaultsDebugOff(): void
    {
        $this->withEnv(['APP_ENV' => 'production', 'APP_KEY' => '', 'APP_DEBUG' => ''], function (): void {
            try {
                $this->bootApp();
                self::fail('Expected production boot without APP_KEY to fail closed.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('APP_KEY', $e->getMessage());
            }
        });

        $strongKey = 'base64:' . base64_encode(random_bytes(32));
        $this->withEnv(['APP_ENV' => 'production', 'APP_KEY' => $strongKey, 'APP_DEBUG' => ''], function (): void {
            $prodApp = $this->bootApp();
            self::assertFalse($prodApp->isDebug());
        });
    }

    /**
     * @param array<string, ?string> $overrides
     */
    private function withEnv(array $overrides, callable $callback): void
    {
        $saved = [];
        foreach (array_keys($overrides) as $name) {
            $saved[$name] = [
                'env' => $_ENV[$name] ?? null,
                'server' => $_SERVER[$name] ?? null,
                'getenv' => getenv($name),
            ];
        }

        try {
            foreach ($overrides as $name => $value) {
                if (null === $value) {
                    unset($_ENV[$name], $_SERVER[$name]);
                    putenv($name);
                } else {
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                    putenv($name . '=' . $value);
                }
            }
            $callback();
        } finally {
            foreach ($saved as $name => $orig) {
                if (null === $orig['env']) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $orig['env'];
                }
                if (null === $orig['server']) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $orig['server'];
                }
                if (false === $orig['getenv']) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $orig['getenv']);
                }
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
