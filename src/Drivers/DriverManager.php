<?php

namespace CocomediaNL\LaravelWebhostingDeploy\Drivers;

class DriverManager
{
    public static function make(?string $name = null): HostingDriver
    {
        $name = $name ?: (string) config('webhosting-deploy.driver', 'directadmin');

        return match ($name) {
            'transip' => new TransipDriver,
            default => new DirectAdminDriver,
        };
    }
}
