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
use CommonDBTM;
use NotificationEvent;
use Override;
use Reservation;
use ReservationItem;
use Ticket;

use function getItemForItemtype;

/** A ticket-driven request to reserve an equipment item for a timeframe. */
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

        if (
            $this->getIntField('status') === self::STATUS_WAITING
            && !isset($this->input['_disablenotif'])
        ) {
            NotificationEvent::raiseEvent('reservation_request_created', $this);
        }
    }

    /**
     * Whether no existing Reservation overlaps the given slot for the item.
     * Single source of truth for the overlap check, mirroring core's
     * Reservation::is_reserved() (strict inequalities, back-to-back slots allowed).
     */
    public static function isSlotFree(int $reservationitems_id, string $begin, string $end): bool
    {
        global $DB;

        $conflicts = $DB->request([
            'COUNT' => 'cpt',
            'FROM' => Reservation::getTable(),
            'WHERE' => [
                'reservationitems_id' => $reservationitems_id,
                'end' => ['>', $begin],
                'begin' => ['<', $end],
            ],
        ])->current();

        $count = is_array($conflicts) && is_numeric($conflicts['cpt'] ?? null)
            ? (int) $conflicts['cpt']
            : 0;

        return $count === 0;
    }

    public function isSlotStillAvailable(): bool
    {
        return self::isSlotFree(
            $this->getIntField('reservationitems_id'),
            $this->getNullableStringField('begin') ?? '',
            $this->getNullableStringField('end') ?? '',
        );
    }

    /** Creates the Reservation for the original requester and accepts this request; returns false, untouched, if the slot is no longer available. */
    public function approve(int $users_id_validate, string $comment = ''): bool
    {
        $reservation = new Reservation();
        $created = $reservation->add([
            'reservationitems_id' => $this->fields['reservationitems_id'],
            'begin' => $this->fields['begin'],
            'end' => $this->fields['end'],
            'users_id' => $this->fields['users_id'],
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
            // Keep a link back to the created Reservation for traceability and cleanup.
            'reservations_id' => $reservation->getID(),
            'validation_date' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);

        if ($updated) {
            NotificationEvent::raiseEvent('reservation_request_approved', $this);
        }

        return $updated;
    }

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

    /** Removes the Reservation created on approval so it never outlives its request. */
    #[Override]
    public function pre_deleteItem(): bool
    {
        $reservations_id = $this->getIntField('reservations_id');
        if ($reservations_id > 0) {
            $reservation = new Reservation();
            if ($reservation->getFromDB($reservations_id)) {
                $reservation->delete(['id' => $reservations_id]);
            }
        }

        return parent::pre_deleteItem();
    }

    /** Marks this request canceled (slot no longer available, e.g. taken during a direct reservation). */
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
            'status' => $this->getIntField('status'),
            'reservationitems_id' => $this->getIntField('reservationitems_id'),
            'begin' => $this->getNullableStringField('begin'),
            'end' => $this->getNullableStringField('end'),
            'comment_validation' => $this->getNullableStringField('comment_validation') ?? '',
            'can_answer' => $this->canAnswer(),
            'is_direct_reservation' => $this->getIntField('status') === self::STATUS_ACCEPTED
                && $this->getIntField('users_id_validate') === 0,
        ];
    }

    /** Resolves the reservable asset's display name; empty string if it no longer exists. */
    public static function getReservableItemName(int $reservationitems_id): string
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

    /** Whether the current user may approve/refuse this still-waiting request. */
    public function canAnswer(): bool
    {
        if ($this->getIntField('status') !== self::STATUS_WAITING) {
            return false;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($this->getIntField('tickets_id'))) {
            return false;
        }

        return $ticket->canUpdateItem();
    }

    private function getIntField(string $field): int
    {
        $value = $this->fields[$field] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function getNullableStringField(string $field): ?string
    {
        $value = $this->fields[$field] ?? null;

        return is_string($value) ? $value : null;
    }
}
