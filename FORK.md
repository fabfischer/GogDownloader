# About this fork

This is a fork of [RikudouSage/GogDownloader](https://github.com/RikudouSage/GogDownloader). It is used as a
library by a separate, self-hosted archiving application, so it needs things the original tool doesn't have yet:

- automated tests for the existing behaviour (added),
- a CLI that also runs when the package is installed as a Composer dependency (added),
- downloads that stream bounded byte ranges without spooling whole files to disk, and a transfer engine that moves
  them part by part with checkpoints (`src/Transfer/`, added),
- a storage contract for archive targets with an in-memory implementation and reusable contract tests
  (`src/Storage/`, added),
- resumable uploads to S3-compatible storage and to Nextcloud over WebDAV, with upload state that a caller can save
  and resume in another process (planned).

The original command-line tool keeps working. Upstream's README describes how to use it.

## Base and changes

| Item | Value |
|---|---|
| Upstream | <https://github.com/RikudouSage/GogDownloader> |
| Base | `v1.15.1` (`2602dbc300c8c07973830b9f15c92265ed615948`, 2026-07-02) |
| Changes | [CHANGES-FORK.md](CHANGES-FORK.md) |
| Known issues found by the tests | [docs/fork/findings.md](docs/fork/findings.md) |

Fixes that upstream could use are kept as small, separate commits without fork-only files, so they can be offered
upstream as pull requests.

## Using it as a dependency

The package keeps its original name. Point Composer at this fork with a VCS repository
([Composer docs](https://getcomposer.org/doc/05-repositories.md#vcs)):

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/fabfischer/GogDownloader"}
    ],
    "require": {
        "rikudou/gog-downloader": "dev-main"
    }
}
```

`composer.lock` then pins the exact commit. The CLI is available as `vendor/bin/app.php`. It keeps its state where
`CONFIG_DIRECTORY` points (default: the current directory) and downloads to `DOWNLOAD_DIRECTORY`. Both are read
from `$_ENV`, so PHP's `variables_order` must include `E`.

## Development

Everything runs in Docker; no PHP is needed on the host. `make help` lists every command.

```bash
make build                       # build the dev image (docker/dev/)
make composer c="install"        # install dependencies
make ci                          # Composer checks, code style, PHPStan, PHPUnit (same as GitHub Actions)
make test c="--filter S3"        # PHPUnit only
make cli c="list"                # run the CLI from source; state lives in the cli-config volume
make clean                       # remove the dev containers and image
```

## Syncing with upstream

```bash
git remote add upstream https://github.com/RikudouSage/GogDownloader.git   # once
git remote set-url --push upstream DISABLED-no-push-to-upstream             # once
git fetch upstream --tags
git switch -c sync/upstream-<tag> main
git merge upstream/main          # or a tag, e.g. v1.16.0
```

Then resolve conflicts (mostly `composer.json` / `composer.lock`; re-run `make composer c="update --lock"`), run
`make ci`, regenerate the PHPStan baseline only if upstream code changed (`make stan-baseline`), and update the base
in this file and in [CHANGES-FORK.md](CHANGES-FORK.md).

## License

MIT, unchanged. Copyright (c) 2022 Dominik Chrástecký, see [LICENSE](LICENSE). The fork's changes are under the
same license.
