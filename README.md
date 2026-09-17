# Laravel Webhosting Deploy

Deploy a Laravel application to shared webhosting (DirectAdmin or TransIP) over SSH, with optional GitHub Actions.

Originally inspired by laravel-hostinger-deploy by TheCodeholic. Previously published as `cocomedia-nl/laravel-directadmin-deploy`.

## Installation

```bash
composer require cocomedia-nl/laravel-webhosting-deploy --dev
```

This package is a development dependency: it is only needed on your machine (and in CI setup), not on the production server.

## Quick start

```bash
php artisan webhosting:deploy-and-setup-cicd
```

A [Laravel Prompts](https://laravel.com/framework/docs/prompts) wizard asks:

1. **Target** — DirectAdmin or TransIP
2. **SSH** — host, username, port
3. **DirectAdmin** — website folder (`domains/{site}/laravel_html`)
4. **TransIP** — confirmation that the control panel DocumentRoot is `/www/public`
5. **APP_URL**
6. **Database** — SQLite or MySQL (MySQL credentials are written to the **remote** `.env` only)
7. Whether to run migrations

The command then deploys the app, writes local `WEBHOSTING_*` settings, creates GitHub secrets (`SSH_*` and `APP_PATH`), and publishes `.github/workflows/webhosting-deploy.yml`.

Switching from a DirectAdmin test server to TransIP live is the same command: choose TransIP in the wizard. The previous test server can be retired afterwards.

## Hosting layouts

### DirectAdmin

- App path: `~/domains/{site}/laravel_html`
- Creates `public_html` → `laravel_html/public` (this step is **not** run on TransIP)

### TransIP

See [Laravel on TransIP webhosting](https://www.transip.nl/knowledgebase/website-algemeen/laravel-installeren-op-een-webhostingpakket) and [DocumentRoot](https://www.transip.nl/knowledgebase/website-algemeen/6605-de-documentroot-van-je-website).

- App path: `~/www`
- **No** `public_html` symlink — set Websitepad / DocumentRoot to `/www/public` in the control panel
- Create the MySQL database in the control panel before running migrations

Override the app path with `WEBHOSTING_APP_PATH` (relative to the SSH home directory).

## Commands

| Command | Alias (legacy) | Purpose |
| --- | --- | --- |
| `webhosting:test-connection` | `directadmin:test-connection` | Test SSH, inbound server-key login, server Git deploy key, and GitHub API without deploying |
| `webhosting:deploy-and-setup-cicd` | `directadmin:deploy-and-setup-cicd` | Wizard, deploy, GitHub secrets + workflow |
| `webhosting:deploy` | `directadmin:deploy` | Deploy only (wizard if SSH settings are missing) |
| `webhosting:setup-cicd` | `directadmin:setup-cicd` | Publish workflow and GitHub secrets |
| `webhosting:publish-workflow` | `directadmin:publish-workflow` | Write the workflow file locally |

Useful options:

- `--fresh` — delete the remote app directory and clone again
- `--reconfigure` — run the wizard again
- `--token=` — GitHub Personal Access Token (or `GITHUB_API_TOKEN` in `.env`)
- `--branch=` / `--php-version=` — workflow generation
- `--show-errors` — print SSH/GitHub error output on failure

## Environment variables

The wizard writes these to your **local** `.env`. Existing `DIRECTADMIN_*` keys still work as fallbacks.

| Variable | Required | Description |
| --- | --- | --- |
| `WEBHOSTING_DRIVER` | No (default `directadmin`) | `directadmin` or `transip` |
| `WEBHOSTING_SSH_HOST` | Yes | SSH hostname or IP |
| `WEBHOSTING_SSH_USERNAME` | Yes | SSH username |
| `WEBHOSTING_SSH_PORT` | No (default `22`) | SSH port |
| `WEBHOSTING_SITE_DIR` | DirectAdmin only | Domain folder name |
| `WEBHOSTING_APP_PATH` | No | Override remote app path from `$HOME` |
| `GITHUB_API_TOKEN` | For automated secrets | PAT with Administration + Secrets write |

Database credentials are **not** stored locally and are **not** added as GitHub secrets.

## GitHub Actions

Published file: `.github/workflows/webhosting-deploy.yml`

Secrets created by `webhosting:setup-cicd`:

- `SSH_HOST`, `SSH_USERNAME`, `SSH_PORT`, `SSH_KEY`
- `APP_PATH` — relative to home (`domains/example.com/laravel_html` or `www`)

Review, commit, and push the workflow yourself. Subsequent pushes deploy with `git reset`, `composer install`, migrate, and cache commands. CI does not recreate the DirectAdmin `public_html` symlink (that happens on the first Artisan deploy).

## SSH keys

Two different authorizations are involved:

1. **Outbound (git fetch)** — the server public key as a GitHub deploy key. Setup via API when `GITHUB_API_TOKEN` is set.
2. **Inbound (GitHub Actions)** — that same key in `authorized_keys`, so CI can SSH in with secret `SSH_KEY`.

On **DirectAdmin**, writing `~/.ssh/authorized_keys` is often not enough. sshd may ignore the file (StrictModes with a group-writable home, or a panel-managed key list). Authorize the same public key in DirectAdmin: **Advanced Features → SSH Keys**, with Authorize / Allow login enabled. That panel step cannot be automated without DirectAdmin login credentials, which this package does not store.

`webhosting:setup-cicd` and `webhosting:test-connection` verify whether the server key is actually accepted for login, and print the public key plus panel steps when it is not.

Your local SSH login (password or your own key) is separate from the server key CI uses.

## Requirements

- PHP ^8.2
- Laravel ^11 / ^12 / ^13
- SSH access to the hosting account
- Git repository (GitHub recommended)
- PHP `exec()` enabled (used for SSH and process management)

## Migrating from `laravel-directadmin-deploy`

Existing apps that require `cocomedia-nl/laravel-directadmin-deploy` stay on **0.1.8** until you switch. `composer update` will not pick up this package automatically.

Per project:

```bash
composer remove cocomedia-nl/laravel-directadmin-deploy
composer require cocomedia-nl/laravel-webhosting-deploy --dev
```

- `DIRECTADMIN_SSH_*` and `DIRECTADMIN_SITE_DIR` keep working
- `directadmin:*` Artisan commands remain as aliases
- Republish the workflow (`php artisan webhosting:publish-workflow` or setup-cicd), then remove `.github/workflows/directadmin-deploy.yml` and `config/directadmin-deploy.php` if you had published them
- Do not install both packages in the same app

## License

MIT
