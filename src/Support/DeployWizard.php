<?php

namespace CocomediaNL\LaravelWebhostingDeploy\Support;

use CocomediaNL\LaravelWebhostingDeploy\Drivers\DriverManager;
use CocomediaNL\LaravelWebhostingDeploy\Drivers\HostingDriver;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class DeployWizard
{
    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $driver = select(
            label: 'Where should this application be deployed?',
            options: [
                'directadmin' => 'DirectAdmin',
                'transip' => 'TransIP webhosting',
            ],
            default: (string) config('webhosting-deploy.driver', 'directadmin'),
            hint: 'You can switch later by running the setup command again.'
        );

        $host = text(
            label: 'SSH host',
            default: (string) config('webhosting-deploy.ssh.host', ''),
            required: true,
            hint: 'Hostname or IP of the webhosting server.'
        );

        $username = text(
            label: 'SSH username',
            default: (string) config('webhosting-deploy.ssh.username', ''),
            required: true
        );

        $port = text(
            label: 'SSH port',
            default: (string) config('webhosting-deploy.ssh.port', 22),
            required: true,
            validate: fn (string $value) => ctype_digit($value) && (int) $value > 0
                ? null
                : 'Enter a valid port number.'
        );

        $siteDir = (string) config('webhosting-deploy.deployment.site_dir', '');

        if ($driver === 'directadmin') {
            $siteDir = text(
                label: 'Website folder (DirectAdmin site directory)',
                default: $siteDir,
                required: true,
                hint: 'Usually the domain name, e.g. example.com'
            );
        } else {
            $confirmed = confirm(
                label: 'Have you set the TransIP DocumentRoot (website path) to /www/public?',
                default: true,
                hint: 'Control panel → Website → Domeinen & SSL → Websitepad bewerken'
            );

            if (! $confirmed) {
                warning('Set the DocumentRoot to /www/public before going live. The deploy will continue without a public_html symlink.');
            }
        }

        $appUrl = text(
            label: 'Application URL',
            default: $this->defaultAppUrl($siteDir),
            required: true,
            hint: 'Used as APP_URL on the remote .env'
        );

        $database = select(
            label: 'Which database should the remote application use?',
            options: [
                'mysql' => 'MySQL (create the database in the control panel first)',
                'sqlite' => 'SQLite',
            ],
            default: 'mysql'
        );

        $db = [
            'connection' => $database,
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => $database === 'sqlite' ? 'database/database.sqlite' : '',
            'username' => '',
            'password' => '',
        ];

        if ($database === 'mysql') {
            $db['host'] = text(
                label: 'MySQL host',
                default: '127.0.0.1',
                required: true,
                hint: 'Use the hostname from your hosting control panel if it is not localhost.'
            );
            $db['port'] = text(
                label: 'MySQL port',
                default: '3306',
                required: true
            );
            $db['database'] = text(
                label: 'MySQL database name',
                required: true
            );
            $db['username'] = text(
                label: 'MySQL username',
                required: true
            );
            $db['password'] = password(
                label: 'MySQL password',
                required: true
            );
        } else {
            $db['database'] = text(
                label: 'SQLite database path',
                default: 'database/database.sqlite',
                required: true,
                hint: 'Relative to the Laravel root on the server.'
            );
        }

        $runMigrations = confirm(
            label: 'Run database migrations after deploy?',
            default: true,
            hint: 'Choose no if the database is not ready yet. You can migrate later.'
        );

        note('Database credentials are written to the remote .env only — not to your local .env or GitHub secrets.');

        return [
            'driver' => $driver,
            'ssh_host' => $host,
            'ssh_username' => $username,
            'ssh_port' => (int) $port,
            'site_dir' => $siteDir,
            'app_url' => $appUrl,
            'database' => $db,
            'run_migrations' => $runMigrations,
        ];
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function applyToConfig(array $answers): void
    {
        config([
            'webhosting-deploy.driver' => $answers['driver'],
            'webhosting-deploy.ssh.host' => $answers['ssh_host'],
            'webhosting-deploy.ssh.username' => $answers['ssh_username'],
            'webhosting-deploy.ssh.port' => $answers['ssh_port'],
            'webhosting-deploy.deployment.site_dir' => $answers['site_dir'] ?: null,
            'webhosting-deploy.deployment.run_migrations' => (bool) $answers['run_migrations'],
            'webhosting-deploy.runtime.wizard' => $answers,
        ]);
    }

    /**
     * Persist deploy connection settings to the local .env (no database secrets).
     *
     * @param  array<string, mixed>  $answers
     */
    public function writeLocalEnv(array $answers): void
    {
        $values = [
            'WEBHOSTING_DRIVER' => $answers['driver'],
            'WEBHOSTING_SSH_HOST' => $answers['ssh_host'],
            'WEBHOSTING_SSH_USERNAME' => $answers['ssh_username'],
            'WEBHOSTING_SSH_PORT' => (string) $answers['ssh_port'],
        ];

        if (($answers['driver'] ?? '') === 'directadmin' && ! empty($answers['site_dir'])) {
            $values['WEBHOSTING_SITE_DIR'] = $answers['site_dir'];
        }

        EnvFile::upsert(base_path('.env'), $values);
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, string>
     */
    public function remoteEnvValues(array $answers): array
    {
        $db = $answers['database'] ?? [];
        $values = [
            'APP_URL' => (string) ($answers['app_url'] ?? ''),
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => (string) ($db['connection'] ?? 'mysql'),
        ];

        if (($db['connection'] ?? '') === 'sqlite') {
            $values['DB_DATABASE'] = (string) ($db['database'] ?? 'database/database.sqlite');
        } else {
            $values['DB_HOST'] = (string) ($db['host'] ?? '127.0.0.1');
            $values['DB_PORT'] = (string) ($db['port'] ?? '3306');
            $values['DB_DATABASE'] = (string) ($db['database'] ?? '');
            $values['DB_USERNAME'] = (string) ($db['username'] ?? '');
            $values['DB_PASSWORD'] = (string) ($db['password'] ?? '');
        }

        return $values;
    }

    public function driver(): HostingDriver
    {
        return DriverManager::make();
    }

    protected function defaultAppUrl(string $siteDir): string
    {
        $configured = (string) config('app.url', '');

        if ($configured !== '' && ! str_contains($configured, 'localhost')) {
            return $configured;
        }

        if ($siteDir !== '') {
            return 'https://'.$siteDir;
        }

        return 'https://';
    }
}
