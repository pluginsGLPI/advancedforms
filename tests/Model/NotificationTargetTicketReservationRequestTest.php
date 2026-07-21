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
use GlpiPlugin\Advancedforms\Model\NotificationTargetTicketReservationRequest;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use Html;
use Notification;
use NotificationTarget;
use Session;
use Ticket;

final class NotificationTargetTicketReservationRequestTest extends DbTestCase
{
    public function testAutoDiscoveryResolvesToThisClass(): void
    {
        $this->assertSame(
            NotificationTargetTicketReservationRequest::class,
            NotificationTarget::getInstanceClass(TicketReservationRequest::class),
        );
    }

    public function testGetEventsHasTheFourExpectedKeysInOrder(): void
    {
        $target = new NotificationTargetTicketReservationRequest();

        $this->assertSame(
            [
                'reservation_request_created',
                'reservation_request_approved',
                'reservation_request_refused',
                'reservation_request_slot_unavailable',
            ],
            array_keys($target->getEvents()),
        );
    }

    public function testGetEventsLabelsAreTranslatedStrings(): void
    {
        $target = new NotificationTargetTicketReservationRequest();

        foreach ($target->getEvents() as $label) {
            $this->assertIsString($label);
            $this->assertNotSame('', $label);
        }
    }

    public function testAdditionalTargetsIncludeTechniciansOnlyForCreatedEvent(): void
    {
        $this->login();

        $author_key = Notification::USER_TYPE . '_' . Notification::AUTHOR;
        $tech_key = Notification::USER_TYPE . '_' . Notification::ITEM_TECH_IN_CHARGE;
        $tech_group_key = Notification::USER_TYPE . '_' . Notification::ITEM_TECH_GROUP_IN_CHARGE;

        $target_created = new NotificationTargetTicketReservationRequest();
        $target_created->addAdditionalTargets('reservation_request_created');
        $this->assertArrayHasKey($author_key, $target_created->notification_targets);
        $this->assertArrayHasKey($tech_key, $target_created->notification_targets);
        $this->assertArrayHasKey($tech_group_key, $target_created->notification_targets);

        foreach (['reservation_request_approved', 'reservation_request_refused', 'reservation_request_slot_unavailable'] as $event) {
            $target = new NotificationTargetTicketReservationRequest();
            $target->addAdditionalTargets($event);
            $this->assertArrayHasKey($author_key, $target->notification_targets, "requester missing for event $event");
            $this->assertArrayNotHasKey($tech_key, $target->notification_targets, "technician unexpectedly present for event $event");
            $this->assertArrayNotHasKey($tech_group_key, $target->notification_targets, "tech group unexpectedly present for event $event");
        }
    }

    public function testGetTagsRegistersReservationRequestTags(): void
    {
        $target = new NotificationTargetTicketReservationRequest();
        $target->getTags();

        $value_tags = array_keys($target->tag_descriptions[NotificationTarget::TAG_VALUE] ?? []);

        $this->assertContains('##reservationrequest.begin##', $value_tags);
        $this->assertContains('##reservationrequest.end##', $value_tags);
        $this->assertContains('##reservationrequest.comment##', $value_tags);
    }

    public function testAddDataForTemplatePopulatesReservationRequestTags(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-04-01 09:00:00',
            'end' => '2026-04-01 10:00:00',
            'status' => TicketReservationRequest::STATUS_ACCEPTED,
            'comment_validation' => 'approved comment',
        ]);

        $target = new NotificationTargetTicketReservationRequest();
        $target->obj = $request;
        $target->addDataForTemplate('reservation_request_approved');

        $this->assertSame(Html::convDateTime('2026-04-01 09:00:00'), $target->data['##reservationrequest.begin##']);
        $this->assertSame(Html::convDateTime('2026-04-01 10:00:00'), $target->data['##reservationrequest.end##']);
        $this->assertSame('approved comment', $target->data['##reservationrequest.comment##']);
    }

    public function testGetObjectItemResolvesToParentTicket(): void
    {
        $this->login();
        $ticket = $this->createItem('Ticket', ['name' => 't', 'content' => 'c', 'entities_id' => $this->getTestRootEntity(true)]);
        $item = $this->getReservableItem();

        $request = $this->createItem(TicketReservationRequest::class, [
            'tickets_id' => $ticket->getID(),
            'reservationitems_id' => $item->getID(),
            'users_id' => Session::getLoginUserID(),
            'begin' => '2026-04-02 09:00:00',
            'end' => '2026-04-02 10:00:00',
            'status' => TicketReservationRequest::STATUS_WAITING,
        ]);

        $notification_target = NotificationTarget::getInstance($request, 'reservation_request_created');

        $this->assertNotFalse($notification_target);
        $this->assertCount(1, $notification_target->target_object);
        $resolved_object = $notification_target->target_object[0];
        $this->assertInstanceOf(Ticket::class, $resolved_object);
        $this->assertSame($ticket->getID(), $resolved_object->getID());
    }

    private function getReservableItem(): \ReservationItem
    {
        $computer = $this->createItem('Computer', ['name' => 'test-computer', 'entities_id' => 0]);
        return $this->createItem('ReservationItem', [
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'is_active' => 1,
        ]);
    }
}
