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

namespace GlpiPlugin\Advancedforms\Tests\Model\Mapper;

use Config;
use Glpi\Form\AccessControl\FormAccessControlManager;
use Glpi\Form\Migration\FormMigration;
use Glpi\Form\Question;
use GlpiPlugin\Advancedforms\Model\QuestionType\HiddenQuestion;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use GlpiPlugin\Advancedforms\Service\InitManager;
use RuntimeException;

final class FormcreatorHiddenTypeMapperTest extends MapperTestCase
{
    public function testHiddenTypeMigrationWhenEnabled(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Arrange: enable ip question type and add some fomrcreator data
        $this->enableHiddenQuestionType();
        $this->createSimpleFormcreatorForm(
            name: "My form",
            questions: [
                [
                    'name'           => 'My hidden question',
                    'fieldtype'      => 'hidden',
                    'default_values' => 'my hidden value',
                ],
            ],
        );

        // Act: execute the migration
        $migration = new FormMigration(
            $DB,
            FormAccessControlManager::getInstance(),
        );
        $result = $migration->execute();

        // Assert: make sure the question type was migrated as expected
        $this->assertTrue($result->isFullyProcessed());
        $hidden_question = getItemByTypeName(Question::class, 'My hidden question');
        $this->assertInstanceOf(
            HiddenQuestion::class,
            $hidden_question->getQuestionType(),
        );
        $this->assertEquals(
            'my hidden value',
            $hidden_question->fields['default_value'],
        );
    }

    public function testHiddenTypeMigrationWhenDisabled(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Arrange: add some fomrcreator data
        $this->createSimpleFormcreatorForm(
            name: "My form",
            questions: [
                [
                    'name'           => 'My hidden question',
                    'fieldtype'      => 'hidden',
                    'default_values' => 'my hidden value',
                ],
            ],
        );

        // Act: execute the migration
        $migration = new FormMigration(
            $DB,
            FormAccessControlManager::getInstance(),
        );
        $result = $migration->execute();

        // Assert: make sure the question was ignored
        $this->assertTrue($result->isFullyProcessed());
        $this->expectException(RuntimeException::class);
        getItemByTypeName(Question::class, 'My hidden question');
    }

    private function enableHiddenQuestionType(): void
    {
        Config::setConfigurationValues('advancedforms', [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_HIDDEN => 1,
        ]);
        InitManager::getInstance()->init();
    }
}
