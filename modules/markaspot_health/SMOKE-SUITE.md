# Mark-a-Spot Smoke Suite

`drush markaspot:smoke` is a tenant-runtime smoke gate that exercises Drupal-internal invariants and the public HTTP surface in seconds, on any environment. It complements `drush markaspot:health` (config-drift detection): smoke catches what only shows up when a tenant boots, health catches what's wrong before it boots.

## Why

The 11.9.72 release session shipped three production-grade bugs through every code-test gate (phpunit, vitest, playwright, image CI):

1. **Type-Variance Fatal** in `DashboardFilterForm` and `DuplicateController`. Typed promoted property collided with untyped `FormBase::$configFactory`. Crashed every `drush cr` and admin-route hit.
2. **Schema-0 Migration Trap** in `markaspot_passwordless`. SQL-INSERT migration left `system.schema=0`, all `hook_update_N` silently skipped, login broken.
3. **Bundle Field Map Corruption** after `pm:uninstall`. `key_value:entity.definitions.bundle_field_map` for `media:request_image` wiped, JSON:API media POSTs returned 422 despite intact field config.

All three live at the intersection of image + DB + config + filesystem, only visible in a running tenant. Code tests are blind to them. The smoke suite makes them detectable in under a minute.

## v1 Catalog (9 plugins)

All v1 plugins are read-only and prod-safe. They live under `src/Plugin/SmokeCheck/`.

| ID | Severity | Category | Catches |
|----|----------|----------|---------|
| `http_frontend_public` | error | http_sanity | GET / responds 2xx/3xx via HttpKernel sub-request |
| `http_backend_login_page` | error | http_sanity | GET /user/login responds 200 (form-system smoke) |
| `http_jsonapi_root` | error | http_sanity | GET /jsonapi responds 200 with JSON body |
| `drush_cr_subprocess` | error | drupal_internal | Spawns `drush cr` as subprocess; non-zero exit indicates fatal during container compile (catches bug #1) |
| `schema_vs_info_yml` | error | drupal_internal | system.schema vs `<module>.install` baseline (catches bug #2) |
| `bundle_field_map_populated` | error | drupal_internal | Asserts entity types with `field_config` have non-empty `bundle_field_map` (catches bug #3) |
| `service_container_fresh` | warning | drupal_internal | Compiled container PHP files younger than 24h (heuristic, skipped on cloud-image deploys) |
| `route_table_fresh` | error | drupal_internal | Route count > 100 (catches catastrophic discovery failures) |
| `health_wrap` | error | wrap | Runs `drush markaspot:health` and aggregates: PASS when all health checks pass, FAIL on any error-severity health drift, WARNING on advisory drift |

## Architecture

The suite reuses the plugin-manager pattern of the existing `HealthCheck` system:

```
modules/markaspot_health/
├── src/
│   ├── Annotation/
│   │   ├── HealthCheck.php       (existing)
│   │   └── SmokeCheck.php        (new)
│   ├── HealthCheckPluginManager.php  (existing)
│   ├── SmokeCheckPluginManager.php   (new, mode-aware skip)
│   ├── HealthCheckResult.php         (existing, 2-state)
│   ├── SmokeCheckResult.php          (new, 4-state pass/fail/skip/warning + mode + evidence)
│   ├── Commands/
│   │   ├── HealthCommands.php    (existing)
│   │   └── SmokeCommands.php     (new)
│   ├── Controller/
│   │   ├── HealthCheckController.php   (existing)
│   │   └── SmokeCheckController.php    (new, mode hard-pinned to read-only)
│   └── Plugin/
│       ├── HealthCheck/          (17 existing drift detectors)
│       └── SmokeCheck/           (9 new smoke checks)
```

### Why a 4-state result (vs. Health's binary passed flag)

Smoke checks meaningfully have four outcomes:

- `pass`: invariant held.
- `fail`: invariant violated, counts toward `--exit-non-zero`.
- `skip`: structurally inapplicable for this run (mutating plugin under read-only mode, or missing dependency like a non-writable container path on cloud-image).
- `warning`: failed but environmental, not a tenant defect (e.g. SMTP unreachable on cp1).

A binary flag would force every "structural skip" into a noise-failure or noise-pass, both wrong.

### Why mode is hard-pinned to read-only on the HTTP path

The CLI accepts `--mode=full` for mutating checks (Phase 2). The HTTP controller (`SmokeCheckController::report()`) hard-pins `mode = 'read-only'` regardless of query string. Stale dashboard tabs and runaway browser polling MUST NOT be able to trigger mutating checks.

## Usage

### Local (DDEV)

```bash
ddev drush markaspot:smoke                              # run, print table
ddev drush markaspot:smoke --format=json | jq .         # machine output
ddev drush markaspot:smoke --severity=error --exit-non-zero   # CI gate
ddev drush markaspot:smoke --jurisdiction=68            # per-tenant context
ddev drush markaspot:smoke --category=http_sanity       # filter to a category
```

### Production (cp1, cp2-test)

```bash
ssh deploy@<server> "docker exec <tenant>-drupal-1 drush markaspot:smoke --severity=error --exit-non-zero"
```

Drush calls Drupal's HTTP-Kernel directly via `Symfony\Component\HttpKernel`. Fast, exact framework path, skips nginx/traefik. The infrastructure layer (Traefik routing, nginx caching, TLS) is the job of an external bash wrapper (`smoke-external.sh`, Phase 2).

### HTTP

```bash
curl -s -H "Cookie: $(ddev drush uli --uid=1)" https://dev.ddev.site/api/admin/smoke-check | jq .
curl -s 'https://dev.ddev.site/api/admin/smoke-check?jurisdiction=1&category=drupal_internal' | jq .
```

## Output shape

```json
{
  "checked_at": "2026-05-09T19:42:00+00:00",
  "mode": "read-only",
  "summary": {
    "passed": 7,
    "failed": 1,
    "skipped": 1,
    "warnings": 0,
    "error_severity_failures": 1
  },
  "checks": [
    {
      "id": "schema-vs-info-yml",
      "label": "Module schema vs .install file",
      "status": "fail",
      "severity": "error",
      "category": "drupal_internal",
      "mutates": false,
      "mode": "read-only",
      "count": 2,
      "message": "2 module(s) behind their .install schema baseline.",
      "evidence": {
        "offenders": [
          { "module": "markaspot_passwordless", "installed": 0, "expected": 11907 }
        ]
      },
      "fix_hint": "Run drush updatedb -y. For installed=0 cases, see drush markaspot:health:repair-schema --apply.",
      "fix_url": null,
      "details": [],
      "details_truncated_count": 0,
      "tenant_id": null,
      "last_run_duration_ms": 38
    }
  ]
}
```

## Phase 2 (separate issue, separate PR)

- Subcommands `markaspot:smoke:auth`, `:reports`, `:media`, `:dashboard`.
- `--mode=full` with `runId`-tagged self-cleanup for mutating checks (auth round-trip, JSON:API create, media upload).
- Output formats `tap` and `junit` for CI consumers.
- External bash wrapper `markaspot-cloud/scripts/smoke-external.sh` (curl + jq from outside the container, validates Traefik/nginx/TLS).
- `release-deploy` skill integration (post-deploy smoke gate per tenant).
- CI workflow `.github/workflows/smoke.yml` in `markaspot/markaspot`.

## Authoring a new smoke plugin

```php
namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;

/**
 * @SmokeCheck(
 *   id = "my_check",
 *   label = @Translation("My check"),
 *   severity = "error",
 *   category = "drupal_internal",
 *   description = @Translation("What it asserts."),
 *   fix_hint = @Translation("How to fix when it fails."),
 * )
 */
class MyCheck extends SmokeCheckPluginBase {

  public function run(array $context = []): SmokeCheckResult {
    $mode = $this->mode($context);
    // ... do work ...
    return $this->pass('All good.', ['evidence_key' => 'value'], $mode);
    // or $this->fail($count, $message, $evidence, $mode);
    // or $this->skip($message, $evidence, $mode);
    // or $this->warning($message, $evidence, $mode);
  }

}
```

Mark a check `mutates = TRUE` in the annotation to opt in to `--mode=full`-only execution. The plugin manager skips mutating plugins under `--mode=read-only` without instantiating them.

## Refs

- Issue: markaspot/markaspot-ui#442
- Origin: 2026-05-09 release-deploy session (3 hotfixes 11.9.72 → 11.9.74)
- Builds on #395 (`markaspot:` namespace harmonization)
