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
    #calendar = null;
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

    /** Groups flat {id, text, itemtype, itemtype_label} results into Select2 optgroups per itemtype. */
    #groupResultsByItemtype(data) {
        const groups = new Map();

        for (const item of data) {
            const key = item.itemtype || '';
            if (!groups.has(key)) {
                groups.set(key, { text: item.itemtype_label || key, children: [] });
            }
            groups.get(key).children.push({
                id: item.id,
                text: item.text,
            });
        }

        return Array.from(groups.values());
    }

    #onItemSelected() {
        const $select = $(this.#root.querySelector('[data-reservation-question-item-select]'));
        this.#setReservationItemsId($select.val());
        $(this.#root.querySelector('[data-reservation-question-dates]')).removeClass('d-none');
        this.#checkAvailability();

        // Absent when the question is configured without the calendar (see #ensureCalendar):
        // fall back to the plain list of existing reservations.
        this.#ensureCalendar();
        if (this.#calendar) {
            this.#calendar.unselect();
            this.#calendar.today();
            this.#calendar.refetchEvents();
        } else {
            this.#loadReservations();
        }
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
        if (this.#calendar) {
            this.#calendar.unselect();
            this.#calendar.removeAllEvents();
        }
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

    /** Builds the calendar once; item changes afterwards just refetch its events (see #onItemSelected). */
    #ensureCalendar() {
        if (this.#calendar) {
            return;
        }

        const container = this.#root.querySelector('[data-reservation-question-calendar]');
        if (!container) {
            return;
        }

        this.#loadCalendarCss();

        // Same locale/hours setup as core js/planning.js; planning hours are absent from CFG_GLPI for anonymous sessions.
        const loaded_locales = typeof FullCalendarLocales !== 'undefined' ? Object.keys(FullCalendarLocales) : [];
        // Like core planning: UTC mode + server "now", so the grid shows server/session wall-clock
        // times (those of the reservations) whatever the browser timezone.
        const now = new Date(`${this.#root.dataset.now.replace(' ', 'T')}Z`);
        this.#calendar = new FullCalendar.Calendar(container, {
            plugins: ['timeGrid', 'interaction'],
            defaultView: 'timeGridWeek',
            header: { left: 'prev,next today', center: 'title', right: 'timeGridWeek,timeGridDay' },
            locale: loaded_locales.length === 1 ? loaded_locales[0] : undefined,
            timeZone: 'UTC',
            now,
            nowIndicator: true,
            minTime: CFG_GLPI.planning_begin ?? '00:00:00',
            maxTime: CFG_GLPI.planning_end ?? '24:00:00',
            height: 450,
            selectable: true,
            selectMirror: true,
            // Don't offer slots the server would reject: past ones or overlapping an existing reservation.
            selectOverlap: false,
            validRange: { start: now },
            selectAllow: (info) => info.start >= now,
            // Keep the highlight visible when focus leaves the calendar (e.g. another question);
            // it is cleared explicitly on item change/select instead (see #onItemSelected/#onItemCleared).
            unselectAuto: false,
            select: (info) => this.#onSlotSelected(info),
            events: (info, successCallback, failureCallback) => this.#fetchEvents(info, successCallback, failureCallback),
        });
        this.#calendar.render();
    }

    /**
     * Several calendar-enabled questions may share the page: only add the stylesheet once.
     * It loads asynchronously, so resize the calendar once it applies: slot positions measured
     * on the unstyled grid would otherwise misplace the events until the next re-render.
     */
    #loadCalendarCss() {
        const href = this.#root.dataset.calendarCss;
        if (!href) {
            return;
        }

        let link = document.querySelector(`link[rel="stylesheet"][href="${CSS.escape(href)}"]`);
        if (!link) {
            link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            document.head.appendChild(link);
        }

        if (!link.sheet) {
            link.addEventListener('load', () => this.#calendar?.updateSize(), { once: true });
        }
    }

    /** FullCalendar event source: reuses the existing Reservations endpoint, scoped to the visible range. */
    #fetchEvents(info, successCallback, failureCallback) {
        const reservationitems_id = this.#root.querySelector('[data-reservation-question-field="reservationitems_id"]')?.value ?? '';
        if (!reservationitems_id) {
            successCallback([]);
            return;
        }

        $.post(`${this.#endpoint_url}/Reservations`, {
            reservationitems_id,
            begin: this.#formatForServer(info.start),
            end: this.#formatForServer(info.end),
        })
            .done((data) => successCallback((Array.isArray(data) ? data : []).map((reservation) => ({
                title: __('Reserved', 'advancedforms'),
                start: reservation.begin.replace(' ', 'T'),
                end: reservation.end.replace(' ', 'T'),
                color: 'var(--tblr-red)',
                editable: false,
                overlap: false,
            }))))
            .fail(() => failureCallback());
    }

    /**
     * User picked a free slot on the calendar: mirror it into the begin/end pickers used by the
     * rest of the widget. The selection highlight is left in place (not unselected) so the user
     * can see what they just picked; it clears on the next selection or item change instead.
     */
    #onSlotSelected(info) {
        const begin_picker = this.#getBeginInput()?.closest('.flatpickr')?._flatpickr;
        const end_picker = this.#getEndInput()?.closest('.flatpickr')?._flatpickr;
        if (!begin_picker || !end_picker) {
            return;
        }

        // Strings, not Date objects: flatpickr would read the UTC-mode dates in the browser timezone.
        begin_picker.setDate(this.#formatForServer(info.start));
        end_picker.setDate(this.#formatForServer(info.end));
        this.#checkAvailability();
    }

    /**
     * The calendar runs in UTC mode: the UTC fields hold the server/session wall-clock time.
     * @returns {string} date formatted as 'Y-m-d H:i:s', as expected by the endpoints and the pickers.
     */
    #formatForServer(date) {
        return date.toISOString().slice(0, 19).replace('T', ' ');
    }

    /** Calendar disabled: fetches the equipment's existing reservations and lists them so the user can see busy slots. */
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
