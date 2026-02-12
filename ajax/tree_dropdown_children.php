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

$where_condition = [$foreign_key => $parent_id];
if (!empty($condition_param)) {
    $where_condition = array_merge($where_condition, $condition_param);
}

$count_result = $DB->request([
    'COUNT' => 'cpt',
    'FROM'  => $table,
    'WHERE' => $where_condition,
])->current();

if (!$count_result || $count_result['cpt'] == 0) {
    exit;
}

$rand_value = random_int(1000000, 9999999);
$temp_field_name = 'temp_tree_child_' . $rand_value;

$twig = TemplateRenderer::getInstance();
echo $twig->renderFromStringTemplate(<<<TWIG
{% import 'components/form/fields_macros.html.twig' as fields %}

<div class="af-tree-level-wrapper mt-2">
{{ fields.dropdownField(
    itemtype,
    temp_field_name,
    '',
    '',
    {
        'init'               : true,
        'no_label'           : true,
        'right'              : 'all',
        'width'              : '100%',
        'mb'                 : '',
        'comments'           : false,
        'addicon'            : false,
        'aria_label'         : aria_label,
        'nochecklimit'       : true,
        'display_emptychoice': true,
        'rand'               : rand_value,
        'condition'          : {(foreign_key): parent_id}|merge(condition_param),
    }
) }}
</div>

<script>
$(document).ready(function() {
    $('#dropdown_{{ temp_field_name }}{{ rand_value }}').on('change', function() {
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
    'itemtype'         => $itemtype,
    'temp_field_name'  => $temp_field_name,
    'final_field_name' => $final_field_name,
    'aria_label'       => $aria_label,
    'rand_value'       => $rand_value,
    'parent_id'        => $parent_id,
    'foreign_key'      => $foreign_key,
    'condition_param'  => $condition_param,
    'root_doc'         => $CFG_GLPI['root_doc'],
]);
