<?php

namespace CocomediaNL\LaravelWebhostingDeploy\Commands;

class DeployAndSetupAutomatedCommand extends BaseWebhostingCommand
{
    protected $signature = 'webhosting:deploy-and-setup-cicd
                            {--fresh : Delete and clone fresh repository}
                            {--site-dir= : Override site directory from config}
                            {--token= : GitHub Personal Access Token}
                            {--branch= : Override default branch}
                            {--php-version= : Override PHP version}
                            {--reconfigure : Re-run the deploy setup wizard}';

    protected $description = 'Deploy Laravel application to shared webhosting and setup automated deployment via GitHub API';

    protected $aliases = ['directadmin:deploy-and-setup-cicd'];

    public function handle(): int
    {
        $this->info('🚀 Starting complete deployment and automated setup...');
        $this->line('');

        $this->runWizard(true);

        $token = $this->option('token') ?: env('GITHUB_API_TOKEN');

        $this->info('═══════════════════════════════════════════════════════');
        $this->info('Step 1: Deploying to webhosting server');
        $this->info('═══════════════════════════════════════════════════════');
        $this->line('');

        $deployOptions = [
            '--skip-wizard' => true,
            '-v' => true,
        ];

        if ($this->option('fresh')) {
            $deployOptions['--fresh'] = true;
        }
        if ($this->option('site-dir')) {
            $deployOptions['--site-dir'] = $this->option('site-dir');
        }
        if ($this->option('reconfigure')) {
            $deployOptions['--reconfigure'] = true;
        }
        if ($token) {
            $deployOptions['--token'] = $token;
        }

        $deployExitCode = $this->call('webhosting:deploy', $deployOptions);

        if ($deployExitCode !== self::SUCCESS) {
            $this->line('');
            $this->error('❌ Deployment to server failed. Cannot proceed with automated setup.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('Step 2: Setting up Automated Deployment');
        $this->info('═══════════════════════════════════════════════════════');
        $this->line('');

        $setupOptions = ['-v' => true];
        if ($token) {
            $setupOptions['--token'] = $token;
        }
        if ($this->option('branch')) {
            $setupOptions['--branch'] = $this->option('branch');
        }
        if ($this->option('php-version')) {
            $setupOptions['--php-version'] = $this->option('php-version');
        }

        $setupExitCode = $this->call('webhosting:setup-cicd', $setupOptions);

        if ($setupExitCode !== self::SUCCESS) {
            $this->line('');
            $this->error('❌ Automated deployment setup failed.');

            return self::FAILURE;
        }

        $workflowFile = config('webhosting-deploy.github.workflow_file', '.github/workflows/webhosting-deploy.yml');
        $siteDir = $this->getSiteDir();

        $this->line('');
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('🎉 Complete Setup Finished Successfully!');
        $this->info('═══════════════════════════════════════════════════════');
        $this->line('');

        if ($siteDir !== '') {
            $this->info("🌐 Your Laravel application: https://{$siteDir}");
            $this->line('');
        }

        $hint = $this->driver()->documentRootHint();
        if ($hint) {
            $this->info("ℹ️  Confirm the TransIP DocumentRoot is set to {$hint}");
            $this->line('');
        }

        $this->info('🚀 Next steps:');
        $this->line("   1. Review the workflow file at {$workflowFile}");
        $this->line('   2. Commit and push the workflow file:');
        $this->line("      git add {$workflowFile}");
        $this->line('      git commit -m "Add webhosting deployment workflow"');
        $this->line('      git push');
        $this->line('   3. Monitor deployments in the Actions tab on GitHub');
        $this->line('   4. Your application will automatically deploy on push');
        $this->line('');

        return self::SUCCESS;
    }
}
