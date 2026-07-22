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
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReservationRequestController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route(
        path: 'ReservationRequest',
        name: 'reservation_request',
        methods: 'POST',
    )]
    public function __invoke(Request $request): Response
    {
        $id = $request->request->getInt('id');
        $action = $request->request->getString('action');
        $comment = $request->request->getString('comment', '');

        if ($id <= 0 || !in_array($action, ['approve', 'refuse'], true)) {
            throw new BadRequestHttpException();
        }

        $reservation_request = new TicketReservationRequest();
        if (!$reservation_request->getFromDB($id)) {
            throw new BadRequestHttpException();
        }

        if (!$reservation_request->canAnswer()) {
            throw new AccessDeniedHttpException();
        }

        // The controller requires an authenticated session (see the
        // SecurityStrategy attribute above), so this is always a real user id.
        $users_id_validate = (int) Session::getLoginUserID();

        if ($action === 'approve') {
            if (!$reservation_request->isSlotStillAvailable()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => __('This slot is no longer available.', 'advancedforms'),
                ]);
            }

            $success = $reservation_request->approve($users_id_validate, $comment);
        } else {
            $success = $reservation_request->refuse($users_id_validate, $comment);
        }

        return new JsonResponse(['success' => $success]);
    }
}
