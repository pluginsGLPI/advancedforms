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

        $key = 'TicketReservationRequest_' . $request->getID();
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

    public function testAddTimelineItemsWiresApproveRefuseButtonsToController(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-05-01 09:30:00',
            'end' => '2026-05-01 10:30:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $timeline = [];
        TimelineManager::addTimelineItems(['item' => $ticket, 'timeline' => &$timeline]);

        $key = 'TicketReservationRequest_' . $request->getID();
        $this->assertArrayHasKey($key, $timeline);
        $content = $timeline[$key]['item']['content'];

        $this->assertStringContainsString('ReservationRequestTimelineActions.js', $content);
        $this->assertStringContainsString('data-reservation-request-action="approve"', $content);
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

        // "post-only" is not the requester and uses the helpdesk interface.
        $this->login('post-only', 'postonly');
        $current_ticket = new Ticket();
        $this->assertTrue($current_ticket->getFromDB($ticket->getID()));

        $timeline = [];
        TimelineManager::addTimelineItems(['item' => $current_ticket, 'timeline' => &$timeline]);

        $key = 'TicketReservationRequest_' . $request->getID();
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

        $key = 'TicketReservationRequest_' . $request->getID();
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

        $key_1 = 'TicketReservationRequest_' . $request_1->getID();
        $key_2 = 'TicketReservationRequest_' . $request_2->getID();
        $this->assertNotSame($key_1, $key_2);
        $this->assertArrayHasKey($key_1, $timeline);
        $this->assertArrayHasKey($key_2, $timeline);
        $this->assertCount(2, $timeline);
    }

    public function testAddTimelineItemsRendersStatusBadgeAndLabelForEachStatus(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        // Maps each status to the twig template's expected badge color
        // ("badge bg-{color}") and translated label.
        $expectations = [
            TicketReservationRequest::STATUS_WAITING  => ['warning', 'Pending validation'],
            TicketReservationRequest::STATUS_ACCEPTED => ['success', 'Approved'],
            TicketReservationRequest::STATUS_REFUSED  => ['danger', 'Refused'],
            TicketReservationRequest::STATUS_CANCELED => ['secondary', 'Canceled'],
        ];

        $requests = [];
        $hour = 9;
        foreach (array_keys($expectations) as $status) {
            $requests[$status] = $this->createItem(TicketReservationRequest::class, [
                'tickets_id' => $ticket->getID(),
                'reservationitems_id' => $item->getID(),
                'users_id' => Session::getLoginUserID(),
                'begin' => sprintf('2026-05-03 %02d:00:00', $hour),
                'end' => sprintf('2026-05-03 %02d:00:00', $hour + 1),
                'status' => $status,
                // Non-zero on ACCEPTED so this isn't read as a direct
                // reservation, which is irrelevant to badge rendering.
                'users_id_validate' => $status === TicketReservationRequest::STATUS_ACCEPTED
                    ? Session::getLoginUserID()
                    : 0,
            ]);
            $hour++;
        }

        $timeline = [];
        TimelineManager::addTimelineItems(['item' => $ticket, 'timeline' => &$timeline]);

        foreach ($expectations as $status => [$badge_color, $label]) {
            $key = 'TicketReservationRequest_' . $requests[$status]->getID();
            $this->assertArrayHasKey($key, $timeline);
            $content = $timeline[$key]['item']['content'];
            $this->assertStringContainsString('badge bg-' . $badge_color, $content);
            $this->assertStringContainsString($label, $content);
        }
    }

    public function testAddTimelineItemsShowsSlotUnavailableMessageWhenCanceledIsNotDirectReservation(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-05-04 09:00:00',
            'end' => '2026-05-04 10:00:00',
            'status' => TicketReservationRequest::STATUS_CANCELED,
            // Non-zero: this was an approved/validated request that later
            // became unavailable, not an auto-accepted direct reservation.
            'users_id_validate' => Session::getLoginUserID(),
        ]);

        $timeline = [];
        TimelineManager::addTimelineItems(['item' => $ticket, 'timeline' => &$timeline]);

        $key = 'TicketReservationRequest_' . $request->getID();
        $this->assertArrayHasKey($key, $timeline);
        $content = $timeline[$key]['item']['content'];
        $this->assertStringContainsString(
            'This slot is no longer available. Please submit a new reservation request.',
            $content,
        );
        $this->assertStringNotContainsString('confirmed automatically', $content);
    }

    public function testAddTimelineItemsRendersCommentValidationWhenSet(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-05-05 09:00:00',
            'end' => '2026-05-05 10:00:00',
            'status' => TicketReservationRequest::STATUS_REFUSED,
            'users_id_validate' => Session::getLoginUserID(),
            'comment_validation' => 'Not available for this timeframe, please pick another slot.',
        ]);

        $timeline = [];
        TimelineManager::addTimelineItems(['item' => $ticket, 'timeline' => &$timeline]);

        $key = 'TicketReservationRequest_' . $request->getID();
        $this->assertArrayHasKey($key, $timeline);
        $this->assertStringContainsString(
            'Not available for this timeframe, please pick another slot.',
            $timeline[$key]['item']['content'],
        );
    }

    public function testAddTimelineItemsRendersEquipmentNameAndTimeSlot(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-05-06 09:00:00',
            'end' => '2026-05-06 10:30:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $timeline = [];
        TimelineManager::addTimelineItems(['item' => $ticket, 'timeline' => &$timeline]);

        $key = 'TicketReservationRequest_' . $request->getID();
        $this->assertArrayHasKey($key, $timeline);
        $content = $timeline[$key]['item']['content'];
        $this->assertStringContainsString('test-computer', $content);
        $this->assertStringContainsString('2026-05-06 09:00:00', $content);
        $this->assertStringContainsString('2026-05-06 10:30:00', $content);
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
