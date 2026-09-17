<?php

namespace CocomediaNL\LaravelWebhostingDeploy\Support;

use CocomediaNL\LaravelWebhostingDeploy\Services\SshConnectionService;

class EnvFile
{
    /**
     * Create or update keys in a local .env file.
     *
     * @param  array<string, scalar|null>  $values
     */
    public static function upsert(string $path, array $values): void
    {
        $content = file_exists($path) ? (string) file_get_contents($path) : '';

        if ($content !== '' && ! str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            $content = self::upsertKey($content, (string) $key, (string) $value);
        }

        file_put_contents($path, $content);
    }

    /**
     * Update a remote .env by uploading a JSON payload and a small PHP helper.
     *
     * @param  array<string, scalar|null>  $values
     */
    public static function upsertRemote(SshConnectionService $ssh, string $shellAppPath, array $values): void
    {
        $filtered = [];

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            $filtered[(string) $key] = (string) $value;
        }

        if ($filtered === []) {
            return;
        }

        $payload = tempnam(sys_get_temp_dir(), 'webhosting-env-');
        $script = tempnam(sys_get_temp_dir(), 'webhosting-env-script-');

        if ($payload === false || $script === false) {
            throw new \RuntimeException('Could not create a temporary file for remote .env updates.');
        }

        try {
            file_put_contents($payload, json_encode($filtered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            file_put_contents($script, self::remoteUpdaterSource());

            $remotePayload = '/tmp/webhosting-deploy-env.json';
            $remoteScript = '/tmp/webhosting-update-env.php';

            $ssh->uploadFile($payload, $remotePayload);
            $ssh->uploadFile($script, $remoteScript);

            $ssh->execute(sprintf(
                'cd %s && php %s %s .env && rm -f %s %s',
                $shellAppPath,
                escapeshellarg($remoteScript),
                escapeshellarg($remotePayload),
                escapeshellarg($remoteScript),
                escapeshellarg($remotePayload)
            ));
        } finally {
            @unlink($payload);
            @unlink($script);
        }
    }

    protected static function upsertKey(string $content, string $key, string $value): string
    {
        $formatted = self::formatLine($key, $value);
        $pattern = '/^'.preg_quote($key, '/').'=/';

        if (! preg_match($pattern.'m', $content)) {
            return $content.$formatted."\n";
        }

        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $replaced = false;
        $output = [];

        foreach ($lines as $index => $line) {
            if ($line === '' && $index === count($lines) - 1) {
                continue;
            }

            if (preg_match($pattern, $line)) {
                if (! $replaced) {
                    $output[] = $formatted;
                    $replaced = true;
                }

                continue;
            }

            $output[] = $line;
        }

        return implode("\n", $output)."\n";
    }

    public static function formatLine(string $key, string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'\\\\]/', $value)) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

            return $key.'="'.$escaped.'"';
        }

        return $key.'='.$value;
    }

    protected static function remoteUpdaterSource(): string
    {
        return <<<'PHP'
<?php
$payloadFile = $argv[1] ?? '';
$envFile = $argv[2] ?? '';

if ($payloadFile === '' || $envFile === '') {
    fwrite(STDERR, "Usage: php update-env.php payload.json .env\n");
    exit(1);
}

$values = json_decode((string) file_get_contents($payloadFile), true);

if (! is_array($values)) {
    fwrite(STDERR, "Invalid JSON payload\n");
    exit(1);
}

$content = is_file($envFile) ? (string) file_get_contents($envFile) : '';

if ($content !== '' && ! str_ends_with($content, "\n")) {
    $content .= "\n";
}

$format = static function (string $key, string $value): string {
    if ($value === '' || preg_match('/[\s#"\'\\\\]/', $value)) {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return $key.'="'.$escaped.'"';
    }

    return $key.'='.$value;
};

foreach ($values as $key => $value) {
    $key = (string) $key;
    $formatted = $format($key, (string) $value);
    $pattern = '/^'.preg_quote($key, '/').'=/';

    if (! preg_match($pattern.'m', $content)) {
        $content .= $formatted."\n";
        continue;
    }

    $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
    $replaced = false;
    $output = [];

    foreach ($lines as $index => $line) {
        if ($line === '' && $index === count($lines) - 1) {
            continue;
        }

        if (preg_match($pattern, $line)) {
            if (! $replaced) {
                $output[] = $formatted;
                $replaced = true;
            }

            continue;
        }

        $output[] = $line;
    }

    $content = implode("\n", $output)."\n";
}

$dir = dirname($envFile);
if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}

file_put_contents($envFile, $content);
PHP;
    }
}
