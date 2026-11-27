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
 * Loaded after the tree cascade dropdown markup (templates/tree_cascade_dropdown.html.twig).
 * Each select carries its own config in data attributes.
 * Waits for DOM ready on full pages, like the former inline modules did.
 */
(function () {
    const ready_attr = 'data-af-tree-cascade-ready';

    const init = () => {
        const els = [...document.querySelectorAll('select[data-af-tree-cascade]:not([' + ready_attr + '])')];
        if (els.length === 0) {
            return;
        }
        els.forEach((el) => el.setAttribute(ready_attr, ''));

        import('/plugins/advancedforms/js/modules/AfTreeCascadeDropdown.js').then(({ AfTreeCascadeDropdown }) => {
            els.forEach((el) => new AfTreeCascadeDropdown({
                selector_id: el.id,
                questions_id: Number(el.dataset.afTreeQuestionsId),
                ajax_limit_count: Number(el.dataset.afTreeAjaxLimit),
                level: Number(el.dataset.afTreeLevel || 1),
                next_container_id: el.dataset.afTreeNextContainer,
                auto_load_parent_id: Number(el.dataset.afTreeAutoLoadParent || 0),
            }));
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
