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
    #errors;

    constructor(tableElement) {
        this.#table    = tableElement;
        this.#body     = tableElement.querySelector('[data-af-table-body]');
        this.#template = tableElement.querySelector('[data-af-table-row-template]');
        this.#addBtn   = tableElement.querySelector('[data-af-table-add-row]');
        this.#errors   = tableElement.querySelector('[data-af-table-errors]');

        if (!this.#body || !this.#template || !this.#addBtn) {
            return;
        }

        this.#watchServerErrors();

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

    /**
     * The core renderer reports validation errors per question, but attaches each
     * message next to every input it finds inside that question. A table has one
     * input per cell, so a single error ends up repeated in every cell, and every
     * cell gets flagged whether or not it is at fault.
     *
     * Watch for those injections, keep one copy of each distinct message in a list
     * below the table, and let the client-side rules decide which cells to flag.
     * The relocated nodes keep their class so the renderer still clears them on
     * the next round.
     */
    #watchServerErrors() {
        if (!this.#errors) { return; }

        // Only hide the in-cell copies once we know we can relocate them: without
        // this class, a module that failed to load leaves core's output visible.
        this.#table.classList.add('af-table-errors-managed');

        new MutationObserver(mutations => {
            const injected = [];
            mutations.forEach(mutation => {
                mutation.addedNodes.forEach(node => {
                    if (node.nodeType !== Node.ELEMENT_NODE) { return; }
                    if (!node.classList.contains('invalid-tooltip')) { return; }
                    // Ignore the ones we just moved ourselves.
                    if (this.#errors.contains(node)) { return; }
                    injected.push(node);
                });
            });

            if (injected.length) { this.#relocateServerErrors(injected); }
        }).observe(this.#table, { childList: true, subtree: true });
    }

    /** @param {Element[]} injected */
    #relocateServerErrors(injected) {
        // Core empties the whole question before injecting a new round, so the
        // list is already clear here; do it explicitly anyway, as #validateTable
        // deliberately leaves this container alone.
        this.#errors.replaceChildren();

        const seen = [];
        injected.forEach(node => {
            const message = (node.textContent ?? '').trim();
            if (message !== '' && !seen.some(kept => kept.textContent.trim() === message)) {
                seen.push(node);
            }
            node.remove();
        });

        // Flag the cells the browser can judge on its own; the detail stays in
        // the list below the table.
        AfTableQuestion.#validateTable(this.#table, false);

        seen.forEach((node, index) => {
            // Core gives every copy the same id; keep it on the first one only so
            // the inputs' aria-errormessage still resolves to a unique element.
            if (index > 0) { node.removeAttribute('id'); }
            node.classList.add('d-block');
            this.#errors.appendChild(node);
        });
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
     * @param {Element} table
     * @param {boolean} withMessages Flag the cells only, when the detail already
     *                               sits in the server error list below the table.
     * @returns {Element|null} the first invalid control of the table, or null.
     */
    static #validateTable(table, withMessages = true) {
        // Skip tables hidden by step-by-step navigation or conditional sections.
        if (table.offsetParent === null) { return null; }

        // Core only clears its own server-rendered errors when a new request
        // round-trips; if we block the submit below, that never happens, leaving
        // stale messages from a previous attempt next to our fresh ones. The
        // ones already relocated below the table are spared: they are the only
        // rendering left for a rule the browser cannot judge on its own, and
        // blocking the submit means no round-trip will bring them back.
        table.querySelectorAll('.invalid-tooltip').forEach(el => {
            if (!el.closest('[data-af-table-errors]')) { el.remove(); }
        });

        const message = text => (withMessages ? text : '');

        // Both payloads are keyed by the "col_N" token found in the field names,
        // and are built server-side from the rules the server itself validates
        // against, so a column can never pick up its neighbour's rule.
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
        Object.entries(patternCols).forEach(([colKey, pattern]) => {
            const regex = AfTableQuestion.#toRegExp(pattern);
            if (regex) { patternRegexes[colKey] = regex; }
        });

        let firstInvalid = null;
        table.querySelectorAll('[data-af-table-row]').forEach(row => {
            const controls = AfTableQuestion.#rowControls(row);
            // Browsers reset .value to "" for un-parseable content in type=number
            // inputs, so hasValue() alone can't see it; badInput must count too.
            const rowHasValue = controls.some(control => AfTableQuestion.#hasValue(control) || control.validity?.badInput);

            controls.forEach(control => {
                const colKey = AfTableQuestion.#columnKey(control);

                if (control.validity?.badInput) {
                    AfTableQuestion.#setCellError(control, message(table.dataset.afPatternMsg ?? ''));
                    if (!firstInvalid) { firstInvalid = control; }
                    return;
                }

                const hasValue = AfTableQuestion.#hasValue(control);

                if (rowHasValue && requiredCols.includes(colKey) && !hasValue) {
                    AfTableQuestion.#setCellError(control, message(table.dataset.afRequiredMsg ?? ''));
                    if (!firstInvalid) { firstInvalid = control; }
                    return;
                }

                const regex = patternRegexes[colKey];
                if (hasValue && regex && !regex.test(control.value)) {
                    AfTableQuestion.#setCellError(control, message(table.dataset.afPatternMsg ?? ''));
                    if (!firstInvalid) { firstInvalid = control; }
                    return;
                }

                // Native constraint from the column's type (number, email...); "missing" is handled above.
                if (hasValue && control.validity && !control.validity.valid && !control.validity.valueMissing) {
                    AfTableQuestion.#setCellError(control, message(table.dataset.afPatternMsg ?? ''));
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
     * with no delimiters.
     *
     * Only flags shared by PCRE and JS are accepted. A flag PCRE understands but
     * JS does not (`x`, for one) makes this return null rather than be dropped,
     * leaving the server as sole judge — which it is anyway. `g` and `y` do not
     * exist in PCRE at all, and would make `test()` stateful across cells.
     *
     * @returns {RegExp|null} null if the pattern is empty or unusable here.
     */
    static #toRegExp(pattern) {
        if (typeof pattern !== 'string' || pattern === '') { return null; }

        const match = /^\/(.*)\/([a-z]*)$/s.exec(pattern);
        const body  = match ? match[1] : pattern;
        const flags = match ? match[2] : '';

        // Dropping a flag we cannot honour would silently evaluate a different
        // regex than the server does, so give up instead and let it decide.
        if (flags.split('').some(f => !'imsu'.includes(f))) { return null; }

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

    /** Extracts the "col_N" key from a cell control name. */
    static #columnKey(control) {
        const match = /\[(col_\d+)\]/.exec(control.name ?? '');
        return match ? match[1] : '';
    }

    /** An empty message flags the cell without writing any text under it. */
    static #setCellError(control, message) {
        control.classList.add('is-invalid');

        const td = control.closest('td') ?? control.parentElement;
        if (!td) { return; }

        if (message === '') {
            td.querySelector('[data-af-cell-error]')?.remove();
            return;
        }

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
        // Keeping one row on screen is presentation only: acceptable row counts
        // are enforced by the form's validation conditions.
        if (!rowElement || this.#rowCount() <= 1) {
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
        const lastRow = this.#rowCount() <= 1;

        this.#body.querySelectorAll('[data-af-table-remove-row]').forEach(icon => {
            icon.classList.toggle('opacity-25', lastRow);
            icon.classList.toggle('pe-none',    lastRow);
        });
    }

    #rowCount() {
        return this.#body.querySelectorAll('[data-af-table-row]').length;
    }
}
