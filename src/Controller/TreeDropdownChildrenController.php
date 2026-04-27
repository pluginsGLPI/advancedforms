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

namespace GlpiPlugin\Advancedforms\Controller;

use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeItemDropdown;
use DBmysql;
use CommonTreeDropdown;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TreeDropdownChildrenController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route(
        path: 'TreeDropdownChildren',
        name: 'tree_dropdown_children',
    )]
    public function __invoke(Request $request): Response
    {
        $questions_id = $request->query->getInt('questions_id', 0);
        $parent_id = $request->query->getInt('parent_id', 0);

        if ($parent_id <= 0 || $questions_id <= 0) {
            return new Response('', Response::HTTP_OK);
        }

        $question = new Question();
        if (!$question->getFromDB($questions_id)) {
            return new Response('', Response::HTTP_OK);
        }

        /** @var QuestionTypeItemDropdown $question_type */
        $question_type = $question->getQuestionType();

        $itemtype = $question_type->getDefaultValueItemtype($question) ?? '';
        $field_name = $question->getEndUserInputName() . '[items_id]';
        $aria_label = $question_type->items_id_aria_label ?? __('Select a dropdown item');

        $dropdown_restriction_params = $question_type->getDropdownRestrictionParams($question);
        /** @var array<string, mixed> $condition_param */
        $condition_param = $dropdown_restriction_params['WHERE'] ?? [];

        if (!class_exists($itemtype) || !is_subclass_of($itemtype, CommonTreeDropdown::class)) {
            return new Response('', Response::HTTP_OK);
        }

        /** @var DBmysql $DB */
        global $DB;

        $foreign_key = $itemtype::getForeignKeyField();
        $table = $itemtype::getTable();

        $level_key = $table . '.level';

        $where = [];

        $item_check = getItemForItemtype($itemtype);
        $is_recursive = $item_check->maybeRecursive();

        $entity_restrict = getEntitiesRestrictCriteria($table, '', '', $is_recursive);
        if (!empty($entity_restrict)) {
            $where = array_merge($where, $entity_restrict);
        }

        if (!empty($condition_param) && is_array($condition_param)) {
            unset($condition_param[$level_key]);
            $where = array_merge($where, $condition_param);
        }

        if ($item_check instanceof CommonTreeDropdown && $item_check->isField('is_deleted')) {
            $where['is_deleted'] = 0;
        }

        $parent_where = $where;
        $parent_where['id'] = $parent_id;

        $parent_is_selectable = false;
        if (count($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $parent_where, 'LIMIT' => 1])) > 0) {
            $parent_is_selectable = true;
        }

        $where[$foreign_key] = $parent_id;

        $children = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => $table,
            'WHERE'  => $where,
            'ORDER'  => 'name ASC',
        ]);

        foreach ($iterator as $row) {
            if (!is_array($row)) {
                continue;
            }

            $children[] = [
                'id' => $row['id'],
                'name' => $row['name'],
            ];
        }

        if ($children === []) {
            return new Response('', Response::HTTP_OK);
        }

        global $CFG_GLPI;

        $rand_value = random_int(1000000, 9999999);
        $select_id = 'tree_cascade_child_' . $rand_value;

        $twig = TemplateRenderer::getInstance();
        $html = $twig->render(
            '@advancedforms/tree_cascade_dropdown_children.html.twig',
            [
                'select_id'            => $select_id,
                'children'             => $children,
                'questions_id'         => $questions_id,
                'final_field_name'     => $field_name,
                'aria_label'           => $aria_label,
                'parent_is_selectable' => $parent_is_selectable,
                'ajax_limit_count'     => is_numeric($CFG_GLPI['ajax_limit_count'] ?? 10) ? (int) ($CFG_GLPI['ajax_limit_count'] ?? 10) : 10,
            ],
        );

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
