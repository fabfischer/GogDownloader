# Findings from the characterization tests

`tests/Upstream/` pins upstream's behaviour at `v1.15.1` as it is, including the flaws below, so any change shows up
as a failing test. All test data is synthetic (`tests/Support/FakeGogApi.php`). ⚠️ The real GOG response shapes and
the disk use in F2 have not been measured yet.

| # | Finding | Evidence | Impact |
|---|---|---|---|
| F1 | **S3 uploads get a corrupted tail.** `S3FileReference::finalize()` passes the leftover buffer to `writeChunk()`, which prepends the buffer again, so the last part contains the remaining bytes twice (21 bytes written, 22 uploaded). Only files whose size is an exact multiple of the chunk size come out intact. The MD5 tag is computed from the stream, so later runs read the tag and treat the corrupt object as valid. | `S3FileReferenceTest::testFullChunksBecomeNumberedPartsAndFinalizeCompletesAndTags` | Data corruption on S3 targets. To be fixed in the fork; worth reporting upstream. |
| F2 | **Downloads are buffered by default.** `DownloadManager::download()` leaves Symfony HttpClient's `buffer` option at `true`. The client then copies the body into `php://temp` (`symfony/http-client` `TransportResponseTrait`: `true === $response->shouldBuffer` → `fopen('php://temp', 'w+')`), which PHP moves to a temporary file above 2 MiB. | Source reading; `MockHttpClient` can't show it | Large downloads may use as much temporary disk as the file size. Streaming needs `buffer => false`. |
| F3 | The GOG bearer token is also sent to the CDN host that serves the signed file URL. | `DownloadManagerTest::testDownloadUsesAnOpenEndedRangeAndSendsTheBearerTokenToTheCdn` | The token reaches a host that doesn't need it. |
| F4 | Resume uses an open-ended `Range: bytes=N-`, not bounded parts. | same test | No per-part retries or checkpoints. |
| F5 | Multi-part installers become separate `GameInstaller` objects with the same name. `version`, file IDs and extra types are dropped. Language packs are flattened into the installer list without a marker. | `OwnedItemsManagerTest::testGameDetailFlattensInstallersPatchesLanguagePacksAndOwnedDlc`, `testNormalizersDropVersionAndFileIdentity` | Callers can't tell builds or parts apart without the raw product payload. |
| F6 | An owned extra whose download link answers 403/404 is silently dropped from the game detail. | `testExtrasAreFilteredToOwnedOnesAndDroppedWhenTheirDownlinkFails` | Missing extras look like they never existed. |
| F7 | Missing checksums become `''` (not `null`). Every file's checksum lookup fetches the whole product payload again (9 product requests for one test game). | `testChecksumLookupCostsSeveralRequestsPerFile` | "Unknown" and "empty" look the same; many extra requests per game. |
| F8 | With a version file (`bin/appversion`, as in upstream's release builds), `PreCommandListener` sends a HEAD request to GitHub on every CLI command. Builds without it (`dev-version`) don't. | `src/Service/NewVersionChecker.php`; `CliKernelSmokeTest` stays offline | A network call per command in release builds. |
| F9 | Upstream's own `src/` doesn't pass its own php-cs-fixer rules (32 of 105 files at v1.15.1). | `vendor/bin/php-cs-fixer check` | The fork checks style for `tests/` only and doesn't reformat `src/`. |
| F10 | The dependencies locked at v1.15.1 had known security advisories (Guzzle 7.13.1, several Symfony 6.4/7.4 components, some rated high). | `composer audit --locked` | Fixed in the fork within the existing constraints; `make ci` fails on new advisories. |
