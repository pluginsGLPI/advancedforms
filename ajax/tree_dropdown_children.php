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

use Glpi\Application\View\TemplateRenderer;

global $CFG_GLPI;
Session::checkLoginUser();

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

$itemtype = $_REQUEST['itemtype'] ?? '';
$parent_id = (int)($_REQUEST['parent_id'] ?? 0);
$final_field_name = $_REQUEST['field_name'] ?? '';
$aria_label = $_REQUEST['aria_label'] ?? '';
$condition_param = $_REQUEST['condition'] ?? [];

if ($parent_id <= 0 || $parent_id == -1) {
    exit;
}

if (!class_exists($itemtype) || !is_subclass_of($itemtype, CommonTreeDropdown::class)) {
    exit;
}

global $DB;

$foreign_key = $itemtype::getForeignKeyField();
$table = $itemtype::getTable();

$where = [$foreign_key => $parent_id];
if (!empty($condition_param)) {
    $where = array_merge($where, $condition_param);
}

$entity_restrict = getEntitiesRestrictCriteria($table);
if (!empty($entity_restrict)) {
    $where = array_merge($where, $entity_restrict);
}

$item_check = new $itemtype();
if ($item_check->isField('is_deleted')) {
    $where['is_deleted'] = 0;
}

$children = [];
$iterator = $DB->request([
    'SELECT' => ['id', 'name'],
    'FROM'   => $table,
    'WHERE'  => $where,
    'ORDER'  => 'name ASC',
]);

foreach ($iterator as $row) {
    $children[] = ['id' => (int) $row['id'], 'name' => $row['name']];
}

if (empty($children)) {
    exit;
}

$rand_value = random_int(1000000, 9999999);
$select_id = 'tree_cascade_child_' . $rand_value;

$twig = TemplateRenderer::getInstance();
echo $twig->renderFromStringTemplate(<<<TWIG
<div class="af-tree-level-wrapper mt-2">
    <select id="{{ select_id }}" class="form-select" aria-label="{{ aria_label }}">
        <option value="0">---</option>
        {% for child in children %}
            <option value="{{ child.id }}">{{ child.name }}</option>
        {% endfor %}
    </select>
</div>

<script>
$(document).ready(function() {
    setupAdaptDropdown({
        field_id: '{{ select_id }}',
        width: '100%',
        dropdown_css_class: '',
        placeholder: '',
        ajax_limit_count: {{ ajax_limit_count }},
        templateresult: templateResult,
        templateselection: templateSelection,
    });

    $('#{{ select_id }}').on('change', function() {
        var value = $(this).val();
        $('input[name="{{ final_field_name }}"]').val(value);
        var wrapper = $(this).closest('.af-tree-level-wrapper');
        wrapper.nextAll('.af-tree-level-wrapper, .af-tree-next-container').remove();
        if (value && value > 0) {
            var container = $('<div class="af-tree-next-container"></div>');
            wrapper.after(container);
            $.ajax({
                url: '{{ root_doc }}/plugins/advancedforms/ajax/tree_dropdown_children.php',
                data: {
                    itemtype: '{{ itemtype }}',
                    parent_id: value,
                    field_name: '{{ final_field_name }}',
                    aria_label: '{{ aria_label }}',
                    condition: {{ condition_param|json_encode()|raw }}
                },
                success: function(html) {
                    if (html.trim().length > 0) {
                        container.html(html);
                    } else {
                        container.remove();
                    }
                }
            });
        }
    });
});
</script>
TWIG, [
    'select_id'        => $select_id,
    'children'         => $children,
    'final_field_name' => $final_field_name,
    'aria_label'       => $aria_label,
    'itemtype'         => $itemtype,
    'root_doc'         => $CFG_GLPI['root_doc'],
    'condition_param'  => $condition_param,
    'ajax_limit_count' => (int) $CFG_GLPI['ajax_limit_count'],
]);
