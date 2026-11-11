# Findings from the characterization tests

`tests/Upstream/` pins upstream's behaviour at `v1.15.1` as it is, including the flaws below, so any change shows up
as a failing test. All test data is synthetic (`tests/Support/FakeGogApi.php`).

Measured on 2026-11-11 (PHP 8.4.27, symfony/http-client 6.4.50, aws/aws-sdk-php 3.403.3), partly with one read-only
probe of a real GOG account (metadata, download links, checksum XML, a few 64 KiB byte ranges). The details are in
[Measurements](#measurements) below.

| # | Finding | Evidence | Impact |
|---|---|---|---|
| F1 | **S3 uploads get a corrupted tail.** `S3FileReference::finalize()` passes the leftover buffer to `writeChunk()`, which prepends the buffer again, so the last part contains the remaining bytes twice (21 bytes written, 22 uploaded). Only files whose size is an exact multiple of the chunk size come out intact. The MD5 tag is computed from the stream, so later runs read the tag and treat the corrupt object as valid. | `S3FileReferenceTest::testFullChunksBecomeNumberedPartsAndFinalizeCompletesAndTags` | Data corruption on S3 targets. The fork's S3 adapter (`src/Storage/S3/`) is a separate code path and not affected; upstream's CLI still is. Worth reporting upstream. |
| F2 | **Downloads are buffered by default.** `DownloadManager::download()` leaves Symfony HttpClient's `buffer` option at `true`. The client then copies the body into `php://temp` (`symfony/http-client` `TransportResponseTrait`: `true === $response->shouldBuffer` → `fopen('php://temp', 'w+')`), which PHP moves to a temporary file above 2 MiB. | Source reading, then measured: a streamed 64 MiB response left **64 MiB in temporary files** with the default and 0 with `buffer => false` | Large downloads use as much temporary disk as the file size. Streaming needs `buffer => false`; the fork's `App\Transfer\Source\HttpRangeSource` sets it. |
| F3 | The GOG bearer token is also sent to the CDN host that serves the signed file URL. | `DownloadManagerTest::testDownloadUsesAnOpenEndedRangeAndSendsTheBearerTokenToTheCdn`; measured: the CDN serves byte ranges of the signed URL with 206 **without** any `Authorization` header | The token reaches a host that doesn't need it. |
| F4 | Resume uses an open-ended `Range: bytes=N-`, not bounded parts. | same test | No per-part retries or checkpoints. |
| F5 | Multi-part installers become separate `GameInstaller` objects with the same name. `version`, file IDs and extra types are dropped. Language packs are flattened into the installer list without a marker. | `OwnedItemsManagerTest::testGameDetailFlattensInstallersPatchesLanguagePacksAndOwnedDlc`, `testNormalizersDropVersionAndFileIdentity` | Callers can't tell builds or parts apart without the raw product payload. |
| F6 | An owned extra whose download link answers 403/404 is silently dropped from the game detail. | `testExtrasAreFilteredToOwnedOnesAndDroppedWhenTheirDownlinkFails` | Missing extras look like they never existed. |
| F7 | Missing checksums become `''` (not `null`). Every file's checksum lookup fetches the whole product payload again (9 product requests for one test game). | `testChecksumLookupCostsSeveralRequestsPerFile`; measured: the checksum XML URL of an extra answered 404 | "Unknown" and "empty" look the same; many extra requests per game. |
| F8 | With a version file (`bin/appversion`, as in upstream's release builds), `PreCommandListener` sends a HEAD request to GitHub on every CLI command. Builds without it (`dev-version`) don't. | `src/Service/NewVersionChecker.php`; `CliKernelSmokeTest` stays offline | A network call per command in release builds. |
| F9 | Upstream's own `src/` doesn't pass its own php-cs-fixer rules (32 of 105 files at v1.15.1). | `vendor/bin/php-cs-fixer check` | The fork checks style for `tests/` only and doesn't reformat `src/`. |
| F10 | The dependencies locked at v1.15.1 had known security advisories (Guzzle 7.13.1, several Symfony 6.4/7.4 components, some rated high). | `composer audit --locked` | Fixed in the fork within the existing constraints; `make ci` fails on new advisories. |

## Measurements

### Temporary files and request bodies (local test server, no GOG)

- **Symfony HttpClient**, reading a response the way `DownloadManager::download()` does (`request()`, then
  `stream()`): 64 MiB received → 64 MiB peak in temporary files with the default `buffer` option, 0 with
  `buffer => false`. `php://temp` moves to a temporary file above 2 MiB
  ([PHP manual](https://www.php.net/manual/en/wrappers.php.php)).
- **AWS SDK `UploadPart`**, 16 MiB part:
  - a PHP string body ends up in a 16 MiB temporary file, while a seekable `php://memory` stream causes none;
  - non-seekable bodies fail, because the SDK hashes the body (SHA-256 payload signature, and by default a CRC32
    checksum header) before sending it;
  - `request_checksum_calculation: when_required` drops the CRC32 header.
- **Hash state:** SHA-256, MD5 and CRC32 contexts survive `serialize()` and give the right hash when resumed in a new
  process. HMAC contexts can't be serialized.

### S3 part uploads (S3-compatible test server)

Measured on 2026-11-11 with `App\Storage\S3\S3StorageAdapter`, aws/aws-sdk-php 3.387.0 and Guzzle 7.16.1 against the
Versity S3 Gateway v1.10.0 of the `test` profile (both containers on one Docker network).

| Part | Checksums | Extra peak memory while sending | Temporary files | Time per part |
|---|---|---|---|---|
| 16 MiB | off | 1.3 MiB | 0 | 0.19 s |
| 64 MiB | off | 1.1 MiB | 0 | 0.76 s |
| 16 MiB | CRC32 | 1.2 MiB | 0 | 0.23 s |
| 64 MiB | CRC32 | 1.2 MiB | 0 | 0.91 s |

- "Extra" is on top of the part's own `php://memory` buffer. The SDK reads that buffer for the SHA-256 signature and
  the CRC32 without copying it. Both values are also checked by `tests/Integration/S3/S3StorageAdapterTest.php`.
- **`Expect: 100-continue` cost one second per part.** Guzzle adds the header to bodies over 1 MB
  ([Guzzle docs](https://docs.guzzlephp.org/en/stable/request-options.html#expect)), and curl waits up to 1,000 ms
  for the server's answer before sending the body anyway
  ([libcurl docs](https://curl.se/libcurl/c/CURLOPT_EXPECT_100_TIMEOUT_MS.html)). The test server never answered it, so
  each part took about one second longer (16 MiB: 1.22 s with the header, 0.19 s without). The adapter turns the header
  off.
- **Early refusals can look like a broken connection.** Without the header the body goes out at once. When the server
  refuses a part before reading it (for example an upload that was already aborted), it answers and closes the
  connection while the body is still being sent; curl then reports "Send failure: Connection reset by peer" (error 55)
  instead of the answer. The adapter asks the server with a small `ListParts` request whenever a part upload ends
  without an answer, so an unknown upload is still reported as one.
- **A PSR-7 stream closes its resource when it is freed.** The SDK wraps the part's stream in one; the adapter detaches
  it after each request, otherwise the caller's buffer is closed and the same part can't be sent again.

### GOG metadata and CDN (one read-only probe)

- **Download link:** `files[].downlink` returns JSON with `downlink` (a signed URL on `gog-cdn-fastly.gog.com`) and
  `checksum` (the checksum XML URL).
  - The signed URL carries an `nva` timestamp about 24 h ahead and serves data without a redirect.
  - Bounded ranges return 206 with an exact `Content-Range`.
  - `ETag` and `Last-Modified` stay the same across ranges and HEAD.
- **ETag:** large files have a multipart-style ETag (`"<md5>-<n>"`), so it is not the content MD5.
- **Sizes:** the product API's `files[].size` is rounded down to whole MiB. The real size comes from `Content-Range`,
  HEAD or the checksum XML `total_size`.
- **Checksum XML:** the file MD5 plus one MD5 per 10 MiB chunk (`<chunk id from to method="md5">`). Some extras have
  none (404).
