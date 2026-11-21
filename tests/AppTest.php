<?php

declare(strict_types=1);

namespace App\Tests;

use Kongono\Http\App;
use Kongono\Http\Testing\TestClient;
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
        $client = new TestClient($this->bootApp());

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
            ->assertSee('/api/echo');

        $client->get('/_kongono')
            ->assertOk()
            ->assertSee('Kongono Dev Studio');

        $client->postJson('/_kongono/api/oshie', ['lesson' => 'routing'])
            ->assertOk()
            ->assertSee('Routing & Controllers');

        $client->postJson('/_kongono/api/chie', ['instruction' => 'Add a /api/stats endpoint'])
            ->assertOk()
            ->assertSee('Chie');

        $mcp = $client->postJson('/mcp', [
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
        $prevEnv = $_ENV['APP_DEBUG'] ?? null;
        $prevServer = $_SERVER['APP_DEBUG'] ?? null;
        $prevGetenv = getenv('APP_DEBUG');

        try {
            $_ENV['APP_DEBUG'] = 'false';
            $_SERVER['APP_DEBUG'] = 'false';
            putenv('APP_DEBUG=false');

            $prodApp = $this->bootApp();
            self::assertFalse($prodApp->isDebug());
            self::assertFalse($prodApp->container()->make(ViewEngine::class)->isAutoReload());
        } finally {
            if (null === $prevEnv) {
                unset($_ENV['APP_DEBUG']);
            } else {
                $_ENV['APP_DEBUG'] = $prevEnv;
            }
            if (null === $prevServer) {
                unset($_SERVER['APP_DEBUG']);
            } else {
                $_SERVER['APP_DEBUG'] = $prevServer;
            }
            if (false === $prevGetenv) {
                putenv('APP_DEBUG');
            } else {
                putenv('APP_DEBUG=' . $prevGetenv);
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
