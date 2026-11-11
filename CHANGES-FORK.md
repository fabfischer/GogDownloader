# Fork changes

Every difference from upstream [RikudouSage/GogDownloader](https://github.com/RikudouSage/GogDownloader).
Base: `v1.15.1` (`2602dbc300c8c07973830b9f15c92265ed615948`). See [FORK.md](FORK.md).

Changes to upstream files (`src/`, `bin/`, `config/`, `composer.json`, `Dockerfile`, `.github/`, `README.md`, …)
get one entry each. Files that only exist in the fork are listed once per area.

## Unreleased

### Changed (upstream files)

- `src/Command/LoginCodeCommand.php`: the private helper `getCode()` is now `extractCode()`. Symfony Console 7.4
  added a public `Command::getCode()`, and a private method of the same name is a fatal error in PHP, so the whole CLI
  died on 7.4 (even `list`). Behaviour is unchanged; works on Symfony 6.4 and 7.4. The PHPStan baseline entry for
  upstream's existing return-type finding follows the rename.
- `bin/app.php`: loads the autoloader from `$GLOBALS['_composer_autoload_path']` when Composer's bin proxy sets it,
  so the CLI also runs as `vendor/bin/app.php` in another project. Falls back to `../vendor/autoload.php`.
  Test: `tests/Fork/BinaryAsDependencyTest.php`.
- `composer.json`: `symfony/polyfill-intl-normalizer` is now a direct requirement. `App\Storage\ObjectKeyBuilder`
  normalizes names to Unicode NFC with `Normalizer`, which the polyfill provides when the `intl` extension is
  missing. It was already installed through `symfony/string`.
- `composer.json`: the Symfony requirements allow 7.x next to 6.x (`^6.0 || ^7.0`), so projects on Symfony 7.4 LTS
  can use the package. Symfony 6.4's bug-fix support ends 11/2026, 7.4 LTS gets bug fixes until 11/2028
  ([symfony.com/releases](https://symfony.com/releases)).
- `composer.lock`: Symfony 6.4.x → 7.4.x for every runtime component (transitive ones pinned to 7.4 too, not 8.x).
  CI therefore tests 7.4; 6.4 is still allowed but no longer tested here.
- `composer.json`: dev dependencies `phpunit/phpunit ^13.4` and `phpstan/phpstan ^2.3`; `autoload-dev` for
  `App\Tests\` → `tests/`. Runtime requirements unchanged.
- `composer.lock`: security updates within the existing constraints (no major versions): Guzzle 7.13.1 → 7.16.1,
  Symfony 6.4.x components → 6.4.49/6.4.50, transitive Symfony 7.4.x → 7.4.22/7.4.23, and their dependencies.
  Fixes the advisories `composer audit` reported for v1.15.1 ([findings](docs/fork/findings.md), F10).
- `.php-cs-fixer.fork.php` (fork-only) now also checks `src/Storage/` and `src/Transfer/`.
- `.gitignore`: a "Fork additions" block (`/var/`, `/.phpunit.cache/`). Upstream lines unchanged.

### Added (fork-only files)

- `src/Storage/` (namespace `App\Storage`): the storage contract for archive targets.
  - `ArchiveStorageAdapter` (begin, listCompletedParts, writePart, complete, abort, stat, openRead, delete);
  - value objects `StorageCapabilities`, `UploadRequest`, `UploadSession` (serializable for checkpoints),
    `PartBody` (seekable memory stream), `UploadedPart`, `StoredObject`, `RemoteObjectInfo`;
  - `ObjectKey` (validated relative key) and `ObjectKeyBuilder` (revision-addressed layout; NFC, traversal-safe,
    length-limited names);
  - exceptions that tell the caller how to react;
  - `InMemory\InMemoryStorageAdapter`, a complete fake with failure injection for tests.
  No upstream code uses it yet; the S3 and Nextcloud adapters follow.
- `src/Transfer/` (namespace `App\Transfer`): moves one file from a source to a storage target in bounded parts.
  - `Source\HttpRangeSource` reads bounded ranges from a signed URL. It checks status 206, the exact
    `Content-Range`, length, total size and validators on every response; uses `buffer => false`; never sends an
    auth header; follows redirects only to allowlisted https hosts; and refreshes the URL on 403/410 or before
    `nva` expiry.
  - `Source\SequentialSource` is the non-resumable fallback for sources without range support.
  - `ChunkPlanner` (16 MiB default, grows to stay within 10,000 parts, refuses parts above the target limit or the
    memory budget).
  - `TransferEngine`:
    - holds each part in memory and hashes SHA-256 and MD5 on copies of the state;
    - checkpoints only after the target acknowledged a part;
    - retries with backoff (re-reading the source, or re-sending the same buffer);
    - aborts when the source changed;
    - handles pause and cancel, resume with reconciliation, a commit gate for deduplication, and a size check after
      commit.
  This new path avoids findings F2–F4. Upstream's own `DownloadManager` is unchanged.
- `tests/Fork/Transfer/`, `tests/Support/RecordingHttpClient.php`: unit tests for sources, planner and engine.
- `tests/Fork/Storage/`: unit tests, and `ArchiveStorageAdapterContractTestCase`, which every adapter's tests
  extend.
- `tests/` (characterization tests of upstream code, fake GOG API, fork tests), `phpunit.xml.dist`,
  `phpstan.dist.neon`, `phpstan-baseline.neon` (upstream's 130 existing findings at level 8),
  `.php-cs-fixer.fork.php` (upstream's style rules, applied to `tests/` only).
- `docker/dev/`, `Makefile`, `tools/ci.sh`, `.github/workflows/fork-tests.yml`: Docker-only development and CI.
- `FORK.md`, `CHANGES-FORK.md`, `docs/fork/findings.md`, `AGENTS.md`, `CLAUDE.md`.

### Repository settings

- Upstream's `build-latest.yaml` and `release.yaml` publish to Docker Hub with upstream's credentials. They
  must stay disabled in this fork's GitHub Actions settings; the files stay unchanged.
