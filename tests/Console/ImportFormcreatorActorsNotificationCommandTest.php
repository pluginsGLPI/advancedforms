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

namespace GlpiPlugin\Advancedforms\Tests\Console;

use DBmysql;
use Glpi\Form\AccessControl\FormAccessControlManager;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Form;
use Glpi\Form\Migration\FormMigration;
use Glpi\Form\Question;
use GlpiPlugin\Advancedforms\Console\ImportFormcreatorActorsNotificationCommand;
use GlpiPlugin\Advancedforms\Model\Destination\ActorNotificationField;
use GlpiPlugin\Advancedforms\Model\Destination\ActorNotificationFieldConfig;
use GlpiPlugin\Advancedforms\Tests\Model\Mapper\MapperTestCase;
use Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use User;

final class ImportFormcreatorActorsNotificationCommandTest extends MapperTestCase
{
    private const ROLE_REQUESTER = 1;

    private const ROLE_OBSERVER = 2;

    private const ROLE_ASSIGN = 3;

    public function testDryRunDisplaysChangesWithoutSaving(): void
    {
        $form = $this->migrateFormcreatorFormWithUncheckedActors();
        $config_before = $this->getDestination($form)->fields['config'];

        $tester = $this->runCommand(dry_run: true);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('dry run', $tester->getDisplay());
        $this->assertStringContainsString('Destinations to update: 1', $tester->getDisplay());
        $this->assertStringContainsString('Disabled sources: 3', $tester->getDisplay());
        $this->assertSame($config_before, $this->getDestination($form)->fields['config']);
    }

    public function testUncheckedActorsSourcesAreDisabled(): void
    {
        $form = $this->migrateFormcreatorFormWithUncheckedActors();

        $tester = $this->runCommand();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Updated destinations: 1', $tester->getDisplay());
        $this->assertStringContainsString('Disabled sources: 3', $tester->getDisplay());
        $this->assertSame(
            $this->expectedConfig($form),
            $this->getDestination($form)->getConfig()[ActorNotificationField::getKey()],
        );
    }

    public function testRerunKeepsManualSettingsWithoutDuplicates(): void
    {
        $form = $this->migrateFormcreatorFormWithUncheckedActors();
        $this->runCommand();

        // Manually disable another source
        $normal_key = 'users_id-' . getItemByTypeName(User::class, 'normal', true);
        $destination = $this->getDestination($form);
        $config = $destination->getConfig();
        $config[ActorNotificationField::getKey()]['roles']['assign']['disabled_actors'][] = $normal_key;
        $this->updateItem(
            FormDestination::class,
            $destination->getID(),
            ['config' => $config, '_from_import' => true], // Saved config, already prepared
            ['config', '_from_import'],
        );

        $tester = $this->runCommand();

        $this->assertStringContainsString('Updated destinations: 0', $tester->getDisplay());
        $this->assertStringContainsString('Disabled sources: 0', $tester->getDisplay());
        $expected = $this->expectedConfig($form);
        $expected['roles']['assign']['disabled_actors'][] = $normal_key;
        $this->assertSame(
            $expected,
            $this->getDestination($form)->getConfig()[ActorNotificationField::getKey()],
        );
    }

    public function testMissingQuestionIsReportedAndOtherSourcesImported(): void
    {
        $form = $this->migrateFormcreatorFormWithUncheckedActors();
        $expected = $this->expectedConfig($form);
        $expected['roles']['observer']['disabled_question_ids'] = [];
        $this->deleteItem(Question::class, $this->getQuestionId($form, 'FC actors question'), purge: true);

        $tester = $this->runCommand();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Question not found', $tester->getDisplay());
        $this->assertStringContainsString('Disabled sources: 2', $tester->getDisplay());
        $this->assertSame(
            $expected,
            $this->getDestination($form)->getConfig()[ActorNotificationField::getKey()],
        );
    }

    public function testMissingFormcreatorTablesFails(): void
    {
        $this->withoutFormcreatorTable(function () {
            $tester = $this->runCommand();

            $this->assertSame(Command::FAILURE, $tester->getStatusCode());
            $this->assertStringContainsString('Formcreator tables not found', $tester->getDisplay());
        });
    }

    private function migrateFormcreatorFormWithUncheckedActors(): Form
    {
        /** @var DBmysql $DB */
        global $DB;

        $this->login();
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        $normal_id = getItemByTypeName(User::class, 'normal', true);
        $group = $this->createItem(Group::class, ['name' => 'FC observers group']);

        $this->createSimpleFormcreatorForm(
            name: 'FC form with unchecked actors',
            questions: [[
                'name'      => 'FC actors question',
                'fieldtype' => 'actor',
                'uuid'      => 'fc-actors-question-uuid',
            ]],
            ticket_destinations: [['name' => 'FC ticket target', 'content' => '##FULLFORM##']],
            properties: ['uuid' => 'fc-form-uuid'],
        );
        $target_id = (int) $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_plugin_formcreator_targettickets',
            'WHERE'  => ['name' => 'FC ticket target'],
        ])->current()['id'];
        $question_id = $this->getFormCreatorQuestionId('FC actors question');

        $actors = [
            [self::ROLE_REQUESTER, 1, 0, 0],                   // Form author, unchecked
            [self::ROLE_OBSERVER, 9, $question_id, 0],         // Actors question, unchecked
            [self::ROLE_OBSERVER, 5, $group->getID(), 0],      // Group, ignored
            [self::ROLE_ASSIGN, 3, $tech_id, 0],               // Specific user, unchecked
            [self::ROLE_ASSIGN, 3, $normal_id, 1],             // Specific user, checked
        ];
        foreach ($actors as [$role, $type, $value, $use_notification]) {
            $DB->insert('glpi_plugin_formcreator_targets_actors', [
                'itemtype'         => 'PluginFormcreatorTargetTicket',
                'items_id'         => $target_id,
                'actor_role'       => $role,
                'actor_type'       => $type,
                'actor_value'      => $value,
                'use_notification' => $use_notification,
            ]);
        }

        $result = (new FormMigration($DB, FormAccessControlManager::getInstance()))->execute();
        $this->assertTrue($result->isFullyProcessed());

        $form = getItemByTypeName(Form::class, 'FC form with unchecked actors');
        $this->assertInstanceOf(Form::class, $form);

        return $form;
    }

    private function runCommand(bool $dry_run = false): CommandTester
    {
        $tester = new CommandTester(new ImportFormcreatorActorsNotificationCommand());
        $tester->execute($dry_run ? ['--dry-run' => true] : []);

        return $tester;
    }

    private function getDestination(Form $form): FormDestination
    {
        $destinations = $form->getDestinations();
        $this->assertCount(1, $destinations);

        $destination = current($destinations);
        $this->assertSame('FC ticket target', $destination->fields['name']);

        return $destination;
    }

    /** @return array<string, mixed> */
    private function expectedConfig(Form $form): array
    {
        return ['roles' => [
            'requester' => ActorNotificationFieldConfig::buildRoleConfig(strategies: ['form_filler']),
            'observer'  => ActorNotificationFieldConfig::buildRoleConfig(
                question_ids: [$this->getQuestionId($form, 'FC actors question')],
            ),
            'assign'    => ActorNotificationFieldConfig::buildRoleConfig(
                actors: ['users_id-' . getItemByTypeName(User::class, 'tech', true)],
            ),
        ]];
    }

    /** Runs the callback while the Formcreator actors table is renamed. */
    private function withoutFormcreatorTable(callable $callback): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->commit();
        $DB->doQuery('RENAME TABLE `glpi_plugin_formcreator_targets_actors` TO `glpi_plugin_formcreator_targets_actors_bak`');
        try {
            $callback();
        } finally {
            $DB->doQuery('RENAME TABLE `glpi_plugin_formcreator_targets_actors_bak` TO `glpi_plugin_formcreator_targets_actors`');
            $DB->beginTransaction();
        }
    }
}
