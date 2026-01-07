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

namespace GlpiPlugin\Advancedforms\Tests\Helpers;

use GlpiPlugin\Advancedforms\Helpers\NetworkHelper;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NetworkHelperTest extends AdvancedFormsTestCase
{
    public static function getRemoteIpAddressProvider(): \Generator
    {
        // Test X-Forwarded-For header with single IP (highest priority)
        yield 'X-Forwarded-For with single IP' => [
            'http_x_forwarded_for' => '192.168.1.100',
            'remote_addr_env' => '10.0.0.1',
            'remote_addr_server' => '10.0.0.1',
            'expected' => '192.168.1.100',
        ];

        // Test X-Forwarded-For header with multiple IPs (should use first one)
        yield 'X-Forwarded-For with multiple IPs' => [
            'http_x_forwarded_for' => '192.168.1.100, 10.0.0.5, 172.16.0.1',
            'remote_addr_env' => '10.0.0.1',
            'remote_addr_server' => '10.0.0.1',
            'expected' => '192.168.1.100',
        ];

        // Test X-Forwarded-For header with spaces
        yield 'X-Forwarded-For with spaces' => [
            'http_x_forwarded_for' => '  192.168.1.200  ',
            'remote_addr_env' => '10.0.0.1',
            'remote_addr_server' => '10.0.0.1',
            'expected' => '192.168.1.200',
        ];

        // Test fallback to REMOTE_ADDR from getenv
        yield 'Fallback to REMOTE_ADDR from getenv' => [
            'http_x_forwarded_for' => '',
            'remote_addr_env' => '123.123.123.123',
            'remote_addr_server' => '10.0.0.1',
            'expected' => '123.123.123.123',
        ];

        // Test fallback to $_SERVER['REMOTE_ADDR']
        yield 'Fallback to $_SERVER REMOTE_ADDR' => [
            'http_x_forwarded_for' => '',
            'remote_addr_env' => '',
            'remote_addr_server' => '99.99.99.99',
            'expected' => '99.99.99.99',
        ];

        // Test no IP available at all
        yield 'No IP available' => [
            'http_x_forwarded_for' => '',
            'remote_addr_env' => '',
            'remote_addr_server' => null,
            'expected' => '',
        ];

        // Test IPv6 address in X-Forwarded-For
        yield 'IPv6 in X-Forwarded-For' => [
            'http_x_forwarded_for' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            'remote_addr_env' => '10.0.0.1',
            'remote_addr_server' => '10.0.0.1',
            'expected' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
        ];

        // Test IPv6 address in REMOTE_ADDR
        yield 'IPv6 in REMOTE_ADDR' => [
            'http_x_forwarded_for' => '',
            'remote_addr_env' => '2001:0db8:85a3::8a2e:0370:7334',
            'remote_addr_server' => '10.0.0.1',
            'expected' => '2001:0db8:85a3::8a2e:0370:7334',
        ];

        // Test X-Forwarded-For with comma separated IPs without spaces
        yield 'X-Forwarded-For comma separated without spaces' => [
            'http_x_forwarded_for' => '192.168.1.50,10.0.0.5',
            'remote_addr_env' => '10.0.0.1',
            'remote_addr_server' => '10.0.0.1',
            'expected' => '192.168.1.50',
        ];
    }

    #[DataProvider('getRemoteIpAddressProvider')]
    public function testGetIPAddress(
        string $http_x_forwarded_for,
        string $remote_addr_env,
        ?string $remote_addr_server,
        string $expected,
    ): void {
        // Save values
        $saveServer = $_SERVER;

        // Setup environment
        if ($http_x_forwarded_for !== '') {
            putenv("HTTP_X_FORWARDED_FOR={$http_x_forwarded_for}");
        } else {
            putenv("HTTP_X_FORWARDED_FOR=");
        }

        if ($remote_addr_env !== '') {
            putenv("REMOTE_ADDR={$remote_addr_env}");
        } else {
            putenv("REMOTE_ADDR=");
        }

        if ($remote_addr_server !== null) {
            $_SERVER['REMOTE_ADDR'] = $remote_addr_server;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }

        // Test
        $ip = NetworkHelper::getRemoteIpAddress();
        $this->assertEquals($expected, $ip);

        // Clean up environment variables
        putenv("HTTP_X_FORWARDED_FOR");
        putenv("REMOTE_ADDR");

        // Restore values
        $_SERVER = $saveServer;
    }
}
