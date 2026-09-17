<?php

namespace CocomediaNL\LaravelWebhostingDeploy\Commands;

use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;

class SetupAutomatedDeployCommand extends BaseWebhostingCommand
{
    protected $signature = 'webhosting:setup-cicd
                            {--token= : GitHub Personal Access Token}
                            {--branch= : Override default branch}
                            {--php-version= : Override PHP version}';

    protected $description = 'Setup automated deployment (publishes workflow file and creates secrets)';

    protected $aliases = ['directadmin:setup-cicd'];

    public function handle(): int
    {
        $this->info('🚀 Setting up automated deployment via GitHub API...');

        if (! $this->validateConfiguration()) {
            return self::FAILURE;
        }

        $repoInfo = $this->getRepositoryInfo();
        if (! $repoInfo) {
            $this->error('❌ Could not detect repository information. Please run this command from a Git repository.');

            return self::FAILURE;
        }

        $this->info("📦 Repository: {$repoInfo['owner']}/{$repoInfo['name']}");

        $apiInitialized = $this->initializeGitHubAPI(null, true);
        if (! $apiInitialized) {
            return self::FAILURE;
        }

        $this->setupSshConnection();

        if (! $this->ssh->testConnection()) {
            $this->error('❌ SSH connection failed. Please check your SSH configuration.');

            return self::FAILURE;
        }

        $this->info('✅ SSH connection successful');

        if (! $this->setupSshKeys(true)) {
            $this->error('❌ Failed to setup SSH keys');

            return self::FAILURE;
        }

        if ($this->githubAPI) {
            $publicKey = $this->ssh->getPublicKey();
            if ($publicKey) {
                $this->addDeployKeyViaAPI($publicKey, $repoInfo);
            }

            $sshHost = (string) config('webhosting-deploy.ssh.host');
            $sshUsername = (string) config('webhosting-deploy.ssh.username');
            $sshPort = (int) config('webhosting-deploy.ssh.port', 22);
            $privateKey = $this->ssh->getPrivateKey();

            if (! $privateKey) {
                $this->error('❌ Could not retrieve private key from server');

                return self::FAILURE;
            }

            if (! $this->createWorkflowFile($repoInfo)) {
                return self::FAILURE;
            }

            if (! $this->createSecrets($repoInfo, $sshHost, $sshUsername, $sshPort, $privateKey)) {
                return self::FAILURE;
            }

            $this->line('');
            $this->info('✅ Automated deployment setup completed successfully!');
        } else {
            if (! $this->createWorkflowFile($repoInfo)) {
                return self::FAILURE;
            }

            $this->displayGitHubSecrets($repoInfo);

            $workflowFile = config('webhosting-deploy.github.workflow_file', '.github/workflows/webhosting-deploy.yml');

            $this->line('');
            $this->info('✅ Setup completed! Next steps:');
            $this->line('');
            $this->line('1. Add all the secrets shown above to GitHub');
            $this->line('2. Add the deploy key to your repository');
            $this->line('3. Commit and push the workflow file:');
            $this->line("      git add {$workflowFile}");
            $this->line('      git commit -m "Add webhosting deployment workflow"');
            $this->line('      git push');
            $this->line('4. Your repository will automatically deploy on push!');
            $this->line('');
        }

        return self::SUCCESS;
    }

    protected function createWorkflowFile(array $repoInfo): bool
    {
        try {
            $this->info('📄 Publishing GitHub Actions workflow file locally...');

            $branch = $this->option('branch') ?: $this->github->getCurrentBranch() ?: config('webhosting-deploy.github.default_branch', 'main');
            $phpVersion = $this->option('php-version') ?: config('webhosting-deploy.github.php_version', '8.3');

            $workflowFile = config('webhosting-deploy.github.workflow_file', '.github/workflows/webhosting-deploy.yml');

            $workflowDir = dirname($workflowFile);
            if (! File::exists($workflowDir)) {
                File::makeDirectory($workflowDir, 0755, true);
                $this->info("📁 Created directory: {$workflowDir}");
            }

            if (File::exists($workflowFile)) {
                if (! confirm("Workflow file already exists at {$workflowFile}. Overwrite it?", true)) {
                    $this->warn('⚠️  Skipping workflow file creation. Using existing file.');

                    return true;
                }
            }

            $workflowContent = $this->generateWorkflowContent($branch, $phpVersion);

            if (File::put($workflowFile, $workflowContent)) {
                $this->info("✅ Workflow file published: {$workflowFile}");
                $this->warn('⚠️  Please review the workflow file, commit it, and push to trigger deployments.');

                return true;
            }

            $this->error("❌ Failed to create workflow file: {$workflowFile}");

            return false;
        } catch (\Exception $e) {
            $this->error('❌ Failed to create workflow file: '.$e->getMessage());

            return false;
        }
    }

    protected function createSecrets(array $repoInfo, string $sshHost, string $sshUsername, int $sshPort, string $sshKey): bool
    {
        try {
            $this->info('🔒 Creating GitHub secrets...');

            $secrets = [
                'SSH_HOST' => $sshHost,
                'SSH_USERNAME' => $sshUsername,
                'SSH_PORT' => (string) $sshPort,
                'SSH_KEY' => $sshKey,
                'APP_PATH' => $this->driver()->relativeAppPath(),
            ];

            foreach ($secrets as $name => $value) {
                $this->githubAPI->createOrUpdateSecret(
                    $repoInfo['owner'],
                    $repoInfo['name'],
                    $name,
                    $value
                );
                $this->info("   ✅ {$name} created");
            }

            $this->info('✅ All secrets created successfully');

            return true;
        } catch (\Exception $e) {
            $this->error('❌ Failed to create secrets: '.$e->getMessage());

            return false;
        }
    }
}
