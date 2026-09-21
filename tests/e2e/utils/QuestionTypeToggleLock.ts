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

import { mkdirSync, rmdirSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';

const LOCK_PATH = join(tmpdir(), 'advancedforms-e2e-question-type-toggle.lock');

// Tracks nested acquisitions within this worker process, so a test that wraps its
// whole body and also calls a helper that locks its own sub-step doesn't deadlock
// waiting on a lock it already holds itself.
let hold_count = 0;

/**
 * Runs `fn` while holding an exclusive lock shared by every Playwright worker process.
 */
export async function withQuestionTypeToggleLock<T>(fn: () => Promise<T>): Promise<T> {
    if (hold_count === 0) {
        for (;;) {
            try {
                mkdirSync(LOCK_PATH);
                break;
            } catch (error) {
                if ((error as NodeJS.ErrnoException).code !== 'EEXIST') {
                    throw error;
                }
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        }
    }
    hold_count++;

    try {
        return await fn();
    } finally {
        hold_count--;
        if (hold_count === 0) {
            rmdirSync(LOCK_PATH);
        }
    }
}
