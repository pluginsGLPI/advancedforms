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

namespace GlpiPlugin\Advancedforms\Tests\Service;

use Glpi\Tests\GLPITestCase;
use Glpi\Toolbox\HttpClient;
use GLPIKey;
use GlpiPlugin\Advancedforms\Service\LatestVersionProvider;
use GlpiPlugin\Advancedforms\Service\SystemInfoParser;
use GlpiPlugin\Advancedforms\Tests\SystemInfoSamples;
use Psr\Log\LogLevel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Safe\json_encode;
use function Safe\preg_replace;

final class LatestVersionProviderTest extends GLPITestCase
{
    private const REGISTRATION_KEY = 'advancedforms-test-registration-key';

    private const GITHUB_RELEASES = [
        ['tag_name' => '12.0.3', 'prerelease' => false],
        ['tag_name' => '11.0.14-rc1', 'prerelease' => true],
        ['tag_name' => '11.0.13', 'prerelease' => false],
        ['tag_name' => '11.0.12', 'prerelease' => false],
    ];

    private mixed $original_registration_key;

    /** @var list<array{url: string, headers: string}> */
    private array $requests = [];

    public function setUp(): void
    {
        global $CFG_GLPI, $GLPI_CACHE;

        parent::setUp();

        $this->original_registration_key = $CFG_GLPI['glpinetwork_registration_key'] ?? null;
        $GLPI_CACHE->deleteMultiple([
            LatestVersionProvider::RELEASES_CACHE_KEY,
            LatestVersionProvider::MARKETPLACE_CACHE_KEY_PREFIX . '11.0.12',
            LatestVersionProvider::MARKETPLACE_CACHE_KEY_PREFIX . '11.0.13',
        ]);
        $this->requests = [];
    }

    public function tearDown(): void
    {
        global $CFG_GLPI;

        $CFG_GLPI['glpinetwork_registration_key'] = $this->original_registration_key;

        parent::tearDown();
    }

    public function testGetLatestGlpiVersionStaysOnBranch(): void
    {
        $provider = $this->makeProvider(['github' => [self::GITHUB_RELEASES]]);

        $this->assertSame('11.0.13', $provider->getLatestGlpiVersion('11.0.12'));
        $this->assertSame('12.0.3', $provider->getLatestGlpiVersion('12.0.1'));
        $this->assertNull($provider->getLatestGlpiVersion('9.5.13'));

        // Releases are fetched once, then read from cache
        $this->assertCount(1, $this->requests);
    }

    public function testGetLatestPluginVersionsFiltersByCompatibility(): void
    {
        $this->fakeRegistration();
        $provider = $this->makeProvider([
            'github'      => [self::GITHUB_RELEASES],
            'marketplace' => [
                [
                    [
                        'key'      => 'datainjection',
                        'versions' => [
                            ['num' => '2.15.8', 'compatibility' => '~11.0.5', 'stability' => 'stable'],
                            ['num' => '2.15.9', 'compatibility' => '~11.0.5', 'stability' => 'beta'],
                            ['num' => '2.16.0', 'compatibility' => '~12.0.0', 'stability' => 'stable'],
                            ['num' => '2.15.99', 'compatibility' => 'not a constraint !', 'stability' => 'stable'],
                        ],
                    ],
                ],
                [],
            ],
        ]);

        $this->assertSame(['datainjection' => '2.15.8'], $provider->getLatestPluginVersions('11.0.12'));

        $marketplace_requests = array_values(array_filter(
            $this->requests,
            fn(array $request): bool => str_contains($request['url'], 'plugins'),
        ));
        $this->assertCount(2, $marketplace_requests);
        $this->assertStringContainsString('User-Agent: GLPI/11.0.12 ', $marketplace_requests[0]['headers']);
        $this->assertStringContainsString('X-Range: 0-199', $marketplace_requests[0]['headers']);
        $this->assertStringContainsString('X-Range: 200-399', $marketplace_requests[1]['headers']);
    }

    public function testUnknownGlpiBranchSkipsMarketplace(): void
    {
        $this->fakeRegistration();
        $provider = $this->makeProvider(['github' => [self::GITHUB_RELEASES]]);

        $this->assertSame([], $provider->getLatestPluginVersions('9.5.13'));
        $this->assertCount(1, $this->requests);
    }

    public function testDevelopmentVersionUsesLatestReleaseOfBranch(): void
    {
        $this->fakeRegistration();
        $provider = $this->makeProvider([
            'github'      => [self::GITHUB_RELEASES],
            'marketplace' => [
                [['key' => 'datainjection', 'versions' => [['num' => '2.15.12', 'compatibility' => '~11.0.5']]]],
                [],
            ],
        ]);

        $this->assertSame(
            ['datainjection' => '2.15.12'],
            $provider->getLatestPluginVersions('11.0.14-dev-git-11.0/bugfixes-e96e925c3f'),
        );
        $this->assertStringContainsString('User-Agent: GLPI/11.0.13 ', $this->requests[1]['headers']);
    }

    public function testHttpErrorGivesUnknownStatus(): void
    {
        $provider = $this->makeProvider(['github' => [new MockResponse('', ['http_code' => 500])]]);

        $parsed = SystemInfoParser::parse(SystemInfoSamples::MODERN);
        $this->assertNotNull($parsed);
        $result = $provider->enrich($parsed);

        $this->assertNull($result['glpi']['latest']);
        $this->assertSame(LatestVersionProvider::STATUS_UNKNOWN, $result['glpi']['status']);
        $this->assertSame(LatestVersionProvider::STATUS_UNKNOWN, $result['plugins'][0]['status']);
        $this->hasPhpLogRecordThatContains('Unable to fetch GLPI releases data', LogLevel::WARNING);
    }

    public function testGithubFailureIsNotRetriedOnEachCall(): void
    {
        $provider = $this->makeProvider(['github' => [new MockResponse('', ['http_code' => 403])]]);

        $this->assertSame([], $provider->getStableGlpiTags());
        $this->assertSame([], $provider->getStableGlpiTags());
        $this->assertCount(1, $this->requests);
        $this->hasPhpLogRecordThatContains('Unable to fetch GLPI releases data', LogLevel::WARNING);
    }

    public function testMarketplaceFailureIsNotRetriedOnEachCall(): void
    {
        $this->fakeRegistration();
        $provider = $this->makeProvider([
            'github'      => [self::GITHUB_RELEASES],
            'marketplace' => [new MockResponse('', ['http_code' => 503])],
        ]);

        $this->assertSame([], $provider->getLatestPluginVersions('11.0.12'));
        $this->assertSame([], $provider->getLatestPluginVersions('11.0.12'));
        $this->assertCount(2, $this->requests);
        $this->hasPhpLogRecordThatContains('Unable to fetch GLPI marketplace data', LogLevel::WARNING);
    }

    public function testEnrichComputesStatuses(): void
    {
        $this->fakeRegistration();
        $provider = $this->makeProvider([
            'github'      => [self::GITHUB_RELEASES],
            'marketplace' => [
                [
                    ['key' => 'datainjection', 'versions' => [['num' => '2.15.10', 'compatibility' => '~11.0.0']]],
                    ['key' => 'fields', 'versions' => [['num' => '1.21.25', 'compatibility' => '~11.0.0']]],
                ],
                [],
            ],
        ]);

        $parsed = SystemInfoParser::parse(SystemInfoSamples::MODERN);
        $this->assertNotNull($parsed);
        $result = $provider->enrich($parsed);

        $this->assertSame(['11.0.13', LatestVersionProvider::STATUS_OUTDATED], [$result['glpi']['latest'], $result['glpi']['status']]);
        $this->assertSame(LatestVersionProvider::STATUS_UP_TO_DATE, $result['plugins'][0]['status']);
        $this->assertSame(['1.21.25', LatestVersionProvider::STATUS_OUTDATED], [$result['plugins'][1]['latest'], $result['plugins'][1]['status']]);
        $this->assertSame(LatestVersionProvider::STATUS_UNKNOWN, $result['plugins'][2]['status']);
    }

    /**
     * @param array{github?: list<mixed>, marketplace?: list<mixed>} $responses
     *        JSON bodies (or MockResponse) served in order, per remote service
     */
    private function makeProvider(array $responses): LatestVersionProvider
    {
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['url' => $url, 'headers' => implode("\n", $options['headers'] ?? [])];

            $service = str_contains($url, 'api.github.com') ? 'github' : 'marketplace';
            $responses[$service] ??= [];
            $response = array_shift($responses[$service]);
            if ($response === null) {
                $this->fail("Unexpected request to $url");
            }

            return $response instanceof MockResponse ? $response : new MockResponse(json_encode($response));
        });

        $http_client = new HttpClient();
        $this->setPrivateProperty($http_client, 'client', $mock);

        return new LatestVersionProvider($http_client);
    }

    private function fakeRegistration(): void
    {
        global $CFG_GLPI, $GLPI_CACHE;

        $CFG_GLPI['glpinetwork_registration_key'] = (new GLPIKey())->encrypt(self::REGISTRATION_KEY);

        // Cached registration information avoids a call to the registration API
        $_SESSION['glpilanguage'] ??= 'en_GB';
        $lang = preg_replace('/^([a-z]+)_.+$/', '$1', $_SESSION['glpilanguage']);
        $GLPI_CACHE->set(
            sprintf('registration_%s_%s_informations', sha1(self::REGISTRATION_KEY), $lang),
            ['is_valid' => true, 'validation_message' => null, 'owner' => null, 'subscription' => null],
        );
    }
}
