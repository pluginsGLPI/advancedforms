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

export class AfTreeCascadeDropdown {
    /**
     * @param {Object} options
     * @param {string} options.selector_id - The ID of the select element to bind
     * @param {string} options.field_name - The hidden input name for final items_id
     * @param {string} options.itemtype - The CommonTreeDropdown itemtype
     * @param {string} options.aria_label - Aria label for accessibility
     * @param {Object} options.condition - Additional SQL restriction params
     * @param {number} options.ajax_limit_count - Limit for Select2 adaptation
     * @param {string} [options.next_container_id] - Optional container ID for auto-loading children
     * @param {number} [options.auto_load_parent_id] - Optional parent ID to auto-load children on init
     * @param {number} [options.level] - Current depth level (1 = root)
     */
    constructor(options) {
        this.selector_id = options.selector_id;
        this.field_name = options.field_name;
        this.itemtype = options.itemtype;
        this.aria_label = options.aria_label;
        this.condition = options.condition || {};
        this.ajax_limit_count = options.ajax_limit_count || 10;
        this.next_container_id = options.next_container_id || null;
        this.auto_load_parent_id = options.auto_load_parent_id || 0;
        this.level = options.level || 1;
        this.endpoint_url = `${CFG_GLPI.root_doc}/plugins/advancedforms/TreeDropdownChildren`;

        this.#init();
    }

    #init() {
        const $select = $(`#${this.selector_id}`);
        if ($select.length === 0) {
            return;
        }

        this.#setupAdapt($select);
        this.#bindChangeEvent($select);

        if (this.auto_load_parent_id > 0 && this.next_container_id) {
            this.#loadChildren(this.auto_load_parent_id, $(`#${this.next_container_id}`));
        }
    }

    #setupAdapt($select) {
        if ($select.hasClass('af-tree-cascade-select')) {
            setupAdaptDropdown({
                field_id: this.selector_id,
                width: '100%',
                dropdown_css_class: '',
                placeholder: '',
                ajax_limit_count: this.ajax_limit_count,
                templateresult: templateResult,
                templateselection: templateSelection,
            });
        }
    }

    #bindChangeEvent($select) {
        $select.on('change', () => {
            const value = $select.val();
            $(`input[name="${this.field_name}"]`).val(value);

            const $wrapper = $select.closest('.af-tree-level-wrapper');
            $wrapper.nextAll('.af-tree-level-wrapper, .af-tree-next-container').remove();

            if (value && value > 0) {
                const container_classes = this.level === 1 ? 'col-12 col-sm-6 af-tree-next-container' : 'af-tree-next-container';
                const $container = $(`<div class="${container_classes}"></div>`);
                $wrapper.after($container);
                this.#loadChildren(value, $container);
            }
        });
    }

    #loadChildren(parent_id, $container) {
        $.ajax({
            url: this.endpoint_url,
            data: {
                itemtype: this.itemtype,
                parent_id: parent_id,
                field_name: this.field_name,
                aria_label: this.aria_label,
                condition: this.condition,
            },
            success: (html) => {
                console.log(this.endpoint_url)
                if (html.trim().length > 0) {
                    $container.html(html);
                    this.#initDynamicChild($container);
                } else {
                    $container.remove();
                }
            },
        });
    }

    #initDynamicChild($container) {
        const $select = $container.find('.af-tree-cascade-select');
        if ($select.length === 0) {
            return;
        }

        const child_id = $select.attr('id');
        const child_options = {
            selector_id: child_id,
            field_name: $select.data('af-tree-field-name'),
            itemtype: $select.data('af-tree-itemtype'),
            aria_label: $select.data('af-tree-aria-label') || this.aria_label,
            condition: $select.data('af-tree-condition') || this.condition,
            ajax_limit_count: $select.data('af-tree-ajax-limit') || this.ajax_limit_count,
            level: this.level + 1,
        };

        new AfTreeCascadeDropdown(child_options);
    }
};
