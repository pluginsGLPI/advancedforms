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

use DOMElement;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Toolbox;

final class ConfigManagerTest extends AdvancedFormsTestCase
{
    #[DataProvider('provideQuestionTypes')]
    #[DataProvider('provideSLMDestinationStrategies')]
    public function testQuestionTypeConfigFormWhenEnabled(
        ConfigurableItemInterface $item,
    ): void {
        // Arrange: enable question type
        $this->enableConfigurableItem($item);

        // Act: render configuration
        $html_disabled = $this->getConfigManager()->renderConfigForm();

        // Assert: the input should be checked
        $testid = Toolbox::slugify($item::class);
        $html_disabled = (new Crawler($html_disabled))
            ->filter("[data-testid=\"feature-$testid\"]")
            ->filter('input[data-testid="feature-toggle"]')
            ->getNode(0)
        ;
        $this->assertInstanceOf(DOMElement::class, $html_disabled);
        /** @var DOMElement $html_disabled */
        $this->assertTrue($html_disabled->hasAttribute('checked'));
    }

    #[DataProvider('provideQuestionTypes')]
    #[DataProvider('provideSLMDestinationStrategies')]
    public function testQuestionTypeConfigFormWhenDisabled(
        ConfigurableItemInterface $item,
    ): void {
        // Arrange: disable question type
        $this->disableConfigurableItem($item);

        // Act: render configuration
        $html_disabled = $this->getConfigManager()->renderConfigForm();

        // Assert: the input should not be checked
        $testid = Toolbox::slugify($item::class);
        $html_disabled = (new Crawler($html_disabled))
            ->filter("[data-testid=\"feature-$testid\"]")
            ->filter('input[data-testid="feature-toggle"]')
            ->getNode(0);
        $this->assertInstanceOf(DOMElement::class, $html_disabled);
        /** @var DOMElement $html_disabled */
        $this->assertFalse($html_disabled->hasAttribute('checked'));
    }

    #[DataProvider('provideQuestionTypes')]
    #[DataProvider('provideSLMDestinationStrategies')]
    public function testQuestionTypeConfigValueWhenEnabled(
        ConfigurableItemInterface $item,
    ): void {
        // Arrange: enable question type
        $this->enableConfigurableItem($item);

        // Act: get configuration
        $manager = $this->getConfigManager();
        $config_enabled = $manager->isConfigurableItemEnabled($item);

        // Assert: the config should be enabled
        $this->assertTrue($config_enabled);
    }

    #[DataProvider('provideQuestionTypes')]
    #[DataProvider('provideSLMDestinationStrategies')]
    public function testQuestionTypeConfigValueWhenDisabled(
        ConfigurableItemInterface $item,
    ): void {
        // Arrange: enable question type
        $this->disableConfigurableItem($item);

        // Act: get configuration
        $manager = $this->getConfigManager();
        $config_disabled = $manager->isConfigurableItemEnabled($item);

        // Assert: the config should be enabled
        $this->assertFalse($config_disabled);
    }

    private function getConfigManager(): ConfigManager
    {
        return ConfigManager::getInstance();
    }
}
