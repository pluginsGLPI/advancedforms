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

namespace GlpiPlugin\Advancedforms\Console;

use Config;
use Glpi\Console\AbstractCommand;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldConfig;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationChange;
use Glpi\Form\Destination\FormDestinationProblem;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Form;
use Glpi\Form\Question;
use GlpiPlugin\Advancedforms\Model\Destination\ActorNotificationField;
use GlpiPlugin\Advancedforms\Model\Destination\ActorNotificationFieldConfig;
use Override;
use Ramsey\Uuid\Uuid;
use Supplier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use User;

/**
 * Disables the "Email followup" sources of the Formcreator actors whose
 * followup was unchecked. Run after the migration, before dropping the
 * Formcreator tables. Imported destinations are skipped by the next runs.
 *
 * @phpstan-type NotFound array{target: string, element: string, reason: string}
 * @phpstan-type Change array{form: string, destination: string, role: string, source: string}
 * @phpstan-import-type Source from ActorNotificationField
 */
final class ImportFormcreatorActorsNotificationCommand extends AbstractCommand
{
    /** One config row per imported destination */
    public const IMPORTED_DESTINATIONS_CONFIG_CONTEXT = 'advancedforms:formcreator_actors_notification';

    private const ACTORS_TABLE = 'glpi_plugin_formcreator_targets_actors';

    private const FORMS_TABLE = 'glpi_plugin_formcreator_forms';

    private const QUESTIONS_TABLE = 'glpi_plugin_formcreator_questions';

    /** Formcreator target itemtype => [target table, destination type] */
    private const TARGETS = [
        'PluginFormcreatorTargetTicket'  => ['glpi_plugin_formcreator_targettickets', FormDestinationTicket::class],
        'PluginFormcreatorTargetChange'  => ['glpi_plugin_formcreator_targetchanges', FormDestinationChange::class],
        'PluginFormcreatorTargetProblem' => ['glpi_plugin_formcreator_targetproblems', FormDestinationProblem::class],
    ];

    /** PluginFormcreatorTarget_Actor::ACTOR_ROLE_* => actor role */
    private const ROLES = [
        1 => 'requester',
        2 => 'observer',
        3 => 'assign',
    ];

    // PluginFormcreatorTarget_Actor::ACTOR_TYPE_* having an email followup
    private const ACTOR_TYPE_CREATOR = 1;

    private const ACTOR_TYPE_PERSON = 3;

    private const ACTOR_TYPE_QUESTION_PERSON = 4;

    private const ACTOR_TYPE_SUPPLIER = 7;

    private const ACTOR_TYPE_QUESTION_SUPPLIER = 8;

    private const ACTOR_TYPE_QUESTION_ACTORS = 9;

    private const QUESTION_ACTOR_TYPES = [
        self::ACTOR_TYPE_QUESTION_PERSON,
        self::ACTOR_TYPE_QUESTION_SUPPLIER,
        self::ACTOR_TYPE_QUESTION_ACTORS,
    ];

    /** @var list<NotFound> */
    private array $not_found = [];

    private ActorNotificationField $field;

    /** @var array<int, FormDestination> Destinations with sources to disable, by id */
    private array $destinations = [];

    /** @var array<int, true> Destinations imported by a previous run */
    private array $imported_destinations = [];

    private bool $force = false;

    /** @var array<int, true> */
    private array $skipped_destinations = [];

    private bool $has_failed_destinations = false;

    /** @var array<int, array<string, list<Source>>> Sources by destination id and role */
    private array $sources = [];

    /** @var array<int, array<string, array<string, list<string|int>>>> Sources to disable by destination id, role and list */
    private array $sources_to_disable = [];

    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('plugins:advancedforms:import-formcreator-actors-notification')
            ->setDescription(__('Disable the email followup of the actors for which it was unchecked in Formcreator', 'advancedforms'))
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                __('Display the changes without saving them', 'advancedforms'),
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                __('Import again the destinations already imported, even the sources enabled again since', 'advancedforms'),
            );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $missing_tables = $this->getMissingFormcreatorTables();
        if ($missing_tables !== []) {
            $output->writeln('<error>' . sprintf(
                __('Formcreator tables not found: %s. Run this command after the Formcreator migration, before removing its tables.', 'advancedforms'),
                implode(', ', $missing_tables),
            ) . '</error>');
            return Command::FAILURE;
        }

        $dry_run = (bool) $input->getOption('dry-run');

        $this->field = new ActorNotificationField();
        $this->force = (bool) $input->getOption('force');
        $this->not_found = [];
        $this->destinations = [];
        $this->imported_destinations = $this->getImportedDestinations();
        $this->skipped_destinations = [];
        $this->has_failed_destinations = false;
        $this->sources = [];
        $this->sources_to_disable = [];
        foreach ($this->getUncheckedActors() as $actor) {
            $this->handleUncheckedActor($actor);
        }

        $changes = [];
        $updated_destinations = 0;
        foreach ($this->sources_to_disable as $destination_id => $sources) {
            $destination_changes = $this->disableSources($this->destinations[$destination_id], $sources, $dry_run);
            if ($destination_changes === null) {
                // Retried by the next run
                unset($this->destinations[$destination_id]);
                $this->has_failed_destinations = true;
                continue;
            }

            if ($destination_changes !== []) {
                $updated_destinations++;
                array_push($changes, ...$destination_changes);
            }
        }

        if (!$dry_run) {
            $this->saveImportedDestinations();
        }

        $this->outputSummary($output, $changes, $updated_destinations, $dry_run);

        return $this->has_failed_destinations ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<string> */
    private function getMissingFormcreatorTables(): array
    {
        $tables = [self::ACTORS_TABLE, self::FORMS_TABLE, self::QUESTIONS_TABLE];
        foreach (self::TARGETS as [$table]) {
            $tables[] = $table;
        }

        return array_values(array_filter(
            $tables,
            fn(string $table): bool => !$this->getDb()->tableExists($table, usecache: false),
        ));
    }

    /** @return array<int, true> */
    private function getImportedDestinations(): array
    {
        $destinations = [];
        foreach (Config::getConfigurationValues(self::IMPORTED_DESTINATIONS_CONFIG_CONTEXT) as $id) {
            $destinations[$this->toInt($id)] = true;
        }

        return $destinations;
    }

    /** Remembers the destinations of this run not imported yet. */
    private function saveImportedDestinations(): void
    {
        $values = [];
        foreach (array_keys(array_diff_key($this->destinations, $this->imported_destinations)) as $id) {
            $values['destination_' . $id] = (string) $id;
        }

        if ($values !== []) {
            Config::setConfigurationValues(self::IMPORTED_DESTINATIONS_CONFIG_CONTEXT, $values);
        }
    }

    /** @return iterable<array<string, mixed>> */
    private function getUncheckedActors(): iterable
    {
        /** @var iterable<array<string, mixed>> */
        return $this->getDb()->request([
            'SELECT' => ['itemtype', 'items_id', 'actor_role', 'actor_type', 'actor_value'],
            'FROM'   => self::ACTORS_TABLE,
            'WHERE'  => [
                'use_notification' => 0,
                'itemtype'         => array_keys(self::TARGETS),
            ],
            'ORDER'  => ['id'],
        ]);
    }

    /** @param array<string, mixed> $actor */
    private function handleUncheckedActor(array $actor): void
    {
        $role = self::ROLES[$this->toInt($actor['actor_role'])] ?? null;
        $actor_type = $this->toInt($actor['actor_type']);
        $actor_value = $this->toInt($actor['actor_value']);
        if ($role === null || !$this->hasEmailFollowup($actor_type)) {
            return;
        }

        $target_itemtype = is_string($actor['itemtype']) ? $actor['itemtype'] : '';
        $target_id = $this->toInt($actor['items_id']);
        $target_label = sprintf('%s #%d', $target_itemtype, $target_id);
        [$target_table, $destination_class] = self::TARGETS[$target_itemtype];

        $target = $this->getDb()->request([
            'SELECT' => ['name', 'plugin_formcreator_forms_id'],
            'FROM'   => $target_table,
            'WHERE'  => ['id' => $target_id],
        ])->current();
        if (!is_array($target)) {
            $this->addNotFound($target_label, $target_label, __('Formcreator target not found', 'advancedforms'));
            return;
        }

        $target_name = is_string($target['name']) ? $target['name'] : '';
        $target_label = sprintf('%s "%s" (#%d)', $target_itemtype, $target_name, $target_id);

        $fc_form_id = $this->toInt($target['plugin_formcreator_forms_id']);
        $form = $this->findMigratedForm($fc_form_id);
        if (!$form instanceof Form) {
            $this->addNotFound($target_label, sprintf('PluginFormcreatorForm #%d', $fc_form_id), __('Form not found', 'advancedforms'));
            return;
        }

        $destinations = (new FormDestination())->find([
            Form::getForeignKeyField() => $form->getID(),
            'itemtype'                 => $destination_class,
            'name'                     => $target_name,
        ]);
        if (count($destinations) !== 1) {
            $this->addNotFound(
                $target_label,
                $target_name,
                $destinations === []
                    ? __('Destination not found', 'advancedforms')
                    : __('Several destinations match, target ignored', 'advancedforms'),
            );
            return;
        }

        // find() returns the rows by id
        $destination = FormDestination::getById($this->toInt(array_key_first($destinations)));
        if (!$destination instanceof FormDestination) {
            return;
        }

        if (!$this->force && isset($this->imported_destinations[$destination->getID()])) {
            $this->skipped_destinations[$destination->getID()] = true;
            return;
        }

        [$list, $value, $element] = $this->getSource($form, $actor_type, $actor_value);
        if ($value === null) {
            $this->addNotFound(
                $target_label,
                $element,
                in_array($actor_type, self::QUESTION_ACTOR_TYPES, true)
                    ? __('Question not found', 'advancedforms')
                    : __('Actor not found', 'advancedforms'),
            );
            return;
        }

        if (!$this->isSourceConfigured($destination, $role, $list, $value)) {
            $this->addNotFound($target_label, $element, __('Source not found in the destination actors', 'advancedforms'));
            return;
        }

        $this->destinations[$destination->getID()] = $destination;

        $values = $this->sources_to_disable[$destination->getID()][$role][$list] ?? [];
        if (!in_array($value, $values, true)) {
            $this->sources_to_disable[$destination->getID()][$role][$list][] = $value;
        }
    }

    private function hasEmailFollowup(int $actor_type): bool
    {
        return in_array($actor_type, [
            self::ACTOR_TYPE_CREATOR,
            self::ACTOR_TYPE_PERSON,
            self::ACTOR_TYPE_SUPPLIER,
            ...self::QUESTION_ACTOR_TYPES,
        ], true);
    }

    /** By the Formcreator uuid, or the one generated from the id by the migration. */
    private function findMigratedForm(int $fc_form_id): ?Form
    {
        $fc_form = $this->getDb()->request([
            'SELECT' => ['uuid'],
            'FROM'   => self::FORMS_TABLE,
            'WHERE'  => ['id' => $fc_form_id],
        ])->current();
        if (!is_array($fc_form)) {
            return null;
        }

        $uuids = [(string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'formcreator-form-' . $fc_form_id)];
        if (is_string($fc_form['uuid']) && $fc_form['uuid'] !== '') {
            $uuids[] = $fc_form['uuid'];
        }

        foreach ($uuids as $uuid) {
            $forms = (new Form())->find(['uuid' => $uuid]);
            if (count($forms) === 1) {
                $form = Form::getById($this->toInt(array_key_first($forms)));
                return $form instanceof Form ? $form : null;
            }
        }

        return null;
    }

    /**
     * Source of a Formcreator actor, value null if not found.
     *
     * @return array{ActorNotificationFieldConfig::DISABLED_*, string|int|null, string}
     */
    private function getSource(Form $form, int $actor_type, int $actor_value): array
    {
        if ($actor_type === self::ACTOR_TYPE_CREATOR) {
            $strategy = ITILActorFieldStrategy::FORM_FILLER->value;
            return [ActorNotificationFieldConfig::DISABLED_STRATEGIES, $strategy, $strategy];
        }

        if (in_array($actor_type, self::QUESTION_ACTOR_TYPES, true)) {
            $element = sprintf('PluginFormcreatorQuestion #%d', $actor_value);
            $question = $this->findMigratedQuestion($form, $actor_value);
            return [ActorNotificationFieldConfig::DISABLED_QUESTION_IDS, $question?->getID(), $element];
        }

        $itemtype = $actor_type === self::ACTOR_TYPE_SUPPLIER ? Supplier::class : User::class;
        $element = sprintf('%s #%d', $itemtype, $actor_value);
        $item = new $itemtype();
        $value = $item->getFromDB($actor_value) ? ActorNotificationField::getActorKey($itemtype, $actor_value) : null;

        return [ActorNotificationFieldConfig::DISABLED_ACTORS, $value, $element];
    }

    private function findMigratedQuestion(Form $form, int $fc_question_id): ?Question
    {
        $fc_question = $this->getDb()->request([
            'SELECT' => ['uuid'],
            'FROM'   => self::QUESTIONS_TABLE,
            'WHERE'  => ['id' => $fc_question_id],
        ])->current();
        if (!is_array($fc_question) || !is_string($fc_question['uuid']) || $fc_question['uuid'] === '') {
            return null;
        }

        foreach (array_keys((new Question())->find(['uuid' => $fc_question['uuid']])) as $question_id) {
            $question = Question::getById($this->toInt($question_id));
            if ($question instanceof Question && $question->getForm()->getID() === $form->getID()) {
                return $question;
            }
        }

        return null;
    }

    private function isSourceConfigured(
        FormDestination $destination,
        string $role,
        string $list,
        string|int $value,
    ): bool {
        foreach ($this->getDestinationSources($destination, $role) as $source) {
            if ($source['list'] === $list && $source['value'] === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sources of a role of the destination, cached.
     *
     * @return list<Source>
     */
    private function getDestinationSources(FormDestination $destination, string $role): array
    {
        $destination_id = $destination->getID();
        if (!isset($this->sources[$destination_id])) {
            $field_config = $this->field->getConfig($destination->getForm(), $destination->getConfig());
            $this->sources[$destination_id] = [];
            foreach ($this->field->getActorFields() as $actor_role => $actor_field) {
                $actor_config = $field_config->getActorsConfig($actor_role);
                $this->sources[$destination_id][$actor_role] = $actor_config instanceof ITILActorFieldConfig
                    ? $this->field->getSources($actor_field, $actor_config)
                    : [];
            }
        }

        return $this->sources[$destination_id][$role] ?? [];
    }

    /**
     * Adds the sources to the disabled ones.
     *
     * @param array<string, array<string, list<string|int>>> $sources
     * @return list<Change>|null null if not saved
     */
    private function disableSources(FormDestination $destination, array $sources, bool $dry_run): ?array
    {
        $form = $destination->getForm();
        $config = $destination->getConfig();
        $field_config = $this->field->getConfig($form, $config);

        $changes = [];
        $roles = [];
        foreach ($this->field->getActorFields() as $role => $actor_field) {
            $role_config = $field_config->getRoleConfig($role);

            foreach ($this->getDestinationSources($destination, $role) as $source) {
                $list = $source['list'];
                if (
                    !in_array($source['value'], $sources[$role][$list] ?? [], true)
                    || in_array($source['value'], $role_config[$list], true)
                ) {
                    continue;
                }

                if ($list === ActorNotificationFieldConfig::DISABLED_QUESTION_IDS) {
                    $role_config[$list][] = (int) $source['value'];
                } else {
                    $role_config[$list][] = (string) $source['value'];
                }

                $changes[] = [
                    'form'        => $form->getName(),
                    'destination' => $destination->getName(),
                    'role'        => $actor_field->getLabel(),
                    'source'      => $this->field->getSourceLabel($actor_field, $source),
                ];
            }

            $roles[$role] = $role_config;
        }

        if ($changes === [] || $dry_run) {
            return $changes;
        }

        $config[ActorNotificationField::getKey()] = (new ActorNotificationFieldConfig($roles))->jsonSerialize();

        // Config already prepared
        $updated = $destination->update([
            'id'           => $destination->getID(),
            'config'       => $config,
            '_from_import' => true,
        ]);

        return $updated ? $changes : null;
    }

    /** @param list<Change> $changes */
    private function outputSummary(OutputInterface $output, array $changes, int $updated_destinations, bool $dry_run): void
    {
        if ($changes !== []) {
            $output->writeln($dry_run
                ? '<info>' . __('Changes to apply (dry run, nothing is saved):', 'advancedforms') . '</info>'
                : '<info>' . __('Applied changes:', 'advancedforms') . '</info>');
            $table = new Table($output);
            $table->setHeaders([
                __('Form', 'advancedforms'),
                __('Destination', 'advancedforms'),
                __('Role', 'advancedforms'),
                __('Disabled source', 'advancedforms'),
            ]);
            foreach ($changes as $change) {
                $table->addRow(array_values($change));
            }

            $table->render();
        }

        $output->writeln(sprintf(
            $dry_run
                ? __('Destinations to update: %d', 'advancedforms')
                : __('Updated destinations: %d', 'advancedforms'),
            $updated_destinations,
        ));
        $output->writeln(sprintf(__('Disabled sources: %d', 'advancedforms'), count($changes)));

        if ($this->skipped_destinations !== []) {
            $output->writeln('<comment>' . sprintf(
                __('Destinations already imported, skipped: %d. Use --force to import them again.', 'advancedforms'),
                count($this->skipped_destinations),
            ) . '</comment>');
        }

        if ($this->not_found !== []) {
            $output->writeln('<comment>' . sprintf(__('Elements not found: %d', 'advancedforms'), count($this->not_found)) . '</comment>');
            $table = new Table($output);
            $table->setHeaders([
                __('Formcreator target', 'advancedforms'),
                __('Element', 'advancedforms'),
                __('Reason', 'advancedforms'),
            ]);
            foreach ($this->not_found as $not_found) {
                $table->addRow(array_values($not_found));
            }

            $table->render();
        }
    }

    private function addNotFound(string $target, string $element, string $reason): void
    {
        $this->not_found[] = ['target' => $target, 'element' => $element, 'reason' => $reason];
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
