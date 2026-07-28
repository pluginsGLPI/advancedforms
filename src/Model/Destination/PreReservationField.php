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
use Glpi\Form\Question;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestionAnswer;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestionConfig;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use InvalidArgumentException;
use Override;
use ReservationItem;
use Safe\Exceptions\JsonException;
use Ticket;

use function Safe\json_decode;

/** Turns a ReservationQuestion answer into a TicketReservationRequest once the destination Ticket is created. */
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
            'CONFIG_FROM_SPECIFIC_QUESTION' => PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION->value,

            'options' => $display_options,

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

        $strategy = current($config->getStrategies());
        if ($strategy !== PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION) {
            return;
        }

        $question_id = $config->getQuestionId();
        if ($question_id === null) {
            return;
        }

        $destination_items = $created_objects[$destination->getID()] ?? [];
        $ticket = $destination_items[0] ?? null;
        if (!$ticket instanceof Ticket) {
            return;
        }

        $question_answer = $answers_set->getAnswerByQuestionId($question_id);
        if (!$question_answer instanceof Answer) {
            return;
        }

        $raw_answer = $question_answer->getRawAnswer();
        if (!is_array($raw_answer)) {
            return;
        }

        try {
            $answer = ReservationQuestionAnswer::fromArray($raw_answer);
        } catch (InvalidArgumentException) {
            return;
        }

        // Reject incoherent timeframes (end before begin, etc.).
        if (!$answer->isValidRange()) {
            return;
        }

        // A pre-reservation without a real requester makes no sense.
        // @phpstan-ignore cast.int (CommonDBTM::$fields is not generically typed)
        $users_id = (int) ($answers_set->fields['users_id'] ?? 0);
        if ($users_id <= 0) {
            return;
        }

        // The item id comes from a client-controlled hidden field: re-check it is
        // an active reservable item allowed by the question configuration.
        if (!$this->isAnswerItemAllowed($answer->getReservationItemsId(), $question_id)) {
            return;
        }

        $require_approval = $config->isApprovalRequired();

        $add_input = [
            'tickets_id'          => $ticket->getID(),
            'reservationitems_id' => $answer->getReservationItemsId(),
            'users_id'            => $users_id,
            'begin'               => $answer->getBegin(),
            'end'                 => $answer->getEnd(),
            'status'              => TicketReservationRequest::STATUS_WAITING,
        ];

        if (!$require_approval) {
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

    /**
     * Whether the answered item is a still-active reservable item that the question accepts.
     * The item id is client-controlled, so it must never be trusted as-is.
     */
    private function isAnswerItemAllowed(int $reservationitems_id, int $question_id): bool
    {
        if ($reservationitems_id <= 0) {
            return false;
        }

        $reservation_item = new ReservationItem();
        if (!$reservation_item->getFromDB($reservationitems_id)) {
            return false;
        }

        $is_active = $reservation_item->fields['is_active'] ?? 0;
        if (!is_numeric($is_active) || (int) $is_active !== 1) {
            return false;
        }

        $allowed_itemtypes = $this->getConfiguredAllowedItemtypes($question_id);
        if ($allowed_itemtypes === []) {
            // Question accepts any reservable item; being active is enough.
            return true;
        }

        $itemtype = $reservation_item->fields['itemtype'] ?? '';

        return is_string($itemtype) && in_array($itemtype, $allowed_itemtypes, true);
    }

    /**
     * Itemtypes explicitly whitelisted on the question, or [] when unrestricted.
     *
     * @return array<string>
     */
    private function getConfiguredAllowedItemtypes(int $question_id): array
    {
        $question = Question::getById($question_id);
        if (!$question instanceof Question) {
            return [];
        }

        $extra_data = $question->fields['extra_data'] ?? null;
        if (!is_string($extra_data) || $extra_data === '') {
            return [];
        }

        try {
            $decoded = json_decode($extra_data, associative: true);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array{allowed_itemtypes?: array<string>} $decoded */
        return ReservationQuestionConfig::jsonDeserialize($decoded)->getAllowedItemtypes();
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
