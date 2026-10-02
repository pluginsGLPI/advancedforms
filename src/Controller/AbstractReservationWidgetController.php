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

namespace GlpiPlugin\Advancedforms\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use Reservation;
use ReservationItem;
use Session;

/** Shared base for the reservation widget AJAX endpoints (common access guard). */
abstract class AbstractReservationWidgetController extends AbstractController
{
    /** @throws AccessDeniedHttpException when the current user cannot read reservations. */
    protected function checkReservationAccess(): void
    {
        if (!Session::haveRightsOr(Reservation::$rightname, [READ, ReservationItem::RESERVEANITEM])) {
            throw new AccessDeniedHttpException();
        }
    }

    /**
     * Loads the reservable item and ensures it is active and within the
     * current user's visible entities. The id is client-controlled, so it
     * must never be trusted without this check.
     *
     * @throws BadRequestHttpException when the id is invalid or the item does not exist.
     * @throws AccessDeniedHttpException when the item is inactive or outside visible entities.
     */
    protected function getAccessibleReservationItem(int $reservationitems_id): ReservationItem
    {
        $reservation_item = new ReservationItem();
        if (
            $reservationitems_id <= 0
            || !$reservation_item->getFromDB($reservationitems_id)
        ) {
            throw new BadRequestHttpException();
        }

        $is_active = $reservation_item->fields['is_active'] ?? 0;
        $entities_id = $reservation_item->fields['entities_id'] ?? -1;
        $is_recursive = $reservation_item->fields['is_recursive'] ?? 0;

        if (
            !is_numeric($is_active) || (int) $is_active !== 1
            || !is_numeric($entities_id) || !Session::haveAccessToEntity((int) $entities_id, (bool) $is_recursive)
        ) {
            throw new AccessDeniedHttpException();
        }

        return $reservation_item;
    }
}
