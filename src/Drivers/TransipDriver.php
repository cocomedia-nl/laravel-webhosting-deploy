<?php

namespace CocomediaNL\LaravelWebhostingDeploy\Drivers;

class TransipDriver extends HostingDriver
{
    public function name(): string
    {
        return 'transip';
    }

    public function relativeAppPath(): string
    {
        if ($custom = $this->customRelativeAppPath()) {
            return $custom;
        }

        return (string) config('webhosting-deploy.paths.transip_www', 'www');
    }

    public function shouldManagePublicHtmlSymlink(): bool
    {
        return false;
    }

    public function requiresSiteDir(): bool
    {
        return false;
    }

    public function documentRootHint(): ?string
    {
        return '/www/public';
    }
}
