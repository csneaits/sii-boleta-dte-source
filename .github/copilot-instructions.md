## Quick instructions for AI coding agents working on sii-boleta-dte

This file gives focused, actionable guidance so an AI agent is productive immediately. It is a concise extract of `AGENT.md` and `README.md` tailored for code changes, tests and investigations.

- Project type: WordPress plugin (PHP 8.4+). Key directories: `src/Domain`, `src/Application`, `src/Infrastructure`, `src/Presentation`.
- Architectural pattern: hexagonal / ports-and-adapters. Domain contracts live in `src/Domain`; use `Infrastructure\Factory\Container` to resolve services.

- Important files to inspect when changing behavior:
  - `src/Infrastructure/Engine/LibreDteEngine.php` — XML/PDF generation and normalization of `Detalle`.
  - `src/Infrastructure/Engine/Caf/LibreDteCafBridgeProvider.php` — folio/Caf synchronization and last-folio semantics.
  - `src/Application/QueueProcessor.php` — queue processing, backoff and retries.
  - `src/Infrastructure/Rest/Api.php` and `Infrastructure/TokenManager` — SII communication and token refresh.
  - `tests/bootstrap.php` and `tests/_helpers/wp-fallbacks.php` — how tests bootstrap a minimal WP runtime and stubs.

- Standard developer workflows (commands the agent may need to run or reference):
  - Install deps: `composer install`
  - Run full tests: `composer test` (or `vendor/bin/phpunit` for targeted tests)
  - Lint: `composer phpcs`
  - Build distribution: `./build.sh` (or `build.ps1` on Windows)

- Testing guidance (useful example commands):
  - Run a focused PHPUnit test: `vendor/bin/phpunit tests/Infrastructure/Engine/AutoFolioTest.php`
  - Queue processor test: `vendor/bin/phpunit tests/Application/QueueProcessorTest.php`
  - If manipulating files in tests, follow `XmlStorage::store()` semantics: use `file_exists()` before `unlink()` or remove the returned stored path.

- Project-specific conventions and gotchas (do not assume defaults):
  - Normalization: The engine requires `Detalle` to be a sequential array of strings; use helper `isSequentialArray()` (project has compatibility helpers instead of `array_is_list`).
  - Fallbacks: Many integrations check `method_exists` or presence of workers before calling LibreDTE APIs — prefer safe feature-detection over direct calls.
  - Secrets/certs: Certificates and private files live in secure upload directories; avoid printing or committing `.pfx` contents. Signed URLs are used to distribute protected files.
  - XmlStorage::store() may remove the source file (it tries `rename()` then `copy()` + `unlink()`), tests should account for that.

- Integration points to be careful with:
  - LibreDTE bridge (`Infrastructure/LibredteBridge.php`) — changes here affect signature/folio flows.
  - CAF handling and folio sequencing (`FoliosDb`, `Settings::update_last_folio_value`) — ensure observed +1 semantics are preserved to avoid gaps.
  - Queue table `wp_sii_boleta_dte_queue` and cron hook `sii_boleta_dte_process_queue` — avoid introducing race conditions; respect locking via transients.

- How to approach a code change as an AI agent (contract + checks):
  1. Identify the domain contract in `src/Domain` and all usages (search for the interface/class). Update implementations in `src/Application` / `src/Infrastructure` only.
 2. Add or update one focused unit test in `tests/` (happy path + 1 edge). Use existing helpers in `tests/_helpers`.
 3. Run the focused test locally (`vendor/bin/phpunit path/to/test.php`). Fix until green.
 4. Run `composer phpcs` and ensure no new warnings.

- Quick references inside the repo (examples to cite in PRs):
  - Queue processing: `src/Application/QueueProcessor.php`, test: `tests/Application/QueueProcessorTest.php`.
  - DTE generation: `src/Infrastructure/Engine/LibreDteEngine.php`, CAF bridge: `src/Infrastructure/Engine/Caf/LibreDteCafBridgeProvider.php`.
  - Tests bootstrap and WP stubs: `tests/bootstrap.php`, `tests/_helpers/wp-fallbacks.php`.

- When unsure, follow these safe fallbacks:
  - Prefer adding an adapter/bridge rather than changing vendor integrations directly.
  - Use feature checks (`method_exists`) before invoking optional LibreDTE features.
  - Preserve backward compatibility for folio handling and normalization helpers.

If anything in this summary is unclear or you'd like more detailed examples for a specific area (queue, CAF, tests, or LibreDTE bridge), tell me which area and I'll expand with concrete code examples and test templates.
