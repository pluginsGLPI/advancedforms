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

namespace GlpiPlugin\Advancedforms\Model\QuestionType;

use Glpi\Form\QuestionType\QuestionTypeFile;
use Glpi\Form\QuestionType\QuestionTypeDateTime;
use Glpi\Form\QuestionType\QuestionTypeRadio;
use Glpi\Form\QuestionType\QuestionTypeDropdown;
use Glpi\Form\QuestionType\QuestionTypeUrgency;
use CommonItilObject_Item;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionType;
use Glpi\Form\QuestionType\AbstractQuestionTypeActors;
use Glpi\Form\QuestionType\AbstractQuestionTypeSelectable;
use Glpi\Form\QuestionType\AbstractQuestionTypeShortAnswer;
use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use Glpi\Form\QuestionType\QuestionTypeInterface;
use Glpi\Form\QuestionType\QuestionTypeItem;
use Glpi\Form\QuestionType\QuestionTypeItemDropdown;
use Glpi\Form\QuestionType\QuestionTypeRequestType;
use Glpi\Form\QuestionType\QuestionTypeUserDevice;
use Glpi\Form\QuestionType\QuestionTypesManager;
use Glpi\Form\Condition\ConditionValueTransformerInterface;
use Glpi\Form\QuestionType\QuestionTypeValidationInterface;
use Glpi\Form\QuestionType\RawAnswerIsHtmlInterface;
use Glpi\Form\ValidationResult;
use Glpi\DBAL\JsonFieldInterface;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\LdapQuestion;
use Override;
use Session;
use User;

use function Safe\json_decode;
use function Safe\json_encode;

final class TableQuestion extends AbstractQuestionType implements
    ConfigurableItemInterface,
    ConditionValueTransformerInterface,
    QuestionTypeValidationInterface,
    RawAnswerIsHtmlInterface
{
    #[Override]
    public function getCategory(): QuestionTypeCategoryInterface
    {
        return new AdvancedCategory();
    }

    #[Override]
    public function getName(): string
    {
        return __('Table', 'advancedforms');
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-table';
    }

    #[Override]
    public function getWeight(): int
    {
        return 50;
    }

    #[Override]
    public function getExtraDataConfigClass(): string
    {
        return TableQuestionConfig::class;
    }

    /** @param array<mixed> $input */
    #[Override]
    public function validateExtraDataInput(array $input): bool
    {
        $columns = $input[TableQuestionConfig::COLUMNS] ?? [];
        if (!is_array($columns) || $columns === []) {
            return false;
        }

        $manager = QuestionTypesManager::getInstance();
        foreach ($columns as $col) {
            if (!is_array($col)) {
                return false;
            }

            $name = $col[TableQuestionConfig::COL_NAME] ?? '';
            if (!is_string($name) || $name === '') {
                return false;
            }

            $fqcn = $col[TableQuestionConfig::COL_QUESTION_TYPE] ?? '';
            if (!is_string($fqcn) || !$manager->isValidQuestionType($fqcn)) {
                return false;
            }
        }

        $min_raw = $input[TableQuestionConfig::MIN_ROWS] ?? 1;
        $max_raw = $input[TableQuestionConfig::MAX_ROWS] ?? 50;
        $min = is_numeric($min_raw) ? (int) $min_raw : 1;
        $max = is_numeric($max_raw) ? (int) $max_raw : 50;
        return $min >= 1 && $max >= $min && $max <= 50;
    }

    /** @param array<mixed> $input */
    #[Override]
    public function prepareExtraData(array $input): array
    {
        $columns = array_values(array_map(
            static function (mixed $col): array {
                $col      = is_array($col) ? $col : [];
                $name     = $col[TableQuestionConfig::COL_NAME] ?? '';
                $type     = $col[TableQuestionConfig::COL_QUESTION_TYPE] ?? '';
                $itemtype = $col[TableQuestionConfig::COL_ITEMTYPE] ?? '';
                return [
                    TableQuestionConfig::COL_NAME          => is_scalar($name) ? (string) $name : '',
                    TableQuestionConfig::COL_QUESTION_TYPE => is_scalar($type) ? (string) $type : '',
                    TableQuestionConfig::COL_REQUIRED      => (bool) ($col[TableQuestionConfig::COL_REQUIRED] ?? false),
                    TableQuestionConfig::COL_ITEMTYPE      => is_scalar($itemtype) ? (string) $itemtype : '',
                ];
            },
            array_filter((array) ($input[TableQuestionConfig::COLUMNS] ?? []), is_array(...)),
        ));

        $min_raw = $input[TableQuestionConfig::MIN_ROWS] ?? 1;
        $max_raw = $input[TableQuestionConfig::MAX_ROWS] ?? 50;
        $min = max(1, is_numeric($min_raw) ? (int) $min_raw : 1);
        $max = min(50, max($min, is_numeric($max_raw) ? (int) $max_raw : 50));

        return [
            TableQuestionConfig::COLUMNS  => $columns,
            TableQuestionConfig::MIN_ROWS => $min,
            TableQuestionConfig::MAX_ROWS => $max,
        ];
    }

    #[Override]
    public function prepareEndUserAnswer(Question $question, mixed $answer): mixed
    {
        if (!is_array($answer)) {
            return [];
        }

        // Drop entirely empty rows so blank rows are not persisted.
        // Mandatory columns are enforced by validateAnswer() before submission.
        $result = [];
        foreach ($answer as $row) {
            if (is_array($row) && $this->rowHasValue($row)) {
                $result[] = $row;
            }
        }

        return $result;
    }

    #[Override]
    public function validateAnswer(Question $question, mixed $answer): ValidationResult
    {
        $result = new ValidationResult();
        if (!is_array($answer)) {
            return $result;
        }

        $required_columns = [];
        foreach ($this->loadConfig($question)->getColumns() as $index => $col) {
            if ($col[TableQuestionConfig::COL_REQUIRED]) {
                $required_columns[$index] = $col[TableQuestionConfig::COL_NAME];
            }
        }

        if ($required_columns === []) {
            return $result;
        }

        $row_number = 0;
        foreach ($answer as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row_number++;

            // Entirely empty rows are dropped on save, so they are not validated.
            if (!$this->rowHasValue($row)) {
                continue;
            }

            foreach ($required_columns as $index => $name) {
                $value = $row['col_' . $index] ?? '';
                if (!is_scalar($value) || (string) $value === '') {
                    $result->addError($question, sprintf(
                        __('Row %1$s: the column "%2$s" is required.', 'advancedforms'),
                        $row_number,
                        $name,
                    ));
                }
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function rowHasValue(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== '' && $value !== null) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function transformConditionValueForComparisons(mixed $value, ?JsonFieldInterface $question_config): string|array
    {
        if (!is_array($value)) {
            return is_scalar($value) ? (string) $value : '';
        }

        $flat = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $cell) {
                if (is_scalar($cell) && (string) $cell !== '') {
                    $flat[] = (string) $cell;
                }
            }
        }

        return $flat;
    }

    #[Override]
    public function formatRawAnswer(mixed $answer, Question $question): string
    {
        $rows = $this->normalizeRawAnswerRows($answer);
        if ($rows === []) {
            return '';
        }

        $columns = $this->loadConfig($question)->getColumns();
        if ($columns === []) {
            return '';
        }

        $twig = TemplateRenderer::getInstance();
        return $twig->render('@advancedforms/table_answer.html.twig', [
            'headers' => array_map(
                static fn(array $col): string => $col[TableQuestionConfig::COL_NAME],
                $columns,
            ),
            'rows' => $this->buildDisplayRows($rows, $columns),
        ]);
    }

    /**
     * GLPI stores the raw answer via a JSON roundtrip, so it may already be a
     * decoded array by the time formatRawAnswer() is called, or still a JSON string.
     *
     * @return list<array<array-key, mixed>>
     */
    private function normalizeRawAnswerRows(mixed $answer): array
    {
        if (is_array($answer)) {
            $rows = $answer;
        } elseif (is_string($answer) && $answer !== '') {
            $decoded = json_decode($answer, associative: true);
            $rows    = is_array($decoded) ? $decoded : [];
        } else {
            $rows = [];
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * Resolves stored cell values into display labels, batching DB access so each
     * column triggers at most one query instead of one query per cell.
     *
     * @param list<array<array-key, mixed>> $rows
     * @param array<array{name: string, question_type: string, required: bool, itemtype: string}> $columns
     * @return list<list<string>>
     */
    private function buildDisplayRows(array $rows, array $columns): array
    {
        $label_maps = [];
        foreach ($columns as $index => $col) {
            $label_maps[$index] = $this->buildColumnLabelMap(
                $col[TableQuestionConfig::COL_QUESTION_TYPE],
                $col[TableQuestionConfig::COL_ITEMTYPE] ?? '',
                $this->collectColumnValues($rows, $index),
            );
        }

        $display_rows = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach (array_keys($columns) as $index) {
                $raw     = $row['col_' . $index] ?? '';
                $value   = is_scalar($raw) ? (string) $raw : '';
                $cells[] = $value === '' ? '' : ($label_maps[$index][$value] ?? $value);
            }

            $display_rows[] = $cells;
        }

        return $display_rows;
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     * @return list<string> Unique non-empty stored values found in the column.
     */
    private function collectColumnValues(array $rows, int $index): array
    {
        // Collected into a list (not array keys) so numeric values such as "1"
        // stay strings instead of being coerced to int array keys.
        $values = [];
        foreach ($rows as $row) {
            $raw   = $row['col_' . $index] ?? '';
            $value = is_scalar($raw) ? (string) $raw : '';
            if ($value !== '' && !in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Builds a [stored value => display label] map for a column.
     * An empty map means the stored value is displayed as-is.
     *
     * @param list<string> $values Unique non-empty stored values to resolve.
     * @return array<array-key, string>
     */
    private function buildColumnLabelMap(string $fqcn, string $itemtype, array $values): array
    {
        return match (true) {
            $values === [] => [],
            is_a($fqcn, AbstractQuestionTypeSelectable::class, true) => in_array('1', $values, true) ? ['1' => __('Yes')] : [],
            is_a($fqcn, AbstractQuestionTypeActors::class, true)     => $this->resolveUserNames($values),
            is_a($fqcn, QuestionTypeUserDevice::class, true)         => $this->resolveDeviceNames($values),
            is_a($fqcn, QuestionTypeItem::class, true)               => $this->resolveItemNames($itemtype, $values),
            default => [],
        };
    }

    /**
     * @param list<string> $values
     * @return array<array-key, string>
     */
    private function resolveUserNames(array $values): array
    {
        $ids = $this->toPositiveIds($values);
        if ($ids === []) {
            return [];
        }

        global $DB;

        $map = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'realname', 'firstname'],
            'FROM'   => User::getTable(),
            'WHERE'  => ['id' => $ids],
        ]) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = is_numeric($row['id']) ? (int) $row['id'] : 0;
            $map[(string) $id] = formatUserName(
                $id,
                is_string($row['name'] ?? null) ? $row['name'] : null,
                is_string($row['realname'] ?? null) ? $row['realname'] : null,
                is_string($row['firstname'] ?? null) ? $row['firstname'] : null,
            );
        }

        return $map;
    }

    /**
     * @param list<string> $values
     * @return array<array-key, string>
     */
    private function resolveItemNames(string $itemtype, array $values): array
    {
        if ($itemtype === '' || !class_exists($itemtype)) {
            return [];
        }

        $item = getItemForItemtype($itemtype);
        if ($item === false) {
            return [];
        }

        $ids = $this->toPositiveIds($values);
        if ($ids === []) {
            return [];
        }

        global $DB;

        $map = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => $item->getTable(),
            'WHERE'  => ['id' => $ids],
        ]) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = is_numeric($row['id']) ? (int) $row['id'] : 0;
            $map[(string) $id] = is_string($row['name'] ?? null) ? $row['name'] : (string) $id;
        }

        return $map;
    }

    /**
     * @param list<string> $values "Itemtype_id" strings as produced by getMyDevices().
     * @return array<array-key, string>
     */
    private function resolveDeviceNames(array $values): array
    {
        global $DB;

        // Group ids by device itemtype so each itemtype is queried only once.
        $ids_by_itemtype = [];
        foreach ($values as $value) {
            if (!str_contains($value, '_')) {
                continue;
            }

            [$device_itemtype, $device_id] = explode('_', $value, 2);
            if (!is_numeric($device_id) || !class_exists($device_itemtype)) {
                continue;
            }

            $ids_by_itemtype[$device_itemtype][] = (int) $device_id;
        }

        $map = [];
        foreach ($ids_by_itemtype as $device_itemtype => $ids) {
            $item = getItemForItemtype($device_itemtype);
            if ($item === false) {
                continue;
            }

            foreach ($DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => $item->getTable(),
                'WHERE'  => ['id' => $ids],
            ]) as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $id   = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $name = is_string($row['name'] ?? null) ? $row['name'] : '';
                if ($name !== '') {
                    $map[$device_itemtype . '_' . $id] = $name;
                }
            }
        }

        return $map;
    }

    /**
     * @param list<string> $values
     * @return list<int> Strictly positive integer ids.
     */
    private function toPositiveIds(array $values): array
    {
        return array_values(array_filter(
            array_map(static fn(string $value): int => (int) $value, $values),
            static fn(int $id): bool => $id > 0,
        ));
    }

    #[Override]
    public static function getConfigKey(): string
    {
        return 'enable_question_type_table';
    }

    #[Override]
    public function getConfigTitle(): string
    {
        return __('Table question type', 'advancedforms');
    }

    #[Override]
    public function getConfigDescription(): string
    {
        return __('Allow users to fill in tabular data with configurable columns.', 'advancedforms');
    }

    #[Override]
    public function getConfigIcon(): string
    {
        return $this->getIcon();
    }

    #[Override]
    public function renderAdministrationTemplate(Question|null $question): string
    {
        $config = ($question instanceof Question)
            ? $this->loadConfig($question)
            : new TableQuestionConfig();

        $twig = TemplateRenderer::getInstance();
        return $twig->render(
            '@advancedforms/editor/question_types/table_admin.html.twig',
            [
                'question'        => $question,
                'config'          => $config,
                'advanced_config' => $this->renderAdvancedConfigurationTemplate($question),
            ],
        );
    }

    #[Override]
    public function renderAdvancedConfigurationTemplate(?Question $question): string
    {
        $config = ($question instanceof Question)
            ? $this->loadConfig($question)
            : new TableQuestionConfig();

        $compatible_types = $this->getCompatibleQuestionTypes();

        // FQCN => icon class, consumed by the select2 formatter defined in the template.
        $icons = [];
        foreach (QuestionTypesManager::getInstance()->getQuestionTypes() as $type) {
            $fqcn = $type::class;
            if (isset($compatible_types[$fqcn])) {
                $icons[$fqcn] = $type->getIcon();
            }
        }

        $itemtype_options = [
            QuestionTypeItem::class => Dropdown::buildItemtypesDropdownOptions(
                (new QuestionTypeItem())->getAllowedItemtypes(),
            ),
            QuestionTypeItemDropdown::class => Dropdown::buildItemtypesDropdownOptions(
                (new QuestionTypeItemDropdown())->getAllowedItemtypes(),
            ),
        ];

        $twig = TemplateRenderer::getInstance();
        return $twig->render(
            '@advancedforms/editor/question_types/table_config.html.twig',
            [
                'question'          => $question,
                'config'            => $config,
                'compatible_types'  => $compatible_types,
                'icons_json'        => json_encode($icons),
                'ajax_limit_count'  => $this->ajaxLimitCount(),
                'COL_NAME'          => TableQuestionConfig::COL_NAME,
                'COL_QUESTION_TYPE' => TableQuestionConfig::COL_QUESTION_TYPE,
                'COL_REQUIRED'      => TableQuestionConfig::COL_REQUIRED,
                'COL_ITEMTYPE'      => TableQuestionConfig::COL_ITEMTYPE,
                'MIN_ROWS'          => TableQuestionConfig::MIN_ROWS,
                'MAX_ROWS'          => TableQuestionConfig::MAX_ROWS,
                'itemtype_options'  => $itemtype_options,
            ],
        );
    }

    #[Override]
    public function renderEndUserTemplate(Question $question): string
    {
        $config = $this->loadConfig($question);

        $type_instances = [];
        foreach (QuestionTypesManager::getInstance()->getQuestionTypes() as $type) {
            $type_instances[$type::class] = $type;
        }

        $cell_map           = [];
        $user_options       = null;
        $device_options     = null;
        $glpi_item_options  = []; // keyed by itemtype FQCN to avoid duplicate DB queries

        foreach ($config->getColumns() as $index => $col) {
            $fqcn     = $col[TableQuestionConfig::COL_QUESTION_TYPE];
            $type     = $type_instances[$fqcn] ?? null;
            $itemtype = $col[TableQuestionConfig::COL_ITEMTYPE] ?? '';

            if (is_a($fqcn, AbstractQuestionTypeActors::class, true)) {
                $user_options     ??= $this->buildUserOptions();
                $cell_map[$index]  = ['mode' => 'select', 'options' => $user_options];
            } elseif (is_a($fqcn, QuestionTypeUserDevice::class, true)) {
                $device_options   ??= $this->buildUserDeviceOptions();
                $cell_map[$index]  = ['mode' => 'select', 'options' => $device_options];
            } elseif (is_a($fqcn, QuestionTypeItem::class, true)) {
                if ($itemtype !== '' && class_exists($itemtype)) {
                    $glpi_item_options[$itemtype] ??= $this->buildGlpiItemtypeOptions($itemtype);
                    $cell_map[$index] = ['mode' => 'select', 'options' => $glpi_item_options[$itemtype]];
                } else {
                    $cell_map[$index] = ['mode' => 'input', 'input_type' => 'text'];
                }
            } else {
                $cell_map[$index]  = $this->getCellInfo($fqcn, $type);
            }
        }

        $twig = TemplateRenderer::getInstance();
        return $twig->render(
            '@advancedforms/table_end_user.html.twig',
            [
                'question'         => $question,
                'config'           => $config,
                'column_cell_map'  => $cell_map,
                'ajax_limit_count' => $this->ajaxLimitCount(),
            ],
        );
    }

    /**
     * Resolves the configured select2 AJAX result limit, falling back to 100.
     */
    private function ajaxLimitCount(): int
    {
        global $CFG_GLPI;

        $value = $CFG_GLPI['ajax_limit_count'] ?? 100;
        return is_numeric($value) ? (int) $value : 100;
    }

    /**
     * Returns question types compatible with a table cell column.
     * Key = FQCN, Value = display name.
     *
     * @return array<string, string>
     */
    public function getCompatibleQuestionTypes(): array
    {
        // is_a() is used (not in_array) so subclasses of an excluded type are rejected too,
        // even when their parent type stays compatible (QuestionTypeItemDropdown is allowed).
        $excluded = [
            QuestionTypeFile::class,
            QuestionTypeDateTime::class,
            QuestionTypeRadio::class,
            QuestionTypeDropdown::class,
            QuestionTypeUrgency::class,
            QuestionTypeRequestType::class,
            TreeCascadeDropdownQuestion::class,
            IpAddressQuestion::class,
            HostnameQuestion::class,
            HiddenQuestion::class,
            LdapQuestion::class,
            self::class,
        ];

        $types = [];
        foreach (QuestionTypesManager::getInstance()->getQuestionTypes() as $type) {
            $fqcn = $type::class;
            foreach ($excluded as $excluded_fqcn) {
                if (is_a($fqcn, $excluded_fqcn, true)) {
                    continue 2;
                }
            }

            $types[$fqcn] = $type->getName();
        }

        return $types;
    }

    /**
     * Returns how a table cell should be rendered for the given question type FQCN.
     *
     * @param ?QuestionTypeInterface $type Pre-resolved instance; instantiated from $fqcn when null.
     * @return array{mode: string, input_type?: string, options?: array<string, string>}
     */
    public function getCellInfo(string $fqcn, ?QuestionTypeInterface $type = null): array
    {
        if (!$type instanceof QuestionTypeInterface) {
            foreach (QuestionTypesManager::getInstance()->getQuestionTypes() as $instance) {
                if ($instance::class === $fqcn) {
                    $type = $instance;
                    break;
                }
            }
        }

        if ($type?->isHiddenInput()) {
            return ['mode' => 'input', 'input_type' => 'hidden'];
        }

        if (is_a($fqcn, AbstractQuestionTypeSelectable::class, true)) {
            return ['mode' => 'checkbox'];
        }

        if (is_a($fqcn, AbstractQuestionTypeShortAnswer::class, true)) {
            $input_type = $type instanceof AbstractQuestionTypeShortAnswer
                ? $type->getInputType()
                : 'text';
            return ['mode' => 'input', 'input_type' => $input_type];
        }

        return ['mode' => 'input', 'input_type' => 'text'];
    }

    /**
     * Builds a [value => label] options array for actor-type columns.
     * Loads up to 200 active users from the database.
     *
     * @return array<int|string, string>
     */
    private function buildUserOptions(): array
    {
        global $DB;

        $options = ['' => Dropdown::EMPTY_VALUE];

        $rows = $DB->request([
            'SELECT' => ['id', 'name', 'realname', 'firstname'],
            'FROM'   => User::getTable(),
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => ['realname', 'firstname', 'name'],
            'LIMIT'  => 200,
        ]);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = is_numeric($row['id']) ? (int) $row['id'] : 0;
            $options[(string) $id] = formatUserName(
                $id,
                is_string($row['name'] ?? null) ? $row['name'] : null,
                is_string($row['realname'] ?? null) ? $row['realname'] : null,
                is_string($row['firstname'] ?? null) ? $row['firstname'] : null,
            );
        }

        return $options;
    }

    /**
     * Builds an optgroup-keyed options array for the User Device column type.
     * Keys at the top level are group labels; inner keys are "Itemtype_id" strings.
     *
     * @return array<string, string|array<string, string>>
     */
    private function buildUserDeviceOptions(): array
    {
        $devices = CommonItilObject_Item::getMyDevices(
            intval(Session::getLoginUserID()),
            Session::getActiveEntities(),
        );

        return array_merge(['' => Dropdown::EMPTY_VALUE], $devices);
    }

    /**
     * Builds a [id => name] options array for a GLPI itemtype (used by Item/ItemDropdown columns).
     * Applies entity and soft-delete filters when applicable.
     *
     * @param class-string $itemtype
     * @return array<int|string, string>
     */
    private function buildGlpiItemtypeOptions(string $itemtype): array
    {
        global $DB;

        $options = ['' => Dropdown::EMPTY_VALUE];
        $item    = getItemForItemtype($itemtype);
        if ($item === false) {
            return $options;
        }

        $where = [];

        if ($item->maybeDeleted()) {
            $where['is_deleted'] = 0;
        }

        if ($item->isEntityAssign()) {
            $where = array_merge($where, getEntitiesRestrictCriteria(
                $item->getTable(),
                '',
                '',
                $item->maybeRecursive(),
            ));
        }

        $criteria = [
            'SELECT' => ['id', 'name'],
            'FROM'   => $item->getTable(),
            'ORDER'  => 'name',
            'LIMIT'  => 200,
        ];

        if ($where !== []) {
            $criteria['WHERE'] = $where;
        }

        foreach ($DB->request($criteria) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $options[(string) (is_numeric($row['id']) ? (int) $row['id'] : 0)] = is_string($row['name']) ? $row['name'] : '';
        }

        return $options;
    }

    private function loadConfig(Question $question): TableQuestionConfig
    {
        $decoded = [];
        if (is_string($question->fields['extra_data'])) {
            $raw = json_decode($question->fields['extra_data'], associative: true);
            if (is_array($raw)) {
                $decoded = $raw;
            }
        }

        $config = $this->getExtraDataConfig($decoded);
        return $config instanceof TableQuestionConfig ? $config : new TableQuestionConfig();
    }
}
