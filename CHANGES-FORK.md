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
    `PartBody` (seekable memory stream), `UploadedPart` (with an optional part checksum), `StoredObject`,
    `RemoteObjectInfo`;
  - `ObjectKey` (validated relative key) and `ObjectKeyBuilder` (revision-addressed layout; NFC, traversal-safe,
    length-limited names);
  - exceptions that tell the caller how to react, including `UnexpectedStorageResponse` for answers an adapter
    cannot classify;
  - `InMemory\InMemoryStorageAdapter`, a complete fake with failure injection for tests.
  No upstream code uses it; the Nextcloud adapter follows.
- `src/Storage/S3/`: `S3StorageAdapter`, the storage contract on S3 multipart uploads, for AWS and S3-compatible
  services, with `S3Settings` (endpoint, region, bucket, prefix, path-style, TLS and CA bundle, optional session token
  and storage class, part limits, timeouts) and `S3ChecksumMode`.
  - Uses the AWS SDK directly; upstream's `S3FileReference` (finding F1) is not used.
  - Sessions carry the UploadId, the target (endpoint, bucket, prefix) and the checksum mode, never credentials.
    A session is refused by an adapter for another target.
  - CRC32 part checksums are declared, sent and repeated at Complete, or not used at all, per upload; defaults: on for
    AWS, off for other endpoints.
  - Never replaces an object: `HeadObject` before opening an upload, and `If-None-Match: *` on Complete where
    enabled (default for AWS) or another `HeadObject` before Complete. A repeated Complete whose first response got
    lost returns the stored object.
  - SDK retries off (the transfer engine retries parts), `Expect: 100-continue` off, a `ListParts` probe after a part
    upload that ended without an answer, `openRead()` streams `GetObject` in bounded chunks, no shared AWS config
    files.
  - Exceptions name operation, S3 error code, HTTP status and request ID; they don't chain the SDK exception, which
    holds the signed request.
  Measurements: [findings](docs/fork/findings.md#s3-part-uploads-s3-compatible-test-server).
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
- `tests/Integration/` and the compose profile `test` in `docker/dev/compose.yaml`: a fake GOG source
  (`tests/Integration/FakeGog/`), an S3-compatible server (Versity S3 Gateway) and a Nextcloud with a quota-limited
  test user (`docker/dev/nextcloud-hooks/`). Run with `make test-integration`, also as the `integration` job of
  `.github/workflows/fork-tests.yml`. `phpunit.xml.dist` keeps them out of the default run (`defaultTestSuite`).
- `tests/Integration/S3/`: the storage contract against the S3 test server in both checksum modes, S3-specific
  cases (prefix, access errors, injected server errors and dropped connections, a lost Complete response, a changed
  checksum setting, memory and temporary files), and whole transfers from the fake GOG source through the engine
  (mid-transfer failures, pause and resume through `ListParts`, deduplication by abort, resume after exhausted
  retries). `FaultInjectingHttpHandler` breaks chosen requests on their way to the real server.
- `tests/Fork/Transfer/`, `tests/Support/RecordingHttpClient.php`: unit tests for sources, planner and engine.
- `tests/Fork/Storage/`: unit tests, and `ArchiveStorageAdapterContractTestCase`, which every adapter's tests
  extend. `tests/Fork/Storage/S3/` tests the S3 adapter's requests and error handling against the SDK's mock handler.
- `tests/Support/TempFiles.php`: bytes the process holds in temporary files, read from `/proc/self/fd`.
- `tests/` (characterization tests of upstream code, fake GOG API, fork tests), `phpunit.xml.dist`,
  `phpstan.dist.neon`, `phpstan-baseline.neon` (upstream's 130 existing findings at level 8),
  `.php-cs-fixer.fork.php` (upstream's style rules, applied to `tests/` only).
- `docker/dev/`, `Makefile`, `tools/ci.sh`, `.github/workflows/fork-tests.yml`: Docker-only development and CI.
- `FORK.md`, `CHANGES-FORK.md`, `docs/fork/findings.md`, `AGENTS.md`, `CLAUDE.md`.

### Repository settings

- Upstream's `build-latest.yaml` and `release.yaml` publish to Docker Hub with upstream's credentials. They
  must stay disabled in this fork's GitHub Actions settings; the files stay unchanged.
