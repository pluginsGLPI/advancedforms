<?php

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

namespace GlpiPlugin\Advancedforms\Model\Destination\Email;

use CommonDBTM;
use Glpi\Form\Destination\FormDestination;

/**
 * The core only lets the fields of a destination prepare their input
 * (see `FormDestination::prepareInput()`) for ITIL destinations.
 * The email destination fields need it too: the recipients field converts the
 * actors submitted by the dropdown to their stored format and the subject
 * field removes its HTML.
 */
final class EmailDestinationConfigNormalizer
{
    /** `pre_item_add` and `pre_item_update` handler on {@see FormDestination}. */
    public static function onPreSave(CommonDBTM $item): void
    {
        if (!$item instanceof FormDestination || !is_array($item->input)) {
            return;
        }

        $itemtype = $item->input['itemtype'] ?? $item->fields['itemtype'] ?? null;
        $config   = $item->input['config'] ?? null;
        if (
            $itemtype !== EmailDestination::class
            || !is_array($config)
            || ($item->input['_from_import'] ?? false)
        ) {
            return;
        }

        foreach ((new EmailDestination())->getConfigurableFields() as $field) {
            $config = $field->prepareInput($config);
        }

        $item->input['config'] = $config;
    }
}
