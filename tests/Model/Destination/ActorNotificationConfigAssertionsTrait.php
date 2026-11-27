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

namespace GlpiPlugin\Advancedforms\Tests\Model\Destination;

/**
 * MySQL reorders the keys of the JSON destination config when saving it:
 * the "Email followup" configs are compared with their keys sorted.
 */
trait ActorNotificationConfigAssertionsTrait
{
    /** @param array<mixed> $expected */
    protected function assertNotificationConfigSame(array $expected, mixed $actual): void
    {
        $this->assertIsArray($actual);
        $this->assertSame($this->sortKeys($expected), $this->sortKeys($actual));
    }

    /**
     * Sorts the keys of the maps, the lists keep their order.
     *
     * @param array<mixed> $array
     * @return array<mixed>
     */
    private function sortKeys(array $array): array
    {
        if (!array_is_list($array)) {
            ksort($array);
        }

        return array_map(fn(mixed $value): mixed => is_array($value) ? $this->sortKeys($value) : $value, $array);
    }
}
