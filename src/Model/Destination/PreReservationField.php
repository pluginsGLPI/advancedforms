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

namespace GlpiPlugin\Advancedforms\Model\Destination;

use Glpi\Form\Answer;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\AbstractConfigField;
use Glpi\Form\Destination\CommonITILField\Category;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Form;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestionAnswer;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use InvalidArgumentException;
use Override;
use Ticket;

/**
 * Destination config field allowing a form to automatically create a
 * `TicketReservationRequest` from the answer of a `ReservationQuestion` once
 * the destination `Ticket` has been created.
 */
final class PreReservationField extends AbstractConfigField
{
    #[Override]
    public function getLabel(): string
    {
        return __('Pre-reservation', 'advancedforms');
    }

    #[Override]
    public function getConfigClass(): string
    {
        return PreReservationFieldConfig::class;
    }

    #[Override]
    public function getWeight(): int
    {
        return 1000;
    }

    #[Override]
    public function getCategory(): Category
    {
        return Category::PROPERTIES;
    }

    #[Override]
    public function getDefaultConfig(Form $form): PreReservationFieldConfig
    {
        return new PreReservationFieldConfig(PreReservationFieldStrategy::NO_PRERESERVATION);
    }

    /** @return array<string, string> */
    #[Override]
    public function getStrategiesForDropdown(): array
    {
        $values = [];
        foreach (PreReservationFieldStrategy::cases() as $strategy) {
            $values[$strategy->value] = $strategy->getLabel();
        }

        return $values;
    }

    /** @param array<string, mixed> $display_options */
    #[Override]
    public function renderConfigForm(
        Form $form,
        FormDestination $destination,
        JsonFieldInterface $config,
        string $input_name,
        array $display_options,
    ): string {
        if (!$config instanceof PreReservationFieldConfig) {
            throw new InvalidArgumentException("Unexpected config class");
        }

        $twig = TemplateRenderer::getInstance();
        return $twig->render('@advancedforms/destination/prereservation_config_field.html.twig', [
            // Possible configuration constant that will be used to hide/show additional fields
            'CONFIG_FROM_SPECIFIC_QUESTION' => PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION->value,

            // General display options
            'options' => $display_options,

            // Additional config displayed only for the FROM_SPECIFIC_QUESTION strategy
            'question_extra_field' => [
                'empty_label'     => __("Select a question...", 'advancedforms'),
                'value'           => $config->getQuestionId(),
                'input_name'      => $input_name . "[" . PreReservationFieldConfig::QUESTION_ID . "]",
                'possible_values' => $this->getReservationQuestionsValuesForDropdown($form),
            ],
            'require_approval_extra_field' => [
                'value'      => $config->isApprovalRequired(),
                'input_name' => $input_name . "[" . PreReservationFieldConfig::REQUIRE_APPROVAL . "]",
            ],
        ]);
    }

    #[Override]
    public function applyConfiguratedValueAfterDestinationCreation(
        FormDestination $destination,
        JsonFieldInterface $config,
        AnswersSet $answers_set,
        array $created_objects,
    ): void {
        if (!$config instanceof PreReservationFieldConfig) {
            return;
        }

        // Only one strategy is allowed
        $strategy = current($config->getStrategies());
        if ($strategy !== PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION) {
            // Nothing to do: no pre-reservation was requested for this destination.
            return;
        }

        $question_id = $config->getQuestionId();
        if ($question_id === null) {
            return;
        }

        // `$created_objects` maps each destination's id to the items it
        // created (see `DestinationFieldInterface::applyConfiguratedValueAfterDestinationCreation()`
        // and `AnswersHandler::createDestinations()`), it is not a flat list.
        $destination_items = $created_objects[$destination->getID()] ?? [];
        $ticket = $destination_items[0] ?? null;
        if (!$ticket instanceof Ticket) {
            return;
        }

        $question_answer = $answers_set->getAnswerByQuestionId($question_id);
        if (!$question_answer instanceof Answer) {
            // The question was left unanswered (or skipped): nothing to do.
            return;
        }

        $raw_answer = $question_answer->getRawAnswer();
        if (!is_array($raw_answer)) {
            return;
        }

        try {
            $answer = ReservationQuestionAnswer::fromArray($raw_answer);
        } catch (InvalidArgumentException) {
            // Incomplete/invalid answer: do not block the ticket creation.
            return;
        }

        $require_approval = $config->isApprovalRequired();

        $add_input = [
            'tickets_id'          => $ticket->getID(),
            'reservationitems_id' => $answer->getReservationItemsId(),
            // @phpstan-ignore cast.int (CommonDBTM::$fields is not generically typed)
            'users_id'            => (int) ($answers_set->fields['users_id'] ?? 0),
            'begin'               => $answer->getBegin(),
            'end'                 => $answer->getEnd(),
            'status'              => TicketReservationRequest::STATUS_WAITING,
        ];

        if (!$require_approval) {
            // In direct/no-approval mode, the WAITING status set above is
            // only a transient step before immediately transitioning this
            // request to its real final state below (ACCEPTED/CANCELED),
            // which each raise their own distinct notification event. The
            // request never actually waits for approval, so the "please wait
            // for approval" notification must be suppressed for this insert.
            // (The key must only be present when disabling: `isset()` is what
            // `TicketReservationRequest::post_addItem()` checks, and it is
            // `true` even for an explicit `false` value, matching the
            // `_disablenotif` convention used throughout GLPI core.)
            $add_input['_disablenotif'] = true;
        }

        $request = new TicketReservationRequest();
        $request_id = $request->add($add_input);

        if (!$request_id) {
            return;
        }

        if (!$require_approval) {
            if ($request->isSlotStillAvailable()) {
                $request->approve(0, '');
            } else {
                $request->markUnavailable();
            }
        }
    }

    /** @return array<int, string> */
    private function getReservationQuestionsValuesForDropdown(Form $form): array
    {
        $values = [];
        $questions = $form->getQuestionsByType(ReservationQuestion::class);

        foreach ($questions as $question) {
            // @phpstan-ignore cast.string (CommonDBTM::$fields is not generically typed)
            $values[$question->getId()] = (string) $question->fields['name'];
        }

        return $values;
    }
}
