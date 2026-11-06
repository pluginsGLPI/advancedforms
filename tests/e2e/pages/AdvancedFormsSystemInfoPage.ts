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

import { Page, expect } from '@playwright/test';
import { GlpiPage } from '../../../../../tests/e2e/pages/GlpiPage';

/**
 * Page helpers for the "GLPI system information" and "GLPI core or plugins
 * concerned" question types.
 */
export class AdvancedFormsSystemInfoPage extends GlpiPage {
    private static readonly CONFIG_TAB =
        'GlpiPlugin\\Advancedforms\\Model\\Config\\ConfigTab$1';

    public constructor(page: Page) {
        super(page);
    }

    /**
     * Enables both question types from the plugin configuration page and disables
     * every other question type the plugin provides, so that the "Advanced"
     * category only offers these two.
     */
    public async enableSystemInfoQuestionTypes(): Promise<void> {
        await this.page.goto(
            `/front/config.form.php?forcetab=${AdvancedFormsSystemInfoPage.CONFIG_TAB}`,
        );

        const cards = [
            'GLPI system information question type',
            'GLPI core or plugins concerned question type',
        ].map((title) => this.page.locator('[data-testid^="feature-"]').filter({ hasText: title }));
        const wanted_names: string[] = [];
        for (const card of cards) {
            wanted_names.push((await card.getByTestId('feature-toggle').getAttribute('name')) ?? '');
        }

        let changed = false;
        for (const toggle of await this.page.getByTestId('feature-toggle').all()) {
            const should_be_checked = wanted_names.includes((await toggle.getAttribute('name')) ?? '');
            if ((await toggle.isChecked()) !== should_be_checked) {
                await toggle.setChecked(should_be_checked);
                changed = true;
            }
        }

        if (changed) {
            await this.getButton('Save').click();
            for (const card of cards) {
                await expect(card.getByTestId('feature-toggle')).toBeChecked();
            }
        }
    }
}
