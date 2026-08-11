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
use Glpi\Form\AnswersSet;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestionAnswer;
use InvalidArgumentException;

enum PreReservationFieldStrategy: string
{
    case NO_PRERESERVATION = 'no_prereservation';
    case SPECIFIC_ANSWER = 'specific_answer';
    case LAST_VALID_ANSWER = 'last_valid_answer';
    case ALL_VALID_ANSWERS = 'all_valid_answers';

    public function getLabel(): string
    {
        return match ($this) {
            self::NO_PRERESERVATION => __('No pre-reservation', 'advancedforms'),
            self::SPECIFIC_ANSWER => __('Answer from a specific question', 'advancedforms'),
            self::LAST_VALID_ANSWER => __('Answer to last "Material reservation" question', 'advancedforms'),
            self::ALL_VALID_ANSWERS => __('All valid "Material reservation" answers', 'advancedforms'),
        };
    }

    /**
     * Resolves the answers this strategy should turn into pre-reservations.
     * Only syntactically valid answers (parseable, coherent timeframe) are
     * returned; per-item authorization is checked separately by the caller.
     *
     * @return array<Answer>
     */
    public function getReservationAnswers(
        PreReservationFieldConfig $config,
        AnswersSet $answers_set,
    ): array {
        return match ($this) {
            self::NO_PRERESERVATION => [],
            self::SPECIFIC_ANSWER => $this->getAnswerForSpecificQuestion(
                $config->getSpecificQuestionId(),
                $answers_set,
            ),
            self::LAST_VALID_ANSWER => $this->getLastValidAnswer($answers_set),
            self::ALL_VALID_ANSWERS => $this->getValidAnswers($answers_set),
        };
    }

    /** @return array<Answer> */
    private function getAnswerForSpecificQuestion(
        ?int $question_id,
        AnswersSet $answers_set,
    ): array {
        if ($question_id === null) {
            return [];
        }

        $answer = $answers_set->getAnswerByQuestionId($question_id);
        if (!$answer instanceof Answer || !$this->isValidAnswer($answer)) {
            return [];
        }

        return [$answer];
    }

    /** @return array<Answer> */
    private function getLastValidAnswer(AnswersSet $answers_set): array
    {
        $valid_answers = $this->getValidAnswers($answers_set);
        if ($valid_answers === []) {
            return [];
        }

        return [end($valid_answers)];
    }

    /** @return array<Answer> */
    private function getValidAnswers(AnswersSet $answers_set): array
    {
        return array_values(array_filter(
            $answers_set->getAnswersByType(ReservationQuestion::class),
            $this->isValidAnswer(...),
        ));
    }

    private function isValidAnswer(Answer $answer): bool
    {
        $raw_answer = $answer->getRawAnswer();
        if (!is_array($raw_answer)) {
            return false;
        }

        try {
            $parsed = ReservationQuestionAnswer::fromArray($raw_answer);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $parsed->isValidRange();
    }
}
