<?php

namespace CocomediaNL\LaravelWebhostingDeploy\Drivers;

abstract class HostingDriver
{
    abstract public function name(): string;

    /**
     * Application path relative to the SSH user's home directory.
     */
    abstract public function relativeAppPath(): string;

    abstract public function shouldManagePublicHtmlSymlink(): bool;

    abstract public function requiresSiteDir(): bool;

    /**
     * Domain root relative to home (DirectAdmin only), used for public_html symlink.
     */
    public function relativeDomainPath(): ?string
    {
        return null;
    }

    public function documentRootHint(): ?string
    {
        return null;
    }

    /**
     * Shell-safe path that expands $HOME on the remote host.
     */
    public function shellAppPath(): string
    {
        return '"$HOME/'.$this->escapedRelative($this->relativeAppPath()).'"';
    }

    public function shellDomainPath(): ?string
    {
        $relative = $this->relativeDomainPath();

        if ($relative === null) {
            return null;
        }

        return '"$HOME/'.$this->escapedRelative($relative).'"';
    }

    /**
     * Path used with rsync user@host:… (tilde expands on the remote).
     */
    public function rsyncAppPath(): string
    {
        return '~/'.$this->escapedRelative($this->relativeAppPath());
    }

    public function publicHtmlName(): string
    {
        return config('webhosting-deploy.paths.public_html', 'public_html');
    }

    public function publicDirName(): string
    {
        return config('webhosting-deploy.paths.public', 'public');
    }

    public function laravelHtmlName(): string
    {
        return config('webhosting-deploy.paths.laravel_html', 'laravel_html');
    }

    protected function customRelativeAppPath(): ?string
    {
        $custom = config('webhosting-deploy.deployment.app_path');

        if (! is_string($custom) || $custom === '') {
            return null;
        }

        return $this->escapedRelative($custom);
    }

    protected function escapedRelative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#^~/#', '', $path) ?? $path;
        $path = ltrim($path, '/');

        return str_replace('"', '', $path);
    }
}
