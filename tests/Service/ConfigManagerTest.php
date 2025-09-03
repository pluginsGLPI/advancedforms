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
use DOMElement;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ConfigManagerTest extends AdvancedFormsTestCase
{
    public function testCanRenderConfigFormWithoutErrors(): void
    {
        // Act: render configuration
        $html = $this->getConfigManager()->renderConfigForm();

        // Assert: some html was generated and no exception were thrown
        $this->assertNotEmpty($html);
    }

    public function testIpQuestionTypeConfigValueIsAppliedInForm(): void
    {
        // Act: render configuration with the both enabled and disabled state
        Config::setConfigurationValues('advancedforms', [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_IP => 0,
        ]);
        $html_disabled = $this->getConfigManager()->renderConfigForm();

        Config::setConfigurationValues('advancedforms', [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_IP => 1,
        ]);
        $html_enabled = $this->getConfigManager()->renderConfigForm();

        // Assert: the input should have the correct values
        $html_disabled = (new Crawler($html_disabled))
            ->filter('[data-testid="feature-ip-question"]')
            ->filter('input[data-testid="feature-toggle"]')
            ->getNode(0);
        $this->assertInstanceOf(DOMElement::class, $html_disabled);
        /** @var DOMElement $html_disabled */
        $this->assertFalse($html_disabled->hasAttribute('checked'));

        $html_enabled = (new Crawler($html_enabled))
            ->filter('[data-testid="feature-ip-question"]')
            ->filter('input[data-testid="feature-toggle"]')
            ->getNode(0);
        $this->assertInstanceOf(DOMElement::class, $html_enabled);
        /** @var DOMElement $html_enabled */
        $this->assertTrue($html_enabled->hasAttribute('checked'));
    }

    public function testEnableQuestionTypeIpConfigValueIsCorrect(): void
    {
        // Act: get configuration with the both enabled and disabled state
        Config::setConfigurationValues('advancedforms', [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_IP => 0,
        ]);
        $config_disabled = $this->getConfigManager()->getConfig();

        Config::setConfigurationValues('advancedforms', [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_IP => 1,
        ]);
        $config_enabled = $this->getConfigManager()->getConfig();

        // Assert: the config should have the correct values
        $this->assertFalse($config_disabled->isIpAddressQuestionTypeEnabled());
        $this->assertTrue($config_enabled->isIpAddressQuestionTypeEnabled());
    }

    private function getConfigManager(): ConfigManager
    {
        return ConfigManager::getInstance();
    }
}
