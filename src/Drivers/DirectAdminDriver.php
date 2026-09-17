<?php

namespace CocomediaNL\LaravelWebhostingDeploy\Drivers;

class DirectAdminDriver extends HostingDriver
{
    public function name(): string
    {
        return 'directadmin';
    }

    public function relativeAppPath(): string
    {
        if ($custom = $this->customRelativeAppPath()) {
            return $custom;
        }

        $domains = config('webhosting-deploy.paths.domains', 'domains');
        $siteDir = (string) config('webhosting-deploy.deployment.site_dir');

        return $domains.'/'.$siteDir.'/'.$this->laravelHtmlName();
    }

    public function relativeDomainPath(): ?string
    {
        $domains = config('webhosting-deploy.paths.domains', 'domains');
        $siteDir = (string) config('webhosting-deploy.deployment.site_dir');

        return $domains.'/'.$siteDir;
    }

    public function shouldManagePublicHtmlSymlink(): bool
    {
        return true;
    }

    public function requiresSiteDir(): bool
    {
        return $this->customRelativeAppPath() === null;
    }
}
