# Publishing

Composer installs this library from [Packagist](https://packagist.org/packages/leo-gan/gld-arrow-php) as `leo-gan/gld-arrow-php`.

The version in `composer.json` is the release version. The GitHub release tag uses the same number as the Mojo libraries: `0.2.0`, with no `v` prefix. `scripts/publish.sh` refuses a tag whose number differs from `composer.json`.

## Register once

1. Sign in to [Packagist](https://packagist.org/login/) with GitHub as `leo-gan`. Grant the Packagist GitHub application access to this repository: [github.com/settings/connections/applications/a059f127e1c09c04aa5a](https://github.com/settings/connections/applications/a059f127e1c09c04aa5a).
2. Submit this exact URL at [packagist.org/packages/submit](https://packagist.org/packages/submit):

    `https://github.com/leo-gan/gld-arrow-php`

3. Open [packagist.org/profile](https://packagist.org/profile/) and copy the API token.
4. In this GitHub repository, open Settings, then Secrets and variables, then Actions. Add repository secrets:

    | Name | Value |
    | --- | --- |
    | `PACKAGIST_USERNAME` | Packagist username. It is `leo-gan` when you sign in with that GitHub account. |
    | `PACKAGIST_TOKEN` | API token from the profile page. |

The vendor half of `leo-gan/gld-arrow-php` has to be your Packagist username. Packagist assigns that vendor to the account that publishes it first.

Signing in with GitHub lets Packagist install a push hook. The release workflow also calls the update API, so a missing hook still publishes the tag.

## Publish a version

Merge `.github/workflows/publish.yml` to `main` before creating the release. The workflow runs only from the default branch.

```bash
git checkout main
git pull
git tag 0.2.0
git push origin 0.2.0
gh release create 0.2.0 --target main --title 0.2.0 --generate-notes
```

The Publish workflow runs the tests, then `scripts/publish.sh` asks Packagist to crawl the tag. Check the plan without calling Packagist:

```bash
bash scripts/publish.sh --dry-run
```

Actions also has a `dry_run` input on workflow_dispatch.

## Install

```bash
composer require leo-gan/gld-arrow-php
```
