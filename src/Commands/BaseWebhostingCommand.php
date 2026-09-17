<?php

namespace CocomediaNL\LaravelWebhostingDeploy\Commands;

use CocomediaNL\LaravelWebhostingDeploy\Drivers\DriverManager;
use CocomediaNL\LaravelWebhostingDeploy\Drivers\HostingDriver;
use CocomediaNL\LaravelWebhostingDeploy\Services\GitHubActionsService;
use CocomediaNL\LaravelWebhostingDeploy\Services\GitHubAPIService;
use CocomediaNL\LaravelWebhostingDeploy\Services\SshConnectionService;
use CocomediaNL\LaravelWebhostingDeploy\Support\DeployWizard;
use CocomediaNL\LaravelWebhostingDeploy\Support\EnvFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\pause;

abstract class BaseWebhostingCommand extends Command
{
    protected SshConnectionService $ssh;

    protected GitHubActionsService $github;

    protected ?GitHubAPIService $githubAPI = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $wizardAnswers = null;

    public function __construct()
    {
        parent::__construct();
        $this->github = new GitHubActionsService;
    }

    protected function driver(): HostingDriver
    {
        return DriverManager::make();
    }

    /**
     * Validate required configuration after the wizard (if any) has run.
     */
    protected function validateConfiguration(): bool
    {
        $host = config('webhosting-deploy.ssh.host');
        $username = config('webhosting-deploy.ssh.username');

        if (empty($host) || empty($username)) {
            $this->error('Missing SSH host or username. Run php artisan webhosting:deploy-and-setup-cicd to configure the target server.');

            return false;
        }

        $driver = $this->driver();

        if ($driver->requiresSiteDir() && empty($this->getSiteDir())) {
            $this->error('Missing required website folder for DirectAdmin. Set WEBHOSTING_SITE_DIR (or DIRECTADMIN_SITE_DIR) or run the setup wizard.');

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function runWizard(bool $force = false): array
    {
        $missing = empty(config('webhosting-deploy.ssh.host')) || empty(config('webhosting-deploy.ssh.username'));
        $needsSiteDir = $this->driver()->requiresSiteDir() && empty($this->getSiteDir());

        if (! $force && ! $missing && ! $needsSiteDir) {
            return $this->wizardAnswers ?? [];
        }

        $wizard = new DeployWizard;
        $this->wizardAnswers = $wizard->run();
        $wizard->applyToConfig($this->wizardAnswers);
        $wizard->writeLocalEnv($this->wizardAnswers);

        $this->info('Saved deploy settings to your local .env');

        return $this->wizardAnswers;
    }

    protected function getRepositoryInfo(): ?array
    {
        if (! $this->github->isGitRepository()) {
            return null;
        }

        return $this->github->getRepositoryInfo();
    }

    protected function getRepositoryUrl(): ?string
    {
        $repoUrl = $this->github->getRepositoryUrl();

        if (! $repoUrl) {
            return null;
        }

        $this->info("✅ Detected Git repository: {$repoUrl}");

        return $repoUrl;
    }

    protected function setupSshConnection(): void
    {
        $this->ssh = new SshConnectionService(
            (string) config('webhosting-deploy.ssh.host'),
            (string) config('webhosting-deploy.ssh.username'),
            (int) config('webhosting-deploy.ssh.port', 22),
            (int) config('webhosting-deploy.ssh.timeout', 30)
        );
    }

    protected function initializeGitHubAPI(?string $repoUrl = null, bool $required = false): bool
    {
        try {
            $token = ($this->hasOption('token') ? $this->option('token') : null) ?: env('GITHUB_API_TOKEN');

            if (! $token) {
                $this->line('');
                $this->warn('⚠️  GitHub Personal Access Token is not set.');
                $this->line('');

                if (! confirm('Do you want to proceed without a token?', true)) {
                    $this->line('');
                    $this->warn('💡 How to provide your GitHub Personal Access Token:');
                    $this->line('   Option 1: Set GITHUB_API_TOKEN in your .env file');
                    $this->line('   Option 2: Use --token=YOUR_TOKEN option when running this command');
                    $this->line('');
                    $this->showGitHubTokenInstructions();
                    $this->line('');
                    $this->info('📝 Please add GITHUB_API_TOKEN to your .env file and rerun the script.');
                    $this->line('');

                    return false;
                }

                if ($required) {
                    $this->warn('⚠️  Continuing without Personal Access Token. Secrets will be displayed for manual setup.');
                } else {
                    $this->warn('⚠️  Continuing without Personal Access Token. Deploy key will be displayed for manual addition.');
                }

                return true;
            }

            $this->githubAPI = new GitHubAPIService($token);

            if (! $this->githubAPI->testConnection()) {
                if ($required) {
                    $this->error('❌ Failed to authenticate with GitHub API. Please check your token.');
                    $this->githubAPI = null;

                    return false;
                }

                $this->warn('⚠️  GitHub API connection failed. Deploy key will need to be added manually.');
                $this->githubAPI = null;

                return true;
            }

            $this->info('✅ GitHub API connection successful');

            return true;
        } catch (\Exception $e) {
            if ($required) {
                $this->error('❌ GitHub API error: '.$e->getMessage());
                $this->githubAPI = null;

                return false;
            }

            $this->warn('⚠️  GitHub API initialization failed: '.$e->getMessage());
            $this->warn('   Deploy key will need to be added manually.');
            $this->githubAPI = null;

            return true;
        }
    }

    protected function showGitHubTokenInstructions(): void
    {
        $this->info('🔑 To create a GitHub Personal Access Token:');
        $this->line('');
        $this->line('   1. Go to: https://github.com/settings/personal-access-tokens');
        $this->line('   2. Click "Generate new token" → "Generate new token (classic)"');
        $this->line('   3. Give your token a descriptive name (e.g., "Webhosting Deploy")');
        $this->line('   4. Set expiration (or no expiration)');
        $this->line('   5. Select the following permissions:');
        $this->line('');
        $this->info('   📋 Required Permissions:');
        $this->info('      ✓ Administration → Read and write');
        $this->info('        (Allows managing deploy keys for the repository)');
        $this->info('      ✓ Secrets → Read and write');
        $this->info('        (Allows creating/updating GitHub Actions secrets)');
        $this->info('      ✓ Metadata → Read-only');
        $this->info('        (Automatically selected, required for API access)');
        $this->line('');
        $this->warn('   6. Click "Generate token" and copy the token immediately');
        $this->warn('      ⚠️  You won\'t be able to see it again!');
        $this->line('');
        $this->info('💡 Tip: You can also set GITHUB_API_TOKEN in your .env file to skip this prompt.');
    }

    protected function setupSshKeys(bool $addToAuthorizedKeys = true): bool
    {
        try {
            $this->ssh->prepareSshDirectory();

            if (! $this->ssh->sshKeyExists()) {
                $this->info('🔑 Generating SSH keys on server...');
                if (! $this->ssh->generateSshKey()) {
                    $this->error('❌ Failed to generate SSH keys');

                    return false;
                }
            } else {
                $this->info('🔑 SSH keys already exist on server');
            }

            $publicKey = $this->ssh->getPublicKey();

            if (! $publicKey) {
                $this->error('❌ Could not retrieve public key from server');

                return false;
            }

            if ($addToAuthorizedKeys) {
                if (! $this->ssh->addToAuthorizedKeys($publicKey)) {
                    $this->warn('⚠️  Could not add public key to authorized_keys (may already exist)');
                }

                $this->verifyInboundServerKey($publicKey);
            }

            $this->info('✅ SSH keys setup completed');

            return true;
        } catch (\Exception $e) {
            $this->error('SSH keys setup error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * GitHub Actions logs in with the server private key. DirectAdmin often ignores
     * a raw authorized_keys write until the same key is authorized in the panel.
     */
    protected function verifyInboundServerKey(?string $publicKey = null): bool
    {
        if ($this->ssh->verifyLoginWithServerKey()) {
            $this->info('✅ Server key is accepted for SSH login (GitHub Actions can connect)');

            return true;
        }

        $this->warn('⚠️  The server key is not accepted for SSH login yet.');
        $this->line('GitHub Actions uses this key (secret SSH_KEY) to connect. Outbound git fetch can still work.');
        $this->explainInboundKeyAuthorization($publicKey ?: $this->ssh->getPublicKey());

        return false;
    }

    protected function explainInboundKeyAuthorization(?string $publicKey): void
    {
        $this->line('');

        if ($this->driver()->name() === 'directadmin') {
            $this->warn('DirectAdmin limitation: sshd often ignores ~/.ssh/authorized_keys until the key is authorized in the panel.');
            $this->line('Typical causes: StrictModes + a group-writable home, or a panel-managed key list instead of the file we write.');
            $this->line('This package cannot call the DirectAdmin SSH Keys API — that needs a panel password or login key, which we do not store.');
            $this->line('');
            $this->info('Authorize the key in DirectAdmin:');
            $this->line('  1. Log in to DirectAdmin');
            $this->line('  2. Advanced Features → SSH Keys (Account Manager → SSH Keys on some skins)');
            $this->line('  3. Add the public key below and enable Authorize / Allow login');
        } else {
            $this->line('Check that ~/.ssh is mode 700 and ~/.ssh/authorized_keys is mode 600, then retry.');
            $this->line('If the home directory is group-writable, OpenSSH StrictModes will ignore authorized_keys.');
        }

        if ($publicKey) {
            $this->line('');
            $this->line('Public key:');
            $this->line($publicKey);
        }

        $this->line('');
    }

    protected function addDeployKeyViaAPI(string $publicKey, ?array $repoInfo = null): void
    {
        if (! $this->githubAPI) {
            return;
        }

        try {
            if (! $repoInfo) {
                $repoInfo = $this->github->getRepositoryInfo();
                if (! $repoInfo) {
                    $this->warn('⚠️  Could not detect repository information. Skipping automatic deploy key setup.');

                    return;
                }
            }

            $owner = $repoInfo['owner'];
            $repo = $repoInfo['name'];

            $this->info('🔑 Adding deploy key to GitHub repository via API...');

            if ($this->githubAPI->keyExists($owner, $repo, $publicKey)) {
                $this->info('✅ Deploy key already exists in repository');

                return;
            }

            $this->githubAPI->createDeployKey($owner, $repo, $publicKey, 'Webhosting Deploy', false);
            $this->info('✅ Deploy key added successfully to repository');
        } catch (\Exception $e) {
            $this->warn('⚠️  Failed to add deploy key via API: '.$e->getMessage());
            $this->warn('   You may need to add it manually.');

            $repoInfoForCheck = $repoInfo ?: $this->github->getRepositoryInfo();
            $keyExists = false;
            if ($repoInfoForCheck) {
                try {
                    $keyExists = $this->githubAPI->keyExists($repoInfoForCheck['owner'], $repoInfoForCheck['name'], $publicKey);
                    if ($keyExists) {
                        $this->info('✅ Deploy key already exists in repository');

                        return;
                    }
                } catch (\Exception $e) {
                    // continue with manual instructions
                }
            }

            $this->line('');
            $this->warn('🔑 Please add this SSH key manually to your GitHub repository:');
            $this->warn('   Settings → Deploy keys → Add deploy key');
            $this->line('');
            $this->line($publicKey);
            $this->line('');

            pause('Press ENTER after adding the key to GitHub to continue.');
        }
    }

    protected function generateWorkflowContent(string $branch, string $phpVersion): string
    {
        $stubPath = __DIR__.'/../../stubs/webhosting-deploy.yml';

        if (! File::exists($stubPath)) {
            throw new \Exception("Workflow stub not found: {$stubPath}");
        }

        $content = File::get($stubPath);
        $content = str_replace('{{BRANCH}}', $branch, $content);
        $content = str_replace('{{PHP_VERSION}}', $phpVersion, $content);

        return $content;
    }

    protected function getSiteDir(): string
    {
        if ($this->hasOption('site-dir') && $this->option('site-dir')) {
            return (string) $this->option('site-dir');
        }

        return (string) config('webhosting-deploy.deployment.site_dir', '');
    }

    protected function getAbsoluteSitePath(): string
    {
        return $this->driver()->shellAppPath();
    }

    protected function testRepositoryAccess(string $repoUrl): bool
    {
        try {
            $escapedRepoUrl = escapeshellarg($repoUrl);
            $testCommand = "git ls-remote {$escapedRepoUrl} HEAD 2>&1";

            try {
                $result = $this->ssh->execute($testCommand);

                if (preg_match('/^[a-f0-9]{40}\s+refs\/heads\/HEAD/', $result) ||
                    preg_match('/^[a-f0-9]{40}\s+HEAD/', $result) ||
                    stripos($result, 'refs/heads') !== false) {
                    return true;
                }

                $errorIndicators = [
                    'Permission denied',
                    'repository not found',
                    'Could not read from remote repository',
                    'Authentication failed',
                    'Host key verification failed',
                ];

                foreach ($errorIndicators as $indicator) {
                    if (stripos($result, $indicator) !== false) {
                        return false;
                    }
                }

                return ! empty(trim($result));
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                $errorIndicators = [
                    'Permission denied',
                    'repository not found',
                    'Could not read from remote repository',
                    'Authentication failed',
                    'Host key verification failed',
                    'SSH command failed',
                ];

                foreach ($errorIndicators as $indicator) {
                    if (stripos($errorMsg, $indicator) !== false) {
                        return false;
                    }
                }

                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function isGitAuthenticationError(\Exception $e): bool
    {
        $errorMessage = $e->getMessage();

        $authErrorPatterns = [
            'Repository not found',
            'Could not read from remote repository',
            'Permission denied',
            'Please make sure you have the correct access rights',
            'Host key verification failed',
            'Authentication failed',
        ];

        foreach ($authErrorPatterns as $pattern) {
            if (stripos($errorMessage, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function displayGitHubSecrets(array $repoInfo): void
    {
        $this->line('');
        $this->info('🔒 GitHub Secrets and Variables Setup');
        $this->line('');

        $privateKey = $this->ssh->getPrivateKey();
        if (! $privateKey) {
            $this->error('❌ Could not retrieve private key from server');

            return;
        }

        $this->warn('📋 Add these secrets to your GitHub repository:');
        $this->line('Go to: '.$repoInfo['secrets_url']);
        $this->line('');

        $secrets = [
            'SSH_HOST' => config('webhosting-deploy.ssh.host'),
            'SSH_USERNAME' => config('webhosting-deploy.ssh.username'),
            'SSH_PORT' => (string) config('webhosting-deploy.ssh.port', 22),
            'SSH_KEY' => $privateKey,
            'APP_PATH' => $this->driver()->relativeAppPath(),
        ];

        foreach ($secrets as $name => $value) {
            $this->line("🔑 {$name}:");
            if ($name === 'SSH_KEY') {
                $this->line('   [Copy the private key below]');
                $this->line('');
                $this->line('   '.str_repeat('-', 50));
                $this->line($value);
                $this->line('   '.str_repeat('-', 50));
            } else {
                $this->line("   {$value}");
            }
            $this->line('');
        }

        $publicKey = $this->ssh->getPublicKey();
        if ($publicKey) {
            $sshRepoUrl = "git@github.com:{$repoInfo['owner']}/{$repoInfo['name']}.git";

            $keyExists = false;
            if ($this->githubAPI) {
                try {
                    $keyExists = $this->githubAPI->keyExists($repoInfo['owner'], $repoInfo['name'], $publicKey);
                } catch (\Exception $e) {
                    // fall through
                }
            }

            if (! $keyExists) {
                $keyExists = $this->testRepositoryAccess($sshRepoUrl);
            }

            if (! $keyExists && ! $this->githubAPI) {
                $token = ($this->hasOption('token') ? $this->option('token') : null) ?: env('GITHUB_API_TOKEN');
                if ($token) {
                    try {
                        $tempAPI = new GitHubAPIService($token);
                        $keyExists = $tempAPI->keyExists($repoInfo['owner'], $repoInfo['name'], $publicKey);
                    } catch (\Exception $e) {
                        $keyExists = $this->testRepositoryAccess($sshRepoUrl);
                    }
                } else {
                    $keyExists = $this->testRepositoryAccess($sshRepoUrl);
                }
            }

            if ($keyExists) {
                $this->info('✅ Deploy key already exists and repository access is working');
                $this->line('');
            } else {
                $this->warn('🔑 Deploy Key Information:');
                $this->line('Go to: '.$repoInfo['deploy_keys_url']);
                $this->line('');
                $this->line('Add this public key as a Deploy Key:');
                $this->line('');
                $this->line('   '.str_repeat('-', 50));
                $this->line($publicKey);
                $this->line('   '.str_repeat('-', 50));
                $this->line('');
            }
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    protected function applyRemoteEnv(array $values): void
    {
        EnvFile::upsertRemote($this->ssh, $this->driver()->shellAppPath(), $values);
    }
}
