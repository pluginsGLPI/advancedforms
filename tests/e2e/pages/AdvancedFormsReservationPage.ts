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
 * Page helpers for the "Material reservation" question type: enabling it,
 * toggling its calendar, and interacting with the rendered end-user widget
 * (item picker, plain date fields and the FullCalendar-based slot picker).
 */
export class AdvancedFormsReservationPage extends GlpiPage {
    private static readonly CONFIG_TAB =
        'GlpiPlugin\\Advancedforms\\Model\\Config\\ConfigTab$1';

    public constructor(page: Page) {
        super(page);
    }

    /**
     * Enables the Material reservation question type from the plugin configuration
     * page, disabling every other question type the plugin provides.
     *
     * With several of the plugin's question types enabled at once, their shared
     * category collapses to a generic "Advanced" group in the form editor's type
     * dropdown instead of exposing this type's own name directly, which is what
     * the rest of this test suite selects by name.
     */
    public async enableReservationQuestionType(): Promise<void> {
        await this.page.goto(
            `/front/config.form.php?forcetab=${AdvancedFormsReservationPage.CONFIG_TAB}`,
        );

        const card = this.page
            .locator('[data-testid^="feature-"]')
            .filter({ hasText: 'Material reservation question type' });
        const toggle = card.getByTestId('feature-toggle');
        const own_name = await toggle.getAttribute('name');

        let changed = false;
        for (const other_toggle of await this.page.getByTestId('feature-toggle').all()) {
            if (await other_toggle.getAttribute('name') === own_name) {
                continue;
            }
            if (await other_toggle.isChecked()) {
                await other_toggle.uncheck();
                changed = true;
            }
        }
        if (!(await toggle.isChecked())) {
            await toggle.check();
            changed = true;
        }

        if (changed) {
            await this.getButton('Save').click();
            await expect(card.getByTestId('feature-toggle')).toBeChecked();
        }
    }

    /** The "Show availability calendar" toggle in the (already open) question editor. */
    public getShowCalendarToggle(question: Locator): Locator {
        return question.getByRole('checkbox', { name: 'Show availability calendar' });
    }

    /** The rendered end-user widget container. */
    public getEndUserWidget(): Locator {
        // eslint-disable-next-line playwright/no-raw-locators
        return this.page.locator('[data-reservation-question-widget]');
    }

    private getItemDropdown(widget: Locator): Locator {
        // eslint-disable-next-line playwright/no-raw-locators
        return widget
            .locator('[data-reservation-question-item-select]')
            .locator('+ span')
            .getByRole('combobox');
    }

    /** Searches and selects a reservable item in the widget's select2 (AJAX-backed, grouped by itemtype). */
    public async selectReservableItem(widget: Locator, item_name: string): Promise<void> {
        await this.doSearchAndClickDropdownValue(this.getItemDropdown(widget), item_name, false);
    }

    public getBeginInput(widget: Locator): Locator {
        // eslint-disable-next-line playwright/no-raw-locators
        return widget.locator('[data-reservation-question-begin-picker]');
    }

    public getEndInput(widget: Locator): Locator {
        // eslint-disable-next-line playwright/no-raw-locators
        return widget.locator('[data-reservation-question-end-picker]');
    }

    public getAvailabilityMessage(widget: Locator): Locator {
        // eslint-disable-next-line playwright/no-raw-locators
        return widget.locator('[data-reservation-question-availability]');
    }

    /** The calendar container. Absent entirely when the question is configured without it. */
    public getCalendar(widget: Locator): Locator {
        // eslint-disable-next-line playwright/no-raw-locators
        return widget.locator('[data-reservation-question-calendar]');
    }

    /** Waits for the FullCalendar week/day view to be fully rendered. */
    public async waitForCalendar(widget: Locator): Promise<void> {
        // eslint-disable-next-line playwright/no-raw-locators
        await this.getCalendar(widget).locator('.fc-header-toolbar').waitFor({ state: 'visible' });
    }

    /**
     * Moves the calendar forward by one full week, so every visible day column is
     * safely in the future regardless of which day of the week the test runs on.
     */
    public async goToNextCalendarWeek(widget: Locator): Promise<void> {
        await this.getCalendar(widget).getByRole('button', { name: 'next', exact: true }).click();
    }

    /** ISO date (YYYY-MM-DD) of the first day column currently visible on the calendar. */
    public async getFirstVisibleCalendarDate(widget: Locator): Promise<string> {
        const date = await this.getCalendar(widget)
            // eslint-disable-next-line playwright/no-raw-locators
            .locator('th.fc-day-header')
            .first()
            .getAttribute('data-date');
        if (date === null) {
            throw new Error('Could not read the visible calendar date');
        }
        return date;
    }

    /**
     * Drags a selection on the calendar from `start_time` to `end_time` (both
     * "HH:MM:SS", matching FullCalendar's own slot label format) on the first day
     * column currently visible. The resulting picked range is mirrored into the
     * widget's begin/end date fields, exactly as a real user's mouse drag would.
     */
    public async selectCalendarSlot(widget: Locator, start_time: string, end_time: string): Promise<void> {
        const calendar = this.getCalendar(widget);
        // eslint-disable-next-line playwright/no-raw-locators
        const day_header = calendar.locator('th.fc-day-header').first();
        // eslint-disable-next-line playwright/no-raw-locators
        const start_row = calendar.locator(`tr[data-time="${start_time}"]`).first();
        // eslint-disable-next-line playwright/no-raw-locators
        const end_row = calendar.locator(`tr[data-time="${end_time}"]`).first();

        // The time axis is scrolled independently from the page: bring both ends of
        // the slot into view before reading bounding boxes for the drag coordinates.
        // Scrolling the start row alone can leave the end row clipped below the
        // calendar's own scroll container when the slot sits later in the day.
        await start_row.scrollIntoViewIfNeeded();
        await end_row.scrollIntoViewIfNeeded();

        const day_box = await day_header.boundingBox();
        const start_box = await start_row.boundingBox();
        const end_box = await end_row.boundingBox();
        if (day_box === null || start_box === null || end_box === null) {
            throw new Error('Could not locate the calendar day column or time rows to drag a selection');
        }

        const x = day_box.x + day_box.width / 2;
        await this.page.mouse.move(x, start_box.y + 3);
        await this.page.mouse.down();
        await this.page.mouse.move(x, end_box.y + 3, { steps: 5 });
        await this.page.mouse.up();
    }

    /**
     * The persisted selection, rendered by FullCalendar's `selectMirror` as a
     * real-looking event once the drag is released (the widget deliberately does
     * not call `unselect()`, so the user can see what they just picked).
     */
    public getCalendarSelectionMirror(widget: Locator): Locator {
        // eslint-disable-next-line playwright/no-raw-locators
        return this.getCalendar(widget).locator('.fc-mirror-container .fc-event');
    }

    /** Existing reservations rendered as busy blocks on the calendar. */
    public getCalendarBusyEvents(widget: Locator): Locator {
        // eslint-disable-next-line playwright/no-raw-locators
        return this.getCalendar(widget).locator('.fc-event').filter({ hasText: 'Reserved' });
    }
}
