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
use Session;
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
     * @param bool|null $can_update_ticket see canAnswer()
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
    public function getTimelineInfo(?bool $can_update_ticket = null): array
    {
        return [
            'id' => $this->getID(),
            'status' => $this->getIntField('status'),
            'reservationitems_id' => $this->getIntField('reservationitems_id'),
            'begin' => $this->getNullableStringField('begin'),
            'end' => $this->getNullableStringField('end'),
            'comment_validation' => $this->getNullableStringField('comment_validation') ?? '',
            'can_answer' => $this->canAnswer($can_update_ticket),
            'is_direct_reservation' => $this->getIntField('status') === self::STATUS_ACCEPTED
                && $this->getIntField('users_id_validate') === 0,
        ];
    }

    /** Resolves the reservable asset's display name; empty string if it no longer exists. */
    public static function getReservableItemName(int $reservationitems_id): string
    {
        return self::getReservableItemNames([$reservationitems_id])[$reservationitems_id] ?? '';
    }

    /**
     * Resolves the display names of several reservable assets with one query on
     * the reservation items and one per asset itemtype, whatever their number.
     *
     * @param int[] $reservationitems_ids
     * @return array<int, string> names indexed by reservation item id, without the ids whose asset no longer exists
     */
    public static function getReservableItemNames(array $reservationitems_ids): array
    {
        global $DB;

        $reservationitems_ids = array_values(array_unique(array_filter(
            $reservationitems_ids,
            fn(int $id): bool => $id > 0,
        )));
        if ($reservationitems_ids === []) {
            return [];
        }

        $reservation_items = $DB->request([
            'SELECT' => ['id', 'itemtype', 'items_id'],
            'FROM' => ReservationItem::getTable(),
            'WHERE' => ['id' => $reservationitems_ids],
        ]);

        /** @var array<string, array<int, int>> $items_ids_by_itemtype items id indexed by reservation item id, per itemtype */
        $items_ids_by_itemtype = [];
        foreach ($reservation_items as $row) {
            if (
                !is_array($row)
                || !is_numeric($row['id'] ?? null)
                || !is_string($row['itemtype'] ?? null)
                || !is_numeric($row['items_id'] ?? null)
            ) {
                continue;
            }

            $items_ids_by_itemtype[$row['itemtype']][(int) $row['id']] = (int) $row['items_id'];
        }

        $names = [];
        foreach ($items_ids_by_itemtype as $itemtype => $items_ids) {
            $item = getItemForItemtype($itemtype);
            if (!$item instanceof CommonDBTM) {
                continue;
            }

            $rows = $item->find(['id' => array_values(array_unique($items_ids))]);
            foreach ($items_ids as $reservationitems_id => $items_id) {
                if (!is_array($rows[$items_id] ?? null)) {
                    continue;
                }

                $item->getFromResultSet($rows[$items_id]);
                $names[$reservationitems_id] = $item->getName();
            }
        }

        return $names;
    }

    /**
     * Whether the current user may approve/refuse this still-waiting request.
     *
     * @param bool|null $can_update_ticket whether the current user can update the parent ticket, when the caller
     *                                     already knows it (timeline display); read from the database when null
     */
    public function canAnswer(?bool $can_update_ticket = null): bool
    {
        if ($this->getIntField('status') !== self::STATUS_WAITING) {
            return false;
        }

        if ($can_update_ticket === null) {
            $ticket = new Ticket();
            $can_update_ticket = $ticket->getFromDB($this->getIntField('tickets_id')) && $ticket->canUpdateItem();
        }

        if (!$can_update_ticket) {
            return false;
        }

        // Approving/refusing creates (or would create) a Reservation for this
        // request's own requester, not for whoever answers it. Mirror core's
        // rule in Reservation::handleAddForm(): creating a reservation for
        // someone else requires CREATE, not just RESERVEANITEM.
        if (
            $this->getIntField('users_id') !== (int) Session::getLoginUserID()
            && !Session::haveRight(Reservation::$rightname, CREATE)
        ) {
            return false;
        }

        return true;
    }

    /** Removes every pending/accepted request tied to a purged Ticket, along with any Reservation it created. */
    public static function purgeForTicket(Ticket $ticket): void
    {
        $request = new self();
        foreach ($request->find(['tickets_id' => $ticket->getID()]) as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_numeric($row['id'])) {
                continue;
            }

            $item = new self();
            $item->delete(['id' => (int) $row['id']], true);
        }
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
