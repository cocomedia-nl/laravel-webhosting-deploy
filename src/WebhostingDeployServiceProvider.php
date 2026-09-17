<?php

namespace CocomediaNL\LaravelWebhostingDeploy;

use CocomediaNL\LaravelWebhostingDeploy\Commands\DeployAndSetupAutomatedCommand;
use CocomediaNL\LaravelWebhostingDeploy\Commands\DeploySharedCommand;
use CocomediaNL\LaravelWebhostingDeploy\Commands\PublishWorkflowCommand;
use CocomediaNL\LaravelWebhostingDeploy\Commands\SetupAutomatedDeployCommand;
use CocomediaNL\LaravelWebhostingDeploy\Commands\TestConnectionCommand;
use Illuminate\Support\ServiceProvider;

class WebhostingDeployServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/webhosting-deploy.php',
            'webhosting-deploy'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DeploySharedCommand::class,
                PublishWorkflowCommand::class,
                SetupAutomatedDeployCommand::class,
                DeployAndSetupAutomatedCommand::class,
                TestConnectionCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/config/webhosting-deploy.php' => config_path('webhosting-deploy.php'),
            ], 'webhosting-deploy-config');

            $this->publishes([
                __DIR__.'/../stubs/webhosting-deploy.yml' => base_path('.github/workflows/webhosting-deploy.yml'),
            ], 'webhosting-deploy-workflow');
        }
    }
}
