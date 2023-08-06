<?php

declare(strict_types=1);

namespace NotificationChannels\Fcm;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Http\HttpClientOptions;

class FirebaseProjectManager
{
    /** @var Application */
    protected $app;

    /** @var FirebaseProject[] */
    protected array $projects = [];

    public function __construct()
    {
        $this->app = Config::all();
    }

    public function project(?string $name = null): FirebaseProject
    {
        $name = $name ?? $this->getDefaultProject();

        if (!isset($this->projects[$name])) {
            $this->projects[$name] = $this->configure($name);
        }

        return $this->projects[$name];
    }

    protected function configuration(string $name): array
    {
        $config = $this->app['firebase']['projects'][$name];

        if (!$config) {
            throw new InvalidArgumentException("Firebase project [{$name}] not configured.");
        }

        return $config;
    }

    protected function resolveCredentials(string $credentials): string
    {
        $isJsonString = \str_starts_with($credentials, '{');
        $isAbsoluteLinuxPath = \str_starts_with($credentials, '/');
        $isAbsoluteWindowsPath = \str_contains($credentials, ':\\');

        $isRelativePath = !$isJsonString && !$isAbsoluteLinuxPath && !$isAbsoluteWindowsPath;

        return $isRelativePath ? base_path($credentials) : $credentials;
    }

    protected function configure(string $name): FirebaseProject
    {
        $factory = new Factory();

        $config = $this->configuration($name);
        Log::info(['config' => $config]);

        if ($tenantId = $config['auth']['tenant_id'] ?? null) {
            $factory = $factory->withTenantId($tenantId);
        }

        if ($credentials = $config['credentials']['file'] ?? null) {
            $resolvedCredentials = $this->resolveCredentials((string) $credentials);

            $factory = $factory->withServiceAccount($resolvedCredentials);
        }

        $enableAutoDiscovery = $config['credentials']['auto_discovery'] ?? ($this->getDefaultProject() === $name);
        if (!$enableAutoDiscovery) {
            $factory = $factory->withDisabledAutoDiscovery();
        }

        if ($databaseUrl = $config['database']['url'] ?? null) {
            $factory = $factory->withDatabaseUri($databaseUrl);
        }

        if ($authVariableOverride = $config['database']['auth_variable_override'] ?? null) {
            $factory = $factory->withDatabaseAuthVariableOverride($authVariableOverride);
        }

        if ($defaultStorageBucket = $config['storage']['default_bucket'] ?? null) {
            $factory = $factory->withDefaultStorageBucket($defaultStorageBucket);
        }

        if ($logChannel = $config['logging']['http_log_channel'] ?? null) {
            $factory = $factory->withHttpLogger(
                app()->make('log')->channel($logChannel)
            );
        }

        if ($logChannel = $config['logging']['http_debug_log_channel'] ?? null) {
            $factory = $factory->withHttpDebugLogger(
                app()->make('log')->channel($logChannel)
            );
        }

        $options = HttpClientOptions::default();

        if ($proxy = $config['http_client_options']['proxy'] ?? null) {
            $options = $options->withProxy($proxy);
        }

        if ($timeout = $config['http_client_options']['timeout'] ?? null) {
            $options = $options->withTimeOut((float) $timeout);
        }

        $factory = $factory->withHttpClientOptions($options);

        return new FirebaseProject($factory, $config);
    }

    public function getDefaultProject(): string
    {
        return Config::get('firebase.default');
    }

    public function setDefaultProject(string $name): void
    {
        Config::set('firebase.default', $name);
    }

    public function __call($method, $parameters)
    {
        // Pass call to default project
        return $this->project()->{$method}(...$parameters);
    }
}
