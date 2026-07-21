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

namespace GlpiPlugin\Advancedforms\Service;

use CommonDBTM;
use CommonITILObject;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use ReservationItem;
use Ticket;

/**
 * Injects `TicketReservationRequest` entries into a `Ticket`'s timeline.
 *
 * Registered against `Hooks::TIMELINE_ITEMS` by `InitManager::registerTimelineHooks()`.
 */
final class TimelineManager
{
    /**
     * @param array{item: object, timeline: array<string, array<string, mixed>>} $params
     *        Called by core's `Plugin::doHook(Hooks::TIMELINE_ITEMS, ['item' => $this, 'timeline' => &$timeline])`
     *        (see `CommonITILObject::getTimelineItems()`). The 'timeline' entry
     *        of the array holds a genuine PHP reference to the caller's
     *        `$timeline` variable: mutating `$params['timeline'][...]` here
     *        is visible to the caller even though `$params` itself is passed
     *        by value (a copied array preserves references held by its
     *        elements). The parameter is therefore intentionally NOT declared
     *        `array &$params`: `Plugin::doHook()` invokes this callback via
     *        `call_user_func()`, which cannot pass its argument by reference
     *        to a callee declaring a by-reference parameter (it triggers a
     *        PHP warning).
     */
    public static function addTimelineItems(array $params): void
    {
        $ticket = $params['item'] ?? null;
        if (!$ticket instanceof Ticket) {
            return;
        }

        $timeline = &$params['timeline'];

        $request = new TicketReservationRequest();

        // Only the ids are read off `find()`'s raw rows: their static return
        // type is a generic (unshaped) `array`, so anything read from a row
        // is reported as `mixed` by phpstan. All actual field values are
        // instead read back from `$request->fields` after `getFromDB()`.
        $ids = array_keys($request->find(['tickets_id' => $ticket->getID()]));

        foreach ($ids as $id) {
            if (!$request->getFromDB($id)) {
                continue;
            }

            $reservationitems_id = $request->fields['reservationitems_id'] ?? 0;
            $reservationitems_id = is_numeric($reservationitems_id) ? (int) $reservationitems_id : 0;

            $content = TemplateRenderer::getInstance()->render('@advancedforms/timeline/reservation_request.html.twig', [
                'request' => $request->getTimelineInfo(),
                'equipment_name' => self::getEquipmentName($reservationitems_id),
            ]);

            $date_creation = $request->fields['date_creation'] ?? null;
            $users_id = $request->fields['users_id'] ?? 0;
            $users_id = is_numeric($users_id) ? (int) $users_id : 0;

            $timeline["TicketReservationRequest_{$request->getID()}"] = [
                'type' => TicketReservationRequest::class,
                'item' => [
                    'id' => $request->getID(),
                    'content' => $content,
                    'is_content_safe' => true,
                    'date' => is_string($date_creation) ? $date_creation : null,
                    'users_id' => $users_id,
                    'can_edit' => false,
                    'timeline_position' => CommonITILObject::TIMELINE_LEFT,
                ],
                'object' => clone $request,
            ];
        }
    }

    /**
     * Resolve a human-readable name for the reservable asset behind a
     * `ReservationItem`, e.g. "Laptop #42". Returns an empty string if the
     * reservation item or its underlying asset can no longer be found.
     */
    private static function getEquipmentName(int $reservationitems_id): string
    {
        $reservation_item = new ReservationItem();
        if (!$reservation_item->getFromDB($reservationitems_id)) {
            return '';
        }

        $itemtype = $reservation_item->fields['itemtype'] ?? '';
        $itemtype = is_string($itemtype) ? $itemtype : '';

        $items_id = $reservation_item->fields['items_id'] ?? 0;
        $items_id = is_numeric($items_id) ? (int) $items_id : 0;

        $item = getItemForItemtype($itemtype);
        if (!$item instanceof CommonDBTM || !$item->getFromDB($items_id)) {
            return '';
        }

        return $item->getName();
    }
}
