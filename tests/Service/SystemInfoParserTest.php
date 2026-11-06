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
use GlpiPlugin\Advancedforms\Service\SystemInfoParser;
use GlpiPlugin\Advancedforms\Tests\SystemInfoSamples;
use PHPUnit\Framework\Attributes\DataProvider;

final class SystemInfoParserTest extends GLPITestCase
{
    public static function parseProvider(): iterable
    {
        yield 'GLPI 11.0+ escaped format' => [
            'raw'      => SystemInfoSamples::MODERN,
            'expected' => [
                'glpi' => [
                    'version'      => '11.0.12',
                    'install_mode' => 'TARBALL',
                    'integrity'    => 'OK',
                ],
                'environment' => [
                    'os'         => 'Linux glpi 6.1.0-28-amd64 #1 SMP x86_64',
                    'php'        => '8.3.6',
                    'web_server' => 'Apache/2.4.62 (Debian) ()',
                    'database'   => 'MariaDB Server 10.11.6-MariaDB-0+deb12u1',
                ],
                'plugins' => [
                    [
                        'key'            => 'datainjection',
                        'name'           => 'Data injection',
                        'version'        => '2.15.10',
                        'state'          => 'Enabled',
                        'install_method' => 'Marketplace',
                    ],
                    [
                        'key'            => 'fields',
                        'name'           => 'Additional fields & co',
                        'version'        => '1.21.22',
                        'state'          => 'Disabled',
                        'install_method' => 'Manual',
                    ],
                    [
                        // Directory is truncated to 20 chars by GLPI itself
                        'key'            => 'averyveryverylongpla',
                        'name'           => 'A very long plugin',
                        'version'        => '1.0.0',
                        'state'          => 'To be cleaned',
                        'install_method' => 'Manual',
                    ],
                ],
            ],
        ];

        yield 'GLPI 10.0 unescaped format' => [
            'raw'      => SystemInfoSamples::LEGACY,
            'expected' => [
                'glpi' => [
                    'version'      => '10.0.18',
                    'install_mode' => 'GIT',
                    'integrity'    => 'N/A',
                ],
                'environment' => [
                    'os'         => '',
                    'php'        => '',
                    'web_server' => '',
                    'database'   => '',
                ],
                'plugins' => [
                    [
                        'key'            => 'formcreator',
                        'name'           => 'Form Creator',
                        'version'        => '2.13.10',
                        'state'          => 'Enabled',
                        'install_method' => 'Manual',
                    ],
                ],
            ],
        ];

        yield 'manual copy without markup' => [
            'raw'      => SystemInfoSamples::PLAIN,
            'expected' => [
                'glpi' => [
                    'version'      => '12.0.1',
                    'install_mode' => 'DOCKER',
                    'integrity'    => '2 files changed',
                ],
                'environment' => [
                    'os'         => '',
                    'php'        => '8.4.1',
                    'web_server' => '',
                    'database'   => 'MySQL Community Server - GPL 8.0.40',
                ],
                'plugins' => [],
            ],
        ];

        yield 'text without GLPI version' => [
            'raw'      => "Hello\nI am on the latest version",
            'expected' => null,
        ];

        yield 'empty text' => [
            'raw'      => '',
            'expected' => null,
        ];
    }

    #[DataProvider('parseProvider')]
    public function testParse(string $raw, ?array $expected): void
    {
        $this->assertSame($expected, SystemInfoParser::parse($raw));
    }

    public function testParseIgnoresLineExhaustingBacktrackLimit(): void
    {
        $crafted = 'p Name: ' . str_repeat(' ', 1950) . 'x';
        $raw = SystemInfoSamples::PLAIN . "\n" . str_repeat($crafted . "\n", 5);

        $parsed = SystemInfoParser::parse($raw);

        $this->assertNotNull($parsed);
        $this->assertSame('12.0.1', $parsed['glpi']['version']);
        $this->assertSame([], $parsed['plugins']);
    }

    public function testParseRejectsOversizedText(): void
    {
        $raw = SystemInfoSamples::PLAIN . str_repeat('a', SystemInfoParser::MAX_LENGTH);

        $this->assertNull(SystemInfoParser::parse($raw));
    }
}
