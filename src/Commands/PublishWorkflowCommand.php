<?php

namespace CocomediaNL\LaravelWebhostingDeploy\Commands;

use Illuminate\Support\Facades\File;

use function Laravel\Prompts\select;

class PublishWorkflowCommand extends BaseWebhostingCommand
{
    protected $signature = 'webhosting:publish-workflow
                            {--branch= : Override default branch}
                            {--php-version= : Override PHP version}';

    protected $description = 'Publish GitHub Actions workflow file for automated deployment';

    protected $aliases = ['directadmin:publish-workflow'];

    public function handle(): int
    {
        $repoInfo = $this->getRepositoryInfo();
        if (! $repoInfo) {
            $this->error('❌ Not in a Git repository or could not detect repository information. Please run this command from a Git repository.');

            return self::FAILURE;
        }

        $branch = $this->option('branch') ?: $this->github->getCurrentBranch() ?: config('webhosting-deploy.github.default_branch', 'main');
        $phpVersion = $this->option('php-version') ?: config('webhosting-deploy.github.php_version', '8.3');
        $workflowFile = config('webhosting-deploy.github.workflow_file', '.github/workflows/webhosting-deploy.yml');

        if (File::exists($workflowFile)) {
            $choice = select(
                label: "Workflow file already exists at {$workflowFile}. What would you like to do?",
                options: ['Overwrite', 'Skip'],
                default: 'Overwrite'
            );

            if ($choice === 'Skip') {
                $this->info('⚠️  Skipping workflow file creation.');

                return self::SUCCESS;
            }
        }

        $workflowDir = dirname($workflowFile);
        if (! File::exists($workflowDir)) {
            File::makeDirectory($workflowDir, 0755, true);
        }

        $workflowContent = $this->generateWorkflowContent($branch, $phpVersion);

        if (File::put($workflowFile, $workflowContent)) {
            $this->info("✅ Workflow file published: {$workflowFile}");
        } else {
            $this->error("❌ Failed to create workflow file: {$workflowFile}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
