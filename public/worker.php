<?php

declare(strict_types=1);

use Kongono\Http\App;
use Kongono\View\ViewEngine;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

if (!$app->isDebug() && $app->container()->has(ViewEngine::class)) {
    $app->container()->make(ViewEngine::class)->warmCache();
}

if (function_exists('frankenphp_handle_request')) {
    $app->runWorker(static fn(Closure $handler): bool => frankenphp_handle_request($handler));
} else {
    $app->run();
}
