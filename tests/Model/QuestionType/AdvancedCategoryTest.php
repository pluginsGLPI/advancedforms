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

namespace GlpiPlugin\Advancedforms\Tests\Model\QuestionType;

use Config;
use Glpi\Form\QuestionType\QuestionTypeInterface;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\AdvancedCategory;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use GlpiPlugin\Advancedforms\Service\InitManager;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdvancedCategoryTest extends AdvancedFormsTestCase
{
    #[DataProvider('provideQuestionTypes')]
    public function testNameWithSingleTypeEnabled(
        ConfigurableItemInterface&QuestionTypeInterface $item,
    ): void {
        // Arrange: enable one type
        Config::setConfigurationValues('advancedforms', [
            $item->getConfigKey() => 1,
        ]);
        InitManager::getInstance()->init();

        // Act: render name and icon
        $name = (new AdvancedCategory())->getLabel();
        $icon = (new AdvancedCategory())->getIcon();

        // Assert: should be replaced by the questions type name
        $this->assertEquals($item->getName(), $name);
        $this->assertEquals($item->getIcon(), $icon);
    }

    public function testNameWithMultipleTypesEnabled(): void
    {
        // Arrange: enabled more than one type
        Config::setConfigurationValues('advancedforms', [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_IP => 1,
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_HOSTNAME => 1,
        ]);
        InitManager::getInstance()->init();

        // Act: render name
        $name = (new AdvancedCategory())->getLabel();
        $icon = (new AdvancedCategory())->getIcon();

        // Assert: should be replaced by the questions type name
        $this->assertEquals("Advanced", $name);
        $this->assertEquals('ti ti-adjustments-plus', $icon);
    }
}
