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

export class ReservationRequestTimelineActions {
    #root;
    #endpoint_url = `${CFG_GLPI.root_doc}/plugins/advancedforms/ReservationRequest`;

    /** @param {HTMLElement} root - one timeline card, rendered by templates/timeline/reservation_request.html.twig */
    constructor(root) {
        if (!root) {
            return;
        }

        this.#root = root;

        this.#initButtons();
    }

    #initButtons() {
        const $buttons = $(this.#root).find('[data-reservation-request-action]');
        if ($buttons.length === 0) {
            return;
        }

        $buttons.on('click', (e) => this.#onActionClicked($(e.currentTarget), $buttons));
    }

    #onActionClicked($button, $buttons) {
        // Guard against double-submission while a request is in flight.
        if ($buttons.prop('disabled')) {
            return;
        }

        const id = $button.data('reservationRequestId');
        const action = $button.data('reservationRequestAction');
        const comment = $(this.#root).find('textarea[name="comment"]').val() ?? '';

        $buttons.prop('disabled', true);

        $.post(this.#endpoint_url, { id, action, comment })
            .done((data) => {
                if (data.success) {
                    window.location.reload();
                    return;
                }

                glpi_toast_error(data.message || __('An error occurred', 'advancedforms'));
                $buttons.prop('disabled', false);
            })
            .fail(() => {
                glpi_toast_error(__('An error occurred', 'advancedforms'));
                $buttons.prop('disabled', false);
            });
    }
}
