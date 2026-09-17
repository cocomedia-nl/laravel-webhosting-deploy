<?php

namespace CocomediaNL\LaravelWebhostingDeploy\Commands;

use CocomediaNL\LaravelWebhostingDeploy\Support\DeployWizard;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

class DeploySharedCommand extends BaseWebhostingCommand
{
    protected $signature = 'webhosting:deploy
                            {--fresh : Delete and clone fresh repository}
                            {--site-dir= : Override site directory from config}
                            {--token= : GitHub Personal Access Token}
                            {--show-errors : Show detailed error messages}
                            {--reconfigure : Re-run the deploy setup wizard}
                            {--skip-wizard : Skip the setup wizard (used by deploy-and-setup-cicd)}';

    protected $description = 'Deploy Laravel application to shared webhosting';

    protected $aliases = ['directadmin:deploy'];

    public function handle(): int
    {
        $this->info('🚀 Starting webhosting deployment...');

        if (! $this->option('skip-wizard')) {
            $this->runWizard((bool) $this->option('reconfigure'));
        }

        if (! $this->validateConfiguration()) {
            return self::FAILURE;
        }

        $repoUrl = $this->getRepositoryUrl();
        if (! $repoUrl) {
            $this->error('❌ Could not detect Git repository URL. Please run this command from a Git repository.');

            return self::FAILURE;
        }

        $this->info("📦 Repository: {$repoUrl}");

        $this->initializeGitHubAPI($repoUrl, false);
        $this->setupSshConnection();
        $this->ssh->setTimeout((int) config('webhosting-deploy.ssh.deploy_timeout', 600));

        if (! $this->ssh->testConnection()) {
            $this->error('❌ SSH connection failed. Please check your SSH configuration.');

            return self::FAILURE;
        }

        $this->info('✅ SSH connection successful');

        $this->buildFrontendAssets();

        if (! $this->deployToServer($repoUrl)) {
            $this->error('❌ Deployment failed');

            return self::FAILURE;
        }

        $this->copyBuiltAssetsToServer();

        $this->info('✅ Deployment completed successfully!');

        $siteDir = $this->getSiteDir();
        if ($siteDir !== '') {
            $this->info("🌐 Your Laravel application: https://{$siteDir}");
        }

        $hint = $this->driver()->documentRootHint();
        if ($hint) {
            $this->info("ℹ️  TransIP DocumentRoot should be {$hint}");
        }

        return self::SUCCESS;
    }

    protected function deployToServer(string $repoUrl): bool
    {
        $isFresh = (bool) $this->option('fresh');

        try {
            $this->setupSshKeysForDeployment();

            $cloneChoice = $this->getDeploymentChoice($isFresh);

            $commands = $this->buildDeploymentCommands($repoUrl, $cloneChoice);

            $this->info('📦 Deploying application...');
            try {
                $this->ssh->executeMultiple($commands);
            } catch (\Exception $e) {
                if ($this->isGitAuthenticationError($e)) {
                    return $this->handleGitAuthenticationError($repoUrl, $cloneChoice);
                }
                throw $e;
            }

            $this->configureRemoteEnvironment();
            $this->runRemoteArtisanSetup();

            return true;
        } catch (\Exception $e) {
            if ($this->isGitAuthenticationError($e)) {
                return $this->handleGitAuthenticationError($repoUrl, 'clone_direct');
            }

            $this->error('❌ Deployment failed.');
            $this->line('');

            $this->displayExceptionDetails($e);

            $this->warn('💡 This might be due to:');
            $this->line('   1. Server connection issues');
            $this->line('   2. Repository access problems');
            $this->line('   3. Missing dependencies on the server');
            $this->line('   4. Command execution failures (composer, git, etc.)');
            $this->line('');

            if (! $this->option('show-errors')) {
                $this->info('💡 Tip: Run with --show-errors flag to see detailed error messages.');
                $this->line('');
            }

            $this->info('🔧 Please check your server configuration and try again.');

            return false;
        }
    }

    protected function setupSshKeysForDeployment(): void
    {
        if (! $this->setupSshKeys(false)) {
            return;
        }

        $publicKey = $this->ssh->getPublicKey();

        if (! $publicKey) {
            $this->error('❌ Could not retrieve public key from server');

            return;
        }

        if ($this->githubAPI) {
            try {
                $repoInfo = $this->github->getRepositoryInfo();
                if ($repoInfo) {
                    if ($this->githubAPI->keyExists($repoInfo['owner'], $repoInfo['name'], $publicKey)) {
                        return;
                    }
                    $this->githubAPI->createDeployKey($repoInfo['owner'], $repoInfo['name'], $publicKey, 'Webhosting Deploy', false);
                }
            } catch (\Exception $e) {
                // Silent failure — handled if git clone fails
            }
        }
    }

    protected function getDeploymentChoice(bool $forceFresh): string
    {
        if ($forceFresh) {
            return 'delete_and_clone_direct';
        }

        $this->info('🔍 Checking website folder...');

        $folderStatus = $this->checkFolderStatus();

        if ($folderStatus === 'empty') {
            $this->info('✅ Empty folder - ready to deploy');

            return 'clone_direct';
        }

        $this->warn('⚠️  Folder not empty - checking contents...');

        $isLaravel = $this->isLaravelProject();
        $fullPath = $this->driver()->rsyncAppPath();

        if ($isLaravel) {
            $this->info("✅ Found existing Laravel project in: {$fullPath}");

            $choice = select(
                label: 'What should happen to the existing project?',
                options: [
                    'skip' => 'Keep existing and continue (update dependencies)',
                    'delete_and_clone_direct' => 'Replace with a fresh clone',
                ],
                default: 'skip'
            );

            return $choice;
        }

        $this->error("❌ Non-Laravel project detected in: {$fullPath}");

        $choice = select(
            label: 'What should happen?',
            options: [
                'cancel' => 'Cancel deployment',
                'delete_and_clone_direct' => 'Replace with a Laravel project',
            ],
            default: 'cancel'
        );

        if ($choice === 'cancel') {
            throw new \Exception('Deployment cancelled by user');
        }

        return $choice;
    }

    protected function checkFolderStatus(): string
    {
        $path = $this->driver()->shellAppPath();

        try {
            $exists = trim($this->ssh->execute("test -d {$path} && echo yes || echo no"));
            if ($exists !== 'yes') {
                return 'empty';
            }

            $result = trim($this->ssh->execute("test -d {$path} && [ -z \"\$(ls -A {$path} 2>/dev/null)\" ] && echo empty || echo not_empty"));

            return $result === 'empty' ? 'empty' : 'not_empty';
        } catch (\Exception $e) {
            return 'not_empty';
        }
    }

    protected function isLaravelProject(): bool
    {
        $path = $this->driver()->shellAppPath();

        try {
            $exists = trim($this->ssh->execute("test -d {$path} && echo yes || echo no"));
            if ($exists !== 'yes') {
                return false;
            }

            $result = trim($this->ssh->execute(
                "test -f {$path}/artisan && test -f {$path}/composer.json && grep -q 'laravel/framework' {$path}/composer.json && echo yes || echo no"
            ));

            return $result === 'yes';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    protected function buildDeploymentCommands(string $repoUrl, string $cloneChoice): array
    {
        $commands = [];
        $appPath = $this->driver()->shellAppPath();
        $domainPath = $this->driver()->shellDomainPath();

        $commands[] = "mkdir -p {$appPath}";
        $commands[] = "cd {$appPath}";

        if ($this->driver()->shouldManagePublicHtmlSymlink() && $domainPath) {
            $publicHtml = $this->driver()->publicHtmlName();
            $commands[] = "cd {$domainPath}";
            $commands[] = "rm -rf {$publicHtml}";
            $commands[] = "cd {$appPath}";
        }

        $gitHost = $this->extractGitHost($repoUrl);
        if ($gitHost) {
            $commands[] = 'mkdir -p ~/.ssh';
            $commands[] = 'chmod 700 ~/.ssh';
            $escapedHost = escapeshellarg($gitHost);
            $commands[] = "ssh-keyscan -H {$escapedHost} >> ~/.ssh/known_hosts 2>/dev/null || true";
        }

        switch ($cloneChoice) {
            case 'clone_direct':
                $commands[] = "git clone {$repoUrl} .";
                break;
            case 'delete_and_clone_direct':
                $commands[] = 'rm -rf * .[^.]* 2>/dev/null || true';
                $commands[] = "git clone {$repoUrl} .";
                break;
            case 'skip':
                $commands[] = 'if [ -d .git ]; then git fetch origin && git pull --ff-only || true; fi';
                break;
            default:
                $commands[] = "if [ -d .git ]; then git pull; else git clone {$repoUrl} .; fi";
                break;
        }

        $composerFlags = config('webhosting-deploy.deployment.composer_flags', '--no-dev --optimize-autoloader');
        $commands[] = "composer install {$composerFlags}";

        $commands[] = 'if [ ! -f .env ] && [ -f .env.example ]; then cp .env.example .env; fi';

        if ($this->driver()->shouldManagePublicHtmlSymlink() && $domainPath) {
            $publicHtml = $this->driver()->publicHtmlName();
            $laravelHtml = $this->driver()->laravelHtmlName();
            $public = $this->driver()->publicDirName();
            $commands[] = "cd {$domainPath}";
            $commands[] = "ln -s {$laravelHtml}/{$public} {$publicHtml}";
            $commands[] = "cd {$appPath}";
        }

        return $commands;
    }

    protected function configureRemoteEnvironment(): void
    {
        $answers = $this->wizardAnswers ?: config('webhosting-deploy.runtime.wizard');

        if (! is_array($answers) || $answers === []) {
            return;
        }

        $this->wizardAnswers = $answers;

        $this->info('📝 Writing remote .env application settings...');
        $this->applyRemoteEnv((new DeployWizard)->remoteEnvValues($answers));

        if (($answers['database']['connection'] ?? '') === 'sqlite') {
            $sqlite = $answers['database']['database'] ?? 'database/database.sqlite';
            $this->ssh->execute('cd '.$this->driver()->shellAppPath().' && mkdir -p "$(dirname '.escapeshellarg($sqlite).')" && touch '.escapeshellarg($sqlite));
        }
    }

    protected function runRemoteArtisanSetup(): void
    {
        $appPath = $this->driver()->shellAppPath();
        $commands = [
            "cd {$appPath}",
            'if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then php artisan key:generate --force --quiet; fi',
        ];

        $runMigrations = (bool) config('webhosting-deploy.deployment.run_migrations', true);
        if ($this->wizardAnswers !== null) {
            $runMigrations = (bool) $this->wizardAnswers['run_migrations'];
        }

        if ($runMigrations) {
            $commands[] = 'php artisan migrate --force --quiet';
        } else {
            $this->warn('⚠️  Skipping migrations (database not configured or skipped in the wizard).');
        }

        if (config('webhosting-deploy.deployment.run_storage_link', true)) {
            $commands[] = 'php artisan storage:link --quiet --force';
        }

        $this->ssh->executeMultiple($commands);
    }

    protected function handleGitAuthenticationError(string $repoUrl, string $cloneChoice): bool
    {
        $this->line('');
        $this->warn('🔑 Git authentication failed! The deploy key is not set up correctly.');
        $this->line('');

        $publicKey = $this->ssh->getPublicKey();

        if (! $publicKey) {
            $this->error('❌ Could not retrieve public key from server. Generating new key...');
            if ($this->ssh->generateSshKey()) {
                $publicKey = $this->ssh->getPublicKey();
            }
        }

        if (! $publicKey) {
            $this->error('❌ Could not retrieve or generate SSH public key.');

            return false;
        }

        $repoInfo = $this->github->parseRepositoryUrl($repoUrl);

        $keyExists = false;
        if ($this->githubAPI && $repoInfo) {
            try {
                $keyExists = $this->githubAPI->keyExists($repoInfo['owner'], $repoInfo['name'], $publicKey);
                if ($keyExists) {
                    $this->info('✅ Deploy key already exists in repository');
                    $this->info('🔄 Retrying deployment...');
                    try {
                        $this->ssh->executeMultiple($this->buildDeploymentCommands($repoUrl, $cloneChoice));
                        $this->configureRemoteEnvironment();
                        $this->runRemoteArtisanSetup();

                        return true;
                    } catch (\Exception $e) {
                        $this->warn('⚠️  Deployment still failed: '.$e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                // continue
            }
        }

        if (! $keyExists && $this->githubAPI && $repoInfo) {
            try {
                $this->info('🔑 Attempting to add deploy key via API...');
                $this->githubAPI->createDeployKey($repoInfo['owner'], $repoInfo['name'], $publicKey, 'Webhosting Deploy', false);
                $this->info('✅ Deploy key added successfully via API');
                $this->info('🔄 Retrying deployment...');
                try {
                    $this->ssh->executeMultiple($this->buildDeploymentCommands($repoUrl, $cloneChoice));
                    $this->configureRemoteEnvironment();
                    $this->runRemoteArtisanSetup();

                    return true;
                } catch (\Exception $e) {
                    $this->warn('⚠️  Deployment still failed: '.$e->getMessage());
                }
            } catch (\Exception $e) {
                $this->warn('⚠️  Failed to add deploy key via API: '.$e->getMessage());
                $this->warn('   Falling back to manual method...');
                $this->line('');
            }
        }

        if ($keyExists) {
            return $this->handleDeploymentFailure(new \Exception('Deployment failed even though deploy key exists'), $repoUrl, false);
        }

        $this->info('📋 Add this SSH public key to your GitHub repository:');
        $this->line('');

        if ($repoInfo) {
            $this->line('   Go to: '.$this->github->getDeployKeysUrl($repoInfo['owner'], $repoInfo['name']));
        } else {
            $this->line('   Go to: Your repository → Settings → Deploy keys');
        }

        $this->line('');
        $this->warn('   Steps:');
        $this->line('   1. Click "Add deploy key"');
        $this->line('   2. Give it a title (e.g., "Webhosting Deploy")');
        $this->line('   3. Paste the public key below');
        $this->line('   4. ✅ Check "Allow write access" (optional, for deployments)');
        $this->line('   5. Click "Add key"');
        $this->line('');
        $this->line('   '.str_repeat('-', 60));
        $this->line($publicKey);
        $this->line('   '.str_repeat('-', 60));
        $this->line('');

        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $this->ask('Press ENTER after you have added the deploy key to GitHub to continue...', '');
            $this->info('🔄 Retrying deployment...');

            try {
                $this->ssh->executeMultiple($this->buildDeploymentCommands($repoUrl, $cloneChoice));
                $this->configureRemoteEnvironment();
                $this->runRemoteArtisanSetup();

                return true;
            } catch (\Exception $e) {
                $attempt++;

                if ($this->isGitAuthenticationError($e) && $attempt < $maxRetries) {
                    $this->line('');
                    $this->warn("⚠️  Authentication still failed (attempt {$attempt}/{$maxRetries})");
                    $this->line('');
                    continue;
                }

                return $this->handleDeploymentFailure($e, $repoUrl, $attempt >= $maxRetries);
            }
        }

        return false;
    }

    protected function handleDeploymentFailure(\Exception $e, string $repoUrl, bool $maxRetriesReached): bool
    {
        $this->line('');

        if ($maxRetriesReached) {
            $this->error('❌ Maximum retry attempts reached.');
            $this->line('');
            $this->displayExceptionDetails($e);
            $this->warn('💡 Please check:');
            $this->line('   1. The deploy key has been added correctly to GitHub');
            $this->line('   2. The repository URL is correct: '.$repoUrl);
            $this->line('   3. You have access to the repository');
            $this->line('');
        } else {
            $this->error('❌ Deployment failed.');
            $this->line('');
            $this->displayExceptionDetails($e);
        }

        if (! $this->option('show-errors')) {
            $this->info('💡 Tip: Run with --show-errors flag to see detailed error messages.');
            $this->line('');
        }

        return false;
    }

    protected function displayExceptionDetails(\Exception $e): void
    {
        $showErrors = (bool) $this->option('show-errors');
        $errorMessage = $e->getMessage();

        if ($showErrors || strpos($errorMessage, 'Error output:') !== false || strpos($errorMessage, 'exit code:') !== false) {
            $this->warn('📋 Error Details:');
            $this->line('');
            foreach (explode("\n", $errorMessage) as $line) {
                $this->line('   '.$line);
            }
            $this->line('');
        }
    }

    protected function extractGitHost(string $repoUrl): ?string
    {
        if (preg_match('/git@([^:]+):/', $repoUrl, $matches)) {
            return $matches[1];
        }

        if (preg_match('/https?:\/\/([^\/]+)/', $repoUrl, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function buildFrontendAssets(): void
    {
        $packageJsonPath = base_path('package.json');

        if (! file_exists($packageJsonPath)) {
            return;
        }

        $this->info('📦 Found package.json - building frontend assets...');

        try {
            $npmCheck = Process::run('which npm');
            if (! $npmCheck->successful()) {
                $this->warn('⚠️  npm not found. Skipping asset build.');

                return;
            }

            if (! is_dir(base_path('node_modules'))) {
                $this->info('📥 Installing npm dependencies...');
                $installProcess = Process::path(base_path())
                    ->timeout(300)
                    ->run('npm install');

                if (! $installProcess->successful()) {
                    $this->warn('⚠️  Failed to install npm dependencies: '.$installProcess->errorOutput());

                    return;
                }
            }

            $this->info('🔨 Running npm run build...');
            $buildProcess = Process::path(base_path())
                ->timeout(300)
                ->run('npm run build');

            if (! $buildProcess->successful()) {
                $this->warn('⚠️  npm run build failed: '.$buildProcess->errorOutput());
                $this->warn('   Continuing deployment without built assets...');

                return;
            }

            $this->info('✅ Frontend assets built successfully');
        } catch (\Exception $e) {
            $this->warn('⚠️  Error building frontend assets: '.$e->getMessage());
            $this->warn('   Continuing deployment without built assets...');
        }
    }

    protected function copyBuiltAssetsToServer(): void
    {
        $buildPath = base_path('public/build');

        if (! is_dir($buildPath)) {
            return;
        }

        $this->info('📤 Copying built assets to server...');

        try {
            $remoteBuildPath = $this->driver()->rsyncAppPath().'/public/build';
            $host = config('webhosting-deploy.ssh.host');
            $username = config('webhosting-deploy.ssh.username');
            $port = config('webhosting-deploy.ssh.port', 22);

            $rsyncCommand = sprintf(
                'rsync -r -e "ssh -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null" %s/ %s@%s:%s',
                $port,
                escapeshellarg($buildPath),
                $username,
                $host,
                $remoteBuildPath
            );

            $rsyncProcess = Process::timeout(60)->run($rsyncCommand);

            if (! $rsyncProcess->successful()) {
                $this->warn('⚠️  Failed to copy built assets: '.$rsyncProcess->errorOutput());

                return;
            }

            $this->info('✅ Built assets copied successfully');
        } catch (\Exception $e) {
            $this->warn('⚠️  Failed to copy built assets: '.$e->getMessage());
        }
    }
}
