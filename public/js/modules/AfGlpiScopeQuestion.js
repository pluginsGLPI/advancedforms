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

import { SYSTEM_INFO_UPDATED_EVENT } from '/plugins/advancedforms/js/modules/AfSystemInfoQuestion.js';

export class AfGlpiScopeQuestion {
    #container;
    #input_name;

    /** @param {HTMLElement} container - rendered by templates/glpi_scope_end_user.html.twig */
    constructor(container) {
        if (!container) {
            return;
        }

        this.#container = container;
        this.#input_name = container.dataset.afInputName;

        document.addEventListener(SYSTEM_INFO_UPDATED_EVENT, (event) => {
            this.#render(event.detail?.components ?? []);
        });
    }

    #render(components) {
        const choices = components.includes('GLPI') ? components : ['GLPI', ...components];
        const checked = new Set(
            [...this.#container.querySelectorAll('input:checked')].map((input) => input.value),
        );

        // Plugin names come from user input: build nodes, never HTML strings
        this.#container.replaceChildren(...choices.map((choice) => {
            const label = document.createElement('label');
            label.className = 'form-check';

            const input = document.createElement('input');
            input.className = 'form-check-input';
            input.type = 'checkbox';
            input.name = this.#input_name;
            input.value = choice;
            input.checked = checked.has(choice);

            const text = document.createElement('span');
            text.className = 'form-check-label';
            text.textContent = choice;

            label.append(input, text);
            return label;
        }));
    }
}
