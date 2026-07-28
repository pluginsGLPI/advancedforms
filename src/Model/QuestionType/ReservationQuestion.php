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

namespace GlpiPlugin\Advancedforms\Model\QuestionType;

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionType;
use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use InvalidArgumentException;
use Override;

use function Safe\json_decode;

final class ReservationQuestion extends AbstractQuestionType implements ConfigurableItemInterface
{
    #[Override]
    public function getCategory(): QuestionTypeCategoryInterface
    {
        return new AdvancedCategory();
    }

    #[Override]
    public function getName(): string
    {
        return __('Material reservation', 'advancedforms');
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-calendar-event';
    }

    #[Override]
    public function getWeight(): int
    {
        return 50;
    }

    #[Override]
    public function getExtraDataConfigClass(): string
    {
        return ReservationQuestionConfig::class;
    }

    /** @param array<mixed> $input */
    #[Override]
    public function validateExtraDataInput(array $input): bool
    {
        return true;
    }

    #[Override]
    public function renderAdministrationTemplate(Question|null $question): string
    {
        $decoded_extra_data = [];
        if ($question instanceof Question && is_string($question->fields['extra_data'])) {
            $decoded_extra_data = json_decode(
                $question->fields['extra_data'],
                associative: true,
            );

            if (!is_array($decoded_extra_data)) {
                $decoded_extra_data = [];
            }
        }

        $config = $this->getExtraDataConfig($decoded_extra_data);
        if (!$config instanceof JsonFieldInterface) {
            $config = new ReservationQuestionConfig();
        }

        global $CFG_GLPI;
        $reservation_types = [];
        $configured_types = $CFG_GLPI['reservation_types'] ?? [];
        if (is_array($configured_types)) {
            foreach ($configured_types as $itemtype) {
                if (is_string($itemtype) && class_exists($itemtype)) {
                    $reservation_types[$itemtype] = $itemtype::getTypeName();
                }
            }
        }

        $twig = TemplateRenderer::getInstance();
        return $twig->render(
            '@advancedforms/editor/question_types/reservation_config.html.twig',
            [
                'question'          => $question,
                'extra_data'        => $config,
                'reservation_types' => $reservation_types,
                'ALLOWED_ITEMTYPES' => ReservationQuestionConfig::ALLOWED_ITEMTYPES,
            ],
        );
    }

    #[Override]
    public function renderEndUserTemplate(Question $question): string
    {
        $decoded_extra_data = [];
        if (is_string($question->fields['extra_data'])) {
            $decoded_extra_data = json_decode(
                $question->fields['extra_data'],
                associative: true,
            );

            if (!is_array($decoded_extra_data)) {
                $decoded_extra_data = [];
            }
        }

        $config = $this->getExtraDataConfig($decoded_extra_data);
        if (!$config instanceof ReservationQuestionConfig) {
            $config = new ReservationQuestionConfig();
        }

        return TemplateRenderer::getInstance()->render('@advancedforms/reservation_question.html.twig', [
            'input_name'        => $question->getEndUserInputName(),
            'allowed_itemtypes' => $config->getAllowedItemtypes(),
        ]);
    }

    #[Override]
    public function prepareEndUserAnswer(Question $question, mixed $answer): mixed
    {
        if (!is_array($answer)) {
            return null;
        }

        try {
            $parsed = ReservationQuestionAnswer::fromArray($answer);
        } catch (InvalidArgumentException) {
            return null;
        }

        // Drop incoherent timeframes (end before begin, etc.) rather than store them.
        if (!$parsed->isValidRange()) {
            return null;
        }

        return $parsed->toArray();
    }

    #[Override]
    public function formatRawAnswer(mixed $answer, Question $question): string
    {
        if (!is_array($answer)) {
            return '';
        }

        try {
            $parsed = ReservationQuestionAnswer::fromArray($answer);
        } catch (InvalidArgumentException) {
            return '';
        }

        $slot = sprintf('%s → %s', $parsed->getBegin(), $parsed->getEnd());
        $equipment_name = TicketReservationRequest::getReservableItemName($parsed->getReservationItemsId());

        return $equipment_name !== ''
            ? sprintf('%s (%s)', $equipment_name, $slot)
            : $slot;
    }

    #[Override]
    public static function getConfigKey(): string
    {
        return "enable_question_type_reservation";
    }

    #[Override]
    public function getConfigTitle(): string
    {
        return __("Material reservation question type", 'advancedforms');
    }

    #[Override]
    public function getConfigDescription(): string
    {
        return __("Allow users to reserve equipment from a form.", 'advancedforms');
    }

    #[Override]
    public function getConfigIcon(): string
    {
        return $this->getIcon();
    }
}
