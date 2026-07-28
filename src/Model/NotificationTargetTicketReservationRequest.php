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

namespace GlpiPlugin\Advancedforms\Model;

use Html;
use Notification;
use NotificationTarget;
use Override;
use Ticket;

/** @extends NotificationTarget<TicketReservationRequest> */
final class NotificationTargetTicketReservationRequest extends NotificationTarget
{
    #[Override]
    public function getEvents(): array
    {
        return [
            'reservation_request_created' => __('New pre-reservation request', 'advancedforms'),
            'reservation_request_approved' => __('Pre-reservation request approved', 'advancedforms'),
            'reservation_request_refused' => __('Pre-reservation request refused', 'advancedforms'),
            'reservation_request_slot_unavailable' => __('Reservation slot no longer available', 'advancedforms'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = ''): void
    {
        // Only the requester is offered: this itemtype carries no technician
        // fields, so core item-technician targets cannot be resolved against it.
        $this->addTarget(Notification::AUTHOR, __('Requester'));
    }

    #[Override]
    public function addDataForTemplate($event, $options = []): void
    {
        if (!$this->obj instanceof TicketReservationRequest) {
            return;
        }

        $begin = $this->obj->getField('begin');
        $end = $this->obj->getField('end');
        $comment = $this->obj->getField('comment_validation');

        $this->data['##reservationrequest.action##'] = $this->getEvents()[$event] ?? '';
        $this->data['##reservationrequest.begin##'] = is_string($begin) ? (Html::convDateTime($begin) ?? '') : '';
        $this->data['##reservationrequest.end##'] = is_string($end) ? (Html::convDateTime($end) ?? '') : '';
        $this->data['##reservationrequest.comment##'] = is_string($comment) ? $comment : '';

        $tickets_id = $this->obj->fields['tickets_id'] ?? 0;
        $ticket = new Ticket();
        if ((is_int($tickets_id) || is_string($tickets_id)) && $ticket->getFromDB($tickets_id)) {
            $title = $ticket->fields['name'] ?? '';
            $this->data['##reservationrequest.ticket_title##'] = is_string($title) ? $title : '';
            $this->data['##reservationrequest.ticket_url##'] = $ticket->getFormURLWithID($ticket->getID());
        } else {
            $this->data['##reservationrequest.ticket_title##'] = '';
            $this->data['##reservationrequest.ticket_url##'] = '';
        }
    }

    #[Override]
    public function getTags(): void
    {
        $tags = [
            'reservationrequest.action' => __('Notification reason', 'advancedforms'),
            'reservationrequest.begin' => __('Reservation start date', 'advancedforms'),
            'reservationrequest.end' => __('Reservation end date', 'advancedforms'),
            'reservationrequest.comment' => __('Validation comment', 'advancedforms'),
            'reservationrequest.ticket_title' => __('Ticket title', 'advancedforms'),
            'reservationrequest.ticket_url' => __('Ticket URL', 'advancedforms'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList([
                'tag' => $tag,
                'label' => $label,
                'value' => true,
            ]);
        }

        parent::getTags();
    }

    #[Override]
    public function getObjectItem($event = '')
    {
        if ($this->obj instanceof TicketReservationRequest) {
            $tickets_id = $this->obj->fields['tickets_id'];
            $ticket = new Ticket();
            if (
                (is_int($tickets_id) || is_string($tickets_id))
                && $ticket->getFromDB($tickets_id)
            ) {
                $this->target_object[] = $ticket;
                return;
            }
        }

        parent::getObjectItem($event);
    }
}
