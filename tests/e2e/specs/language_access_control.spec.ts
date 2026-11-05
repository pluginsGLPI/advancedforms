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
import type { Browser, Page } from '@playwright/test';
import { test, expect } from '../fixtures/advancedforms_fixture';
import { FormPage } from '../../../../../tests/e2e/pages/FormPage';
import { LoginPage } from '../../../../../tests/e2e/pages/LoginPage';
import type { Api } from '../../../../../tests/e2e/utils/Api';
import { Profiles } from '../../../../../tests/e2e/utils/Profiles';
import {
    getWorkerEntityId,
    getWorkerLogin,
    getWorkerUserId,
} from '../../../../../tests/e2e/utils/WorkerEntities';

const POLICY = 'Allow specific languages';
const ALLOW_LIST = 'Allow specifics users, groups or profiles';
const EMPTY_WARNING = 'No language is selected in the "Allow specific languages" policy: it denies access to everyone.';

/** Create an active form without any access policy and open its access controls tab. */
async function createFormAndOpenAccessControls(
    form: FormPage,
    api: Api,
    name: string,
): Promise<number> {
    const form_id = await api.createItem('Glpi\\Form\\Form', {
        'name': name,
        'is_active': true,
        '_init_access_policies': false,
        'entities_id': getWorkerEntityId(),
    });

    const tab = 'Glpi\\Form\\AccessControl\\FormAccessControl$1';
    await form.page.goto(`/front/form/form.form.php?id=${form_id}&forcetab=${tab}`);

    return form_id;
}

async function activatePolicy(form: FormPage, name: string): Promise<void> {
    const active = form.getRegion(name).getByRole('checkbox', { name: 'Active', exact: true });
    await expect(active).not.toBeChecked();
    await active.click();
}

async function selectLanguages(form: FormPage, languages: string[]): Promise<void> {
    for (const language of languages) {
        await form.doSetDropdownValue(form.getDropdownByLabel(POLICY), language, true, false);
    }
}

async function saveAccessControls(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Save changes' }).click();
}

/**
 * Log in again as the worker user, with the given language, in a session of
 * its own: the language of a session is read when it is created, and a
 * separate browser context keeps the session of the worker untouched.
 * Self-Service is used as form administrators bypass access policies.
 */
async function openSessionWithLanguage(browser: Browser, api: Api, language: string): Promise<Page> {
    await api.updateItem('User', getWorkerUserId(), { 'language': language });

    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();

    const login_page = new LoginPage(page);
    await login_page.goto();
    await login_page.doLogin(getWorkerLogin(), getWorkerLogin());
    await page.goto('/');

    // Labels are translated, so the profile is changed without the menu.
    const csrf_token = await page.evaluate(
        () => document
            .querySelector('meta[property="glpi:csrf_token"]')
            ?.getAttribute('content') ?? ''
    );
    const response = await page.request.post('/Session/ChangeProfile', {
        form: { id: Profiles.SelfService },
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': csrf_token,
        },
    });
    expect(response.ok()).toBe(true);

    return page;
}

/** Whether the form is listed in the service catalog, and the HTTP status of its direct URL. */
async function getFormAccess(
    page: Page,
    form_id: number,
    form_name: string,
): Promise<{ in_catalog: boolean, render_status: number | undefined }> {
    // Query the catalog items directly: the search box label is translated.
    const items = await page.request.get('/ServiceCatalog/Items', {
        params: { filter: form_name },
    });
    expect(items.ok()).toBe(true);
    const in_catalog = (await items.text()).includes(form_name);

    const response = await page.goto(`/Form/Render/${form_id}`);
    await page.context().close();

    return { in_catalog, render_status: response?.status() };
}

test.describe('Advanced forms - Language access control', () => {
    // The language of the worker user is shared by every test of this worker.
    test.afterEach(async ({ profile, api }) => {
        await profile.set(Profiles.SuperAdmin);
        await api.updateItem('User', getWorkerUserId(), { 'language': '' });
    });

    test('can configure the policy and warns when no language is selected', async ({
        page,
        profile,
        api,
    }) => {
        await profile.set(Profiles.SuperAdmin);
        const form = new FormPage(page);
        await createFormAndOpenAccessControls(form, api, `[Tests] Language policy ${randomUUID()}`);

        // Active without any language: the form is closed to everyone, say so.
        await activatePolicy(form, POLICY);
        await saveAccessControls(page);
        await expect(page.getByRole('alert').filter({ hasText: EMPTY_WARNING })).toBeVisible();

        // Select several languages and save.
        await selectLanguages(form, ['English', 'Deutsch']);
        await saveAccessControls(page);

        await expect(
            form.getRegion(POLICY).getByRole('checkbox', { name: 'Active', exact: true })
        ).toBeChecked();
        const dropdown = form.getDropdownByLabel(POLICY);
        await expect(dropdown).toContainText('English');
        await expect(dropdown).toContainText('Deutsch');
        await expect(page.getByRole('alert').filter({ hasText: EMPTY_WARNING })).toHaveCount(0);
    });

    test('"All users" + languages only lets in users with a listed language', async ({
        page,
        browser,
        profile,
        api,
    }) => {
        await profile.set(Profiles.SuperAdmin);
        const form = new FormPage(page);
        const form_name = `[Tests] Language policy ${randomUUID()}`;
        const form_id = await createFormAndOpenAccessControls(form, api, form_name);

        await activatePolicy(form, ALLOW_LIST);
        await form.doSetDropdownValue(form.getDropdownByLabel(ALLOW_LIST), 'All users');
        await activatePolicy(form, POLICY);
        await selectLanguages(form, ['English', 'Deutsch']);
        await saveAccessControls(page);
        await expect(form.getDropdownByLabel(POLICY)).toContainText('English');

        // Listed language: visible in the catalog and can be opened.
        const english = await openSessionWithLanguage(browser, api, 'en_GB');
        expect(await getFormAccess(english, form_id, form_name))
            .toEqual({ in_catalog: true, render_status: 200 });

        // Unlisted language: hidden from the catalog and the direct URL is refused,
        // although "All users" grants access.
        const french = await openSessionWithLanguage(browser, api, 'fr_FR');
        expect(await getFormAccess(french, form_id, form_name))
            .toEqual({ in_catalog: false, render_status: 403 });
    });
});
