# Fork changes

Every difference from upstream [RikudouSage/GogDownloader](https://github.com/RikudouSage/GogDownloader).
Base: `v1.15.1` (`2602dbc300c8c07973830b9f15c92265ed615948`). See [FORK.md](FORK.md).

Changes to upstream files (`src/`, `bin/`, `config/`, `composer.json`, `Dockerfile`, `.github/`, `README.md`, …)
get one entry each. Files that only exist in the fork are listed once per area.

## Unreleased

### Changed (upstream files)

- `src/Service/OwnedItemsManager.php`: two new public methods; the existing ones are unchanged.
  - `getRawOwnedProducts()` yields the owned products as GOG lists them, with every field (hidden ones too, unless
    left out).
  - `getRawProductPayload()` returns a product's account view (`gameDetails`) and its product API payload (downloads
    and expanded DLC) unchanged, as the new `App\DTO\RawProductPayload`. Nothing is filtered, no checksum is fetched.

  Callers can now tell builds, parts, language packs, DLC and extra types apart (finding F5). Test:
  `tests/Fork/Gog/RawGogDataTest.php`.
- `src/Service/DownloadManager.php`: two new public methods; the existing ones are unchanged.
  - `resolveDownlink()` turns a file's `downlink` from the product API into the signed CDN URL and its checksum URL.
    It sends the account's token only to `api.gog.com` or `embed.gog.com` over https.
  - `getChecksumXml()` returns the checksum file, or `null` when there is none (404 or empty).

  Same test.
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
  - `ArchiveStorageAdapter` (begin, listCompletedParts, writePart, complete, abort, stat, openRead, delete).
    `complete()` is safe to repeat: when the session is gone and the key holds an object of the full size, an earlier
    call succeeded and that object is returned;
  - value objects `StorageCapabilities`, `UploadRequest`, `UploadSession` (serializable for checkpoints),
    `PartBody` (seekable memory stream), `UploadedPart` (with an optional part checksum), `StoredObject`,
    `RemoteObjectInfo`;
  - `ObjectKey` (validated relative key) and `ObjectKeyBuilder` (revision-addressed layout; NFC, traversal-safe,
    length-limited names);
  - exceptions that tell the caller how to react, including `UnexpectedStorageResponse` for answers an adapter
    cannot classify;
  - `InMemory\InMemoryStorageAdapter`, a complete fake with failure injection for tests (failed part uploads, failed
    or lost answers when completing, expired sessions).
  No upstream code uses it.
- `src/DTO/RawProductPayload.php`: the two unchanged payloads of one product (see `OwnedItemsManager` above).
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
- `src/Storage/Nextcloud/`: `NextcloudStorageAdapter`, the storage contract on Nextcloud's WebDAV chunked upload
  (version 2), with `NextcloudSettings` (base URL, also in a subdirectory; login name and app password; user ID for
  the WebDAV paths; folder; TLS and CA bundle; part limits; idle and assembly timeouts).
  - One upload folder per object, named `gog-archive-` plus 32 hex digits; chunks `00001`…`10000`; a final `MOVE` of
    `.file` with `Overwrite: F`, so Nextcloud refuses to replace an existing file. Sessions carry the folder name and
    the target (base URL, user ID, folder), never the password; sessions of other targets or with other folder
    names are refused, so nothing outside the target's folder and the adapter's own upload folders is touched.
  - `begin()` creates missing parent folders (deepest first, upwards only on 409) and checks that the key is free
    before it opens the upload: with object storage, Nextcloud changes the recorded size of an existing file that
    an upload is aimed at.
  - `complete()` checks that the upload folder holds exactly the chunks 1…n with their sizes before the `MOVE`: with
    object storage, Nextcloud assembles whatever chunks exist without checking the total. A repeated `complete()`
    after a lost answer returns the stored file.
  - Error handling for 404 on a chunk (upload folder gone, or destination folder deleted: created again), 412
    (Nextcloud's cache lost the upload), 413 from a proxy, 423 while a file is assembled, 507, 401/403, 5xx, and
    connections that break without an answer. No retries inside the adapter, no redirects followed.
  - `MultiStatus` and `DavEntry` read PROPFIND answers without loading anything from the network.
  Measurements and server behaviour: [findings](docs/fork/findings.md#nextcloud-chunked-uploads-test-nextclouds).
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
    - handles pause and cancel, a commit gate for deduplication, and a size check after commit;
    - retries completing the upload like a part; after an attempt with an unknown outcome, a key reported as taken
      counts as temporary, because the target may still be finishing that attempt;
    - resumes from a checkpoint after reconciling it with the target. An upload that an earlier run completed but did
      not report is returned without sending anything, reading the source or asking the commit gate again
      (`TransferResult::$recovered`).
  - `Reconciler`, `Reconciliation`, `ReconcileOutcome`: compare a checkpoint with what the target holds (`ListParts`,
    `PROPFIND`, a stat of the key) without changing anything. Outcomes: resumable from the next part (with the parts
    the target holds beyond the checkpoint), already completed, session gone, or a checkpointed part missing.
  This new path avoids findings F2–F4. Upstream's own `DownloadManager` is unchanged.
- `tests/Integration/` and the compose profile `test` in `docker/dev/compose.yaml`: a fake GOG source
  (`tests/Integration/FakeGog/`), an S3-compatible server (Versity S3 Gateway) and two Nextclouds, one on local
  storage and one on object storage (a bucket on the S3 test server) with Valkey as its distributed cache, which
  switches on Nextcloud's other code path for chunked uploads. `docker/dev/nextcloud-hooks/` adds a quota-limited test
  user and turns off brute force protection in these throwaway containers. Run with `make test-integration`, also as the `integration` job of
  `.github/workflows/fork-tests.yml`. `phpunit.xml.dist` keeps them out of the default run (`defaultTestSuite`).
- `tests/Integration/S3/`: the storage contract against the S3 test server in both checksum modes, S3-specific
  cases (prefix, access errors, injected server errors and dropped connections, a lost Complete response, a changed
  checksum setting, memory and temporary files), and whole transfers from the fake GOG source through the engine
  (mid-transfer failures, pause and resume through `ListParts`, deduplication by abort, resume after exhausted
  retries). `FaultInjectingHttpHandler` breaks chosen requests on their way to the real server.
- `tests/Integration/Nextcloud/`: the storage contract against both Nextclouds, Nextcloud-specific cases (names that
  need encoding, wrong credentials, a full quota, an expired upload folder, a cache that lost the upload, cancelling,
  a chunk the server lacks, a `MOVE` whose answer got lost, proxy failures, a destination folder deleted mid-upload,
  an existing file that must stay intact, memory and temporary files), whole transfers through the engine, and
  `NextcloudChunkingFactsTest`, which pins the server behaviour the adapter relies on. `FaultInjectingHttpClient`
  breaks chosen requests; tests use app passwords, as real targets do.
- `tests/Integration/Resume/`: a worker process (`crash-worker.php`) transfers a 10-part file and is killed with
  SIGKILL while sending part 3, after the target stored part 3 but before its checkpoint, or after completing; a
  second process resumes from the checkpoint file it left. Runs against S3 and both Nextclouds. The fault injectors
  can stall a request halfway through its body for this (`stallOn()`).
  Results: [findings](docs/fork/findings.md#worker-killed-in-the-middle-of-a-transfer-test-services).
- `tests/Fork/Transfer/`, `tests/Support/RecordingHttpClient.php`: unit tests for sources, planner, engine and
  reconciler.
- `tests/Fork/Storage/`: unit tests, and `ArchiveStorageAdapterContractTestCase`, which every adapter's tests
  extend (including a repeated `complete()` and completing a gone session). `tests/Fork/Storage/S3/` tests the S3 adapter's requests and error handling against the SDK's mock handler,
  `tests/Fork/Storage/Nextcloud/` the Nextcloud adapter's against Symfony's `MockHttpClient`.
- `tests/Support/TempFiles.php`: bytes the process holds in temporary files, read from `/proc/self/fd`.
- `tests/` (characterization tests of upstream code, fake GOG API, fork tests), `phpunit.xml.dist`,
  `phpstan.dist.neon`, `phpstan-baseline.neon` (upstream's 130 existing findings at level 8),
  `.php-cs-fixer.fork.php` (upstream's style rules, applied to `tests/` only).
- `docker/dev/`, `Makefile`, `tools/ci.sh`, `.github/workflows/fork-tests.yml`: Docker-only development and CI.
- `FORK.md`, `CHANGES-FORK.md`, `docs/fork/findings.md`, `AGENTS.md`, `CLAUDE.md`.

### Repository settings

- Upstream's `build-latest.yaml` and `release.yaml` publish to Docker Hub with upstream's credentials. They
  must stay disabled in this fork's GitHub Actions settings; the files stay unchanged.
