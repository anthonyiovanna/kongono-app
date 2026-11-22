# AI Agent Engineering Guide (Kongono Framework v1.2+)

This application runs on **Kongono Framework v1.2+** — pure PHP 8.5+, zero Symfony packages, zero magic facades, and persistent-runtime first (FrankenPHP / RoadRunner / PHP-FPM).

## Non-Negotiable Rules
1. **Strict Pure PHP 8.5+**: Always include `declare(strict_types=1);`. Prefer `final readonly class`, enums, and native attributes. Never install `symfony/*` or Laravel packages.
2. **Zero Static Mutable State**: Never use `$_SESSION`, `$_GET`, `$_POST`, or static caches for per-request data. Use `Request`, `Session` (`$request->getAttribute(Session::class)`), and container DI.
3. **Fail-Closed Security Defaults**:
   - Outbound HTTP: always use `Kongono\Security\Egress\SafeHttpClient` (DNS IP pinning + SSRF block).
   - Encryption: use `Kongono\Security\Encryptor` (`XChaCha20-Poly1305` + tenant/record AAD + `previousKeys`).
   - Multi-tenant DB queries: always scope via `QueryBuilder::forTenant(new TenantContext($workspaceId))` or `requireTenant()`.
   - Auth routes (`/login`, `/register`, `/forgot-password`, `/reset-password`): attach `#[Middleware(RateLimitMiddleware::class)]`.
   - Payment/mutating API routes: attach `#[Idempotent(ttlSeconds: 3600)]`.
   - Logs & AI prompts: pass untrusted strings/arrays through `SecretScrubber::scrub()` / `SecretScrubber::scrubArray()`.
   - Templates (`.kgn.html`): use `{{ $var }}` (auto-escaped). Only use `{!! $slot !!}` for trusted component slots; keep all JS in external `/assets/*.js` (strict CSP `default-src 'self'`).
4. **Verify Before Finishing**:
   - Run `composer quality` (PHPStan Level 9 + strict rules + PHPUnit 13 strict).
   - Run `php vendor/bin/kongo audit` and `php vendor/bin/kongo doctor`.
   - Use MCP tools (`kongono_routes`, `kongono_graph`, `kongono_audit`, `kongono_test_route`) when available.
