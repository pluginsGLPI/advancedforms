<?php

/**
 * -------------------------------------------------------------------------
 * advancedforms plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2025 by the advancedforms plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/pluginsGLPI/advancedforms
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Advancedforms\Service;

use Composer\Semver\Semver;
use Glpi\Toolbox\HttpClient;
use Glpi\Toolbox\VersionParser;
use GLPINetwork;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use UnexpectedValueException;

/**
 * Computes the latest available version of GLPI and of its plugins on the
 * branch of a given GLPI version (not the one of the current instance).
 *
 * @phpstan-import-type ParsedSystemInfo from SystemInfoParser
 */
final readonly class LatestVersionProvider
{
    public const STATUS_UP_TO_DATE = 'up_to_date';

    public const STATUS_OUTDATED   = 'outdated';

    public const STATUS_UNKNOWN    = 'unknown';

    public const RELEASES_CACHE_KEY           = 'advancedforms_glpi_releases';

    public const MARKETPLACE_CACHE_KEY_PREFIX = 'advancedforms_marketplace_';

    private const GITHUB_RELEASES_URL   = 'https://api.github.com/repos/glpi-project/glpi/releases?per_page=100';

    private const MARKETPLACE_PAGE_SIZE = 200;

    private const MARKETPLACE_MAX_PAGES = 20;

    private const FAILURE_CACHE_TTL     = 5 * MINUTE_TIMESTAMP;

    private HttpClient $http_client;

    public function __construct(?HttpClient $http_client = null)
    {
        $this->http_client = $http_client ?? new HttpClient(context: GLPINetwork::class);
    }

    /** @return list<string> Stable GLPI release tags. */
    public function getStableGlpiTags(): array
    {
        global $GLPI_CACHE;

        $cached = $GLPI_CACHE->get(self::RELEASES_CACHE_KEY);
        if (is_array($cached)) {
            return array_values(array_filter($cached, is_string(...)));
        }

        try {
            $releases = $this->http_client->get(self::GITHUB_RELEASES_URL)->toArray();
        } catch (ExceptionInterface $exception) {
            $this->logWarning('Unable to fetch GLPI releases data', $exception);
            // Short negative cache: previews must not hammer GitHub while it fails or rate-limits
            $GLPI_CACHE->set(self::RELEASES_CACHE_KEY, [], self::FAILURE_CACHE_TTL);
            return [];
        }

        $tags = [];
        foreach ($releases as $release) {
            if (
                is_array($release)
                && ($release['prerelease'] ?? true) === false
                && is_string($release['tag_name'] ?? null)
            ) {
                $tags[] = $release['tag_name'];
            }
        }

        $GLPI_CACHE->set(self::RELEASES_CACHE_KEY, $tags, HOUR_TIMESTAMP);
        return $tags;
    }

    public function getLatestGlpiVersion(string $version): ?string
    {
        $parts = explode('.', VersionParser::getNormalizedVersion($version, false));
        if (count($parts) < 2) {
            return null;
        }

        $branch_prefix = $parts[0] . '.' . $parts[1] . '.';
        $latest = null;
        foreach ($this->getStableGlpiTags() as $tag) {
            if (str_starts_with($tag, $branch_prefix) && ($latest === null || version_compare($tag, $latest, '>'))) {
                $latest = $tag;
            }
        }

        return $latest;
    }

    /** @return array<string, string> Latest compatible stable version, keyed by plugin key. */
    public function getLatestPluginVersions(string $glpi_version): array
    {
        global $GLPI_CACHE;

        if (!GLPINetwork::isRegistered()) {
            return [];
        }

        // Only real releases are queried, which bounds the number of cache entries;
        // an unreleased version (e.g. 12.0.2-dev) uses the latest release of its branch
        $normalized = VersionParser::getNormalizedVersion($glpi_version, false);
        if (!in_array($normalized, $this->getStableGlpiTags(), true)) {
            $normalized = $this->getLatestGlpiVersion($glpi_version);
            if ($normalized === null) {
                return [];
            }
        }

        $cache_key = self::MARKETPLACE_CACHE_KEY_PREFIX . $normalized;
        $cached = $GLPI_CACHE->get($cache_key);
        if (is_array($cached)) {
            $latest = [];
            foreach ($cached as $key => $version) {
                if (is_string($key) && is_string($version)) {
                    $latest[$key] = $version;
                }
            }

            return $latest;
        }

        $plugins  = [];
        $complete = true;
        for ($page = 0; $page < self::MARKETPLACE_MAX_PAGES; $page++) {
            $start = $page * self::MARKETPLACE_PAGE_SIZE;
            try {
                $current = $this->http_client->get(GLPI_MARKETPLACE_PLUGINS_API_URI . 'plugins', [
                    'headers' => [
                        'Accept'             => 'application/json',
                        // The marketplace only returns versions compatible with the announced GLPI version
                        'User-Agent'         => sprintf('GLPI/%s (installation-mode:%s)', $normalized, GLPI_INSTALL_MODE),
                        'X-Registration-Key' => GLPINetwork::getRegistrationKey(),
                        'X-Glpi-Network-Uid' => GLPINetwork::getGlpiNetworkUid(),
                        'X-Range'            => $start . '-' . ($start + self::MARKETPLACE_PAGE_SIZE - 1),
                    ],
                ])->toArray();
            } catch (ExceptionInterface $e) {
                $this->logWarning('Unable to fetch GLPI marketplace data', $e);
                $complete = false;
                break;
            }

            if ($current === []) {
                break;
            }

            foreach ($current as $plugin) {
                $plugins[] = $plugin;
            }
        }

        $latest = $this->computeLatestPluginVersions($plugins, $normalized);
        $GLPI_CACHE->set($cache_key, $latest, $complete ? HOUR_TIMESTAMP : self::FAILURE_CACHE_TTL);

        return $latest;
    }

    /**
     * @param ParsedSystemInfo $parsed
     * @return array<string, mixed> The parsed data, with `latest` and `status` added to GLPI and to each plugin.
     */
    public function enrich(array $parsed): array
    {
        $glpi_version = $parsed['glpi']['version'];
        $result = $parsed;
        $result['glpi'] += $this->buildStatus($glpi_version, $this->getLatestGlpiVersion($glpi_version));

        $latest_plugins = $this->getLatestPluginVersions($glpi_version);
        foreach ($parsed['plugins'] as $index => $plugin) {
            $result['plugins'][$index] += $this->buildStatus(
                $plugin['version'],
                $latest_plugins[$plugin['key']] ?? null,
            );
        }

        return $result;
    }

    /**
     * @param array<mixed> $plugins Raw marketplace plugins
     * @return array<string, string>
     */
    private function computeLatestPluginVersions(array $plugins, string $glpi_version): array
    {
        $latest = [];
        foreach ($plugins as $plugin) {
            if (!is_array($plugin) || !is_string($plugin['key'] ?? null) || !is_array($plugin['versions'] ?? null)) {
                continue;
            }

            $key = $plugin['key'];
            foreach ($plugin['versions'] as $version) {
                if (
                    !is_array($version)
                    || !is_string($version['num'] ?? null)
                    || !is_string($version['compatibility'] ?? null)
                    || (isset($version['stability']) && $version['stability'] !== 'stable')
                    || !$this->satisfies($glpi_version, $version['compatibility'])
                ) {
                    continue;
                }

                if (!isset($latest[$key]) || version_compare($version['num'], $latest[$key], '>')) {
                    $latest[$key] = $version['num'];
                }
            }
        }

        return $latest;
    }

    private function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    /** @return array{latest: ?string, status: string} */
    private function buildStatus(string $installed, ?string $latest): array
    {
        if ($latest === null) {
            $status = self::STATUS_UNKNOWN;
        } elseif (version_compare($installed, $latest, '>=')) {
            $status = self::STATUS_UP_TO_DATE;
        } else {
            $status = self::STATUS_OUTDATED;
        }

        return ['latest' => $latest, 'status' => $status];
    }

    private function logWarning(string $message, ExceptionInterface $e): void
    {
        global $PHPLOGGER;

        $PHPLOGGER->warning(
            sprintf('%s: %s', $message, $e->getMessage()),
            ['exception' => $e],
        );
    }
}
