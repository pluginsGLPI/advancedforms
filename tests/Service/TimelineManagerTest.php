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

namespace GlpiPlugin\Advancedforms\Tests\Service;

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use GlpiPlugin\Advancedforms\Service\TimelineManager;
use ReservationItem;
use Session;
use Ticket;

final class TimelineManagerTest extends DbTestCase
{
    public function testAddTimelineItemsAddsWaitingEntryWithApproveRefuseButtons(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-05-01 09:00:00',
            'end' => '2026-05-01 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $timeline = [];
        TimelineManager::addTimelineItems(['item' => $ticket, 'timeline' => &$timeline]);

        $key = "TicketReservationRequest_{$request->getID()}";
        $this->assertArrayHasKey($key, $timeline);
        $this->assertSame(TicketReservationRequest::class, $timeline[$key]['type']);
        $this->assertTrue($timeline[$key]['item']['is_content_safe']);
        $this->assertStringContainsString(
            'data-reservation-request-id="' . $request->getID() . '"',
            $timeline[$key]['item']['content'],
        );
        $this->assertStringContainsString(
            'data-reservation-request-action="approve"',
            $timeline[$key]['item']['content'],
        );
        $this->assertStringContainsString(
            'data-reservation-request-action="refuse"',
            $timeline[$key]['item']['content'],
        );
    }

    public function testAddTimelineItemsHidesApproveRefuseButtonsWhenUserCannotAnswer(): void
    {
        // Ticket is created by the default logged-in user (requester).
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-05-01 11:00:00',
            'end' => '2026-05-01 12:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        // Switch to a "post-only" session: this user is not the ticket's
        // requester and uses the helpdesk interface, so canAnswer() is
        // guaranteed to return false regardless of any right assignment.
        $this->login('post-only', 'postonly');
        $current_ticket = new Ticket();
        $this->assertTrue($current_ticket->getFromDB($ticket->getID()));

        $timeline = [];
        TimelineManager::addTimelineItems(['item' => $current_ticket, 'timeline' => &$timeline]);

        $key = "TicketReservationRequest_{$request->getID()}";
        $this->assertArrayHasKey($key, $timeline);
        $this->assertStringNotContainsString(
            'data-reservation-request-action="approve"',
            $timeline[$key]['item']['content'],
        );
        $this->assertStringNotContainsString(
            'data-reservation-request-action="refuse"',
            $timeline[$key]['item']['content'],
        );
    }

    public function testAddTimelineItemsShowsDirectReservationMessageWhenAutoAccepted(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-05-01 13:00:00',
            'end' => '2026-05-01 14:00:00',
            'status' => TicketReservationRequest::STATUS_ACCEPTED,
            'users_id_validate' => 0,
        ]);

        $timeline = [];
        TimelineManager::addTimelineItems(['item' => $ticket, 'timeline' => &$timeline]);

        $key = "TicketReservationRequest_{$request->getID()}";
        $this->assertArrayHasKey($key, $timeline);
        $content = $timeline[$key]['item']['content'];
        $this->assertStringContainsString('confirmed automatically', $content);
        $this->assertStringNotContainsString('data-reservation-request-action="approve"', $content);
        $this->assertStringNotContainsString('data-reservation-request-action="refuse"', $content);
    }

    public function testAddTimelineItemsAddsOneEntryPerRequest(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request_1 = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-05-02 09:00:00',
            'end' => '2026-05-02 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);
        $request_2 = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-05-02 11:00:00',
            'end' => '2026-05-02 12:00:00',
            'status' => TicketReservationRequest::STATUS_REFUSED,
        ]);

        $timeline = [];
        TimelineManager::addTimelineItems(['item' => $ticket, 'timeline' => &$timeline]);

        $key_1 = "TicketReservationRequest_{$request_1->getID()}";
        $key_2 = "TicketReservationRequest_{$request_2->getID()}";
        $this->assertNotSame($key_1, $key_2);
        $this->assertArrayHasKey($key_1, $timeline);
        $this->assertArrayHasKey($key_2, $timeline);
        $this->assertCount(2, $timeline);
    }

    private function getReservableItem(): ReservationItem
    {
        $computer = $this->createItem('Computer', ['name' => 'test-computer', 'entities_id' => $this->getTestRootEntity(true)]);
        return $this->createItem('ReservationItem', [
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'is_active' => 1,
        ]);
    }
}
