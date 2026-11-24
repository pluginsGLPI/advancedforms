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

/**
 * Loaded right after the table question config markup (templates/editor/question_types/table_config.html.twig).
 * Idempotent: each markup block is initialised once, even when rendered again.
 */
(function () {
    const ready_attr = 'data-af-table-config-ready';
    const els = [...document.querySelectorAll('[data-af-table-config]:not([' + ready_attr + '])')];
    if (els.length === 0) {
        return;
    }
    els.forEach((el) => el.setAttribute(ready_attr, ''));

    window.select2_configs = window.select2_configs || {};
    els.forEach((el) => {
        const icons = JSON.parse(el.dataset.afTableConfigIcons);
        window.afTableColumnTypeTemplate = window.afTableColumnTypeTemplate || function (item) {
            if (!item.id || !icons[item.id]) {
                return item.text;
            }
            return $('<span>', { 'class': 'd-flex align-items-center gap-1' })
                .append($('<i>', { 'class': icons[item.id] }))
                .append(document.createTextNode(' ' + item.text));
        };

        const field_id = 'af-table-type-template-' + el.dataset.afTableConfig;
        window.select2_configs[field_id] = {
            type: 'adapt',
            field_id: field_id,
            width: '200px',
            dropdown_css_class: '',
            placeholder: '',
            ajax_limit_count: Number(el.dataset.afTableConfigAjaxLimit),
            templateresult: window.afTableColumnTypeTemplate,
            templateselection: window.afTableColumnTypeTemplate,
        };
    });

    import('/plugins/advancedforms/js/modules/AfTableQuestionConfig.js').then(({ AfTableQuestionConfig }) => {
        new AfTableQuestionConfig();
    });
})();
