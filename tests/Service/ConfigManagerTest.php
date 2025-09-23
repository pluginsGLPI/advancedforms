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
use GlpiPlugin\Advancedforms\Tests\Provider\QuestionTypesProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

final class ConfigManagerTest extends AdvancedFormsTestCase
{
    public static function getConfigurableQuestionTypesWithTestId(): array
    {
        return QuestionTypesProvider::provideQuestionTypes([
            'config_key',
            'data_testid',
        ]);
    }

    #[DataProvider('getConfigurableQuestionTypesWithTestId')]
    public function testQuestionTypeConfigFormWhenEnabled(
        string $config_key,
        string $data_testid,
    ): void {
        // Arrange: enable question type
        Config::setConfigurationValues('advancedforms', [
            $config_key => 1,
        ]);

        // Act: render configuration
        $html_disabled = $this->getConfigManager()->renderConfigForm();

        // Assert: the input should be checked
        $html_disabled = (new Crawler($html_disabled))
            ->filter("[data-testid=\"$data_testid\"]")
            ->filter('input[data-testid="feature-toggle"]')
            ->getNode(0)
        ;
        $this->assertInstanceOf(DOMElement::class, $html_disabled);
        /** @var DOMElement $html_disabled */
        $this->assertTrue($html_disabled->hasAttribute('checked'));
    }

    #[DataProvider('getConfigurableQuestionTypesWithTestId')]
    public function testQuestionTypeConfigFormWhenDisabled(
        string $config_key,
        string $data_testid,
    ): void {
        // Arrange: disable question type
        Config::setConfigurationValues('advancedforms', [
            $config_key => 0,
        ]);

        // Act: render configuration
        $html_disabled = $this->getConfigManager()->renderConfigForm();

        // Assert: the input should not be checked
        $html_disabled = (new Crawler($html_disabled))
            ->filter("[data-testid=\"$data_testid\"]")
            ->filter('input[data-testid="feature-toggle"]')
            ->getNode(0);
        $this->assertInstanceOf(DOMElement::class, $html_disabled);
        /** @var DOMElement $html_disabled */
        $this->assertFalse($html_disabled->hasAttribute('checked'));
    }

    public static function getConfigurableQuestionTypesWithConfig(): array
    {
        return QuestionTypesProvider::provideQuestionTypes([
            'config_key',
            'fetch_config',
        ]);
    }

    #[DataProvider('getConfigurableQuestionTypesWithConfig')]
    public function testQuestionTypeConfigValueWhenEnabled(
        string $config_key,
        callable $fetch_config,
    ): void {
        // Arrange: enable question type
        Config::setConfigurationValues('advancedforms', [
            $config_key => 1,
        ]);

        // Act: get configuration
        $config_enabled = $this->getConfigManager()->getConfig();

        // Assert: the config should be enabled
        $this->assertTrue($fetch_config($config_enabled));
    }

    #[DataProvider('getConfigurableQuestionTypesWithConfig')]
    public function testQuestionTypeConfigValueWhenDisabled(
        string $config_key,
        callable $fetch_config,
    ): void {
        // Arrange: enable question type
        Config::setConfigurationValues('advancedforms', [
            $config_key => 0,
        ]);

        // Act: get configuration
        $config_disabled = $this->getConfigManager()->getConfig();

        // Assert: the config should be enabled
        $this->assertFalse($fetch_config($config_disabled));
    }

    private function getConfigManager(): ConfigManager
    {
        return ConfigManager::getInstance();
    }
}
