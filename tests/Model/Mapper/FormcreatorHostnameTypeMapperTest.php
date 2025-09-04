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
use GlpiPlugin\Advancedforms\Model\QuestionType\HostnameQuestion;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use GlpiPlugin\Advancedforms\Service\InitManager;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use RuntimeException;

final class FormcreatorHostnameTypeMapperTest extends AdvancedFormsTestCase
{
    public static function setUpBeforeClass(): void
    {
        global $DB;

        parent::setUpBeforeClass();

        $queries = $DB->getQueriesFromFile(sprintf(
            '%s/plugins/advancedforms/tests/fixtures/formcreator.sql',
            GLPI_ROOT,
        ));
        foreach ($queries as $query) {
            $DB->doQuery($query);
        }
    }

    public static function tearDownAfterClass(): void
    {
        global $DB;

        $tables = $DB->listTables('glpi\_plugin\_formcreator\_%');
        foreach ($tables as $table) {
            $DB->dropTable($table['TABLE_NAME']);
        }

        parent::tearDownAfterClass();
    }

    public function testHostnameTypeMigrationWhenEnabled(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Arrange: enable ip question type and add some fomrcreator data
        $this->enableHostnameQuestionType();
        $this->createSimpleFormcreatorForm(
            name: "My form",
            questions: [['name' => 'My hostname question', 'fieldtype' => 'hostname']],
        );

        // Act: execute the migration
        $migration = new FormMigration(
            $DB,
            FormAccessControlManager::getInstance(),
        );
        $result = $migration->execute();

        // Assert: make sure the question type was migrated as expected
        $this->assertTrue($result->isFullyProcessed());
        $hostname_question = getItemByTypeName(Question::class, 'My hostname question');
        $this->assertInstanceOf(
            HostnameQuestion::class,
            $hostname_question->getQuestionType(),
        );
    }

    public function testHostnameTypeMigrationWhenDisabled(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Arrange: add some fomrcreator data
        $this->createSimpleFormcreatorForm(
            name: "My form",
            questions: [['name' => 'My hostname question', 'fieldtype' => 'hostname']],
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
        getItemByTypeName(Question::class, 'My hostname question');
    }

    private function enableHostnameQuestionType(): void
    {
        Config::setConfigurationValues('advancedforms', [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_HOSTNAME => 1,
        ]);
        InitManager::getInstance()->init();
    }

    private function createSimpleFormcreatorForm(
        string $name,
        array $questions,
    ): void {
        /** @var \DBmysql $DB */
        global $DB;

        // Add form
        $DB->insert('glpi_plugin_formcreator_forms', [
            'name' => $name,
        ]);
        $form_id = $DB->insertId();

        // Add a section
        $DB->insert('glpi_plugin_formcreator_sections', [
            'plugin_formcreator_forms_id' => $form_id,
        ]);
        $section_id = $DB->insertId();

        // Add questions
        foreach ($questions as $data) {
            $data['plugin_formcreator_sections_id'] = $section_id;
            $DB->insert('glpi_plugin_formcreator_questions', $data);
        }
    }
}
