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

/** Fired on document with `detail.components`, the choices offered to GLPI scope questions. */
export const SYSTEM_INFO_UPDATED_EVENT = 'advancedforms:system-info-updated';

export class AfSystemInfoQuestion {
    #textarea;
    #preview;
    #timer = null;
    #request = null;
    // Forward the form URL parameters (e.g. access token) used by the form access policies
    #endpoint_url = `${CFG_GLPI.root_doc}/plugins/advancedforms/SystemInfoPreview${window.location.search}`;

    /** @param {HTMLTextAreaElement} textarea - rendered by templates/system_info_end_user.html.twig */
    constructor(textarea) {
        if (!textarea) {
            return;
        }

        this.#textarea = textarea;
        this.#preview = textarea.closest('[data-af-sysinfo]').querySelector('[data-af-sysinfo-preview]');

        textarea.addEventListener('input', () => this.#schedulePreview());

        if (textarea.value.trim() !== '') {
            this.#refreshPreview();
        }
    }

    #schedulePreview() {
        clearTimeout(this.#timer);
        this.#timer = setTimeout(() => this.#refreshPreview(), 500);
    }

    #refreshPreview() {
        const text = this.#textarea.value;
        this.#request?.abort();

        if (text.trim() === '') {
            $(this.#preview).empty();
            this.#dispatch(['GLPI']);
            return;
        }

        this.#request = $.post(this.#endpoint_url, {
            questions_id: this.#textarea.dataset.afQuestionsId,
            text,
        })
            .done((data) => {
                // Server side Twig rendering, values already escaped
                $(this.#preview).html(data.html);
                this.#dispatch(data.components);
            })
            .fail((xhr, status) => {
                if (status !== 'abort') {
                    glpi_toast_error(__('An error occurred', 'advancedforms'));
                }
            });
    }

    #dispatch(components) {
        document.dispatchEvent(new CustomEvent(SYSTEM_INFO_UPDATED_EVENT, { detail: { components } }));
    }
}
