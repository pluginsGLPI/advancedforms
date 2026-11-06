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
import { test, expect } from '../fixtures/advancedforms_fixture';
import { FormPage } from '../../../../../tests/e2e/pages/FormPage';
import { Profiles } from '../../../../../tests/e2e/utils/Profiles';
import { getWorkerEntityId } from '../../../../../tests/e2e/utils/WorkerEntities';
import { AdvancedFormsSystemInfoPage } from '../pages/AdvancedFormsSystemInfoPage';
import { withQuestionTypeToggleLock } from '../utils/QuestionTypeToggleLock';

// Text produced by the "Copy system information" button of GLPI 11.0+
const SYSTEM_INFO = [
    '<details><summary>GLPI information</summary><pre>',
    'GLPI: 11.0.12 (/ =&gt; /var/www/glpi)',
    'Installation mode: TARBALL',
    'Source Integrity: OK',
    '</pre></details><details><summary>Plugins list</summary><pre>',
    'datainjection        Name: Data injection                 Version: 2.15.10      State: Enabled                                  Install Method: Marketplace',
    '</pre></details>',
].join('\n');

test.describe('Advanced forms - GLPI system information question', () => {
    test.describe.configure({ timeout: 180_000 });

    test('pasted system information feeds the preview, the scope choices and the ticket', async ({ page, profile, api }) => {
        await withQuestionTypeToggleLock(async () => {
            await profile.set(Profiles.SuperAdmin);
            const form = new FormPage(page);
            await new AdvancedFormsSystemInfoPage(page).enableSystemInfoQuestionTypes();

            const form_name = `E2E system information - ${randomUUID()}`;
            const form_id = await api.createItem('Glpi\\Form\\Form', {
                name: form_name,
                entities_id: getWorkerEntityId(),
            });
            await form.goto(form_id);

            const sysinfo_question = await form.addQuestion('System information');
            await form.setQuestionType(sysinfo_question, 'Advanced');
            await form.setSubQuestionType(sysinfo_question, 'GLPI system information');

            const scope_question = await form.addQuestion('Concerned');
            await form.setQuestionType(scope_question, 'Advanced');
            await form.setSubQuestionType(scope_question, 'GLPI core or plugins concerned');

            await form.doSaveFormEditor();
            await form.doPreviewForm();

            await page.getByRole('textbox', { name: 'System information' }).fill(SYSTEM_INFO);

            // Latest versions depend on the network: only installed versions are asserted
            const preview = page.locator('[data-af-sysinfo-preview]');
            await expect(preview.getByRole('cell', { name: '11.0.12', exact: true }).first()).toBeVisible();
            await expect(preview.getByRole('cell', { name: 'Data injection (datainjection)' })).toBeVisible();

            await page.getByRole('checkbox', { name: 'Data injection (datainjection)' }).check();
            await page.getByRole('button', { name: 'Submit' }).click();

            await expect(page).toHaveURL(/\/front\/ticket\.form\.php\?id=\d+/);
            await expect(page.getByRole('cell', { name: 'Data injection (datainjection)' })).toBeVisible();
        });
    });
});
