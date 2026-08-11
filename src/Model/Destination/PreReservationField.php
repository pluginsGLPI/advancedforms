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

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\Answer;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\AbstractCommonITILFormDestination;
use Glpi\Form\Destination\AbstractConfigField;
use Glpi\Form\Destination\CommonITILField\Category;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Export\Context\DatabaseMapper;
use Glpi\Form\Export\Serializer\DynamicExportDataField;
use Glpi\Form\Export\Specification\DataRequirementSpecification;
use Glpi\Form\Form;
use Glpi\Form\Question;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestionAnswer;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestionConfig;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use InvalidArgumentException;
use ITILFollowup;
use Override;
use ReservationItem;
use Safe\Exceptions\JsonException;
use Session;
use Ticket;

use function Safe\json_decode;

/** Turns ReservationQuestion answers into TicketReservationRequest(s) once the destination Ticket is created. */
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
        return 40;
    }

    #[Override]
    public function getCategory(): Category
    {
        return Category::TIMELINE;
    }

    #[Override]
    public function getDefaultConfig(Form $form): PreReservationFieldConfig
    {
        // A question of this type is a strong signal that the destination
        // should use it; default to the safest question-backed strategy
        // rather than silently doing nothing.
        return new PreReservationFieldConfig(PreReservationFieldStrategy::LAST_VALID_ANSWER);
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
            'CONFIG_SPECIFIC_ANSWER' => PreReservationFieldStrategy::SPECIFIC_ANSWER->value,
            'CONFIG_LAST_VALID_ANSWER' => PreReservationFieldStrategy::LAST_VALID_ANSWER->value,
            'CONFIG_ALL_VALID_ANSWERS' => PreReservationFieldStrategy::ALL_VALID_ANSWERS->value,

            'options' => $display_options,

            'question_extra_field' => [
                'empty_label'     => __("Select a question...", 'advancedforms'),
                'value'           => $config->getSpecificQuestionId(),
                'input_name'      => $input_name . "[" . PreReservationFieldConfig::SPECIFIC_QUESTION_ID . "]",
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

        // Only one strategy is allowed.
        $strategy = $config->getStrategies()[0];
        $answers = $strategy->getReservationAnswers($config, $answers_set);
        if ($answers === []) {
            return;
        }

        $destination_items = $created_objects[$destination->getID()] ?? [];
        $ticket = $destination_items[0] ?? null;
        if (!$ticket instanceof Ticket) {
            return;
        }

        // A pre-reservation without a real requester makes no sense (e.g. anonymous form access).
        // @phpstan-ignore cast.int (CommonDBTM::$fields is not generically typed)
        $users_id = (int) ($answers_set->fields['users_id'] ?? 0);
        if ($users_id <= 0) {
            $this->logAndNotifyFailure(
                $ticket,
                sprintf('Pre-reservation not created for ticket #%d: no identifiable requester.', $ticket->getID()),
                __('The pre-reservation could not be created: no identifiable requester.', 'advancedforms'),
            );
            return;
        }

        $require_approval = $config->isApprovalRequired();

        foreach ($answers as $answer) {
            $this->createReservationRequest($ticket, $answer, $users_id, $require_approval);
        }
    }

    private function createReservationRequest(
        Ticket $ticket,
        Answer $answer,
        int $users_id,
        bool $require_approval,
    ): void {
        $raw_answer = $answer->getRawAnswer();
        if (!is_array($raw_answer)) {
            return;
        }

        try {
            $parsed = ReservationQuestionAnswer::fromArray($raw_answer);
        } catch (InvalidArgumentException) {
            return;
        }

        // Reject incoherent timeframes (end before begin, etc.).
        if (!$parsed->isValidRange()) {
            return;
        }

        // The item id comes from a client-controlled hidden field: re-check it is
        // an active reservable item, in a visible entity, allowed by the question configuration.
        if (!$this->isAnswerItemAllowed($parsed->getReservationItemsId(), $answer->getQuestionId())) {
            $this->logAndNotifyFailure(
                $ticket,
                sprintf(
                    'Pre-reservation not created for ticket #%d: item #%d is not allowed.',
                    $ticket->getID(),
                    $parsed->getReservationItemsId(),
                ),
                __('The pre-reservation could not be created: the selected item is not allowed.', 'advancedforms'),
            );
            return;
        }

        $add_input = [
            'tickets_id'          => $ticket->getID(),
            'reservationitems_id' => $parsed->getReservationItemsId(),
            'users_id'            => $users_id,
            'begin'               => $parsed->getBegin(),
            'end'                 => $parsed->getEnd(),
            'status'              => TicketReservationRequest::STATUS_WAITING,
        ];

        if (!$require_approval) {
            $add_input['_disablenotif'] = true;
        }

        $request = new TicketReservationRequest();
        $request_id = $request->add($add_input);

        if (!$request_id) {
            trigger_error(
                sprintf('Failed to create the pre-reservation request for ticket #%d.', $ticket->getID()),
                E_USER_WARNING,
            );
            return;
        }

        if (!$require_approval) {
            // Evaluate availability once: approve() itself creates the Reservation,
            // which would make a second isSlotStillAvailable() call return false.
            $slot_was_available = $request->isSlotStillAvailable();
            if (!$slot_was_available || !$request->approve(0, '')) {
                $request->markUnavailable();
            }
        }
    }

    /** Logs the failure and adds a visible followup so the technician isn't left guessing why no reservation exists. */
    private function logAndNotifyFailure(Ticket $ticket, string $log_message, string $followup_message): void
    {
        trigger_error($log_message, E_USER_WARNING);

        $followup = new ITILFollowup();
        $followup->add([
            'itemtype'      => Ticket::class,
            'items_id'      => $ticket->getID(),
            'content'       => $followup_message,
            '_disablenotif' => true,
        ]);
    }

    /**
     * Whether the answered item is a still-active reservable item, in a visible
     * entity, that the question accepts.
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

        $entities_id = $reservation_item->fields['entities_id'] ?? -1;
        $is_recursive = $reservation_item->fields['is_recursive'] ?? 0;
        if (!is_numeric($entities_id) || !Session::haveAccessToEntity((int) $entities_id, (bool) $is_recursive)) {
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

    /** @param array<string, mixed> $config */
    #[Override]
    public function exportDynamicConfig(
        array $config,
        AbstractCommonITILFormDestination $destination,
    ): DynamicExportDataField {
        $fallback = parent::exportDynamicConfig($config, $destination);

        $question_id = $config[PreReservationFieldConfig::SPECIFIC_QUESTION_ID] ?? null;
        if (!is_int($question_id)) {
            return $fallback;
        }

        $question = Question::getById($question_id);
        if (!$question instanceof Question) {
            $config[PreReservationFieldConfig::SPECIFIC_QUESTION_ID] = null;
            return new DynamicExportDataField($config, []);
        }

        // Insert question name and requirement.
        $requirement = DataRequirementSpecification::fromItem($question);
        $config[PreReservationFieldConfig::SPECIFIC_QUESTION_ID] = $requirement->name;

        return new DynamicExportDataField($config, [$requirement]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    #[Override]
    public static function prepareDynamicConfigDataForImport(
        array $config,
        AbstractCommonITILFormDestination $destination,
        DatabaseMapper $mapper,
    ): array {
        $specific_question_name = $config[PreReservationFieldConfig::SPECIFIC_QUESTION_ID] ?? null;
        if (is_string($specific_question_name)) {
            $config[PreReservationFieldConfig::SPECIFIC_QUESTION_ID] = $mapper->getItemId(
                Question::class,
                $specific_question_name,
            );
        }

        return $config;
    }
}
