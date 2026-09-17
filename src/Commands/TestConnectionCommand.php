<?php

namespace CocomediaNL\LaravelWebhostingDeploy\Commands;

use CocomediaNL\LaravelWebhostingDeploy\Services\GitHubAPIService;

class TestConnectionCommand extends BaseWebhostingCommand
{
    protected $signature = 'webhosting:test-connection
                            {--token= : GitHub Personal Access Token}
                            {--show-errors : Show detailed error output}';

    protected $description = 'Test SSH, inbound server-key login, server deploy-key Git access, and GitHub API without deploying';

    protected $aliases = ['directadmin:test-connection'];

    public function handle(): int
    {
        $sshOk = $this->testSshConnection();
        $inboundOk = $sshOk ? $this->testInboundServerKeyLogin() : false;
        $gitFromServerOk = $sshOk ? $this->testServerGitAccess() : false;
        $githubOk = $this->testGitHubConnection();

        $this->line('');

        if ($sshOk && $inboundOk && $gitFromServerOk && $githubOk) {
            $this->info('All connection checks passed.');

            return self::SUCCESS;
        }

        $this->error('One or more connection checks failed.');

        return self::FAILURE;
    }

    protected function testSshConnection(): bool
    {
        $host = config('webhosting-deploy.ssh.host');
        $username = config('webhosting-deploy.ssh.username');
        $port = (int) config('webhosting-deploy.ssh.port', 22);
        $driver = $this->driver();

        $this->info('SSH');
        $this->line('');
        $this->line('  Driver:   '.$driver->name());
        $this->line("  Host:     {$username}@{$host}:{$port}");
        $this->line('  App path: ~/'.$driver->relativeAppPath());
        $this->line('');

        if (empty($host) || empty($username)) {
            $this->error('SSH is not configured (WEBHOSTING_SSH_HOST / WEBHOSTING_SSH_USERNAME).');

            return false;
        }

        $this->setupSshConnection();

        try {
            $this->ssh->execute('echo SSH_OK');
        } catch (\Exception $e) {
            $this->error('SSH connection failed.');
            $this->printErrorDetails($e);

            return false;
        }

        $whoami = $this->remoteProbe('printf %s "${USER:-}"');
        $home = $this->remoteProbe('printf %s "${HOME:-}"');
        $php = $this->remoteProbe('php -r "echo PHP_VERSION;" 2>/dev/null || echo unavailable');
        $composer = $this->remoteProbe('composer --version --no-ansi 2>/dev/null | head -n 1 || echo unavailable');
        $git = $this->remoteProbe('git --version 2>/dev/null || echo unavailable');

        $this->info('SSH connection successful.');
        $this->line("  Remote user: {$whoami}");
        $this->line("  Home:        {$home}");
        $this->line("  PHP:         {$php}");
        $this->line("  Composer:    {$composer}");
        $this->line("  Git:         {$git}");

        if ($driver->requiresSiteDir() && empty($this->getSiteDir())) {
            $this->line('');
            $this->warn('Skipping app-path check: WEBHOSTING_SITE_DIR (or DIRECTADMIN_SITE_DIR) is not set.');

            return true;
        }

        $appPath = $driver->shellAppPath();

        try {
            $exists = trim($this->ssh->execute("test -d {$appPath} && echo yes || echo no"));
        } catch (\Exception $e) {
            $this->line('');
            $this->warn('Could not check the application directory.');
            $this->printErrorDetails($e);

            return true;
        }

        $this->line('');

        if ($exists !== 'yes') {
            $this->warn('Application directory does not exist yet: ~/'.$driver->relativeAppPath());
            $this->line('That is expected before the first deploy.');

            return true;
        }

        $this->info('Application directory exists: ~/'.$driver->relativeAppPath());

        try {
            $isLaravel = trim($this->ssh->execute(
                "test -f {$appPath}/artisan && test -f {$appPath}/composer.json && echo yes || echo no"
            ));
        } catch (\Exception $e) {
            return true;
        }

        if ($isLaravel === 'yes') {
            $this->info('Laravel project detected (artisan + composer.json).');
        } else {
            $this->warn('Directory exists but does not look like a Laravel project yet.');
        }

        return true;
    }

    /**
     * Verify GitHub Actions can log in with the server private key (secret SSH_KEY).
     */
    protected function testInboundServerKeyLogin(): bool
    {
        $this->line('');
        $this->info('Inbound SSH (GitHub Actions)');
        $this->line('');

        $hasKey = $this->remoteProbe('test -f ~/.ssh/id_rsa && echo yes || echo no', 'no');

        if ($hasKey !== 'yes') {
            $this->warn('No server key yet (~/.ssh/id_rsa). Skipping inbound login check.');
            $this->line('Run php artisan webhosting:setup-cicd to generate the key used as GitHub secret SSH_KEY.');

            return true;
        }

        if ($this->verifyInboundServerKey($this->ssh->getPublicKey())) {
            return true;
        }

        return false;
    }

    /**
     * Verify the server deploy key can read the Git repository (same path as git fetch in CI).
     */
    protected function testServerGitAccess(): bool
    {
        $this->line('');
        $this->info('Git from server (deploy key)');
        $this->line('');

        $hasKey = $this->remoteProbe('test -f ~/.ssh/id_rsa && echo yes || echo no', 'no');

        if ($hasKey !== 'yes') {
            $this->error('No SSH key found on the server (~/.ssh/id_rsa).');
            $this->line('The first deploy or webhosting:setup-cicd generates this key and adds it as a GitHub deploy key.');

            return false;
        }

        $this->line('  Server key: ~/.ssh/id_rsa');

        $repoUrl = $this->serverGitRepositoryUrl();

        if (! $repoUrl) {
            $this->error('Could not determine the GitHub repository URL to test.');
            $this->line('Run this command from a Git repository with a GitHub origin remote.');

            return false;
        }

        $this->line("  Remote:     {$repoUrl}");

        $gitHost = $this->gitHostFromUrl($repoUrl);

        if ($gitHost) {
            try {
                $this->ssh->execute(
                    'mkdir -p ~/.ssh && chmod 700 ~/.ssh && ssh-keyscan -H '.escapeshellarg($gitHost).' >> ~/.ssh/known_hosts 2>/dev/null || true'
                );
            } catch (\Exception $e) {
                $this->warn('Could not update known_hosts for '.$gitHost.'.');
                $this->printErrorDetails($e);
            }
        }

        $escapedRepoUrl = escapeshellarg($repoUrl);
        $appPath = $this->driver()->shellAppPath();

        try {
            $hasOrigin = trim($this->ssh->execute(
                "test -d {$appPath}/.git && echo yes || echo no"
            ));
        } catch (\Exception $e) {
            $hasOrigin = 'no';
        }

        try {
            if ($hasOrigin === 'yes') {
                $this->line('  Using:      git ls-remote origin HEAD (existing clone)');
                $output = $this->ssh->execute("cd {$appPath} && ".$this->gitLsRemoteCommand('origin HEAD'));
            } else {
                $this->line('  Using:      git ls-remote (server deploy key, no clone yet)');
                $output = $this->ssh->execute($this->gitLsRemoteCommand("{$escapedRepoUrl} HEAD"));
            }
        } catch (\Exception $e) {
            $this->error('The server cannot read the Git repository with its SSH key.');
            $this->explainServerGitFailure($e, $repoUrl);
            $this->printGitErrorDetails($e);

            return false;
        }

        if (trim($output) === '') {
            $this->error('git ls-remote succeeded but returned no refs.');

            return false;
        }

        $this->info('Server deploy key can fetch this repository (git fetch will work).');

        return true;
    }

    protected function serverGitRepositoryUrl(): ?string
    {
        $repoInfo = $this->github->getRepositoryInfo();

        if ($repoInfo) {
            return "git@github.com:{$repoInfo['owner']}/{$repoInfo['name']}.git";
        }

        $url = $this->github->getRepositoryUrl();

        return $url ?: null;
    }

    protected function gitHostFromUrl(string $repoUrl): ?string
    {
        if (preg_match('/git@([^:]+):/', $repoUrl, $matches)) {
            return $matches[1];
        }

        if (preg_match('/https?:\/\/([^\/]+)/', $repoUrl, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Force the server deploy key. Shared hosting often has another identity that GitHub already knows.
     */
    protected function gitLsRemoteCommand(string $target): string
    {
        return "GIT_SSH_COMMAND='ssh -i ~/.ssh/id_rsa -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new' git ls-remote {$target}";
    }

    protected function explainServerGitFailure(\Exception $e, string $repoUrl): void
    {
        $message = $e->getMessage();
        $repoInfo = $this->github->getRepositoryInfo();
        $publicKey = $this->ssh->getPublicKey();
        $token = $this->option('token') ?: env('GITHUB_API_TOKEN') ?: config('webhosting-deploy.github.api_token');

        if ($publicKey && $repoInfo && $token) {
            try {
                $api = new GitHubAPIService((string) $token);
                $onThisRepo = $api->keyExists($repoInfo['owner'], $repoInfo['name'], $publicKey);
            } catch (\Exception) {
                $onThisRepo = null;
            }

            if ($onThisRepo === true) {
                $this->line('The server public key is already a deploy key on this GitHub repository.');
                $this->line('Git still cannot fetch. Check that github.com is reachable from the server and in known_hosts.');

                return;
            }

            if ($onThisRepo === false) {
                $this->line('The TransIP/DirectAdmin public key is not a deploy key on this GitHub repository.');
                $this->line('The GitHub API token can see the repo; the server key cannot. Those are different credentials.');
                $this->line('A GitHub deploy key can be attached to only one repository. If this hosting account already deploys another repo, ~/.ssh/id_rsa is probably that other key.');
                $this->line('Run php artisan webhosting:setup-cicd to add this server key, or add ~/.ssh/id_rsa.pub under Settings → Deploy keys.');

                return;
            }
        }

        if (stripos($message, 'Repository not found') !== false || stripos($message, 'Permission denied') !== false) {
            $this->line('GitHub rejected ~/.ssh/id_rsa for '.$repoUrl.'.');
            $this->line('Add that public key as a deploy key, or run php artisan webhosting:setup-cicd.');
            $this->line('A deploy key works on only one GitHub repository.');
        }
    }

    protected function printGitErrorDetails(\Exception $e): void
    {
        if (! $this->option('show-errors')) {
            $this->line('Run with --show-errors for details.');

            return;
        }

        $lines = preg_split("/\r\n|\n/", $e->getMessage()) ?: [];
        $useful = [];

        foreach ($lines as $line) {
            if (preg_match('/ERROR:|fatal:|Permission denied|Repository not found|Host key verification|Could not read from remote/i', $line)) {
                $useful[] = trim($line);
            }
        }

        $this->warn('Error details:');

        foreach ($useful !== [] ? $useful : explode("\n", $e->getMessage()) as $line) {
            $this->line('  '.$line);
        }
    }

    protected function testGitHubConnection(): bool
    {
        $this->line('');
        $this->info('GitHub');
        $this->line('');

        $token = $this->option('token') ?: env('GITHUB_API_TOKEN') ?: config('webhosting-deploy.github.api_token');

        if (! $token) {
            $this->error('No GitHub API token found.');
            $this->line('Set GITHUB_API_TOKEN in your .env, or pass --token=.');
            $this->line('Without a token, deploy keys and GitHub secrets must be added manually.');

            return false;
        }

        $this->line('  Token:     '.str_repeat('*', 8).substr((string) $token, -4));

        try {
            $api = new GitHubAPIService((string) $token);
            $user = $api->getAuthenticatedUser();
        } catch (\Exception $e) {
            $this->error('GitHub API authentication failed.');
            $this->printErrorDetails($e);

            return false;
        }

        $login = $user['login'] ?? 'unknown';
        $this->info("GitHub API token is valid (authenticated as {$login}).");

        $repoInfo = $this->github->getRepositoryInfo();

        if (! $repoInfo) {
            $this->warn('Could not detect a GitHub origin remote in this directory.');

            return true;
        }

        $this->line("  Repository: {$repoInfo['owner']}/{$repoInfo['name']}");

        try {
            $api->getRepository($repoInfo['owner'], $repoInfo['name']);
            $this->info('Token can access this repository.');
        } catch (\Exception $e) {
            $this->error('Token cannot access this repository.');
            $this->printErrorDetails($e);

            return false;
        }

        return true;
    }

    protected function remoteProbe(string $command, string $fallback = 'unavailable'): string
    {
        try {
            $value = trim($this->ssh->execute($command));

            return $value !== '' ? $value : $fallback;
        } catch (\Exception) {
            return $fallback;
        }
    }

    protected function printErrorDetails(\Exception $e): void
    {
        if (! $this->option('show-errors')) {
            $this->line('Run with --show-errors for details.');

            return;
        }

        $this->warn('Error details:');
        foreach (explode("\n", $e->getMessage()) as $line) {
            $this->line('  '.$line);
        }
    }
}
