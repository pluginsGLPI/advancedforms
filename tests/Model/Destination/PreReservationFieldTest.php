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

namespace GlpiPlugin\Advancedforms\Tests\Model\Destination;

use Glpi\Form\QuestionType\QuestionTypeCheckbox;
use Glpi\Form\QuestionType\QuestionTypeSelectableExtraDataConfig;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\Destination\PreReservationField;
use GlpiPlugin\Advancedforms\Model\Destination\PreReservationFieldConfig;
use GlpiPlugin\Advancedforms\Model\Destination\PreReservationFieldStrategy;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestion;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Reservation;
use ReservationItem;
use Session;
use Ticket;

final class PreReservationFieldTest extends AdvancedFormsTestCase
{
    public function testNoPrereservationCreatesNothing(): void
    {
        [$ticket] = $this->submitReservationForm(PreReservationFieldStrategy::NO_PRERESERVATION, true);

        $request = new TicketReservationRequest();
        $this->assertCount(0, $request->find(['tickets_id' => $ticket->getID()]));
    }

    public function testWithApprovalCreatesWaitingRequestOnly(): void
    {
        [$ticket, $item] = $this->submitReservationForm(PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION, true);

        $request = new TicketReservationRequest();
        $rows = $request->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(1, $rows);

        $row = reset($rows);
        $this->assertSame(TicketReservationRequest::STATUS_WAITING, (int) $row['status']);
        $this->assertSame($ticket->getID(), (int) $row['tickets_id']);
        $this->assertSame($item->getID(), (int) $row['reservationitems_id']);
        $this->assertSame('2026-04-10 09:00:00', $row['begin']);
        $this->assertSame('2026-04-10 10:00:00', $row['end']);
        $this->assertSame(Session::getLoginUserID(), (int) $row['users_id']);

        $reservation = new Reservation();
        $this->assertCount(0, $reservation->find(['reservationitems_id' => $item->getID()]));
    }

    public function testDirectModeSlotFreeAutoApproves(): void
    {
        [$ticket, $item] = $this->submitReservationForm(PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION, false);

        $request = new TicketReservationRequest();
        $rows = $request->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(1, $rows);
        $this->assertSame(TicketReservationRequest::STATUS_ACCEPTED, (int) reset($rows)['status']);

        $reservation = new Reservation();
        $found = $reservation->find(['reservationitems_id' => $item->getID()]);
        $this->assertCount(1, $found);
        $this->assertSame(Session::getLoginUserID(), (int) reset($found)['users_id']);
    }

    public function testDirectModeSlotConflictingCancels(): void
    {
        $item = $this->getReservableItem();

        // Pre-existing reservation that overlaps the answer's timeframe.
        $reservation = new Reservation();
        $this->assertGreaterThan(0, $reservation->add([
            'reservationitems_id' => $item->getID(),
            'begin' => '2026-04-01 09:00:00',
            'end' => '2026-04-01 11:00:00',
            'users_id' => Session::getLoginUserID(),
            'comment' => '',
        ]));

        [$ticket] = $this->submitReservationForm(
            PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION,
            false,
            item: $item,
            begin: '2026-04-01 10:00:00',
            end: '2026-04-01 12:00:00',
        );

        $request = new TicketReservationRequest();
        $rows = $request->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(1, $rows);
        $this->assertSame(TicketReservationRequest::STATUS_CANCELED, (int) reset($rows)['status']);

        // Only the original, pre-existing reservation should exist.
        $this->assertCount(1, $reservation->find(['reservationitems_id' => $item->getID()]));
    }

    public function testUnansweredQuestionCreatesNoRequestAndDoesNotCrash(): void
    {
        [$ticket] = $this->submitReservationForm(
            PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION,
            true,
            answer_question: false,
        );

        $request = new TicketReservationRequest();
        $this->assertCount(0, $request->find(['tickets_id' => $ticket->getID()]));
    }

    public function testAnswerWithMismatchedShapeCreatesNoRequestAndDoesNotCrash(): void
    {
        $this->login();
        $this->enableConfigurableItem(new ReservationQuestion());

        $builder = new FormBuilder("Reservation form with mismatched pre-reservation question");
        $builder->addQuestion("Reservation", ReservationQuestion::class);
        $builder->addQuestion(
            "Not a reservation",
            QuestionTypeCheckbox::class,
            extra_data: json_encode(new QuestionTypeSelectableExtraDataConfig([
                'foo' => 'Foo',
                'bar' => 'Bar',
            ])),
        );
        $form = $this->createForm($builder);

        // Point the pre-reservation config at the checkbox question rather
        // than the actual `ReservationQuestion`.
        $question_id = $this->getQuestionId($form, "Not a reservation");

        $config = new PreReservationFieldConfig(
            strategy: PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION,
            question_id: $question_id,
            require_approval: true,
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
            "Not a reservation" => ['foo', 'bar'],
        ]);

        // Ticket creation must not be blocked by the mismatched answer shape.
        $this->assertGreaterThan(0, $ticket->getID());

        $request = new TicketReservationRequest();
        $this->assertCount(0, $request->find(['tickets_id' => $ticket->getID()]));
    }

    public function testInvalidTimeRangeCreatesNoRequest(): void
    {
        // End before begin: the answer must be rejected server-side.
        [$ticket] = $this->submitReservationForm(
            PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION,
            true,
            begin: '2026-04-10 11:00:00',
            end: '2026-04-10 09:00:00',
        );

        $request = new TicketReservationRequest();
        $this->assertCount(0, $request->find(['tickets_id' => $ticket->getID()]));
    }

    public function testInactiveReservableItemCreatesNoRequest(): void
    {
        $computer = $this->createItem('Computer', [
            'name' => 'prereservationfield-inactive-computer',
            'entities_id' => $this->getTestRootEntity(true),
        ]);
        $inactive_item = $this->createItem(ReservationItem::class, [
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'is_active' => 0,
        ]);

        [$ticket] = $this->submitReservationForm(
            PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION,
            false,
            item: $inactive_item,
        );

        $request = new TicketReservationRequest();
        $this->assertCount(0, $request->find(['tickets_id' => $ticket->getID()]));
    }

    /** @return array{0: Ticket, 1: ReservationItem} */
    private function submitReservationForm(
        PreReservationFieldStrategy $strategy,
        bool $require_approval,
        ?ReservationItem $item = null,
        string $begin = '2026-04-10 09:00:00',
        string $end = '2026-04-10 10:00:00',
        bool $answer_question = true,
    ): array {
        $this->login();
        $this->enableConfigurableItem(new ReservationQuestion());

        $item ??= $this->getReservableItem();

        $builder = new FormBuilder("Reservation form");
        $builder->addQuestion("Reservation", ReservationQuestion::class);
        $form = $this->createForm($builder);

        $question_id = $strategy === PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION
            ? $this->getQuestionId($form, "Reservation")
            : null;

        $config = new PreReservationFieldConfig(
            strategy: $strategy,
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

        $answers = [];
        if ($answer_question) {
            $answers['Reservation'] = [
                'reservationitems_id' => $item->getID(),
                'begin' => $begin,
                'end' => $end,
            ];
        }

        $ticket = $this->sendFormAndGetCreatedTicket($form, $answers);

        return [$ticket, $item];
    }

    private function getReservableItem(): ReservationItem
    {
        $computer = $this->createItem('Computer', [
            'name' => 'prereservationfield-test-computer',
            'entities_id' => $this->getTestRootEntity(true),
        ]);

        return $this->createItem(ReservationItem::class, [
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'is_active' => 1,
        ]);
    }
}
