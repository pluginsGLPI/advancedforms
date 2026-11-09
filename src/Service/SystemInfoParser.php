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

use Safe\Exceptions\PcreException;

use function Safe\preg_match;
use function Safe\preg_replace;
use function Safe\preg_split;

/**
 * Parses the text copied by the "Copy system information" button of GLPI
 * (10.0 unescaped, 11.0+ escaped inside <details><pre>, or a manual copy).
 *
 * @phpstan-type ParsedPlugin array{key: string, name: string, version: string, state: string, install_method: string}
 * @phpstan-type ParsedSystemInfo array{
 *     glpi: array{version: string, install_mode: string, integrity: string},
 *     environment: array{os: string, php: string, web_server: string, database: string},
 *     plugins: list<ParsedPlugin>
 * }
 */
final class SystemInfoParser
{
    public const MAX_LENGTH = 200000;

    private const MAX_LINE_LENGTH = 2000;

    private const MAX_PLUGINS = 300;

    /** Single value fields, first match wins. */
    private const FIELD_PATTERNS = [
        'glpi_version'     => '/^\s*GLPI:?\s+(\d+\.\d+\.\d+[^\s(]*)/',
        'install_mode'     => '/^\s*Installation mode:\s*(\S+)/',
        'integrity'        => '/^\s*Source Integrity:\s*(.+)$/',
        'os'               => '/^\s*Operating system:\s*(.+)$/',
        'php'              => '/^\s*PHP:\s*(\S+)/',
        'web_server'       => '/^\s*Web server:\s*(.+)$/',
        'db_software'      => '/^\s*Server Software:\s*(.+)$/',
        'db_version'       => '/^\s*Server Version:\s*(.+)$/',
    ];

    private const PLUGIN_PATTERN = '/^\s*(\S+)\s+Name:\s*(.+?)\s+Version:\s*(\S+)\s+State:\s*(.+?)\s+Install Method:\s*(\S+)\s*$/';

    /** @return ParsedSystemInfo|null */
    public static function parse(string $raw): ?array
    {
        if (mb_strlen($raw) > self::MAX_LENGTH) {
            return null;
        }

        // Wrapper tags become line breaks: GLPI 10.0 glues the section title to its first line
        $text = (string) preg_replace('#</?(details|summary|pre)\b[^>]*>#i', "\n", $raw);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $fields  = array_fill_keys(array_keys(self::FIELD_PATTERNS), '');
        $plugins = [];

        foreach (preg_split('/\R/', $text) as $line) {
            if (!is_string($line) || strlen($line) > self::MAX_LINE_LENGTH) {
                continue;
            }

            if (count($plugins) < self::MAX_PLUGINS && self::match(self::PLUGIN_PATTERN, $line, $matches)) {
                $plugins[] = [
                    'key'            => $matches[1] ?? '',
                    'name'           => trim($matches[2] ?? ''),
                    'version'        => $matches[3] ?? '',
                    'state'          => trim($matches[4] ?? ''),
                    'install_method' => $matches[5] ?? '',
                ];
                continue;
            }

            foreach (self::FIELD_PATTERNS as $field => $pattern) {
                if ($fields[$field] === '' && self::match($pattern, $line, $matches)) {
                    $fields[$field] = trim($matches[1] ?? '');
                    break;
                }
            }
        }

        if ($fields['glpi_version'] === '') {
            return null;
        }

        return [
            'glpi' => [
                'version'      => $fields['glpi_version'],
                'install_mode' => $fields['install_mode'],
                'integrity'    => $fields['integrity'],
            ],
            'environment' => [
                'os'         => $fields['os'],
                'php'        => $fields['php'],
                'web_server' => $fields['web_server'],
                'database'   => trim($fields['db_software'] . ' ' . $fields['db_version']),
            ],
            'plugins' => $plugins,
        ];
    }

    /**
     * A crafted line can exhaust the PCRE backtrack limit: it is then ignored
     * instead of failing the whole request.
     *
     * @param array<array-key, string>|null $matches
     * @param-out array<array-key, string> $matches
     */
    private static function match(string $pattern, string $line, ?array &$matches): bool
    {
        $matches = [];
        try {
            $result = [];
            $found = preg_match($pattern, $line, $result) === 1;
        } catch (PcreException) {
            return false;
        }

        $matches = array_filter($result ?? [], is_string(...));
        return $found;
    }
}
