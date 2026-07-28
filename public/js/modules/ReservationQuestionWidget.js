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

export class ReservationQuestionWidget {
    #root;
    #endpoint_url = `${CFG_GLPI.root_doc}/plugins/advancedforms/ReservationWidget`;

    /** @param {HTMLElement} root - root element rendered by templates/reservation_question.html.twig */
    constructor(root) {
        if (!root) {
            return;
        }

        this.#root = root;

        this.#initItemSelect();
        this.#initDateChangeListeners();
        this.#initSubmitReset();
    }

    /**
     * The form renderer validates each question server-side before submitting and
     * surfaces any error next to the question, so clear our inline availability
     * hint on submit to avoid showing duplicate or stale messaging.
     */
    #initSubmitReset() {
        const form = this.#root.closest('form');
        if (!form) {
            return;
        }

        $(form).on('submit', () => this.#showAvailability(null));
        $(form)
            .find('[data-glpi-form-renderer-action="submit"]')
            .on('click', () => this.#showAvailability(null));
    }

    #initItemSelect() {
        const $select = $(this.#root.querySelector('[data-reservation-question-item-select]'));
        if ($select.length === 0) {
            return;
        }

        const allowed_itemtypes = JSON.parse(this.#root.dataset.allowedItemtypes || '[]');

        $select.select2({
            width: '100%',
            allowClear: true,
            placeholder: __('Select an item to reserve', 'advancedforms'),
            ajax: {
                url: `${this.#endpoint_url}/ReservableItems`,
                type: 'POST',
                delay: 250,
                data: (params) => ({
                    allowed_itemtypes: allowed_itemtypes,
                    search: params.term || '',
                }),
                processResults: (data) => ({
                    results: this.#groupResultsByItemtype(Array.isArray(data) ? data : []),
                }),
            },
        });

        $select.on('select2:select', () => this.#onItemSelected());
        $select.on('select2:clear select2:unselecting', () => this.#onItemCleared());
    }

    /** Groups flat {id, text, itemtype} results into Select2 optgroups per itemtype. */
    #groupResultsByItemtype(data) {
        const groups = new Map();

        for (const item of data) {
            const group_label = item.itemtype || '';
            if (!groups.has(group_label)) {
                groups.set(group_label, []);
            }
            groups.get(group_label).push({
                id: item.id,
                text: item.text,
            });
        }

        return Array.from(groups, ([text, children]) => ({ text, children }));
    }

    #onItemSelected() {
        const $select = $(this.#root.querySelector('[data-reservation-question-item-select]'));
        this.#setReservationItemsId($select.val());
        $(this.#root.querySelector('[data-reservation-question-dates]')).removeClass('d-none');
        this.#checkAvailability();
        this.#loadReservations();
    }

    #onItemCleared() {
        this.#setReservationItemsId('');
        if (this.#getBeginInput()) {
            this.#getBeginInput().value = '';
        }
        if (this.#getEndInput()) {
            this.#getEndInput().value = '';
        }
        $(this.#root.querySelector('[data-reservation-question-dates]')).addClass('d-none');
        this.#showAvailability(null);
        this.#renderReservations([]);
    }

    /** begin/end are rendered by the datetimeField macro (self-initializing Flatpickr); just react to changes. */
    #initDateChangeListeners() {
        const begin_input = this.#getBeginInput();
        const end_input = this.#getEndInput();
        if (!begin_input || !end_input) {
            return;
        }

        $(begin_input).on('change', () => this.#checkAvailability());
        $(end_input).on('change', () => this.#checkAvailability());
    }

    #getBeginInput() {
        return this.#root.querySelector('[data-reservation-question-begin-picker]');
    }

    #getEndInput() {
        return this.#root.querySelector('[data-reservation-question-end-picker]');
    }

    #setReservationItemsId(value) {
        const input = this.#root.querySelector('[data-reservation-question-field="reservationitems_id"]');
        if (input) {
            input.value = value ?? '';
        }
    }

    #checkAvailability() {
        const reservationitems_id = this.#root.querySelector('[data-reservation-question-field="reservationitems_id"]')?.value ?? '';
        const begin = this.#getBeginInput()?.value ?? '';
        const end = this.#getEndInput()?.value ?? '';

        if (!reservationitems_id || !begin || !end) {
            this.#showAvailability(null);
            return;
        }

        // Catch the obvious end-before-begin case locally, before asking the server.
        if (!this.#isRangeValid(begin, end)) {
            this.#showRangeError();
            return;
        }

        $.post(`${this.#endpoint_url}/CheckAvailability`, { reservationitems_id, begin, end })
            .done((data) => this.#showAvailability(data.available))
            .fail(() => this.#showStatus(__('Could not check availability, please try again', 'advancedforms'), 'text-danger'));
    }

    /** @returns {boolean} false only when both dates parse and end is not strictly after begin. */
    #isRangeValid(begin, end) {
        const begin_ts = Date.parse(begin.replace(' ', 'T'));
        const end_ts = Date.parse(end.replace(' ', 'T'));

        if (Number.isNaN(begin_ts) || Number.isNaN(end_ts)) {
            // Unparseable here: let the server-side validation decide.
            return true;
        }

        return end_ts > begin_ts;
    }

    #showRangeError() {
        this.#showStatus(__('The end date must be after the start date', 'advancedforms'), 'text-danger');
    }

    /** Fetches the equipment's existing reservations and lists them so the user can see busy slots. */
    #loadReservations() {
        const reservationitems_id = this.#root.querySelector('[data-reservation-question-field="reservationitems_id"]')?.value ?? '';
        if (!reservationitems_id) {
            this.#renderReservations([]);
            return;
        }

        $.post(`${this.#endpoint_url}/Reservations`, { reservationitems_id })
            .done((data) => this.#renderReservations(Array.isArray(data) ? data : []))
            .fail(() => this.#renderReservations([]));
    }

    /** @param {Array<{begin: string, end: string}>} reservations */
    #renderReservations(reservations) {
        const container = this.#root.querySelector('[data-reservation-question-reservations]');
        if (!container) {
            return;
        }

        if (reservations.length === 0) {
            container.innerHTML = '';
            return;
        }

        const title = document.createElement('div');
        title.className = 'text-muted small mb-1';
        title.textContent = __('Existing reservations for this item', 'advancedforms');

        const list = document.createElement('ul');
        list.className = 'list-unstyled small mb-0';
        for (const reservation of reservations) {
            const line = document.createElement('li');
            line.className = 'text-muted';
            // textContent, never innerHTML: dates come from the server but stay untrusted here.
            line.textContent = `${reservation.begin} → ${reservation.end}`;
            list.appendChild(line);
        }

        container.replaceChildren(title, list);
    }

    #showAvailability(available) {
        if (available === null || available === undefined) {
            this.#showStatus('', null);
            return;
        }

        this.#showStatus(
            available ? __('This slot is available', 'advancedforms') : __('This slot is no longer available', 'advancedforms'),
            available ? 'text-success' : 'text-danger',
        );
    }

    /** Renders a single status line under the date pickers (availability, range error or request failure). */
    #showStatus(text, css_class) {
        const $result = $(this.#root.querySelector('[data-reservation-question-availability]'));
        if ($result.length === 0) {
            return;
        }

        $result.text(text).removeClass('text-danger text-success');
        if (css_class) {
            $result.addClass(css_class);
        }
    }
}
