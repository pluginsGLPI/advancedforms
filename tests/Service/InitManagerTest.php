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
use Glpi\Form\Migration\TypesConversionMapper;
use Glpi\Form\QuestionType\QuestionTypesManager;
use GlpiPlugin\Advancedforms\Model\Mapper\FormcreatorIpTypeMapper;
use GlpiPlugin\Advancedforms\Model\QuestionType\IpAddressQuestion;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use GlpiPlugin\Advancedforms\Service\InitManager;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;

final class InitManagerTest extends AdvancedFormsTestCase
{
    public function testQuestionTypeIpIsAvailableWhenEnabled(): void
    {
        // Arrange: enable question type
        $this->enableIpQuestionType();

        // Act: get enabled types
        $manager = QuestionTypesManager::getInstance();
        $types = $manager->getQuestionTypes();

        // Assert: the ip address question type should only be found after enabling
        $classes = array_map(
            fn($type) => $type::class,
            $types,
        );
        $this->assertContains(IpAddressQuestion::class, $classes);
    }

    public function testQuestionTypeIpIsNotAvailableWhenDisabled(): void
    {
        // Act: get enabled types
        $manager = QuestionTypesManager::getInstance();
        $types = $manager->getQuestionTypes();

        // Assert: the ip address question type should only be found after enabling
        $classes = array_map(
            fn($type) => $type::class,
            $types,
        );
        $this->assertNotContains(IpAddressQuestion::class, $classes);
    }

    public function testQuestionTypeIpIsMappedInConverterWhenEnabled(): void
    {
        // Arrange: enable question type
        $this->enableIpQuestionType();

        // Act: get enabled types
        $mapper = TypesConversionMapper::getInstance();
        $mapped_types = $mapper->getQuestionTypesConversionMap();

        // Assert: the ip address question type should only be found after enabling
        $this->assertInstanceOf(FormcreatorIpTypeMapper::class, $mapped_types['ip']);
    }

    public function testQuestionTypeIpIsNotMappedInConverterWhenDisabled(): void
    {
        // Act: get enabled types
        $mapper = TypesConversionMapper::getInstance();
        $mapped_types = $mapper->getQuestionTypesConversionMap();

        // Assert: the ip address question type should only be found after enabling
        $this->assertNull($mapped_types['ip']);
    }

    private function enableIpQuestionType(): void
    {
        Config::setConfigurationValues('advancedforms', [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_IP => 1,
        ]);
        InitManager::getInstance()->init();
    }
}
