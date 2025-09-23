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
use GlpiPlugin\Advancedforms\Model\Config\Config as AdvancedFormsConfig;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use GlpiPlugin\Advancedforms\Tests\Provider\QuestionTypesProvider;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConfigFormTest extends FrontTestCase
{
    public function testTabExistOnConfigPageForAdmin(): void
    {
        // Act: go to config form as adminstrator
        $this->login();
        $crawler = $this->get("/front/config.form.php");

        // Assert: a link to the plugin config tab should exist
        $tab = $crawler->filter('a[data-bs-toggle="tab"][title="Advanced forms"]');
        $this->assertCount(1, $tab);
    }

    public function testTabDoesNotExistOnConfigPageForTech(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        // Act: go to config form as technician
        $this->login('tech');
        $this->get("/front/config.form.php");
    }

    public function testTabHasContentExistOnConfigPageForAdmin(): void
    {
        // Act: go to the advanced form tab on the config
        $this->login();
        $crawler = $this->get("/ajax/common.tabs.php", $this->getConfigTabUrlParams());

        // Assert: just make sure some arbitrary content exist, more detailled
        // testing will be done in the services tests instead.
        $config_header = $crawler->filter('[data-testid="advanced-forms-config-header"]');
        $this->assertCount(1, $config_header);
    }

    public function testTabContentIsEmptyForTech(): void
    {
        // Act: go to the advanced form tab on the config
        $this->login('tech');
        $crawler = $this->get("/ajax/common.tabs.php", $this->getConfigTabUrlParams());

        // Assert: no html was rendered as we lack rights to view config
        $this->assertEmpty($crawler);
    }

    #[DataProvider('provideQuestionTypes')]
    public function testCanDisableQuestionTypeConfig(
        ConfigurableItemInterface $item,
    ): void {
        // Arrange: enable config
        Config::setConfigurationValues('advancedforms', [
            $item->getConfigKey() => 1,
        ]);

        // Act: submit config form
        $this->login();
        $this->sendForm("/ajax/common.tabs.php", $this->getConfigTabUrlParams(), [
            $item->getConfigKey() => 0,
        ]);

        // Assert: config should now be disabled
        $manager = $this->getConfigManager();
        $this->assertFalse($item->isConfigEnabled($manager->getConfig()));
    }

    #[DataProvider('provideQuestionTypes')]
    public function testCanEnableQuestionTypeConfig(
        ConfigurableItemInterface $item,
    ): void {
        // Arrange: disable config
        Config::setConfigurationValues('advancedforms', [
            $item->getConfigKey() => 0,
        ]);

        // Act: submit config form
        $this->login();
        $this->sendForm("/ajax/common.tabs.php", $this->getConfigTabUrlParams(), [
            $item->getConfigKey() => 1,
        ]);

        // Assert: config should now be enabled
        $manager = $this->getConfigManager();
        $this->assertTrue($item->isConfigEnabled($manager->getConfig()));
    }

    #[DataProvider('provideQuestionTypes')]
    public function testGetEnabledQuestionsTypes(
        ConfigurableItemInterface $item,
    ): void {
        // Arrange: enable config
        Config::setConfigurationValues('advancedforms', [
            $item->getConfigKey() => 1,
        ]);

        // Act: get enabled types
        $types = $this->getConfigManager()->getEnabledQuestionsTypes();

        // Assert: the expected type should be found
        $this->assertCount(1, $types);
        $type = array_pop($types);
        $this->assertInstanceOf($item::class, $type);
    }

    private function getConfigManager(): ConfigManager
    {
        return ConfigManager::getInstance();
    }

    private function getConfigTabUrlParams(): array
    {
        return [
            '_glpi_tab'   => "GlpiPlugin\\Advancedforms\\Model\\Config\\ConfigTab$1",
            '_itemtype'   => Config::class,
            '_target'     => '/front/config.form.php',
            'id'          => 1,
        ];
    }
}
