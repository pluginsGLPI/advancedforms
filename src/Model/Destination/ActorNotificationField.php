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

namespace GlpiPlugin\Advancedforms\Model\Destination;

use CommonDBTM;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\AbstractCommonITILFormDestination;
use Glpi\Form\Destination\AbstractConfigField;
use Glpi\Form\Destination\CommonITILField\AssigneeField;
use Glpi\Form\Destination\CommonITILField\Category;
use Glpi\Form\Destination\CommonITILField\ITILActorField;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldConfig;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Destination\CommonITILField\ObserverField;
use Glpi\Form\Destination\CommonITILField\RequesterField;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Export\Context\DatabaseMapper;
use Glpi\Form\Export\Serializer\DynamicExportDataField;
use Glpi\Form\Export\Specification\DataRequirementSpecification;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionTypeActors;
use Glpi\Form\QuestionType\QuestionTypeActorsExtraDataConfig;
use Glpi\Form\QuestionType\QuestionTypeEmail;
use Glpi\Form\QuestionType\QuestionTypeItem;
use InvalidArgumentException;
use Override;
use Supplier;
use User;

/**
 * Turns off the email followup of the actors found only by disabled sources.
 *
 * @phpstan-type Source array{
 *      list: ActorNotificationFieldConfig::DISABLED_*,
 *      value: string|int,
 *      strategy: ITILActorFieldStrategy,
 *      question?: Question
 * }
 */
final class ActorNotificationField extends AbstractConfigField
{
    /** Strategies with a single toggle for all the actors they find. */
    private const STRATEGY_LEVEL_SOURCES = [
        ITILActorFieldStrategy::FORM_FILLER,
        ITILActorFieldStrategy::FORM_FILLER_SUPERVISOR,
        ITILActorFieldStrategy::LAST_VALID_ANSWER,
        ITILActorFieldStrategy::USER_FROM_OBJECT_ANSWER,
        ITILActorFieldStrategy::TECH_USER_FROM_OBJECT_ANSWER,
    ];

    /** Groups have no email followup on an ITIL object. */
    private const NOTIFIABLE_ITEMTYPES = [User::class, Supplier::class];

    #[Override]
    public function getLabel(): string
    {
        return __('Email followup', 'advancedforms');
    }

    #[Override]
    public function getConfigClass(): string
    {
        return ActorNotificationFieldConfig::class;
    }

    #[Override]
    public function getWeight(): int
    {
        // After requesters (100), observers (110) and assignees (120).
        return 130;
    }

    #[Override]
    public function getCategory(): Category
    {
        return Category::ACTORS;
    }

    #[Override]
    public function getDefaultConfig(Form $form): ActorNotificationFieldConfig
    {
        return new ActorNotificationFieldConfig();
    }

    /** @param array<mixed> $config */
    #[Override]
    public function getConfig(Form $form, array $config, ?AnswersSet $answers_set = null): ActorNotificationFieldConfig
    {
        $field_config = parent::getConfig($form, $config, $answers_set);
        if (!$field_config instanceof ActorNotificationFieldConfig) {
            $field_config = $this->getDefaultConfig($form);
        }

        $actors_configs = [];
        foreach ($this->getActorFields() as $role => $actor_field) {
            $actor_config = $actor_field->getConfig($form, $config, $answers_set);
            if ($actor_config instanceof ITILActorFieldConfig) {
                $actors_configs[$role] = $actor_config;
            }
        }

        return $field_config->withActorsConfigs($actors_configs);
    }

    /** @param array<string, mixed> $display_options */
    #[Override]
    public function renderConfigForm(
        Form $form,
        FormDestination $destination,
        JsonFieldInterface $config,
        string $input_name,
        array $display_options,
    ): string {
        if (!$config instanceof ActorNotificationFieldConfig) {
            throw new InvalidArgumentException("Unexpected config class");
        }

        $roles = [];
        foreach ($this->getActorFields() as $role => $actor_field) {
            $actor_config = $config->getActorsConfig($role);
            if (!$actor_config instanceof ITILActorFieldConfig) {
                continue;
            }

            $rows = [];
            foreach ($this->getSources($actor_field, $actor_config) as $source) {
                $rows[] = [
                    'label'          => $this->getSourceLabel($actor_field, $source),
                    'strategy_label' => $source['list'] === ActorNotificationFieldConfig::DISABLED_STRATEGIES
                        ? null
                        : $source['strategy']->getLabel($actor_field->getLabel()),
                    'input_name'     => sprintf(
                        '%s[%s][%s][%s][%s]',
                        $input_name,
                        ActorNotificationFieldConfig::ROLES,
                        $role,
                        $source['list'],
                        $source['value'],
                    ),
                    'enabled'        => !$this->isSourceDisabled($config, $role, $source),
                ];
            }

            if ($rows !== []) {
                $roles[] = [
                    'label' => $actor_field->getLabel(),
                    'rows'  => $rows,
                ];
            }
        }

        $twig = TemplateRenderer::getInstance();
        return $twig->render('@advancedforms/destination/actor_notification_config_field.html.twig', [
            'roles'   => $roles,
            'options' => $display_options,
        ]);
    }

    /**
     * @param array<mixed> $input
     * @return array<mixed>
     */
    #[Override]
    public function applyConfiguratedValueToInputUsingAnswers(
        JsonFieldInterface $config,
        array $input,
        AnswersSet $answers_set,
    ): array {
        if (!$config instanceof ActorNotificationFieldConfig) {
            throw new InvalidArgumentException("Unexpected config class");
        }

        foreach ($this->getActorFields() as $role => $actor_field) {
            $actor_config = $config->getActorsConfig($role);
            if (!$actor_config instanceof ITILActorFieldConfig || !$config->hasDisabledSources($role)) {
                continue;
            }

            [$disabled_persons, $enabled_persons] = $this->getPersons($config, $role, $actor_field, $actor_config, $answers_set);

            // An enabled source wins
            $persons_to_mute = array_diff_key($disabled_persons, $enabled_persons);
            if ($persons_to_mute === []) {
                continue;
            }

            foreach (self::NOTIFIABLE_ITEMTYPES as $itemtype) {
                $input = $this->muteActorsInInput($input, $itemtype, $role, $persons_to_mute);
            }
        }

        return $input;
    }

    /**
     * @param array<mixed> $input
     * @return array<mixed>
     */
    #[Override]
    public function prepareInput(array $input): array
    {
        $input = parent::prepareInput($input);

        $key = self::getKey();
        if (!array_key_exists($key, $input)) {
            return $input;
        }

        $raw_config = is_array($input[$key]) ? $input[$key] : [];
        $raw_roles = is_array($raw_config[ActorNotificationFieldConfig::ROLES] ?? null)
            ? $raw_config[ActorNotificationFieldConfig::ROLES]
            : [];

        $roles = [];
        foreach ($this->getActorFields() as $role => $actor_field) {
            // Actors fields are already prepared (lower weight)
            $actor_config = $this->getActorFieldConfigFromInput($actor_field, $input);
            $raw_role = is_array($raw_roles[$role] ?? null) ? $raw_roles[$role] : [];

            $allowed = [];
            foreach ($this->getSources($actor_field, $actor_config) as $source) {
                $allowed[$source['list']][$source['value']] = true;
            }

            $disabled = [];
            foreach (array_keys(ActorNotificationFieldConfig::buildRoleConfig()) as $list) {
                $disabled[$list] = array_values(array_filter(
                    $this->normalizeDisabledValues($raw_role[$list] ?? []),
                    fn(string $value): bool => isset($allowed[$list][$value]),
                ));
            }

            $roles[$role] = ActorNotificationFieldConfig::buildRoleConfig(
                strategies: $disabled[ActorNotificationFieldConfig::DISABLED_STRATEGIES],
                actors: $disabled[ActorNotificationFieldConfig::DISABLED_ACTORS],
                question_ids: array_map(
                    intval(...),
                    $disabled[ActorNotificationFieldConfig::DISABLED_QUESTION_IDS],
                ),
            );
        }

        $input[$key] = [ActorNotificationFieldConfig::ROLES => $roles];

        return $input;
    }

    /** @param array<mixed> $config */
    #[Override]
    public function exportDynamicConfig(
        array $config,
        AbstractCommonITILFormDestination $destination,
    ): DynamicExportDataField {
        $roles = $config[ActorNotificationFieldConfig::ROLES] ?? null;
        if (!is_array($roles)) {
            return parent::exportDynamicConfig($config, $destination);
        }

        // Question ids are mapped by the form serializer
        $requirements = [];
        foreach ($roles as $role => $role_config) {
            if (!is_array($role_config) || !is_array($role_config[ActorNotificationFieldConfig::DISABLED_ACTORS] ?? null)) {
                continue;
            }

            $exported_actors = [];
            foreach ($role_config[ActorNotificationFieldConfig::DISABLED_ACTORS] as $actor_key) {
                $item = is_string($actor_key) ? $this->getItemFromActorKey($actor_key) : null;
                if (!$item instanceof CommonDBTM) {
                    // Deleted actors are removed from the export
                    continue;
                }

                $requirement = DataRequirementSpecification::fromItem($item);
                $requirements[] = $requirement;
                $exported_actors[] = $item::getForeignKeyField() . '-' . $requirement->name;
            }

            $role_config[ActorNotificationFieldConfig::DISABLED_ACTORS] = $exported_actors;
            $roles[$role] = $role_config;
        }

        $config[ActorNotificationFieldConfig::ROLES] = $roles;
        return new DynamicExportDataField($config, $requirements);
    }

    /**
     * @param array<mixed> $config
     * @return array<mixed>
     */
    #[Override]
    public static function prepareDynamicConfigDataForImport(
        array $config,
        AbstractCommonITILFormDestination $destination,
        DatabaseMapper $mapper,
    ): array {
        $roles = $config[ActorNotificationFieldConfig::ROLES] ?? null;
        if (!is_array($roles)) {
            return $config;
        }

        foreach ($roles as $role => $role_config) {
            if (!is_array($role_config)) {
                continue;
            }

            $actors = [];
            $exported_actors = $role_config[ActorNotificationFieldConfig::DISABLED_ACTORS] ?? [];
            foreach (is_array($exported_actors) ? $exported_actors : [] as $actor) {
                if (!is_string($actor)) {
                    continue;
                }

                $parsed_actor = self::parseActorKey($actor);
                if ($parsed_actor === null) {
                    continue;
                }

                [$itemtype, $name] = $parsed_actor;
                $actors[] = self::getActorKey($itemtype, $mapper->getItemId($itemtype, $name));
            }

            $questions_ids = [];
            $exported_questions_ids = $role_config[ActorNotificationFieldConfig::DISABLED_QUESTION_IDS] ?? [];
            foreach (is_array($exported_questions_ids) ? $exported_questions_ids : [] as $question_id) {
                if (is_int($question_id) || is_string($question_id)) {
                    $questions_ids[] = $mapper->getItemId(Question::class, $question_id);
                }
            }

            $role_config[ActorNotificationFieldConfig::DISABLED_ACTORS] = $actors;
            $role_config[ActorNotificationFieldConfig::DISABLED_QUESTION_IDS] = $questions_ids;
            $roles[$role] = $role_config;
        }

        $config[ActorNotificationFieldConfig::ROLES] = $roles;
        return $config;
    }

    /**
     * Actors fields of the destination, by actor role.
     *
     * @return array<string, ITILActorField>
     */
    public function getActorFields(): array
    {
        $fields = [];
        foreach ([new RequesterField(), new ObserverField(), new AssigneeField()] as $field) {
            $fields[$field->getActorType()] = $field;
        }

        return $fields;
    }

    /**
     * Toggleable sources: strategies, specific users or suppliers, questions.
     *
     * @return list<Source>
     */
    public function getSources(ITILActorField $actor_field, ITILActorFieldConfig $actor_config): array
    {
        $sources = [];
        foreach ($this->getUniqueStrategies($actor_config) as $strategy) {
            if (in_array($strategy, self::STRATEGY_LEVEL_SOURCES, true)) {
                $sources[] = [
                    'list'     => ActorNotificationFieldConfig::DISABLED_STRATEGIES,
                    'value'    => $strategy->value,
                    'strategy' => $strategy,
                ];
            } elseif ($strategy === ITILActorFieldStrategy::SPECIFIC_VALUES) {
                $notifiable_itemtypes = $this->getNotifiableItemtypes($actor_field);
                foreach ($actor_config->getSpecificITILActorsIds() ?? [] as $itemtype => $ids) {
                    if (!in_array($itemtype, $notifiable_itemtypes, true) || !is_array($ids)) {
                        continue;
                    }

                    foreach ($ids as $id) {
                        if (!is_numeric($id)) {
                            continue;
                        }

                        $sources[] = [
                            'list'     => ActorNotificationFieldConfig::DISABLED_ACTORS,
                            'value'    => self::getActorKey($itemtype, (int) $id),
                            'strategy' => $strategy,
                        ];
                    }
                }
            } elseif ($strategy === ITILActorFieldStrategy::SPECIFIC_ANSWERS) {
                foreach ($actor_config->getSpecificQuestionIds() ?? [] as $question_id) {
                    $question = is_numeric($question_id) ? Question::getById((int) $question_id) : null;
                    if (!$question instanceof Question || !$this->isNotifiableQuestion($actor_field, $question)) {
                        continue;
                    }

                    // Kept for the label
                    $sources[] = [
                        'list'     => ActorNotificationFieldConfig::DISABLED_QUESTION_IDS,
                        'value'    => $question->getID(),
                        'strategy' => $strategy,
                        'question' => $question,
                    ];
                }
            }
        }

        return $sources;
    }

    /** @param class-string<CommonDBTM> $itemtype */
    public static function getActorKey(string $itemtype, int $id): string
    {
        return getForeignKeyFieldForItemType($itemtype) . '-' . $id;
    }

    /**
     * Users and suppliers accepted by the field.
     *
     * @return list<class-string<User|Supplier>>
     */
    private function getNotifiableItemtypes(ITILActorField $actor_field): array
    {
        return array_values(array_filter(
            self::NOTIFIABLE_ITEMTYPES,
            fn(string $itemtype): bool => in_array($itemtype, $actor_field->getAllowedActorTypes(), true),
        ));
    }

    /** @return list<ITILActorFieldStrategy> */
    private function getUniqueStrategies(ITILActorFieldConfig $actor_config): array
    {
        $strategies = [];
        foreach ($actor_config->getStrategies() as $strategy) {
            if ($strategy instanceof ITILActorFieldStrategy && !in_array($strategy, $strategies, true)) {
                $strategies[] = $strategy;
            }
        }

        return $strategies;
    }

    /** @param Source $source */
    private function isSourceDisabled(ActorNotificationFieldConfig $config, string $role, array $source): bool
    {
        return match ($source['list']) {
            ActorNotificationFieldConfig::DISABLED_STRATEGIES   => $config->isStrategyDisabled($role, $source['strategy']),
            ActorNotificationFieldConfig::DISABLED_ACTORS       => $config->isActorDisabled($role, (string) $source['value']),
            ActorNotificationFieldConfig::DISABLED_QUESTION_IDS => $config->isQuestionDisabled($role, (int) $source['value']),
        };
    }

    /** @param Source $source */
    public function getSourceLabel(ITILActorField $actor_field, array $source): string
    {
        switch ($source['list']) {
            case ActorNotificationFieldConfig::DISABLED_ACTORS:
                $item = $this->getItemFromActorKey((string) $source['value']);
                return $item instanceof CommonDBTM ? $item->getFriendlyName() : (string) $source['value'];

            case ActorNotificationFieldConfig::DISABLED_QUESTION_IDS:
                return isset($source['question']) ? $source['question']->getName() : (string) $source['value'];

            default:
                return $source['strategy']->getLabel($actor_field->getLabel());
        }
    }

    /**
     * Splits `<foreign key>-<id>` (`<foreign key>-<name>` in an export).
     *
     * @return array{class-string<User|Supplier>, string}|null
     */
    private static function parseActorKey(string $actor_key): ?array
    {
        [$fkey, $value] = array_pad(explode('-', $actor_key, 2), 2, '');
        $itemtype = getItemtypeForForeignKeyField($fkey);
        if (!in_array($itemtype, self::NOTIFIABLE_ITEMTYPES, true)) {
            return null;
        }

        return [$itemtype, $value];
    }

    private function getItemFromActorKey(string $actor_key): ?CommonDBTM
    {
        $parsed_actor = self::parseActorKey($actor_key);
        if ($parsed_actor === null || !is_numeric($parsed_actor[1])) {
            return null;
        }

        [$itemtype, $id] = $parsed_actor;
        $item = getItemForItemtype($itemtype);
        if (!$item instanceof CommonDBTM || !$item->getFromDB((int) $id)) {
            return null;
        }

        return $item;
    }

    /** Question whose answer can give users or suppliers. */
    private function isNotifiableQuestion(ITILActorField $actor_field, Question $question): bool
    {
        $type = $question->getQuestionType();
        $notifiable_itemtypes = $this->getNotifiableItemtypes($actor_field);

        if ($type instanceof QuestionTypeEmail) {
            return true;
        }

        if ($type instanceof QuestionTypeItem) {
            return in_array($type->getDefaultValueItemtype($question), $notifiable_itemtypes, true);
        }

        if ($type instanceof AbstractQuestionTypeActors) {
            $extra_data = $question->getExtraDataConfig();
            foreach ($notifiable_itemtypes as $itemtype) {
                if (!in_array($itemtype, $type->getAllowedActorTypes(), true)) {
                    continue;
                }

                if (!$extra_data instanceof QuestionTypeActorsExtraDataConfig || $extra_data->isTypeEnabled($itemtype)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Persons found by the disabled and the enabled sources, each resolved once.
     *
     * @return array{array<string, true>, array<string, true>} Disabled and enabled persons
     */
    private function getPersons(
        ActorNotificationFieldConfig $config,
        string $role,
        ITILActorField $actor_field,
        ITILActorFieldConfig $actor_config,
        AnswersSet $answers_set,
    ): array {
        $disabled_persons = [];
        $enabled_persons = [];

        foreach ($this->getUniqueStrategies($actor_config) as $strategy) {
            if ($strategy === ITILActorFieldStrategy::SPECIFIC_VALUES) {
                $notifiable_itemtypes = $this->getNotifiableItemtypes($actor_field);
                foreach ($actor_config->getSpecificITILActorsIds() ?? [] as $itemtype => $ids) {
                    if (!in_array($itemtype, $notifiable_itemtypes, true)) {
                        continue;
                    }

                    foreach (is_array($ids) ? $ids : [] as $id) {
                        $person = $this->getPersonKey(['itemtype' => $itemtype, 'items_id' => $id]);
                        if ($person === null) {
                            continue;
                        }

                        if ($config->isActorDisabled($role, $person)) {
                            $disabled_persons[$person] = true;
                        } else {
                            $enabled_persons[$person] = true;
                        }
                    }
                }

                continue;
            }

            if ($strategy === ITILActorFieldStrategy::SPECIFIC_ANSWERS) {
                foreach ($actor_config->getSpecificQuestionIds() ?? [] as $question_id) {
                    if (!is_numeric($question_id)) {
                        continue;
                    }

                    $question_disabled = $config->isQuestionDisabled($role, (int) $question_id);
                    foreach ($this->getActorsForQuestion($actor_field, (int) $question_id, $answers_set) as $actor) {
                        $person = $this->getPersonKey($actor);
                        if ($person === null) {
                            continue;
                        }

                        if ($question_disabled) {
                            $disabled_persons[$person] = true;
                        } else {
                            $enabled_persons[$person] = true;
                        }
                    }
                }

                continue;
            }

            // Template and groups strategies give no user or supplier
            if (!in_array($strategy, self::STRATEGY_LEVEL_SOURCES, true)) {
                continue;
            }

            $strategy_disabled = $config->isStrategyDisabled($role, $strategy);
            foreach ($strategy->getITILActors($actor_field, $actor_config, $answers_set) ?? [] as $actor) {
                $person = $this->getPersonKey($actor);
                if ($person === null) {
                    continue;
                }

                // The delegated user keeps the delegation block choice
                if ($strategy_disabled && !$this->isDelegatedFormFiller($strategy, $actor, $answers_set)) {
                    $disabled_persons[$person] = true;
                } else {
                    $enabled_persons[$person] = true;
                }
            }
        }

        return [$disabled_persons, $enabled_persons];
    }

    /** Form filler sent on behalf of another user. */
    private function isDelegatedFormFiller(ITILActorFieldStrategy $strategy, mixed $actor, AnswersSet $answers_set): bool
    {
        $delegated_users_id = $answers_set->getDelegation()->users_id;
        if (
            $strategy !== ITILActorFieldStrategy::FORM_FILLER
            || $delegated_users_id === null
            || !is_array($actor)
        ) {
            return false;
        }

        // Selecting oneself is not a delegation
        $author_id = is_numeric($answers_set->fields['users_id'] ?? null) ? (int) $answers_set->fields['users_id'] : 0;
        $items_id = is_numeric($actor['items_id'] ?? null) ? (int) $actor['items_id'] : 0;

        return $items_id === $delegated_users_id && $delegated_users_id !== $author_id;
    }

    /** @return array<mixed> */
    private function getActorsForQuestion(ITILActorField $actor_field, int $question_id, AnswersSet $answers_set): array
    {
        $config_class = $actor_field->getConfigClass();
        if (!is_a($config_class, ITILActorFieldConfig::class, true)) {
            return [];
        }

        $question_config = $config_class::jsonDeserialize([
            ITILActorFieldConfig::STRATEGIES            => [ITILActorFieldStrategy::SPECIFIC_ANSWERS->value],
            ITILActorFieldConfig::SPECIFIC_QUESTION_IDS => [$question_id],
        ]);
        if (!$question_config instanceof ITILActorFieldConfig) {
            return [];
        }

        return ITILActorFieldStrategy::SPECIFIC_ANSWERS->getITILActors(
            $actor_field,
            $question_config,
            $answers_set,
        ) ?? [];
    }

    /**
     * Actor key, or `email-<address>` without account. Null for groups.
     */
    private function getPersonKey(mixed $actor): ?string
    {
        if (!is_array($actor)) {
            return null;
        }

        $itemtype = $actor['itemtype'] ?? null;
        if (!in_array($itemtype, self::NOTIFIABLE_ITEMTYPES, true)) {
            return null;
        }

        $items_id = is_numeric($actor['items_id'] ?? null) ? (int) $actor['items_id'] : 0;
        if ($items_id > 0) {
            return self::getActorKey($itemtype, $items_id);
        }

        $email = $actor['alternative_email'] ?? '';
        if ($itemtype === User::class && is_string($email) && $email !== '') {
            return 'email-' . $email;
        }

        return null;
    }

    /**
     * Turns off the followup of the given persons. Template lines come first,
     * without notification entry, and keep the followup.
     *
     * @param array<mixed>        $input
     * @param class-string<User|Supplier> $itemtype
     * @param array<string, true> $persons_to_mute
     * @return array<mixed>
     */
    private function muteActorsInInput(array $input, string $itemtype, string $role, array $persons_to_mute): array
    {
        $actors_key = sprintf('_%s_%s', getForeignKeyFieldForItemType($itemtype), $role);
        $notif_key = $actors_key . '_notif';

        $actors = $input[$actors_key] ?? null;
        $notif = $input[$notif_key] ?? [];
        if (!is_array($actors) || !is_array($notif)) {
            return $input;
        }

        $actors = array_values($actors);
        $use_notification = is_array($notif['use_notification'] ?? null) ? array_values($notif['use_notification']) : [];
        $alternative_email = is_array($notif['alternative_email'] ?? null) ? array_values($notif['alternative_email']) : [];

        $count_actors = count($actors);
        $count_notif = count($use_notification);
        $template_lines = $count_actors >= $count_notif ? $count_actors - $count_notif : $count_actors;

        for ($line = 0; $line < $template_lines; $line++) {
            $person = $this->getPersonKey(['itemtype' => $itemtype, 'items_id' => $actors[$line]]);
            if ($person !== null) {
                unset($persons_to_mute[$person]);
            }
        }

        $new_use_notification = [];
        $new_alternative_email = [];
        $has_changes = false;
        for ($line = $template_lines; $line < $count_actors; $line++) {
            $notif_index = $line - $template_lines;
            $use = $use_notification[$notif_index] ?? '1';
            $email = $alternative_email[$notif_index] ?? '';

            $person = $this->getPersonKey([
                'itemtype'          => $itemtype,
                'items_id'          => $actors[$line],
                'alternative_email' => $email,
            ]);
            if ($person !== null && isset($persons_to_mute[$person])) {
                $use = 0;
                $has_changes = true;
            }

            $new_use_notification[$line] = $use;
            $new_alternative_email[$line] = $email;
        }

        if (!$has_changes) {
            return $input;
        }

        $input[$actors_key] = $actors;
        $input[$notif_key] = [
            'use_notification'  => $new_use_notification,
            'alternative_email' => $new_alternative_email,
        ];

        return $input;
    }

    /** @param array<mixed> $input */
    private function getActorFieldConfigFromInput(ITILActorField $actor_field, array $input): ITILActorFieldConfig
    {
        $raw_config = $input[$actor_field::getKey()] ?? [];
        $config_class = $actor_field->getConfigClass();
        $config = $config_class::jsonDeserialize(is_array($raw_config) ? $raw_config : []);
        if (!$config instanceof ITILActorFieldConfig) {
            throw new InvalidArgumentException("Unexpected actor field config class");
        }

        return $config;
    }

    /**
     * Accepts a list (saved) or a map value => 0|1 (form toggles).
     *
     * @return list<string>
     */
    private function normalizeDisabledValues(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $values = array_is_list($raw)
            ? $raw
            : array_keys(array_filter($raw, fn($disabled): bool => is_scalar($disabled) && (bool) $disabled));

        $normalized = [];
        foreach ($values as $value) {
            if (is_string($value) || is_int($value)) {
                $normalized[] = (string) $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}
