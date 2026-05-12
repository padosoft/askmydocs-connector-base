<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Padosoft\AskMyDocsConnectorBase\Exceptions\RegistryConfigurationException;

/**
 * Auto-discovery registry for connector implementations.
 *
 * Two discovery channels are merged into a single resolved list:
 *
 *   1. **Built-in connectors** — FQCN list from
 *      `config/connectors.php::built_in`. Used for connectors the
 *      host app wires by hand (no composer install step needed).
 *
 *   2. **Composer-package connectors** — discovered by walking
 *      `composer.lock`'s `packages` array and reading each entry's
 *      `extra.askmydocs.connectors` field (array of FQCNs). Used by
 *      OS connectors shipped as separate `padosoft/askmydocs-connector-*`
 *      packages or community-authored. The pattern mirrors Laravel's
 *      `extra.laravel.providers` auto-discovery convention.
 *
 * **R23 — every FQCN is validated at boot.** The registry calls
 * `app($fqcn)` immediately, asserts the instance is a
 * `ConnectorInterface`, and throws
 * {@see RegistryConfigurationException} on mismatch so the failure
 * surfaces as a fatal at app boot instead of a confusing "undefined
 * method" the first time someone hits an admin route.
 *
 * Bound as a singleton in {@see ConnectorServiceProvider::register()}
 * so the boot cost is paid once per request.
 */
class ConnectorRegistry
{
    /** @var array<string, ConnectorInterface> Keyed by `key()`. */
    private array $connectors = [];

    /**
     * @param  array{built_in?: list<class-string>}  $config
     * @param  array<int, array<string,mixed>>|null  $composerPackages
     *
     * @throws RegistryConfigurationException
     */
    public function __construct(
        Container $app,
        array $config,
        ?array $composerPackages = null,
    ) {
        $fqcns = array_values(array_unique(array_merge(
            $this->normaliseBuiltIn($config['built_in'] ?? []),
            $this->collectComposerConnectors($composerPackages),
        )));

        foreach ($fqcns as $fqcn) {
            $this->register($app, $fqcn);
        }
    }

    /**
     * @return Collection<int, ConnectorInterface>
     */
    public function all(): Collection
    {
        return collect(array_values($this->connectors));
    }

    public function get(string $name): ?ConnectorInterface
    {
        return $this->connectors[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->connectors[$name]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->connectors);
    }

    /**
     * @param  list<mixed>  $entries
     * @return list<class-string>
     */
    private function normaliseBuiltIn(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (! is_string($entry) || $entry === '') {
                throw new RegistryConfigurationException(
                    'config/connectors.php::built_in entries must be non-empty FQCN strings.'
                );
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Read `composer.lock` and harvest every package's
     * `extra.askmydocs.connectors` array. We read the lockfile rather
     * than `composer.json`'s `require` list because the lockfile is
     * the source of truth for what's actually installed AND it
     * includes the `extra` block from each package's own
     * composer.json verbatim — we don't need to spider the vendor
     * directory.
     *
     * If `composer.lock` is missing (e.g. fresh checkout with no
     * `composer install` yet, or in some test environments), we
     * silently return an empty list — built-in connectors still work.
     *
     * @param  array<int, array<string,mixed>>|null  $override  Explicit
     *                                                          package list (used by tests + dry-run paths to avoid
     *                                                          disk reads). When null, we read base_path('composer.lock').
     * @return list<class-string>
     */
    private function collectComposerConnectors(?array $override): array
    {
        $packages = $override ?? $this->readComposerLock();
        if ($packages === []) {
            return [];
        }

        $out = [];
        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            $extra = $package['extra'] ?? null;
            if (! is_array($extra)) {
                continue;
            }

            $askmydocs = $extra['askmydocs'] ?? null;
            if (! is_array($askmydocs)) {
                continue;
            }

            $connectors = $askmydocs['connectors'] ?? null;
            if (! is_array($connectors)) {
                continue;
            }

            $packageName = (string) ($package['name'] ?? 'unknown-package');
            foreach ($connectors as $fqcn) {
                if (! is_string($fqcn) || $fqcn === '') {
                    throw new RegistryConfigurationException(
                        "Package '{$packageName}' declared an invalid connector FQCN in composer.json `extra.askmydocs.connectors` — entries must be non-empty strings."
                    );
                }
                $out[] = $fqcn;
            }
        }

        return $out;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function readComposerLock(): array
    {
        $path = base_path('composer.lock');
        if (! is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $packages = $decoded['packages'] ?? [];

        return is_array($packages) ? $packages : [];
    }

    /**
     * Resolve a single FQCN via the container, verify the resulting
     * instance is a `ConnectorInterface`, and register it under its
     * `key()`. Throws if (a) the class doesn't exist, (b) doesn't
     * implement the contract, or (c) collides with an already-
     * registered connector key — first-match-wins resolution would
     * silently mask a duplicate (R23 + PipelineRegistry pattern).
     */
    private function register(Container $app, string $fqcn): void
    {
        if (! class_exists($fqcn)) {
            throw new RegistryConfigurationException(
                "Connector FQCN '{$fqcn}' does not exist — check composer autoload + config/connectors.php."
            );
        }

        try {
            $instance = $app->make($fqcn);
        } catch (\Throwable $e) {
            throw new RegistryConfigurationException(
                "Connector '{$fqcn}' could not be resolved by the container: {$e->getMessage()}",
                0,
                $e,
            );
        }

        if (! $instance instanceof ConnectorInterface) {
            throw new RegistryConfigurationException(sprintf(
                "Connector '%s' does not implement %s.",
                $fqcn,
                ConnectorInterface::class,
            ));
        }

        $key = $instance->key();
        if (isset($this->connectors[$key])) {
            throw new RegistryConfigurationException(sprintf(
                "Duplicate connector key '%s' — '%s' collides with '%s'. Connector keys must be unique across built-in + composer-discovered registries.",
                $key,
                $fqcn,
                $this->connectors[$key]::class,
            ));
        }

        $this->connectors[$key] = $instance;
    }
}
