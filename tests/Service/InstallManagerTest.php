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

use Config;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use GlpiPlugin\Advancedforms\Service\InstallManager;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use GlpiPlugin\Advancedforms\Tests\Provider\QuestionTypesProvider;

final class InstallManagerTest extends AdvancedFormsTestCase
{
    public function testUninstallRemoveConfig(): void
    {
        // Arrange: set multiples config values
        $config_values = [];
        foreach ($this->getConfigurableQuestionTypesConfigKeys() as $key) {
            $config_values[$key] = 1;
        }
        Config::setConfigurationValues('advancedforms', $config_values);

        // Act: uninstall plugin
        $config_before = Config::getConfigurationValues('advancedforms');
        InstallManager::getInstance()->uninstall();
        $config_after = Config::getConfigurationValues('advancedforms');

        // Assert: config should be empty after uninstallation
        $this->assertNotEmpty($config_before);
        $this->assertEmpty($config_after);
    }

    private function getConfigurableQuestionTypesConfigKeys(): array
    {
        $types = QuestionTypesProvider::provideQuestionTypes(['config_key']);
        return array_column($types, 'config_key');
    }
}
