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

use CommonITILObject;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use Ticket;

/** Injects `TicketReservationRequest` entries into a `Ticket`'s timeline (registered against `Hooks::TIMELINE_ITEMS`). */
final class TimelineManager
{
    /**
     * @param array{item: object, timeline: array<string, array<string, mixed>>} $params
     *        `$params['timeline']` holds a real PHP reference to the caller's array (see
     *        `CommonITILObject::getTimelineItems()`), which survives being passed here by
     *        value — do not declare this `array &$params`, `Plugin::doHook()` calls it via
     *        `call_user_func()`, which warns on by-ref params.
     */
    public static function addTimelineItems(array $params): void
    {
        $ticket = $params['item'] ?? null;
        if (!$ticket instanceof Ticket) {
            return;
        }

        $timeline = &$params['timeline'];

        $request = new TicketReservationRequest();
        $rows = array_filter($request->find(['tickets_id' => $ticket->getID()]), is_array(...));
        if ($rows === []) {
            return;
        }

        // A ticket can carry many requests: names and rights are resolved once for all of them.
        $equipment_names = TicketReservationRequest::getReservableItemNames(array_map(
            fn(array $row): int => is_numeric($row['reservationitems_id'] ?? null) ? (int) $row['reservationitems_id'] : 0,
            $rows,
        ));
        $can_update_ticket = null;
        $actions_script_added = false;

        foreach ($rows as $row) {
            // find() already returns the full row; hydrate in place, no extra query.
            $request->getFromResultSet($row);

            $reservationitems_id = $request->fields['reservationitems_id'] ?? 0;
            $reservationitems_id = is_numeric($reservationitems_id) ? (int) $reservationitems_id : 0;

            $status = $request->fields['status'] ?? 0;
            if ($can_update_ticket === null && is_numeric($status) && (int) $status === TicketReservationRequest::STATUS_WAITING) {
                $can_update_ticket = $ticket->canUpdateItem();
            }

            $info = $request->getTimelineInfo($can_update_ticket ?? false);
            $content = TemplateRenderer::getInstance()->render('@advancedforms/timeline/reservation_request.html.twig', [
                'request' => $info,
                'equipment_name' => $equipment_names[$reservationitems_id] ?? '',
                // The script handles the buttons of every request of the page, one copy is enough.
                'include_actions_script' => $info['can_answer'] && !$actions_script_added,
            ]);
            $actions_script_added = $actions_script_added || $info['can_answer'];

            $date_creation = $request->fields['date_creation'] ?? null;
            $users_id = $request->fields['users_id'] ?? 0;
            $users_id = is_numeric($users_id) ? (int) $users_id : 0;

            $timeline['TicketReservationRequest_' . $request->getID()] = [
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
}
