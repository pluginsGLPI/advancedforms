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

namespace GlpiPlugin\Advancedforms\Tests\Provider;

use GlpiPlugin\Advancedforms\Model\Config\Config;
use GlpiPlugin\Advancedforms\Service\ConfigManager;

final class QuestionTypesProvider
{
    public static function provideQuestionTypes(array $properties): array
    {
        $types = [];
        $types['ip question type'] = [
            'config_key' => ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_IP,
            'fetch_config' => fn(Config $c): bool => $c->isIpAddressQuestionTypeEnabled(),
            'data_testid' => 'feature-ip-question',
        ];
        $types['hostname question type'] = [
            'config_key' => ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_HOSTNAME,
            'fetch_config' => fn(Config $c): bool => $c->isHostnameQuestionTypeEnabled(),
            'data_testid' => 'feature-hostname-question',
        ];
        $types['hidden question type'] = [
            'config_key' => ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_HIDDEN,
            'fetch_config' => fn(Config $c): bool => $c->isHiddenQuestionTypeEnabled(),
            'data_testid' => 'feature-hidden-question',
        ];

        // Keep only requested properties
        foreach ($types as $label => $type) {
            foreach (array_keys($type) as $key) {
                if (!in_array($key, $properties)) {
                    unset($types[$label][$key]);
                }
            }
        }

        return $types;
    }
}
