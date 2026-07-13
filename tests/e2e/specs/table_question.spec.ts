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

import { randomUUID } from 'crypto';
import { test, expect, Page } from '../fixtures/advancedforms_fixture';
import { FormPage } from '../../../../../tests/e2e/pages/FormPage';
import { Profiles } from '../../../../../tests/e2e/utils/Profiles';
import { getWorkerEntityId } from '../../../../../tests/e2e/utils/WorkerEntities';
import { AdvancedFormsTablePage } from '../pages/AdvancedFormsTablePage';

/**
 * Builds a form with a single Table question through the editor and returns its id.
 * The table has a required "Name" column and an optional "Comment" column,
 * both rendered as plain text inputs.
 */
async function createFormWithTableQuestion(
    page: Page,
    api: { createItem(itemtype: string, fields: object): Promise<number> },
    form_name: string,
): Promise<number> {
    const form = new FormPage(page);
    const table = new AdvancedFormsTablePage(page);

    await table.enableTableQuestionType();

    const form_id = await api.createItem('Glpi\\Form\\Form', {
        name: form_name,
        entities_id: getWorkerEntityId(),
    });
    await form.goto(form_id);

    const question = await form.addQuestion('Devices');
    await form.doChangeQuestionType(question, 'Table');

    await table.openColumnConfig(question);
    await table.addColumn(question, { name: 'Name', type: 'Text', required: true });
    await table.addColumn(question, { name: 'Comment', type: 'Text', required: false });

    await form.doSaveFormEditor();

    return form_id;
}

test.describe('Advanced forms - Table question', () => {
    test('admin can configure and persist table columns', async ({ page, profile, api }) => {
        await profile.set(Profiles.SuperAdmin);
        const form = new FormPage(page);
        const form_id = await createFormWithTableQuestion(
            page,
            api,
            `E2E table config - ${randomUUID()}`,
        );

        // Reload the editor and check the columns survived the roundtrip.
        await form.goto(form_id);
        const question = page.getByRole('region', { name: 'Question details' }).first();
        await expect(question.getByText('Name')).toBeVisible();
        await expect(question.getByText('Comment')).toBeVisible();
    });

    test('end user can fill and submit the table', async ({ page, profile, api }) => {
        await profile.set(Profiles.SuperAdmin);
        const form = new FormPage(page);
        const table = new AdvancedFormsTablePage(page);
        const form_name = `E2E table submit - ${randomUUID()}`;

        await createFormWithTableQuestion(page, api, form_name);
        await form.doPreviewForm();

        const eu_table = table.getEndUserTable();
        await table.getEndUserCell(eu_table, 0, 0).fill('PC-001');
        await table.getEndUserCell(eu_table, 0, 1).fill('Spare laptop');

        await page.getByRole('button', { name: 'Submit' }).click();

        // A link to the created answer/item confirms the submission succeeded.
        await expect(page.getByRole('link', { name: form_name })).toBeVisible();
    });

    test('required column blocks submission and highlights the empty cell', async ({ page, profile, api }) => {
        await profile.set(Profiles.SuperAdmin);
        const form = new FormPage(page);
        const table = new AdvancedFormsTablePage(page);

        await createFormWithTableQuestion(page, api, `E2E table required - ${randomUUID()}`);
        await form.doPreviewForm();

        const eu_table = table.getEndUserTable();

        // Fill only the optional column, leaving the required "Name" cell empty.
        await table.getEndUserCell(eu_table, 0, 1).fill('Has a comment but no name');
        await page.getByRole('button', { name: 'Submit' }).click();

        // Blocked: error shown, empty required cell highlighted, optional one is not.
        await expect(eu_table.getByText('Please fill in all required columns.')).toBeVisible();
        await expect(table.getEndUserCell(eu_table, 0, 0)).toHaveClass(/is-invalid/);
        await expect(table.getEndUserCell(eu_table, 0, 1)).not.toHaveClass(/is-invalid/);

        // Filling the required cell clears the error.
        await table.getEndUserCell(eu_table, 0, 0).fill('PC-002');
        await expect(table.getEndUserCell(eu_table, 0, 0)).not.toHaveClass(/is-invalid/);
    });

    test('column pattern blocks submission and highlights the specific cell', async ({ page, profile, api }) => {
        await profile.set(Profiles.SuperAdmin);
        const form = new FormPage(page);
        const table = new AdvancedFormsTablePage(page);

        await table.enableTableQuestionType();

        const form_id = await api.createItem('Glpi\\Form\\Form', {
            name: `E2E table pattern - ${randomUUID()}`,
            entities_id: getWorkerEntityId(),
        });
        await form.goto(form_id);

        const question = await form.addQuestion('Devices');
        await form.doChangeQuestionType(question, 'Table');

        await table.openColumnConfig(question);
        await table.addColumn(question, { name: 'Source IP', type: 'Text', required: false, pattern: '/^172\\.23\\./' });

        await form.doSaveFormEditor();
        await form.doPreviewForm();

        const eu_table = table.getEndUserTable();

        // Fill the cell with a value that does not match the configured pattern.
        await table.getEndUserCell(eu_table, 0, 0).fill('10.0.0.1');
        await page.getByRole('button', { name: 'Submit' }).click();

        // Submission is blocked: the specific cell is highlighted with the pattern error.
        await expect(eu_table.getByText('This value does not match the expected format.')).toBeVisible();
        await expect(table.getEndUserCell(eu_table, 0, 0)).toHaveClass(/is-invalid/);

        // Filling the cell with a matching value clears the error.
        await table.getEndUserCell(eu_table, 0, 0).fill('172.23.0.1');
        await expect(table.getEndUserCell(eu_table, 0, 0)).not.toHaveClass(/is-invalid/);
    });
});
