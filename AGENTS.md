# AGENTS.md: rules for AI agents and contributors

This repository is a public fork of [RikudouSage/GogDownloader](https://github.com/RikudouSage/GogDownloader).
Read [FORK.md](FORK.md) first, then [CHANGES-FORK.md](CHANGES-FORK.md) and
[docs/fork/findings.md](docs/fork/findings.md). When in doubt, ask the maintainer before acting.

## Rules

1. **Docker only.** Run every PHP, Composer, test and lint command through the `Makefile` (`make …`), which uses
   `docker/dev/`. Never use a PHP or Composer installed on the host.
2. **Keep upstream mergeable.** Change upstream files (`src/`, `bin/`, `config/`, `composer.json`, `Dockerfile`,
   `.github/workflows/build-latest.yaml`, `.github/workflows/release.yaml`, `README.md`) only when needed, in small
   focused commits. Don't reformat upstream code. Log every change to an upstream file in `CHANGES-FORK.md`.
   Keep fixes that upstream could use free of fork-only files, so they can become upstream pull requests.
3. **This repository is public.** Never commit secrets: GOG tokens, signed download URLs, storage credentials, API
   keys. Don't add personal data, private host names, internal ticket numbers or links to private systems. Commit
   messages carry no `#123` references: on GitHub they would link to unrelated issues.
4. **No real game files.** Installers and other proprietary binaries never go into the repository, test fixtures
   or CI. Tests use synthetic data (`tests/Support/`).
5. **Real accounts belong to their owners.** Agents never log in to GOG or storage services with real credentials.
   Requests against a real GOG account need the account owner's approval for that run and stay read-only (metadata,
   bounded byte-range probes), never whole downloads.
6. **Ask before** pushing, merging into `main`, creating tags, rewriting shared history or changing repository
   settings. Never push to `upstream`; its push URL is disabled on purpose.
7. **Verify before claiming success.** `make ci` must pass. Report the commands you ran and their output.
8. **Workflows** use no secrets and pin third-party actions by commit SHA.

## Conventions

- Branches: `main` = upstream plus the fork's merged work; one work branch per change.
- Commits: Conventional Commits in English (`feat:`, `fix:`, `test:`, `docs:`, `build:`, `ci:`, `refactor:`,
  `chore:`), imperative subject of at most 72 characters, a body that explains why.
- New code follows upstream's style (`.php-cs-fixer.dist.php` rules, checked for `tests/` by
  `.php-cs-fixer.fork.php`) and passes PHPStan level 8 without new baseline entries.

## Commands

```bash
make build                     # build the dev image
make composer c="install"      # any Composer command
make ci                        # everything GitHub Actions runs
make test c="--filter S3"      # PHPUnit only
make stan                      # PHPStan
make cs / make cs-check        # fix / check code style of tests/
make cli c="list"              # the CLI from source
make clean                     # remove containers and the dev image
```
