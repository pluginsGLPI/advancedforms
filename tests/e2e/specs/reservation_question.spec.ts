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
import { getWorkerEntityId, getWorkerUserId } from '../../../../../tests/e2e/utils/WorkerEntities';
import { AdvancedFormsReservationPage } from '../pages/AdvancedFormsReservationPage';
import { withQuestionTypeToggleLock } from '../utils/QuestionTypeToggleLock';

/**
 * Builds a form with a single Material reservation question, backed by one
 * reservable Computer, through the editor. Returns the ids needed to interact
 * with it as an end user and to create reservations on it directly via the API.
 */
async function createFormWithReservationQuestion(
    page: Page,
    api: { createItem(itemtype: string, fields: object): Promise<number> },
    form_name: string,
): Promise<{ form_id: number; reservable_item_name: string; reservationitems_id: number }> {
    const form = new FormPage(page);
    const reservation = new AdvancedFormsReservationPage(page);

    await reservation.enableReservationQuestionType();

    const reservable_item_name = `E2E reservable computer ${randomUUID()}`;
    const computer_id = await api.createItem('Computer', {
        name: reservable_item_name,
        entities_id: getWorkerEntityId(),
    });
    const reservationitems_id = await api.createItem('ReservationItem', {
        itemtype: 'Computer',
        items_id: computer_id,
    });

    const form_id = await api.createItem('Glpi\\Form\\Form', {
        name: form_name,
        entities_id: getWorkerEntityId(),
    });
    await form.goto(form_id);

    const question = await form.addQuestion('Vehicle');
    await form.doChangeQuestionType(question, 'Material reservation');

    await form.doSaveFormEditor();

    return { form_id, reservable_item_name, reservationitems_id };
}

test.describe('Advanced forms - Material reservation question', () => {
    test.describe.configure({ timeout: 180_000 });
    test('end user can select an item and pick a slot on the calendar, then submit', async ({ page, profile, api }) => {
        await withQuestionTypeToggleLock(async () => {
            await profile.set(Profiles.SuperAdmin);
            const form = new FormPage(page);
            const reservation = new AdvancedFormsReservationPage(page);
            const form_name = `E2E reservation calendar - ${randomUUID()}`;

            const { reservable_item_name } = await createFormWithReservationQuestion(page, api, form_name);
            await form.doPreviewForm();

            const widget = reservation.getEndUserWidget();
            await reservation.selectReservableItem(widget, reservable_item_name);
            await reservation.waitForCalendar(widget);

            // A full week ahead so the picked slot is never in the past, whichever
            // day of the week this test happens to run on.
            await reservation.goToNextCalendarWeek(widget);
            await reservation.selectCalendarSlot(widget, '09:00:00', '11:00:00');

            // The drag is mirrored into the plain date fields the rest of the widget relies on.
            await expect(reservation.getBeginInput(widget)).not.toHaveValue('');
            await expect(reservation.getEndInput(widget)).not.toHaveValue('');
            await expect(reservation.getAvailabilityMessage(widget)).toHaveText('This slot is available');

            await page.getByRole('button', { name: 'Submit' }).click();
            await expect(page.getByRole('link', { name: form_name })).toBeVisible();
        });
    });

    /**
     * Regression test: the widget used to call `unselect()` right after mirroring
     * the drag into the date fields, which cleared the highlight the instant the
     * user released the mouse, with no visible confirmation of what was picked.
     */
    test('the calendar selection stays highlighted after picking a slot', async ({ page, profile, api }) => {
        await withQuestionTypeToggleLock(async () => {
            await profile.set(Profiles.SuperAdmin);
            const form = new FormPage(page);
            const reservation = new AdvancedFormsReservationPage(page);

            const { reservable_item_name } = await createFormWithReservationQuestion(
                page,
                api,
                `E2E reservation highlight - ${randomUUID()}`,
            );
            await form.doPreviewForm();

            const widget = reservation.getEndUserWidget();
            await reservation.selectReservableItem(widget, reservable_item_name);
            await reservation.waitForCalendar(widget);
            await reservation.goToNextCalendarWeek(widget);
            await reservation.selectCalendarSlot(widget, '09:00:00', '11:00:00');

            await expect(reservation.getCalendarSelectionMirror(widget)).toBeVisible();
        });
    });

    test('an existing reservation blocks selecting an overlapping slot on the calendar', async ({ page, profile, api }) => {
        await withQuestionTypeToggleLock(async () => {
            await profile.set(Profiles.SuperAdmin);
            const form = new FormPage(page);
            const reservation = new AdvancedFormsReservationPage(page);

            const { reservable_item_name, reservationitems_id } = await createFormWithReservationQuestion(
                page,
                api,
                `E2E reservation overlap - ${randomUUID()}`,
            );
            await form.doPreviewForm();

            // Find out which date the calendar will show a week from now, so the
            // reservation created below lands exactly on a day the widget can see.
            let widget = reservation.getEndUserWidget();
            await reservation.selectReservableItem(widget, reservable_item_name);
            await reservation.waitForCalendar(widget);
            await reservation.goToNextCalendarWeek(widget);
            const target_date = await reservation.getFirstVisibleCalendarDate(widget);

            await api.createItem('Reservation', {
                reservationitems_id,
                begin: `${target_date} 10:00:00`,
                end: `${target_date} 12:00:00`,
                users_id: getWorkerUserId(),
            });

            // Reload so the widget's calendar fetches the reservation just created.
            await page.reload();
            widget = reservation.getEndUserWidget();
            await reservation.selectReservableItem(widget, reservable_item_name);
            await reservation.waitForCalendar(widget);
            await reservation.goToNextCalendarWeek(widget);

            await expect(reservation.getCalendarBusyEvents(widget)).toBeVisible();

            // Dragging across the busy slot is rejected outright: no selection is made.
            await reservation.selectCalendarSlot(widget, '10:00:00', '12:00:00');
            await expect(reservation.getBeginInput(widget)).toHaveValue('');
            await expect(reservation.getEndInput(widget)).toHaveValue('');

            // A free slot right after the busy one still works.
            await reservation.selectCalendarSlot(widget, '13:00:00', '14:00:00');
            await expect(reservation.getBeginInput(widget)).not.toHaveValue('');
            await expect(reservation.getAvailabilityMessage(widget)).toHaveText('This slot is available');
        });
    });

    test('disabling the calendar removes it from the end-user form', async ({ page, profile, api }) => {
        await withQuestionTypeToggleLock(async () => {
            await profile.set(Profiles.SuperAdmin);
            const form = new FormPage(page);
            const reservation = new AdvancedFormsReservationPage(page);
            const form_name = `E2E reservation no calendar - ${randomUUID()}`;

            const { form_id, reservable_item_name } = await createFormWithReservationQuestion(page, api, form_name);

            await form.goto(form_id);
            const question = page.getByRole('region', { name: 'Question details' }).first();
            // Reopening the editor loads questions collapsed; expand it to reveal its config panel.
            await question.getByRole('textbox', { name: 'Question name' }).click();
            await reservation.getShowCalendarToggle(question).uncheck();
            await form.doSaveFormEditor();

            await form.doPreviewForm();
            const widget = reservation.getEndUserWidget();
            await reservation.selectReservableItem(widget, reservable_item_name);

            await expect(reservation.getCalendar(widget)).toHaveCount(0);
            await expect(widget.locator('.reservation-question-dates')).toBeVisible();
        });
    });
});
