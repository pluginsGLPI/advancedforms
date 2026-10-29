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
use Glpi\Form\Destination\CommonITILField\AssigneeField;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldConfig;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Form;
use Glpi\Form\Migration\FormMigration;
use Glpi\Form\Question;
use Glpi\Plugin\Hooks;
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

        // A dry run does not mark the destination as imported
        $this->assertStringContainsString('Updated destinations: 1', $this->runCommand()->getDisplay());
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

    public function testRerunSkipsImportedDestinations(): void
    {
        $form = $this->migrateFormcreatorFormWithUncheckedActors();
        $this->runCommand();
        $expected = $this->changeSettingsByHand($form);

        $tester = $this->runCommand();

        $this->assertStringContainsString('Updated destinations: 0', $tester->getDisplay());
        $this->assertStringContainsString('Disabled sources: 0', $tester->getDisplay());
        $this->assertStringContainsString('Destinations already imported, skipped: 1', $tester->getDisplay());
        $this->assertSame(
            $expected,
            $this->getDestination($form)->getConfig()[ActorNotificationField::getKey()],
        );
    }

    public function testForcedRerunImportsAgainWithoutDuplicates(): void
    {
        $form = $this->migrateFormcreatorFormWithUncheckedActors();
        $this->runCommand();
        $expected = $this->changeSettingsByHand($form);

        $tester = $this->runCommand(force: true);

        $this->assertStringContainsString('Updated destinations: 1', $tester->getDisplay());
        $this->assertStringContainsString('Disabled sources: 1', $tester->getDisplay());
        $expected['roles']['requester']['disabled_strategies'] = ['form_filler'];
        $this->assertSame(
            $expected,
            $this->getDestination($form)->getConfig()[ActorNotificationField::getKey()],
        );
    }

    public function testDestinationWithoutSourceFoundIsNotRemembered(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $form = $this->migrateFormcreatorFormWithUncheckedActors();
        // Only the technician stays unchecked
        $DB->update(
            'glpi_plugin_formcreator_targets_actors',
            ['use_notification' => 1],
            ['NOT' => ['actor_role' => self::ROLE_ASSIGN]],
        );
        $config = $this->getDestination($form)->getConfig();
        $config_without_tech = $config;
        $config_without_tech[AssigneeField::getKey()][ITILActorFieldConfig::SPECIFIC_ITILACTORS_IDS] = [
            User::class => [getItemByTypeName(User::class, 'normal', true)],
        ];
        $this->saveDestinationConfig($form, $config_without_tech);

        $tester = $this->runCommand();

        $this->assertStringContainsString('Source not found in the destination actors', $tester->getDisplay());
        $this->assertStringContainsString('Updated destinations: 0', $tester->getDisplay());

        // Once the technician is added to the destination, the next run imports it
        $this->saveDestinationConfig($form, $config);
        $tester = $this->runCommand();

        $this->assertStringContainsString('Updated destinations: 1', $tester->getDisplay());
        $this->assertStringContainsString('Disabled sources: 1', $tester->getDisplay());
    }

    public function testFailedUpdateIsReportedAndNotRemembered(): void
    {
        global $PLUGIN_HOOKS;

        $form = $this->migrateFormcreatorFormWithUncheckedActors();
        $config_before = $this->getDestination($form)->fields['config'];

        // A plugin refuses the update of the destination
        $plugin_hooks = $PLUGIN_HOOKS;
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['advancedforms'][FormDestination::class] = static function (FormDestination $destination): void {
            $destination->input = false;
        };
        try {
            $tester = $this->runCommand();
        } finally {
            $PLUGIN_HOOKS = $plugin_hooks;
        }

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Updated destinations: 0', $tester->getDisplay());
        $this->assertSame($config_before, $this->getDestination($form)->fields['config']);

        $this->assertStringContainsString('Updated destinations: 1', $this->runCommand()->getDisplay());
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
        /** @var DBmysql $DB */
        global $DB;

        // The command uses the global connection: a stub hides the actors table.
        $db_stub = $this->createStub(DBmysql::class);
        $db_stub->connected = true;
        $db_stub->method('tableExists')->willReturnCallback(
            fn(string $table): bool => $table !== 'glpi_plugin_formcreator_targets_actors',
        );

        $real_db = $DB;
        $DB = $db_stub;
        try {
            $tester = $this->runCommand();
        } finally {
            $DB = $real_db;
        }

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString(
            'Formcreator tables not found: glpi_plugin_formcreator_targets_actors.',
            $tester->getDisplay(),
        );
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

    private function runCommand(bool $dry_run = false, bool $force = false): CommandTester
    {
        $tester = new CommandTester(new ImportFormcreatorActorsNotificationCommand());
        $tester->execute(array_filter(['--dry-run' => $dry_run, '--force' => $force]));

        return $tester;
    }

    /**
     * Enables again the imported form filler and disables another source.
     *
     * @return array<string, mixed> The new config
     */
    private function changeSettingsByHand(Form $form): array
    {
        $config_key = ActorNotificationField::getKey();
        $destination = $this->getDestination($form);
        $config = $destination->getConfig();
        $config[$config_key]['roles']['requester']['disabled_strategies'] = [];
        $config[$config_key]['roles']['assign']['disabled_actors'][] = 'users_id-' . getItemByTypeName(User::class, 'normal', true);
        $this->saveDestinationConfig($form, $config);

        return $this->getDestination($form)->getConfig()[$config_key];
    }

    /** @param array<string, mixed> $config Saved config, already prepared */
    private function saveDestinationConfig(Form $form, array $config): void
    {
        $this->updateItem(
            FormDestination::class,
            $this->getDestination($form)->getID(),
            ['config' => $config, '_from_import' => true],
            ['config', '_from_import'],
        );
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
}
