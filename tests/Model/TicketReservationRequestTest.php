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

namespace GlpiPlugin\Advancedforms\Tests\Model;

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;
use Reservation;
use ReservationItem;
use Session;
use Ticket;
use UserEmail;

final class TicketReservationRequestTest extends DbTestCase
{
    public function testIsSlotStillAvailableWithNoConflict(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-03-01 09:00:00',
            'end' => '2026-03-01 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $this->assertTrue($request->isSlotStillAvailable());
    }

    public function testIsSlotStillAvailableWithOverlap(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-03-01 09:00:00',
            'end' => '2026-03-01 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $reservation = new Reservation();
        $this->assertGreaterThan(0, $reservation->add([
            'reservationitems_id' => $item->getID(),
            'begin' => '2026-03-01 09:30:00',
            'end' => '2026-03-01 10:30:00',
            'users_id' => Session::getLoginUserID(),
            'comment' => '',
        ]));

        $this->assertFalse($request->isSlotStillAvailable());
    }

    public function testIsSlotStillAvailableWithBackToBackSlot(): void
    {
        // Back-to-back reservations (one ending exactly when the other begins)
        // are not considered overlapping: core's Reservation::is_reserved()
        // uses strict inequalities (`end > begin AND begin < end`).
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-03-01 10:00:00',
            'end' => '2026-03-01 11:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $reservation = new Reservation();
        $this->assertGreaterThan(0, $reservation->add([
            'reservationitems_id' => $item->getID(),
            'begin' => '2026-03-01 09:00:00',
            'end' => '2026-03-01 10:00:00', // ends exactly when the request begins
            'users_id' => Session::getLoginUserID(),
            'comment' => '',
        ]));

        $this->assertTrue($request->isSlotStillAvailable());
    }

    public function testApproveCreatesReservationWithOriginalRequester(): void
    {
        $this->login(); // requester
        $requester_id = Session::getLoginUserID();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => $requester_id,
            'begin' => '2026-03-02 09:00:00',
            'end' => '2026-03-02 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
            'comment_submission' => 'please',
        ]);

        $this->login('glpi', 'glpi'); // validator (different user)
        $validator_id = Session::getLoginUserID();
        $this->assertNotSame($requester_id, $validator_id);

        $this->assertTrue($request->approve($validator_id, 'ok'));
        $this->assertTrue($request->getFromDB($request->getID()));

        $this->assertSame(TicketReservationRequest::STATUS_ACCEPTED, (int) $request->fields['status']);
        $this->assertSame($validator_id, (int) $request->fields['users_id_validate']);
        $this->assertSame('ok', $request->fields['comment_validation']);
        $this->assertNotEmpty($request->fields['validation_date']);

        $reservation = new Reservation();
        $found = $reservation->find([
            'reservationitems_id' => $item->getID(),
            'users_id' => $requester_id,
        ]);
        $this->assertCount(1, $found);
        $found_reservation = reset($found);
        $this->assertSame('please', $found_reservation['comment']);
    }

    public function testApproveFailsAndDoesNotMutateStatusWhenSlotIsNoLongerAvailable(): void
    {
        // Simulate a Reservation::add() failure by creating a conflicting
        // reservation on the same item/timeframe before approving: core's
        // Reservation::prepareInputForAdd() rejects overlapping reservations,
        // so add() will gracefully return false without throwing.
        $this->login();
        $requester_id = Session::getLoginUserID();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => $requester_id,
            'begin' => '2026-03-04 09:00:00',
            'end' => '2026-03-04 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $conflicting = new Reservation();
        $this->assertGreaterThan(0, $conflicting->add([
            'reservationitems_id' => $item->getID(),
            'begin' => '2026-03-04 09:00:00',
            'end' => '2026-03-04 10:00:00',
            'users_id' => $requester_id,
            'comment' => '',
        ]));

        $this->assertFalse($request->approve($requester_id, 'ok'));
        $this->hasSessionMessages(ERROR, ['The required item is already reserved for this timeframe']);
        $this->assertTrue($request->getFromDB($request->getID()));
        $this->assertSame(TicketReservationRequest::STATUS_WAITING, (int) $request->fields['status']);
        $this->assertSame(0, (int) $request->fields['users_id_validate']);
    }

    public function testRefuseDoesNotCreateReservation(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-03-03 09:00:00',
            'end' => '2026-03-03 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $this->assertTrue($request->refuse(Session::getLoginUserID(), 'nope'));
        $this->assertTrue($request->getFromDB($request->getID()));
        $this->assertSame(TicketReservationRequest::STATUS_REFUSED, (int) $request->fields['status']);
        $this->assertSame('nope', $request->fields['comment_validation']);

        $reservation = new Reservation();
        $this->assertCount(0, $reservation->find(['reservationitems_id' => $item->getID()]));
    }

    public function testMarkUnavailableSetsCanceledStatus(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-03-05 09:00:00',
            'end' => '2026-03-05 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $this->assertTrue($request->markUnavailable());
        $this->assertTrue($request->getFromDB($request->getID()));
        $this->assertSame(TicketReservationRequest::STATUS_CANCELED, (int) $request->fields['status']);

        $reservation = new Reservation();
        $this->assertCount(0, $reservation->find(['reservationitems_id' => $item->getID()]));
    }

    public function testCreatingWaitingRequestRaisesCreatedNotification(): void
    {
        $this->login();
        $requester_id = Session::getLoginUserID();
        $this->giveUserADefaultEmail($requester_id);
        $this->activateReservationRequestNotification('reservation_request_created');

        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $queue_before = countElementsInTable('glpi_queuednotifications');

        $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => $requester_id,
            'begin' => '2026-05-01 09:00:00',
            'end' => '2026-05-01 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $queue_after = countElementsInTable('glpi_queuednotifications');
        $this->assertGreaterThan($queue_before, $queue_after);
    }

    public function testCreatingWaitingRequestWithDisablenotifFlagDoesNotRaiseCreatedNotification(): void
    {
        // A request created in the WAITING state can still opt out of the
        // "new request needs an answer" notification via the standard
        // `_disablenotif` input key (same convention used throughout GLPI
        // core, e.g. `Ticket`/`CommonITILObject`/`Reservation`). This is what
        // `PreReservationField` relies on for its "no approval required"
        // (direct reservation) path: the row is inserted as WAITING only as
        // a transient step before immediately being transitioned to its real
        // final state, so the "please wait for approval" notification must
        // not fire for that insert.
        $this->login();
        $requester_id = Session::getLoginUserID();
        $this->giveUserADefaultEmail($requester_id);
        $this->activateReservationRequestNotification('reservation_request_created');

        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $queue_before = countElementsInTable('glpi_queuednotifications');

        $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => $requester_id,
            'begin' => '2026-05-07 09:00:00',
            'end' => '2026-05-07 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
            '_disablenotif' => true,
        ]);

        $queue_after = countElementsInTable('glpi_queuednotifications');
        $this->assertSame($queue_before, $queue_after);
    }

    public function testCreatingAlreadyAcceptedRequestDoesNotRaiseCreatedNotification(): void
    {
        // A request created directly in the ACCEPTED state (no approval
        // step needed) must not raise the "new request needs an answer"
        // notification.
        $this->login();
        $requester_id = Session::getLoginUserID();
        $this->giveUserADefaultEmail($requester_id);
        $this->activateReservationRequestNotification('reservation_request_created');

        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $queue_before = countElementsInTable('glpi_queuednotifications');

        $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => $requester_id,
            'begin' => '2026-05-02 09:00:00',
            'end' => '2026-05-02 10:00:00',
            'status' => TicketReservationRequest::STATUS_ACCEPTED,
        ]);

        $queue_after = countElementsInTable('glpi_queuednotifications');
        $this->assertSame($queue_before, $queue_after);
    }

    public function testApproveRaisesApprovedNotification(): void
    {
        $this->login();
        $requester_id = Session::getLoginUserID();
        $this->giveUserADefaultEmail($requester_id);
        $this->activateReservationRequestNotification('reservation_request_approved');

        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => $requester_id,
            'begin' => '2026-05-03 09:00:00',
            'end' => '2026-05-03 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $queue_before = countElementsInTable('glpi_queuednotifications');
        $this->assertTrue($request->approve($requester_id, 'ok'));
        $queue_after = countElementsInTable('glpi_queuednotifications');

        $this->assertGreaterThan($queue_before, $queue_after);
    }

    public function testApproveFailureDoesNotRaiseApprovedNotification(): void
    {
        // Same setup as testApproveFailsAndDoesNotMutateStatusWhenSlotIsNoLongerAvailable:
        // a conflicting reservation makes Reservation::add() fail gracefully,
        // so approve() must return false without raising any notification.
        $this->login();
        $requester_id = Session::getLoginUserID();
        $this->giveUserADefaultEmail($requester_id);
        $this->activateReservationRequestNotification('reservation_request_approved');

        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => $requester_id,
            'begin' => '2026-05-04 09:00:00',
            'end' => '2026-05-04 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $conflicting = new Reservation();
        $this->assertGreaterThan(0, $conflicting->add([
            'reservationitems_id' => $item->getID(),
            'begin' => '2026-05-04 09:00:00',
            'end' => '2026-05-04 10:00:00',
            'users_id' => $requester_id,
            'comment' => '',
        ]));

        $queue_before = countElementsInTable('glpi_queuednotifications');
        $this->assertFalse($request->approve($requester_id, 'ok'));
        $this->hasSessionMessages(ERROR, ['The required item is already reserved for this timeframe']);
        $queue_after = countElementsInTable('glpi_queuednotifications');

        $this->assertSame($queue_before, $queue_after);
    }

    public function testRefuseRaisesRefusedNotification(): void
    {
        $this->login();
        $requester_id = Session::getLoginUserID();
        $this->giveUserADefaultEmail($requester_id);
        $this->activateReservationRequestNotification('reservation_request_refused');

        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => $requester_id,
            'begin' => '2026-05-05 09:00:00',
            'end' => '2026-05-05 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $queue_before = countElementsInTable('glpi_queuednotifications');
        $this->assertTrue($request->refuse($requester_id, 'nope'));
        $queue_after = countElementsInTable('glpi_queuednotifications');

        $this->assertGreaterThan($queue_before, $queue_after);
    }

    public function testMarkUnavailableRaisesSlotUnavailableNotification(): void
    {
        $this->login();
        $requester_id = Session::getLoginUserID();
        $this->giveUserADefaultEmail($requester_id);
        $this->activateReservationRequestNotification('reservation_request_slot_unavailable');

        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => $requester_id,
            'begin' => '2026-05-06 09:00:00',
            'end' => '2026-05-06 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $queue_before = countElementsInTable('glpi_queuednotifications');
        $this->assertTrue($request->markUnavailable());
        $queue_after = countElementsInTable('glpi_queuednotifications');

        $this->assertGreaterThan($queue_before, $queue_after);
    }

    public function testCanAnswerTrueWhenWaitingAndUserCanUpdateTicket(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-03-06 09:00:00',
            'end' => '2026-03-06 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $this->assertTrue($request->canAnswer());
    }

    public function testCanAnswerFalseWhenNotWaiting(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-03-07 09:00:00',
            'end' => '2026-03-07 10:00:00',
            'status' => TicketReservationRequest::STATUS_ACCEPTED,
        ]);

        $this->assertFalse($request->canAnswer());
    }

    public function testCanAnswerFalseWhenSessionLacksTicketUpdateRight(): void
    {
        // Ticket is created by the default logged in user (requester).
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-03-08 09:00:00',
            'end' => '2026-03-08 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        // Switch to a "post-only" session: this user is not the ticket's
        // requester and uses the helpdesk interface, so Ticket::canUpdateItem()
        // is guaranteed to return false regardless of any right assignment.
        $this->login('post-only', 'postonly');
        $current_ticket = new Ticket();
        $this->assertTrue($current_ticket->getFromDB($ticket->getID()));
        $this->assertFalse($current_ticket->canUpdateItem());

        $this->assertFalse($request->canAnswer());
    }

    public function testGetTimelineInfo(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-03-09 09:00:00',
            'end' => '2026-03-09 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $info = $request->getTimelineInfo();

        $this->assertSame($request->getID(), $info['id']);
        $this->assertSame(TicketReservationRequest::STATUS_WAITING, $info['status']);
        $this->assertSame($item->getID(), $info['reservationitems_id']);
        $this->assertSame('2026-03-09 09:00:00', $info['begin']);
        $this->assertSame('2026-03-09 10:00:00', $info['end']);
        $this->assertTrue($info['can_answer']);
        $this->assertFalse($info['is_direct_reservation']);
    }

    private function getReservableItem(): ReservationItem
    {
        $computer = $this->createItem('Computer', ['name' => 'test-computer', 'entities_id' => 0]);
        return $this->createItem('ReservationItem', [
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'is_active' => 1,
        ]);
    }

    /**
     * Give the given user a default email address: `NotificationTarget`'s
     * `addItemAuthor()`/`addToRecipientsList()` silently drops recipients
     * that have no resolvable email address, so without this a queued
     * notification would never be created regardless of everything else
     * being correctly configured.
     */
    private function giveUserADefaultEmail(int $users_id): void
    {
        $this->createItem(UserEmail::class, [
            'users_id' => $users_id,
            'email' => 'reservationrequest-test-' . $users_id . '@example.com',
            'is_default' => 1,
        ]);
    }

    /**
     * Register a minimal, active `Notification` (+ template + "requester"
     * target) for the given `TicketReservationRequest` event, so that
     * `NotificationEvent::raiseEvent()` actually queues something: this
     * plugin does not ship any notification configuration of its own (that
     * is left to the GLPI administrator), so tests must create their own.
     *
     * Also enables notifications globally in `$CFG_GLPI`.
     */
    private function activateReservationRequestNotification(string $event): void
    {
        global $CFG_GLPI;

        $CFG_GLPI['use_notifications'] = 1;
        $CFG_GLPI['notifications_mailing'] = 1;

        $notification = $this->createItem(Notification::class, [
            'name' => 'test-' . $event,
            'itemtype' => TicketReservationRequest::class,
            'event' => $event,
            'entities_id' => 0,
            'is_recursive' => 1,
            'is_active' => 1,
        ]);

        $template = $this->createItem(NotificationTemplate::class, [
            'name' => 'test-template-' . $event,
            'itemtype' => TicketReservationRequest::class,
        ]);

        $this->createItem(NotificationTemplateTranslation::class, [
            'notificationtemplates_id' => $template->getID(),
            'language' => '',
            'subject' => 'test subject',
            'content_text' => 'test content',
            'content_html' => '',
        ]);

        $this->createItem(Notification_NotificationTemplate::class, [
            'notifications_id' => $notification->getID(),
            'mode' => Notification_NotificationTemplate::MODE_MAIL,
            'notificationtemplates_id' => $template->getID(),
        ]);

        $this->createItem(NotificationTarget::class, [
            'notifications_id' => $notification->getID(),
            'type' => Notification::USER_TYPE,
            'items_id' => Notification::AUTHOR,
        ]);
    }
}
