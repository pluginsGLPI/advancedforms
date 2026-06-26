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

export class AfTableQuestionConfig {
    static #delegationRegistered = false;

    constructor() {
        AfTableQuestionConfig.#register();
    }

    static #register() {
        if (AfTableQuestionConfig.#delegationRegistered) { return; }
        AfTableQuestionConfig.#delegationRegistered = true;

        document.addEventListener('shown.bs.dropdown', (e) => {
            const dropdown = e.target.closest('.dropdown');
            if (!dropdown) { return; }
            const container = dropdown.querySelector('[data-af-table-columns-container]');
            if (!container || container.dataset.afTableInited) { return; }
            container.dataset.afTableInited = '1';

            const cardBody = container.closest('.card-body');
            if (!cardBody) { return; }
            const template = cardBody.querySelector('[data-af-table-column-template]');
            const addBtn = cardBody.querySelector('[data-af-table-column-add]');

            AfTableQuestionConfig.#bindRemoveButtons(container);

            // Bind itemtype visibility for all existing columns
            container.querySelectorAll('[data-af-table-column]').forEach(row => {
                AfTableQuestionConfig.#bindItemtypeVisibility(row);
            });

            if (addBtn && template) {
                addBtn.addEventListener('click', () => {
                    AfTableQuestionConfig.#addColumn(container, template);
                });
            }
        });
    }

    static #addColumn(container, template) {
        if (!container || !template) { return; }
        const index = container.querySelectorAll('[data-af-table-column]').length;
        const clone = template.content.cloneNode(true);
        clone.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace('__INDEX__', index);
        });
        container.appendChild(clone);
        const newRow = container.lastElementChild;
        AfTableQuestionConfig.#bindRemoveButtons(newRow);
        AfTableQuestionConfig.#reindex(container);

        const newSelect = newRow.querySelector('[data-af-table-type-select]');
        AfTableQuestionConfig.#initNewColumnSelect(newSelect, container.dataset.afTableColumnsContainer);
        AfTableQuestionConfig.#bindItemtypeVisibility(newRow);
    }

    static #initNewColumnSelect(select, rand) {
        if (!select || !rand || !window.setupAdaptDropdown) { return; }
        const templateConfig = window.select2_configs?.['af-table-type-template-' + rand];
        if (!templateConfig) { return; }
        const newId = 'dropdown_af_table_type_' + rand + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
        select.id = newId;
        const config = { ...templateConfig, field_id: newId };
        window.select2_configs[newId] = config;
        window.setupAdaptDropdown(config);
    }

    static #bindItemtypeVisibility(columnRow) {
        const typeSelect = columnRow.querySelector('select[name*="[question_type]"]');
        if (!typeSelect) { return; }

        const container = columnRow.closest('[data-af-table-columns-container]');
        const rand = container?.dataset.afTableColumnsContainer;

        const update = () => {
            const selected = typeSelect.value;
            columnRow.querySelectorAll('[data-af-itemtype-wrapper]').forEach(wrapper => {
                const show = wrapper.dataset.afItemtypeWrapper === selected;
                wrapper.style.display = show ? '' : 'none';
                const sel = wrapper.querySelector('select');
                if (!sel) { return; }
                sel.disabled = !show;
                if (!show) {
                    // Clear value without triggering Select2 events to keep state clean
                    sel.value = '';
                    if (sel.dataset.afS2Inited && window.$) {
                        window.$(sel).val('').trigger('change.select2');
                    }
                    return;
                }
                if (!sel.dataset.afS2Inited) {
                    sel.dataset.afS2Inited = '1';
                    AfTableQuestionConfig.#initItemtypeSelect(sel, rand);
                }
            });
        };

        typeSelect.addEventListener('change', update);
        update();
    }

    static #initItemtypeSelect(select, rand) {
        if (!select || !window.setupAdaptDropdown) { return; }
        if (!select.id) {
            select.id = 'dropdown_af_itemtype_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
        }
        const templateConfig = window.select2_configs?.['af-table-type-template-' + rand];
        const config = {
            type: 'adapt',
            field_id: select.id,
            width: '170px',
            dropdown_css_class: '',
            placeholder: select.querySelector('option[value=""]')?.textContent?.trim() ?? '',
            ajax_limit_count: templateConfig?.ajax_limit_count ?? 100,
        };
        window.select2_configs = window.select2_configs || {};
        window.select2_configs[select.id] = config;
        window.setupAdaptDropdown(config);
    }

    static #bindRemoveButtons(scope) {
        if (!scope) { return; }
        scope.querySelectorAll('[data-af-table-column-remove]').forEach(btn => {
            if (btn.dataset.afRemoveBound) { return; }
            btn.dataset.afRemoveBound = '1';
            btn.addEventListener('click', () => {
                const container = btn.closest('[data-af-table-columns-container]');
                btn.closest('[data-af-table-column]')?.remove();
                if (container) { AfTableQuestionConfig.#reindex(container); }
            });
        });
    }

    static #reindex(container) {
        container.querySelectorAll('[data-af-table-column]').forEach((row, i) => {
            row.querySelectorAll('[name]').forEach(el => {
                el.name = el.name.replace(/\[columns\]\[\d+\]/, `[columns][${i}]`);
            });
        });
    }
}
