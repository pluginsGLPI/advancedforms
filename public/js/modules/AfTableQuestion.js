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

export class AfTableQuestion {
    static #submitGuardRegistered = false;

    #table;
    #body;
    #template;
    #addBtn;
    #minRows;
    #maxRows;

    constructor(tableElement) {
        this.#table    = tableElement;
        this.#body     = tableElement.querySelector('[data-af-table-body]');
        this.#template = tableElement.querySelector('[data-af-table-row-template]');
        this.#addBtn   = tableElement.querySelector('[data-af-table-add-row]');
        this.#minRows  = parseInt(tableElement.dataset.afMinRows, 10) || 1;
        this.#maxRows  = parseInt(tableElement.dataset.afMaxRows, 10) || 50;

        if (!this.#body || !this.#template || !this.#addBtn) {
            return;
        }

        this.#addBtn.addEventListener('click', () => this.addRow());
        this.#body.addEventListener('click', e => {
            const btn = e.target.closest('[data-af-table-remove-row]');
            if (btn) {
                this.removeRow(btn.closest('[data-af-table-row]'));
            }
        });
        // Clear a cell's error state as soon as the user fills it.
        const clear = e => AfTableQuestion.#clearCellError(e.target);
        this.#body.addEventListener('input', clear);
        if (window.$) {
            // select2's "change" only fires through jQuery, not native addEventListener.
            window.$(this.#body).on('change', clear);
        } else {
            this.#body.addEventListener('change', clear);
        }
        this.#updateButtonStates();

        AfTableQuestion.#registerSubmitGuard();
    }

    static #registerSubmitGuard() {
        if (AfTableQuestion.#submitGuardRegistered) { return; }
        AfTableQuestion.#submitGuardRegistered = true;

        document.addEventListener('click', e => {
            const trigger = e.target.closest('[data-glpi-form-renderer-action=submit]');
            if (!trigger) { return; }

            const scope = trigger.closest('form') ?? document;
            let firstInvalid = null;
            scope.querySelectorAll('[data-af-table-question]').forEach(table => {
                const invalid = AfTableQuestion.#validateTable(table);
                if (invalid && !firstInvalid) { firstInvalid = invalid; }
            });

            if (firstInvalid) {
                e.preventDefault();
                e.stopImmediatePropagation();
                // Scroll to the cell, as a select2-managed <select> is itself hidden.
                (firstInvalid.closest('td') ?? firstInvalid)
                    .scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, true);
    }

    /**
     * @returns {Element|null} the first invalid control of the table, or null.
     */
    static #validateTable(table) {
        // Skip tables hidden by step-by-step navigation or conditional sections.
        if (table.offsetParent === null) { return null; }

        const requiredCols = (table.dataset.afRequiredCols ?? '')
            .split(',')
            .filter(value => value !== '');

        let patternCols = {};
        try {
            patternCols = JSON.parse(table.dataset.afPatternCols ?? '{}');
        } catch {
            patternCols = {};
        }
        const patternRegexes = {};
        Object.entries(patternCols).forEach(([colIndex, pattern]) => {
            const regex = AfTableQuestion.#toRegExp(pattern);
            if (regex) { patternRegexes[colIndex] = regex; }
        });

        if (requiredCols.length === 0 && Object.keys(patternRegexes).length === 0) { return null; }

        let firstInvalid = null;
        table.querySelectorAll('[data-af-table-row]').forEach(row => {
            const controls = AfTableQuestion.#rowControls(row);
            const rowHasValue = controls.some(control => AfTableQuestion.#hasValue(control));

            controls.forEach(control => {
                const colIndex = AfTableQuestion.#columnIndex(control);
                const hasValue = AfTableQuestion.#hasValue(control);

                if (rowHasValue && requiredCols.includes(colIndex) && !hasValue) {
                    AfTableQuestion.#setCellError(control, table.dataset.afRequiredMsg ?? '');
                    if (!firstInvalid) { firstInvalid = control; }
                    return;
                }

                const regex = patternRegexes[colIndex];
                if (hasValue && regex && !regex.test(control.value)) {
                    AfTableQuestion.#setCellError(control, table.dataset.afPatternMsg ?? '');
                    if (!firstInvalid) { firstInvalid = control; }
                    return;
                }

                AfTableQuestion.#clearCellError(control);
            });
        });

        return firstInvalid;
    }

    /**
     * Parses a PHP-style `/regex/flags` string into a RegExp, or a bare pattern
     * with no delimiters. Only JS-supported flags (gimsuy) are kept.
     *
     * @returns {RegExp|null} null if the pattern is empty or invalid.
     */
    static #toRegExp(pattern) {
        if (typeof pattern !== 'string' || pattern === '') { return null; }

        const match = /^\/(.*)\/([a-z]*)$/s.exec(pattern);
        const body  = match ? match[1] : pattern;
        const flags = (match ? match[2] : '').split('').filter(f => 'gimsuy'.includes(f)).join('');

        try {
            return new RegExp(body, flags);
        } catch {
            return null;
        }
    }

    /** @returns {Element[]} */
    static #rowControls(row) {
        return Array.from(row.querySelectorAll(
            'input[name]:not([type=hidden]):not(.select2-search__field), select[name]',
        ));
    }

    static #hasValue(control) {
        if (control.type === 'checkbox' || control.type === 'radio') {
            return control.checked;
        }
        return (control.value ?? '').trim() !== '';
    }

    /** Extracts the "col_N" index from a cell control name, as a string. */
    static #columnIndex(control) {
        const match = /\[col_(\d+)\]/.exec(control.name ?? '');
        return match ? match[1] : '';
    }

    static #setCellError(control, message) {
        control.classList.add('is-invalid');

        const td = control.closest('td') ?? control.parentElement;
        if (!td) { return; }

        let feedback = td.querySelector('[data-af-cell-error]');
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.setAttribute('data-af-cell-error', '');
            feedback.className = 'invalid-feedback d-block';
            td.appendChild(feedback);
        }
        feedback.textContent = message;
    }

    static #clearCellError(control) {
        if (!control?.classList?.contains('is-invalid')) { return; }
        control.classList.remove('is-invalid');
        control.closest('td')?.querySelector('[data-af-cell-error]')?.remove();
    }

    addRow() {
        const rowCount = this.#rowCount();
        if (rowCount >= this.#maxRows) {
            return;
        }
        const clone = this.#template.content.cloneNode(true);
        clone.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace('__ROW__', rowCount);
        });
        this.#body.appendChild(clone);
        this.#initSelectsInRow(this.#body.lastElementChild);
        this.#updateButtonStates();
    }

    #initSelectsInRow(row) {
        if (!row || !window.setupAdaptDropdown) { return; }
        const limit = parseInt(this.#table.dataset.afS2Limit, 10) || 100;
        row.querySelectorAll('[data-af-needs-s2]').forEach(select => {
            const id = 'dropdown_af_eu_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
            select.id = id;
            const config = {
                type: 'adapt',
                field_id: id,
                width: '100%',
                dropdown_css_class: '',
                placeholder: '',
                ajax_limit_count: limit,
            };
            window.select2_configs = window.select2_configs || {};
            window.select2_configs[id] = config;
            window.setupAdaptDropdown(config);
        });
    }

    removeRow(rowElement) {
        if (!rowElement || this.#rowCount() <= this.#minRows) {
            return;
        }
        rowElement.remove();
        this.#reindexRows();
        this.#updateButtonStates();
    }

    #reindexRows() {
        this.#body.querySelectorAll('[data-af-table-row]').forEach((row, i) => {
            row.querySelectorAll('[name]').forEach(el => {
                el.name = el.name.replace(/\[\d+\]/, `[${i}]`);
            });
        });
    }

    #updateButtonStates() {
        const count = this.#rowCount();
        const atMax = count >= this.#maxRows;
        const atMin = count <= this.#minRows;

        this.#addBtn.classList.toggle('opacity-25', atMax);
        this.#addBtn.classList.toggle('pe-none',    atMax);

        this.#body.querySelectorAll('[data-af-table-remove-row]').forEach(icon => {
            icon.classList.toggle('opacity-25', atMin);
            icon.classList.toggle('pe-none',    atMin);
        });
    }

    #rowCount() {
        return this.#body.querySelectorAll('[data-af-table-row]').length;
    }
}
