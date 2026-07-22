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

namespace GlpiPlugin\Advancedforms\Tests;

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Controller\ReservationRequestController;
use GlpiPlugin\Advancedforms\Model\Destination\PreReservationField;
use GlpiPlugin\Advancedforms\Model\Destination\PreReservationFieldConfig;
use GlpiPlugin\Advancedforms\Model\Destination\PreReservationFieldStrategy;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestion;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;
use Reservation;
use ReservationItem;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Ticket;
use UserEmail;

/**
 * Functional end-to-end test exercising the full reservation question
 * workflow across all the pieces built for it: `ReservationQuestion`,
 * `PreReservationField`, `TicketReservationRequest` (+ notifications) and
 * `ReservationRequestController`.
 *
 * This test focuses on integration seams between those pieces rather than
 * re-testing each one in isolation (that is already covered by their own
 * dedicated unit tests).
 */
final class ReservationWorkflowTest extends AdvancedFormsTestCase
{
    public function testFullApprovalFlow(): void
    {
        $this->login();
        $requester_id = Session::getLoginUserID();
        $this->giveUserADefaultEmail($requester_id);
        $this->activateReservationRequestNotification('reservation_request_created');
        $this->activateReservationRequestNotification('reservation_request_approved');

        $queue_before_submission = countElementsInTable('glpi_queuednotifications');

        [$ticket, $item] = $this->submitReservationForm(require_approval: true);
        $this->assertGreaterThan(0, $ticket->getID());

        $request = new TicketReservationRequest();
        $rows = $request->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(1, $rows);
        $this->assertTrue($request->getFromDB((int) array_key_first($rows)));
        $this->assertSame(TicketReservationRequest::STATUS_WAITING, (int) $request->fields['status']);

        // The requester must have been notified that their request needs
        // an answer.
        $queue_after_submission = countElementsInTable('glpi_queuednotifications');
        $this->assertGreaterThan($queue_before_submission, $queue_after_submission);

        // A different (technician) user approves the request through the
        // real HTTP controller, not by calling the model directly, so that
        // the full stack (rights-checking included) is exercised.
        $this->login('glpi', 'glpi');
        $this->assertNotSame($requester_id, Session::getLoginUserID());

        $controller = new ReservationRequestController();
        $response = $controller(Request::create('/', 'POST', [
            'id' => $request->getID(),
            'action' => 'approve',
            'comment' => 'ok',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true], json_decode((string) $response->getContent(), true));

        $this->assertTrue($request->getFromDB($request->getID()));
        $this->assertSame(TicketReservationRequest::STATUS_ACCEPTED, (int) $request->fields['status']);

        // The resulting Reservation must be attributed to the original
        // requester, not to the technician who approved it.
        $reservation = new Reservation();
        $found = $reservation->find(['reservationitems_id' => $item->getID()]);
        $this->assertCount(1, $found);
        $this->assertSame($requester_id, (int) reset($found)['users_id']);

        $queue_after_approval = countElementsInTable('glpi_queuednotifications');
        $this->assertGreaterThan($queue_after_submission, $queue_after_approval);
    }

    public function testFullRefusalFlow(): void
    {
        $this->login();
        $requester_id = Session::getLoginUserID();
        $this->giveUserADefaultEmail($requester_id);
        $this->activateReservationRequestNotification('reservation_request_created');
        $this->activateReservationRequestNotification('reservation_request_refused');

        [$ticket, $item] = $this->submitReservationForm(require_approval: true);

        $request = new TicketReservationRequest();
        $rows = $request->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(1, $rows);
        $this->assertTrue($request->getFromDB((int) array_key_first($rows)));

        $queue_before_refusal = countElementsInTable('glpi_queuednotifications');

        $this->login('glpi', 'glpi');
        $controller = new ReservationRequestController();
        $response = $controller(Request::create('/', 'POST', [
            'id' => $request->getID(),
            'action' => 'refuse',
            'comment' => 'no',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true], json_decode((string) $response->getContent(), true));

        $this->assertTrue($request->getFromDB($request->getID()));
        $this->assertSame(TicketReservationRequest::STATUS_REFUSED, (int) $request->fields['status']);

        $reservation = new Reservation();
        $this->assertCount(0, $reservation->find(['reservationitems_id' => $item->getID()]));

        $queue_after_refusal = countElementsInTable('glpi_queuednotifications');
        $this->assertGreaterThan($queue_before_refusal, $queue_after_refusal);
    }

    public function testDirectModeSlotFreeAutoApproves(): void
    {
        $this->login();
        $requester_id = Session::getLoginUserID();

        [$ticket, $item] = $this->submitReservationForm(require_approval: false);

        $request = new TicketReservationRequest();
        $rows = $request->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame(TicketReservationRequest::STATUS_ACCEPTED, (int) $row['status']);
        // No technician ever answered this request: it was auto-approved.
        $this->assertSame(0, (int) $row['users_id_validate']);

        $reservation = new Reservation();
        $found = $reservation->find(['reservationitems_id' => $item->getID()]);
        $this->assertCount(1, $found);
        $this->assertSame($requester_id, (int) reset($found)['users_id']);
    }

    public function testDirectModeSlotConflictingCancelsAndNotifiesRequester(): void
    {
        $this->login();
        $requester_id = Session::getLoginUserID();
        $this->giveUserADefaultEmail($requester_id);
        $this->activateReservationRequestNotification('reservation_request_slot_unavailable');

        $item = $this->getReservableItem();

        // Seed a conflicting reservation for the same slot before submitting.
        $conflict = new Reservation();
        $this->assertGreaterThan(0, $conflict->add([
            'reservationitems_id' => $item->getID(),
            'begin' => '2026-07-01 09:00:00',
            'end' => '2026-07-01 11:00:00',
            'users_id' => $requester_id,
            'comment' => '',
        ]));

        $queue_before_submission = countElementsInTable('glpi_queuednotifications');

        [$ticket] = $this->submitReservationForm(
            require_approval: false,
            item: $item,
            begin: '2026-07-01 10:00:00',
            end: '2026-07-01 12:00:00',
        );

        $request = new TicketReservationRequest();
        $rows = $request->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(1, $rows);
        $this->assertSame(TicketReservationRequest::STATUS_CANCELED, (int) reset($rows)['status']);

        // Only the pre-existing conflicting reservation must exist: no new
        // Reservation was created for this request.
        $reservation_found = (new Reservation())->find(['reservationitems_id' => $item->getID()]);
        $this->assertCount(1, $reservation_found);
        $this->assertSame('2026-07-01 09:00:00', reset($reservation_found)['begin']);

        $queue_after_submission = countElementsInTable('glpi_queuednotifications');
        $this->assertGreaterThan($queue_before_submission, $queue_after_submission);
    }

    public function testDirectModeSlotFreeDoesNotRaiseCreatedNotification(): void
    {
        // Regression test: in "no approval required" (direct reservation)
        // mode, the underlying `TicketReservationRequest` row is still
        // inserted as WAITING before immediately being auto-approved by
        // `PreReservationField`. Only the "created" ("please wait for
        // approval") notification is activated here: if it fired for this
        // transient WAITING insert, the requester would wrongly receive both
        // a "please wait" and an "approved" notification for one
        // auto-confirmed action, and technicians-in-charge would be pinged
        // with an approval request for something that needs no approval.
        //
        // The count is scoped to `TicketReservationRequest` notifications
        // (rather than the whole queue) because submitting the form also
        // creates a `Ticket` and a `Reservation`, which raise their own,
        // unrelated core notifications ("New ticket"/"New reservation") in
        // this test environment.
        $this->login();
        $requester_id = Session::getLoginUserID();
        $this->giveUserADefaultEmail($requester_id);
        $this->activateReservationRequestNotification('reservation_request_created');

        $queue_before_submission = $this->countReservationRequestQueuedNotifications();

        [$ticket] = $this->submitReservationForm(require_approval: false);
        $this->assertGreaterThan(0, $ticket->getID());

        $request = new TicketReservationRequest();
        $rows = $request->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(1, $rows);
        $this->assertSame(TicketReservationRequest::STATUS_ACCEPTED, (int) reset($rows)['status']);

        $queue_after_submission = $this->countReservationRequestQueuedNotifications();
        $this->assertSame($queue_before_submission, $queue_after_submission);
    }

    public function testDirectModeSlotConflictingDoesNotRaiseCreatedNotification(): void
    {
        // Same regression as above, but for the "slot no longer available"
        // (markUnavailable) branch of direct mode.
        $this->login();
        $requester_id = Session::getLoginUserID();
        $this->giveUserADefaultEmail($requester_id);
        $this->activateReservationRequestNotification('reservation_request_created');

        $item = $this->getReservableItem();

        $conflict = new Reservation();
        $this->assertGreaterThan(0, $conflict->add([
            'reservationitems_id' => $item->getID(),
            'begin' => '2026-07-03 09:00:00',
            'end' => '2026-07-03 11:00:00',
            'users_id' => $requester_id,
            'comment' => '',
        ]));

        $queue_before_submission = $this->countReservationRequestQueuedNotifications();

        [$ticket] = $this->submitReservationForm(
            require_approval: false,
            item: $item,
            begin: '2026-07-03 10:00:00',
            end: '2026-07-03 12:00:00',
        );

        $request = new TicketReservationRequest();
        $rows = $request->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(1, $rows);
        $this->assertSame(TicketReservationRequest::STATUS_CANCELED, (int) reset($rows)['status']);

        $queue_after_submission = $this->countReservationRequestQueuedNotifications();
        $this->assertSame($queue_before_submission, $queue_after_submission);
    }

    public function testApproveDeniedWithoutTicketUpdateRightLeavesRequestUnchanged(): void
    {
        [$ticket] = $this->submitReservationForm(require_approval: true);

        $request = new TicketReservationRequest();
        $rows = $request->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(1, $rows);
        $this->assertTrue($request->getFromDB((int) array_key_first($rows)));

        // Switch to a "post-only" session: this user is not the ticket's
        // requester and uses the helpdesk interface, so Ticket::canUpdateItem()
        // is guaranteed to return false regardless of any right assignment.
        $this->login('post-only', 'postonly');

        $controller = new ReservationRequestController();
        $denied = false;
        try {
            $controller(Request::create('/', 'POST', [
                'id' => $request->getID(),
                'action' => 'approve',
            ]));
        } catch (AccessDeniedHttpException) {
            $denied = true;
        }
        $this->assertTrue($denied, 'Expected an AccessDeniedHttpException to be thrown.');

        // The denial must be a real gate, not just an exception with a
        // silent side effect: the request must be untouched.
        $this->assertTrue($request->getFromDB($request->getID()));
        $this->assertSame(TicketReservationRequest::STATUS_WAITING, (int) $request->fields['status']);
    }

    /**
     * Count queued notifications for `TicketReservationRequest`, ignoring
     * unrelated notifications also queued by submitting the form (e.g. the
     * core "New ticket"/"New reservation" notifications triggered by the
     * `Ticket`/`Reservation` created along the way).
     */
    private function countReservationRequestQueuedNotifications(): int
    {
        return countElementsInTable('glpi_queuednotifications', [
            'itemtype' => TicketReservationRequest::class,
        ]);
    }

    /**
     * Build a form with a single `ReservationQuestion`, configure its
     * `Ticket` destination's `PreReservationField` with the given approval
     * mode, submit it as the current test user and return the created
     * `Ticket` alongside the `ReservationItem` used for the answer.
     *
     * @return array{0: Ticket, 1: ReservationItem}
     */
    private function submitReservationForm(
        bool $require_approval,
        ?ReservationItem $item = null,
        string $begin = '2026-07-02 09:00:00',
        string $end = '2026-07-02 10:00:00',
    ): array {
        $this->login();
        $this->enableConfigurableItem(new ReservationQuestion());

        $item ??= $this->getReservableItem();

        $builder = new FormBuilder("Reservation workflow form");
        $builder->addQuestion("Reservation", ReservationQuestion::class);
        $form = $this->createForm($builder);

        $question_id = $this->getQuestionId($form, "Reservation");

        $config = new PreReservationFieldConfig(
            strategy: PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION,
            question_id: $question_id,
            require_approval: $require_approval,
        );

        $destinations = $form->getDestinations();
        $this->assertCount(1, $destinations);
        $destination = current($destinations);
        $this->updateItem(
            $destination::getType(),
            $destination->getId(),
            ['config' => [PreReservationField::getKey() => $config->jsonSerialize()]],
            ['config'],
        );

        $ticket = $this->sendFormAndGetCreatedTicket($form, [
            "Reservation" => [
                'reservationitems_id' => $item->getID(),
                'begin' => $begin,
                'end' => $end,
            ],
        ]);

        return [$ticket, $item];
    }

    private function getReservableItem(): ReservationItem
    {
        $computer = $this->createItem('Computer', [
            'name' => 'reservationworkflow-test-computer',
            'entities_id' => $this->getTestRootEntity(true),
        ]);

        return $this->createItem(ReservationItem::class, [
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
            'email' => 'reservationworkflow-test-' . $users_id . '@example.com',
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
