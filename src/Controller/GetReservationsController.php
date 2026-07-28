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

use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Reservation;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GetReservationsController extends AbstractReservationWidgetController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route(
        path: 'ReservationWidget/Reservations',
        name: 'reservation_widget_reservations',
        methods: 'POST',
    )]
    public function __invoke(Request $request): Response
    {
        $this->checkReservationAccess();

        $reservationitems_id = $request->request->getInt('reservationitems_id');
        if ($reservationitems_id <= 0) {
            throw new BadRequestHttpException();
        }

        $begin = $request->request->getString('begin', '');
        $end = $request->request->getString('end', '');

        $where = ['reservationitems_id' => $reservationitems_id];
        if ($begin !== '') {
            $where['end'] = ['>', $begin];
        }

        if ($end !== '') {
            $where['begin'] = ['<', $end];
        }

        global $DB;
        $rows = $DB->request([
            'FROM' => Reservation::getTable(),
            'WHERE' => $where,
        ]);

        $results = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $begin_value = $row['begin'] ?? null;
            $end_value = $row['end'] ?? null;
            $users_id_value = $row['users_id'] ?? null;

            $results[] = [
                'begin' => is_scalar($begin_value) ? (string) $begin_value : '',
                'end' => is_scalar($end_value) ? (string) $end_value : '',
                'users_id' => is_numeric($users_id_value) ? (int) $users_id_value : 0,
            ];
        }

        return new JsonResponse($results);
    }
}
