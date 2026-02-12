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

use CommonTreeDropdown;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use Glpi\Form\QuestionType\QuestionTypeItem;
use Glpi\Form\QuestionType\QuestionTypeItemDropdown;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\AdvancedCategory;
use Override;

final class TreeCascadeDropdownQuestion extends QuestionTypeItem implements ConfigurableItemInterface
{
    #[Override]
    public function getCategory(): QuestionTypeCategoryInterface
    {
        return new AdvancedCategory();
    }

    #[Override]
    public function __construct()
    {
        parent::__construct();

        $this->itemtype_aria_label = __('Select a dropdown type');
        $this->items_id_aria_label = __('Select a dropdown item');
    }

    /**
     * @return array<string, array<int, class-string>>
     */
    #[Override]
    public function getAllowedItemtypes(): array
    {
        return [
            'Ticket' => [
                \Location::class,
                \ITILCategory::class,
            ]
        ];
    }
    #[Override]
    public function getName(): string
    {
        return __('Tree Cascade Dropdown', 'advancedforms');
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-sitemap';
    }

    #[Override]
    public function getWeight(): int
    {
        return 30;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getDropdownRestrictionParams(?Question $question): array
    {
        /** @var array<string, mixed> */
        return parent::getDropdownRestrictionParams($question);
    }

    #[Override]
    public function renderEndUserTemplate(Question $question): string
    {
        global $CFG_GLPI;

        $itemtype = $this->getDefaultValueItemtype($question);
        if ($itemtype === null || !is_a($itemtype, CommonTreeDropdown::class, true)) {
            return parent::renderEndUserTemplate($question);
        }

        $default_items_id = (int) $this->getDefaultValueItemId($question);
        $aria_label = $this->items_id_aria_label;

        $tree_table = $itemtype::getTable();
        $foreign_key = $itemtype::getForeignKeyField();

        $rand_tree = random_int(1000000, 9999999);
        $final_items_id_name = $question->getEndUserInputName() . '[items_id]';
        $level2_container = 'level2_container_' . $rand_tree;

        $dropdown_restriction_params = $this->getDropdownRestrictionParams($question);
        /** @var array<string, mixed> $restriction_where */
        $restriction_where = $dropdown_restriction_params['WHERE'] ?? [];

        $ancestor_chain = $this->buildAncestorChain($itemtype, $default_items_id, $restriction_where);

        $twig = TemplateRenderer::getInstance();
        return $twig->render(
            '@advancedforms/tree_cascade_dropdown.html.twig',
            [
                'question'                    => $question,
                'itemtype'                    => $itemtype,
                'tree_table'                  => $tree_table,
                'foreign_key'                 => $foreign_key,
                'default_items_id'            => $default_items_id,
                'aria_label'                  => $aria_label,
                'rand_tree'                   => $rand_tree,
                'final_items_id_name'         => $final_items_id_name,
                'level2_container'            => $level2_container,
                'dropdown_restriction_params' => $restriction_where,
                'root_doc'                    => $CFG_GLPI['root_doc'],
                'ancestor_chain'              => $ancestor_chain,
                'ajax_limit_count'            => is_numeric($CFG_GLPI['ajax_limit_count'] ?? 10) ? (int) ($CFG_GLPI['ajax_limit_count'] ?? 10) : 10,
            ]
        );
    }

    /**
     * @param class-string<CommonTreeDropdown> $itemtype
     * @param array<string, mixed> $extra_conditions
     * @return array<int, array{id: int, parent_id: int, level: int, siblings: array<int, array{id: int, name: string}>}>
     */
    private function buildAncestorChain(string $itemtype, int $items_id, array $extra_conditions = []): array
    {
        if ($items_id <= 0) {
            return [];
        }

        $item = getItemForItemtype($itemtype);
        if (!($item instanceof CommonTreeDropdown) || !$item->getFromDB($items_id)) {
            return [];
        }

        /** @var \DBmysql $DB */
        global $DB;

        $foreign_key = $itemtype::getForeignKeyField();
        $table = $itemtype::getTable();
        $chain = [];
        $current = $item;

        while (true) {
            /** @var array<string, mixed> $fields */
            $fields = $current->fields;
            $id = is_numeric($fields['id'] ?? 0) ? (int) ($fields['id'] ?? 0) : 0;
            $parent_id_value = is_numeric($fields[$foreign_key] ?? 0) ? (int) ($fields[$foreign_key] ?? 0) : 0;
            $level = is_numeric($fields['level'] ?? 0) ? (int) ($fields['level'] ?? 0) : 0;

            array_unshift($chain, [
                'id'        => $id,
                'parent_id' => $parent_id_value,
                'level'     => $level,
                'siblings'  => [],
            ]);

            $parent_id = is_numeric($fields[$foreign_key] ?? 0) ? (int) ($fields[$foreign_key] ?? 0) : 0;
            if ($parent_id <= 0) {
                break;
            }

            $parent = getItemForItemtype($itemtype);
            if (!($parent instanceof CommonTreeDropdown) || !$parent->getFromDB($parent_id)) {
                break;
            }
            $current = $parent;
        }

        $entity_restrict = getEntitiesRestrictCriteria($table);
        $has_is_deleted = $item->isField('is_deleted');

        foreach ($chain as &$node) {
            $where = [];
            if ($node['level'] === 1) {
                $where[$table . '.level'] = 1;
            } else {
                $where[$foreign_key] = $node['parent_id'];
            }

            if (!empty($entity_restrict)) {
                $where = array_merge($where, $entity_restrict);
            }

            if (!empty($extra_conditions)) {
                $where = array_merge($where, $extra_conditions);
            }

            if ($has_is_deleted) {
                $where['is_deleted'] = 0;
            }

            $siblings = [];
            $iterator = $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => $table,
                'WHERE'  => $where,
                'ORDER'  => 'name ASC',
            ]);

            foreach ($iterator as $row) {
                /** @var array{id: mixed, name: mixed} $row */
                $row_id = is_numeric($row['id'] ?? 0) ? (int) ($row['id'] ?? 0) : 0;
                $row_name = is_string($row['name'] ?? '') ? (string) ($row['name'] ?? '') : '';
                $siblings[] = ['id' => $row_id, 'name' => $row_name];
            }

            $node['siblings'] = $siblings;
        }

        return $chain;
    }

    #[Override]
    public function prepareEndUserAnswer(Question $question, mixed $answer): mixed
    {
        $question->fields['type'] = QuestionTypeItemDropdown::class;

        return parent::prepareEndUserAnswer($question, $answer);
    }

    /**
     * @param array<string, mixed> $rawData
     */
    #[Override]
    public function getTargetQuestionType(array $rawData): string
    {
        return \Glpi\Form\QuestionType\QuestionTypeItemDropdown::class;
    }

    #[Override]
    public function getConfigDescription(): string
    {
        return __('Enable tree cascade dropdown question type (recursive dropdown for hierarchical data)', 'advancedforms');
    }

    #[Override]
    public static function getConfigKey(): string
    {
        return 'enable_tree_cascade_dropdown';
    }

    #[Override]
    public function getConfigTitle(): string
    {
        return $this->getName();
    }

    #[Override]
    public function getConfigIcon(): string
    {
        return $this->getIcon();
    }
}
