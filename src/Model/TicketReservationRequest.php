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

namespace GlpiPlugin\Advancedforms\Model;

use CommonDBChild;
use NotificationEvent;
use Override;
use Reservation;
use Ticket;

/**
 * A request, made from a ticket, to reserve a reservable item (asset) for a
 * given timeframe. It is created by the "reservation" question type's
 * destination field and must be approved or refused by a ticket actor before
 * an actual `Reservation` is created.
 */
final class TicketReservationRequest extends CommonDBChild
{
    public static $itemtype = 'Ticket';

    public static $items_id = 'tickets_id';

    public static $rightname = 'ticket';

    public const STATUS_WAITING  = 1;

    public const STATUS_ACCEPTED = 2;

    public const STATUS_REFUSED  = 3;

    public const STATUS_CANCELED = 4;

    #[Override]
    public static function getTable($classname = null): string
    {
        // The default table name computation (based on the fully qualified
        // class name) would produce `glpi_plugin_advancedforms_models_ticketreservationrequests`
        // because of the `Model` sub-namespace. The table must be forced to
        // its expected name instead.
        return 'glpi_plugin_advancedforms_ticketreservationrequests';
    }

    #[Override]
    public static function getTypeName($nb = 0): string
    {
        return _n('Reservation request', 'Reservation requests', $nb, 'advancedforms');
    }

    #[Override]
    public static function getIcon(): string
    {
        return 'ti ti-calendar-event';
    }

    #[Override]
    public function post_addItem(): void
    {
        parent::post_addItem();

        // Only notify ticket actors that a new request needs an answer when
        // the request is actually created in a waiting state. A request
        // created already accepted (e.g. a future "direct reservation, no
        // approval needed" path) has nothing to approve/refuse, so it must
        // not raise this event.
        //
        // A caller can also explicitly opt out of this notification (via the
        // standard `_disablenotif` input key, see `CommonDBTM`/`Ticket`/etc.)
        // even while inserting the row in the WAITING state: this is used by
        // `PreReservationField` for its "no approval required" (direct
        // reservation) path, where the row is inserted as WAITING only as a
        // transient step before immediately transitioning it to its real
        // final state (ACCEPTED/CANCELED) via `approve()`/`markUnavailable()`,
        // which each raise their own distinct event. Without this, the
        // "please wait for approval" notification would fire for something
        // that never actually waits for approval.
        if (
            (int) $this->fields['status'] === self::STATUS_WAITING
            && !isset($this->input['_disablenotif'])
        ) {
            NotificationEvent::raiseEvent('reservation_request_created', $this);
        }
    }

    /**
     * Whether the requested reservation item is still free for this
     * request's timeframe, i.e. no existing `Reservation` overlaps it.
     *
     * Mirrors core's `Reservation::is_reserved()` conflict detection exactly
     * (strict inequalities: `end > begin AND begin < end`).
     */
    public function isSlotStillAvailable(): bool
    {
        global $DB;

        $conflicts = $DB->request([
            'COUNT' => 'cpt',
            'FROM' => Reservation::getTable(),
            'WHERE' => [
                'reservationitems_id' => $this->fields['reservationitems_id'],
                'end' => ['>', $this->fields['begin']],
                'begin' => ['<', $this->fields['end']],
            ],
        ])->current();

        return ((int) $conflicts['cpt']) === 0;
    }

    /**
     * Approve this request: create the actual `Reservation` (attributed to
     * the original requester, not the validator) and mark this request as
     * accepted.
     *
     * @param int    $users_id_validate Id of the user validating the request
     * @param string $comment           Optional comment from the validator
     *
     * @return bool False if the `Reservation` could not be created (for
     *              instance because the slot is no longer available); in
     *              that case, this request is left untouched.
     */
    public function approve(int $users_id_validate, string $comment = ''): bool
    {
        $reservation = new Reservation();
        $created = $reservation->add([
            'reservationitems_id' => $this->fields['reservationitems_id'],
            'begin' => $this->fields['begin'],
            'end' => $this->fields['end'],
            'users_id' => $this->fields['users_id'], // original requester, not the validator
            'comment' => $this->fields['comment_submission'] ?? '',
        ]);

        if (!$created) {
            return false;
        }

        $updated = $this->update([
            'id' => $this->getID(),
            'status' => self::STATUS_ACCEPTED,
            'users_id_validate' => $users_id_validate,
            'comment_validation' => $comment,
            'validation_date' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);

        if ($updated) {
            NotificationEvent::raiseEvent('reservation_request_approved', $this);
        }

        return $updated;
    }

    /**
     * Refuse this request. No `Reservation` is created.
     *
     * @param int    $users_id_validate Id of the user refusing the request
     * @param string $comment           Optional comment from the validator
     */
    public function refuse(int $users_id_validate, string $comment = ''): bool
    {
        $updated = $this->update([
            'id' => $this->getID(),
            'status' => self::STATUS_REFUSED,
            'users_id_validate' => $users_id_validate,
            'comment_validation' => $comment,
            'validation_date' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);

        if ($updated) {
            NotificationEvent::raiseEvent('reservation_request_refused', $this);
        }

        return $updated;
    }

    /**
     * Mark this request as no longer available (e.g. the reservable item was
     * removed or deactivated).
     */
    public function markUnavailable(): bool
    {
        $updated = $this->update([
            'id' => $this->getID(),
            'status' => self::STATUS_CANCELED,
        ]);

        if ($updated) {
            NotificationEvent::raiseEvent('reservation_request_slot_unavailable', $this);
        }

        return $updated;
    }

    /**
     * Data needed to render this request in a ticket's timeline.
     *
     * @return array{
     *     id: int,
     *     status: int,
     *     reservationitems_id: int,
     *     begin: string|null,
     *     end: string|null,
     *     comment_validation: string,
     *     can_answer: bool,
     *     is_direct_reservation: bool,
     * }
     */
    public function getTimelineInfo(): array
    {
        return [
            'id' => $this->getID(),
            'status' => (int) $this->fields['status'],
            'reservationitems_id' => (int) $this->fields['reservationitems_id'],
            'begin' => $this->fields['begin'],
            'end' => $this->fields['end'],
            'comment_validation' => $this->fields['comment_validation'] ?? '',
            'can_answer' => $this->canAnswer(),
            'is_direct_reservation' => (int) $this->fields['status'] === self::STATUS_ACCEPTED
                && (int) $this->fields['users_id_validate'] === 0,
        ];
    }

    /**
     * Whether the current session's user may approve/refuse this request:
     * it must still be waiting, and the user must be able to update the
     * parent ticket.
     */
    public function canAnswer(): bool
    {
        if ((int) $this->fields['status'] !== self::STATUS_WAITING) {
            return false;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($this->fields['tickets_id'])) {
            return false;
        }

        return $ticket->canUpdateItem();
    }
}
