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

namespace GlpiPlugin\Advancedforms\Tests\Front;

use Config;
use GlpiPlugin\Advancedforms\Service\ConfigManager;

final class ConfigFormTest extends FrontTestCase
{
    public function testTabExistOnConfigPage(): void
    {
        // Act: go to config form
        $this->login();
        $crawler = $this->get("/front/config.form.php");

        // Assert: a link to the plugin config tab should exist
        $tab = $crawler->filter('a[data-bs-toggle="tab"][title="Advanced forms"]');
        $this->assertCount(1, $tab);
    }

    public function testTabHasContentExistOnConfigPage(): void
    {
        // Act: go to the advanced form tab on the config
        $this->login();
        $crawler = $this->get("/ajax/common.tabs.php", $this->getConfigTagUrlParams());

        // Assert: just make sure some arbitrary content exist, more detailled
        // testing will be done in the services tests instead.
        $config_header = $crawler->filter('[data-testid="advanced-forms-config-header"]');
        $this->assertCount(1, $config_header);
    }

    public function testCanDisableQuestionTypeIpConfig(): void
    {
        // Arrange: enable config
        $manager = $this->getConfigManager();
        Config::setConfigurationValues('advancedforms', [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_IP => 1,
        ]);
        $this->assertTrue($manager->getConfig()->isIpAddressQuestionTypeEnabled());

        // Act: submit config form
        $this->login();
        $this->sendForm("/ajax/common.tabs.php", $this->getConfigTagUrlParams(), [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_IP => 0,
        ]);

        // Assert: config should now be disabled
        $this->assertFalse($manager->getConfig()->isIpAddressQuestionTypeEnabled());
    }

    public function testCanEnableQuestionTypeIpConfig(): void
    {
        // Arrange: disable config
        $manager = $this->getConfigManager();
        Config::setConfigurationValues('advancedforms', [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_IP => 0,
        ]);
        $this->assertFalse($manager->getConfig()->isIpAddressQuestionTypeEnabled());

        // Act: submit config form
        $this->login();
        $this->sendForm("/ajax/common.tabs.php", $this->getConfigTagUrlParams(), [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_IP => 1,
        ]);

        // Assert: config should now be enabled
        $this->assertTrue($manager->getConfig()->isIpAddressQuestionTypeEnabled());
    }

    private function getConfigManager(): ConfigManager
    {
        return ConfigManager::getInstance();
    }

    private function getConfigTagUrlParams(): array
    {
        return [
            '_glpi_tab'   => "GlpiPlugin\\Advancedforms\\Model\\Config\\ConfigTab$1",
            '_itemtype'   => Config::class,
            '_target'     => '/front/config.form.php',
            'id'          => 1,
        ];
    }
}
