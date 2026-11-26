# Kongono Application Skeleton (`kongono/app`)

[![CI](https://github.com/kongono/app/actions/workflows/ci.yml/badge.svg)](https://github.com/kongono/app/actions/workflows/ci.yml)
[![Discord](https://img.shields.io/badge/Discord-Join%20Community-5865F2?logo=discord&logoColor=white)](https://discord.gg/SXWjgBGwrZ)
![PHP 8.5+](https://img.shields.io/badge/PHP-8.5%2B-777BB4?logo=php&logoColor=white)
![Zero Symfony](https://img.shields.io/badge/Symfony-0%20packages-16a34a)
![License MIT](https://img.shields.io/badge/License-MIT-0284c7)

The official starter application skeleton for [**Kongono Framework v1**](https://github.com/kongono/framework) — pure PHP 8.5+, **zero Symfony packages (runtime or dev)**, FrankenPHP worker ready, and built-in **Oshie**, **Chie**, and **Hantei** AI companions.

## Quick Start

```bash
composer create-project kongono/app my-app
cd my-app
composer dev
```

Then open in your browser:
- **App Home:** http://127.0.0.1:8000/
- **Kongono Dev Studio:** http://127.0.0.1:8000/_kongono *(auto-disabled when `APP_DEBUG=false`)*
- **Interactive OpenAPI 3.1 Explorer:** http://127.0.0.1:8000/docs
- **Readiness Health Check:** http://127.0.0.1:8000/health
- **Prometheus Metrics:** http://127.0.0.1:8000/metrics *(requires Bearer token with `ops.view`)*

## Included Commands

```bash
php vendor/bin/kongo doctor                # Verify PHP 8.5, extensions, AI provider & app graph
php vendor/bin/kongo env:check             # Verify .env against .env.example
php vendor/bin/kongo tinker                # Interactive PHP 8.5 REPL with live $app & $container
php vendor/bin/kongo routes                # List all registered HTTP routes
php vendor/bin/kongo make:controller Post  # Generate a PHP 8.5 attribute controller
php vendor/bin/kongo make:dto CreatePost   # Generate a validated readonly DTO
php vendor/bin/kongo optimize              # Precompile .kgn.html views & cache graph/OpenAPI
php vendor/bin/kongo oshie --interactive   # Launch Oshie interactive AI tutor
php vendor/bin/kongo chie "Add /api/posts" # Generate idiomatic Kongono code (diff preview)
php vendor/bin/kongo hantei check          # Run tests & diagnose failures
php vendor/bin/kongo mcp                   # Connect AI IDEs (Antigravity, Cursor, Claude Code)
```

## Quality Checks

```bash
composer quality   # Runs PHPStan Level 9 Strict + PHPUnit 13 Strict
```

## Production Deployment (FrankenPHP)

```bash
docker build -t my-kongono-app .
docker run -p 8080:8080 --env-file .env my-kongono-app
```

## License

MIT — see [LICENSE](LICENSE).
