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
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Reservation;
use ReservationItem;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CheckAvailabilityController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route(
        path: 'ReservationWidget/CheckAvailability',
        name: 'reservation_widget_check_availability',
        methods: 'POST',
    )]
    public function __invoke(Request $request): Response
    {
        if (!Session::haveRightsOr('reservation', [READ, ReservationItem::RESERVEANITEM])) {
            throw new AccessDeniedHttpException();
        }

        $reservationitems_id = $request->request->getInt('reservationitems_id');
        $begin = $request->request->getString('begin');
        $end = $request->request->getString('end');

        if ($reservationitems_id <= 0 || $begin === '' || $end === '') {
            throw new BadRequestHttpException();
        }

        global $DB;
        $conflict = $DB->request([
            'COUNT' => 'cpt',
            'FROM' => Reservation::getTable(),
            'WHERE' => [
                'reservationitems_id' => $reservationitems_id,
                'end' => ['>', $begin],
                'begin' => ['<', $end],
            ],
        ])->current();

        $count = 0;
        if (is_array($conflict) && isset($conflict['cpt']) && is_numeric($conflict['cpt'])) {
            $count = (int) $conflict['cpt'];
        }

        return new JsonResponse(['available' => $count === 0]);
    }
}
