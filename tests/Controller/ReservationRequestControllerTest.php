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

namespace GlpiPlugin\Advancedforms\Tests\Controller;

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Advancedforms\Controller\ReservationRequestController;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Reservation;
use Session;
use Symfony\Component\HttpFoundation\Request;

final class ReservationRequestControllerTest extends AdvancedFormsTestCase
{
    public function testMissingIdThrowsBadRequest(): void
    {
        $this->login();

        $controller = new ReservationRequestController();
        $this->expectException(BadRequestHttpException::class);
        $controller(Request::create('/', 'POST', ['action' => 'approve']));
    }

    public function testInvalidActionThrowsBadRequest(): void
    {
        $this->login();
        $request = $this->createWaitingRequest();

        $controller = new ReservationRequestController();
        $this->expectException(BadRequestHttpException::class);
        $controller(Request::create('/', 'POST', [
            'id' => $request->getID(),
            'action' => 'not-a-real-action',
        ]));
    }

    public function testNonExistentIdThrowsBadRequest(): void
    {
        $this->login();

        $controller = new ReservationRequestController();
        $this->expectException(BadRequestHttpException::class);
        $controller(Request::create('/', 'POST', [
            'id' => 999999999,
            'action' => 'approve',
        ]));
    }

    public function testUserWithoutRightsCannotApprove(): void
    {
        // Ticket is created by the default logged in user (requester).
        $this->login();
        $request = $this->createWaitingRequest();

        // Switch to a "post-only" session: this user is not the ticket's
        // requester and uses the helpdesk interface, so Ticket::canUpdateItem()
        // is guaranteed to return false regardless of any right assignment,
        // making TicketReservationRequest::canAnswer() false as well.
        $this->login('post-only', 'postonly');

        $controller = new ReservationRequestController();
        $this->expectException(AccessDeniedHttpException::class);
        $controller(Request::create('/', 'POST', [
            'id' => $request->getID(),
            'action' => 'approve',
        ]));
    }

    public function testUserWithoutRightsCannotRefuse(): void
    {
        $this->login();
        $request = $this->createWaitingRequest();

        $this->login('post-only', 'postonly');

        $controller = new ReservationRequestController();
        $this->expectException(AccessDeniedHttpException::class);
        $controller(Request::create('/', 'POST', [
            'id' => $request->getID(),
            'action' => 'refuse',
        ]));
    }

    public function testApproveByAuthorizedUserSucceeds(): void
    {
        $this->login();
        $request = $this->createWaitingRequest();

        $controller = new ReservationRequestController();
        $response = $controller(Request::create('/', 'POST', [
            'id' => $request->getID(),
            'action' => 'approve',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true], json_decode((string) $response->getContent(), true));

        $this->assertTrue($request->getFromDB($request->getID()));
        $this->assertSame(TicketReservationRequest::STATUS_ACCEPTED, (int) $request->fields['status']);

        $reservation = new Reservation();
        $found = $reservation->find([
            'reservationitems_id' => $request->fields['reservationitems_id'],
            'begin' => $request->fields['begin'],
            'end' => $request->fields['end'],
        ]);
        $this->assertCount(1, $found);
    }

    public function testApproveWhenSlotBecameUnavailableReturnsFailureWithoutChangingStatus(): void
    {
        $this->login();
        $request = $this->createWaitingRequest();

        $conflict = new Reservation();
        $this->assertGreaterThan(0, $conflict->add([
            'reservationitems_id' => $request->fields['reservationitems_id'],
            'begin' => $request->fields['begin'],
            'end' => $request->fields['end'],
            'users_id' => Session::getLoginUserID(),
            'comment' => '',
        ]));

        $controller = new ReservationRequestController();
        $response = $controller(Request::create('/', 'POST', [
            'id' => $request->getID(),
            'action' => 'approve',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertNotEmpty($body['message']);

        $this->assertTrue($request->getFromDB($request->getID()));
        $this->assertSame(TicketReservationRequest::STATUS_WAITING, (int) $request->fields['status']);
    }

    public function testRefuseByAuthorizedUserSucceeds(): void
    {
        $this->login();
        $request = $this->createWaitingRequest();

        $controller = new ReservationRequestController();
        $response = $controller(Request::create('/', 'POST', [
            'id' => $request->getID(),
            'action' => 'refuse',
            'comment' => 'no thanks',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true], json_decode((string) $response->getContent(), true));

        $this->assertTrue($request->getFromDB($request->getID()));
        $this->assertSame(TicketReservationRequest::STATUS_REFUSED, (int) $request->fields['status']);
    }

    private function createWaitingRequest(): TicketReservationRequest
    {
        $ticket = $this->createItem('Ticket', [
            'name' => 't',
            'content' => 'c',
            'entities_id' => $this->getTestRootEntity(true),
        ]);
        $computer = $this->createItem('Computer', ['name' => 'test-computer', 'entities_id' => 0]);
        $item = $this->createItem('ReservationItem', [
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'is_active' => 1,
        ]);

        return $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-06-01 09:00:00',
            'end' => '2026-06-01 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);
    }
}
