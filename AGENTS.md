# AI Agent Engineering Guide (Kongono Framework v1.3+)

This application runs on **Kongono Framework v1.3+** — pure PHP 8.5+, zero Symfony packages, zero magic facades, and persistent-runtime first (FrankenPHP / RoadRunner / PHP-FPM).

## Non-Negotiable Rules
1. **Strict Pure PHP 8.5+**: Always include `declare(strict_types=1);`. Prefer `final readonly class`, enums, and native attributes. Never install `symfony/*` or Laravel packages.
2. **Zero Static Mutable State**: Never use `$_SESSION`, `$_GET`, `$_POST`, or static caches for per-request data. Use `Request`, `Session` (`$request->getAttribute(Session::class)`), and container DI.
3. **Fail-Closed Security & AI Threat Defenses**:
   - Outbound HTTP: always use `Kongono\Security\Egress\SafeHttpClient` (`request()` or parallel `requestMany()` via `curl_multi`, DNS IP pinning + SSRF block).
   - Autonomous AI Recon / Bot Shield: attach `Kongono\Security\Middleware\AiThreatShieldMiddleware` for honeypot traps, 404 probe burst auto-banning, and cost-aware compute budgets.
   - AI Prompts & Untrusted Data: wrap external text with `PromptGuard::wrapUntrusted(new UntrustedInput(...))` and verify outputs with `PromptGuard::inspectOutput($reply, $canary)`.
   - Encryption: use `Kongono\Security\Encryptor` (`XChaCha20-Poly1305` + tenant/record AAD + `previousKeys`).
   - Multi-tenant DB queries: always scope via `QueryBuilder::forTenant(new TenantContext($workspaceId))` or `requireTenant()`.
   - Auth routes (`/login`, `/register`, `/forgot-password`, `/reset-password`): attach `#[Middleware(RateLimitMiddleware::class)]`.
   - Payment/mutating API routes: attach `#[Idempotent(ttlSeconds: 3600)]`.
   - Logs & AI prompts: pass untrusted strings/arrays through `SecretScrubber::scrub()` / `SecretScrubber::scrubArray()`.
   - Templates (`.kgn.html`): use `{{ $var }}` (auto-escaped). For zero-build interactivity, mount `LiveMiddleware` + `@liveScript` (`kgn-get`/`kgn-post`/`kgn-target`/`kgn-swap`/`kgn-stream`) and return `Response::fragment()` when `$request->isLive()`. Keep strict CSP `default-src 'self'`.
4. **Concurrency & Performance**:
   - Use `Kongono\Core\Async\Async::all()`, `Async::race()`, `Async::map()`, and `Async::allSettled()` for cooperative PHP 8.5 Fiber concurrency, and `SafeHttpClient::requestMany()` for parallel outbound HTTPS calls.
   - Run `php vendor/bin/kongo optimize` in production builds to precompile `.kgn.html` views and emit OPcache-ready `var/cache/routes.compiled.php`.
5. **Typed Multi-Platform SDKs**:
   - Run `php vendor/bin/kongo sdk:generate --lang=ts|swift|kotlin|dart --out=...` whenever API routes or DTOs change.
6. **Verify Before Finishing**:
   - Run `composer quality` (PHPStan Level 9 + strict rules + PHPUnit 13 strict).
   - Run `php vendor/bin/kongo audit --attack` and `php vendor/bin/kongo doctor`.
   - Use MCP tools (`kongono_routes`, `kongono_graph`, `kongono_sdk_generate`, `kongono_audit`, `kongono_test_route`) when available.
