<?php

declare(strict_types=1);

namespace App\Http;

use Kongono\Cli\Mcp\KongonoMcpFactory;
use Kongono\Core\Config;
use Kongono\Core\Health\HealthCheck;
use Kongono\Core\Telemetry\Metrics;
use Kongono\Http\App;
use Kongono\Http\OpenApi\ApiDocsRenderer;
use Kongono\Http\OpenApi\OpenApiGenerator;
use Kongono\Http\Request;
use Kongono\Http\Response;
use Kongono\Http\Routing\CacheControl;
use Kongono\Http\Routing\Get;
use Kongono\Http\Routing\Idempotent;
use Kongono\Http\Routing\Post;
use Kongono\View\ViewEngine;

final readonly class HomeController
{
    public function __construct(
        private ViewEngine $views,
        private Config $config,
        private HealthCheck $health,
        private Metrics $metrics,
        private App $app,
    ) {}

    #[Get('/', name: 'home')]
    public function index(): Response
    {
        $this->metrics->increment('app_http_requests_total', ['route' => 'home']);

        return Response::html($this->views->render('home', [
            'title' => $this->config->string('app.name', 'My Kongono App'),
            'phpVersion' => PHP_VERSION,
            'routeCount' => count($this->app->graph()->routes),
            'debug' => $this->app->isDebug(),
        ]));
    }

    #[Post('/api/echo', name: 'api.echo')]
    #[Idempotent(ttlSeconds: 3600)]
    public function echo(EchoInput $input): Response
    {
        return Response::json([
            'message' => $input->message,
            'length' => mb_strlen($input->message),
            'timestamp' => gmdate(DATE_ATOM),
        ], status: 201);
    }

    #[Get('/health', name: 'health')]
    public function health(): Response
    {
        $report = $this->health->run();

        return Response::json($report, status: $report['healthy'] ? 200 : 503);
    }

    #[Get('/metrics', name: 'metrics')]
    public function metrics(): Response
    {
        return Response::text($this->metrics->toPrometheus(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    #[Get('/openapi.json', name: 'openapi')]
    #[CacheControl(maxAge: 300, public: true, staleWhileRevalidate: 60)]
    public function openApi(): Response
    {
        return Response::json((new OpenApiGenerator())->generate($this->app));
    }

    #[Get('/docs', name: 'api.docs')]
    public function docs(): Response
    {
        return Response::html((new ApiDocsRenderer())->render($this->app));
    }

    #[Post('/mcp', name: 'mcp')]
    public function mcp(Request $request): Response
    {
        $server = KongonoMcpFactory::forApp($this->app->basePath(), $this->app);
        $reply = $server->handleJson((string) $request->getBody());
        if (null === $reply) {
            return Response::noContent();
        }

        return Response::text($reply, 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
