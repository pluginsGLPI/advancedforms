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

test.describe('Advanced forms - Email followup destination field', () => {
    test('the helper is shown next to the field label', async ({ page, profile, api }) => {
        await profile.set(Profiles.SuperAdmin);
        const form = new FormPage(page);

        // The default ticket destination is created with the form
        const form_id = await api.createItem('Glpi\\Form\\Form', {
            name: `E2E email followup - ${randomUUID()}`,
            entities_id: getWorkerEntityId(),
        });
        await form.gotoDestinationTab(form_id);
        await form.doOpenDestinationAccordionItem('Actors');

        const config = form.getRegion('Email followup configuration');
        const helper = config.getByTestId('actor-notification-helper');
        await expect(helper).toBeVisible();

        // The helper is moved into the label rendered by core: a change of the
        // core markup would leave it at the top of the plugin block.
        const label = await helper.evaluate(
            (node) => node.parentElement?.matches('label.form-label') ? node.parentElement.textContent : null,
        );
        expect(label).toContain('Email followup');

        await helper.hover();
        await expect(page.getByRole('tooltip')).toContainText(
            'Actors found only by disabled sources are added without email followup.',
        );
    });
});
