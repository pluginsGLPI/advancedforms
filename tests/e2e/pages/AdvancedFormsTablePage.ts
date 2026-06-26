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

import { Locator, Page, expect } from '@playwright/test';
import { GlpiPage } from '../../../../../tests/e2e/pages/GlpiPage';

/**
 * Page helpers for the "Table" question type: enabling it, configuring its
 * columns in the editor and interacting with the rendered end-user table.
 */
export class AdvancedFormsTablePage extends GlpiPage {
    private static readonly CONFIG_TAB =
        'GlpiPlugin\\Advancedforms\\Model\\Config\\ConfigTab$1';

    public constructor(page: Page) {
        super(page);
    }

    /** Enables the Table question type from the plugin configuration page. */
    public async enableTableQuestionType(): Promise<void> {
        await this.page.goto(
            `/front/config.form.php?forcetab=${AdvancedFormsTablePage.CONFIG_TAB}`,
        );

        const card = this.page
            .locator('[data-testid^="feature-"]')
            .filter({ hasText: 'Table question type' });
        const toggle = card.getByTestId('feature-toggle');

        if (!(await toggle.isChecked())) {
            await toggle.check();
            await this.getButton('Save').click();
            await expect(card.getByTestId('feature-toggle')).toBeChecked();
        }
    }

    /** Opens the column configuration dropdown of a table question in the editor. */
    public async openColumnConfig(question: Locator): Promise<void> {
        await question.getByRole('button', { name: 'Configure table columns' }).click();
        await question.locator('[data-af-table-columns-container]').waitFor({ state: 'visible' });
    }

    /**
     * Adds a column in the (already open) configuration dropdown.
     * `type` is the visible label of a compatible question type (e.g. "Text").
     */
    public async addColumn(
        question: Locator,
        column: { name: string; type: string; required?: boolean },
    ): Promise<void> {
        await question.locator('[data-af-table-column-add]').click();

        const row = question.locator('[data-af-table-column]').last();
        await row.getByPlaceholder('Column name').fill(column.name);

        // Column type is a select2-enhanced <select>.
        const type_dropdown = row.locator('.select2-container').first();
        await type_dropdown.waitFor({ state: 'visible' });
        await this.doSetDropdownValue(type_dropdown, column.type, false);

        if (column.required) {
            await row.locator('.af-required-toggle').click();
        }
    }

    /** The rendered end-user table container. */
    public getEndUserTable(): Locator {
        return this.page.locator('[data-af-table-question]');
    }

    /** A cell control of the rendered end-user table, by row and column index. */
    public getEndUserCell(table: Locator, row: number, col: number): Locator {
        // eslint-disable-next-line playwright/no-raw-locators
        return table.locator(`[name$="[${row}][col_${col}]"]`);
    }
}
