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

namespace GlpiPlugin\Advancedforms\Model\Destination\Strategies;

use DateMalformedStringException;
use DateInterval;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Form\Answer;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\CommonITILField\SLMField;
use Glpi\Form\Destination\CommonITILField\SLMFieldConfig;
use Glpi\Form\Destination\CommonITILField\SLMFieldStrategyInterface;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeDateTime;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use LevelAgreement;
use Override;
use Safe\DateTime;

final class ComputedFromSpecificDateAnswerSLMStrategy implements ConfigurableItemInterface, SLMFieldStrategyInterface
{
    public const KEY                       = 'advancedforms_computed_from_specific_date_answer';

    public const CONFIG_KEY                = 'slm_strategy_computed_from_specific_date_answer';

    public const EXTRA_KEY_QUESTION_ID     = 'question_id';

    public const EXTRA_KEY_TIME_OFFSET     = 'time_offset';

    public const EXTRA_KEY_TIME_DEFINITION = 'time_definition';

    #[Override]
    public static function getConfigKey(): string
    {
        return self::CONFIG_KEY;
    }

    #[Override]
    public function getConfigTitle(): string
    {
        return __('Computed date from specific date question', 'advancedforms');
    }

    #[Override]
    public function getConfigDescription(): string
    {
        return __('Set SLA/OLA based on a specific date answer from the form plus/minus a defined offset.', 'advancedforms');
    }

    #[Override]
    public function getConfigIcon(): string
    {
        return 'ti ti-calendar';
    }

    #[Override]
    public function getKey(): string
    {
        return self::KEY;
    }

    #[Override]
    public function getLabel(SLMField $field): string
    {
        return __('Computed date from specific date question', 'advancedforms');
    }

    #[Override]
    public function applyStrategyToInput(
        SLMField $field,
        SLMFieldConfig $config,
        array $input,
        AnswersSet $answers_set,
    ): array {
        // Get the question ID, time offset and time definition from the configuration
        $question_id     = $config->getExtraDataValue(self::EXTRA_KEY_QUESTION_ID);
        $time_offset     = $config->getExtraDataValue(self::EXTRA_KEY_TIME_OFFSET);
        $time_definition = $config->getExtraDataValue(self::EXTRA_KEY_TIME_DEFINITION);

        // Validate configuration values
        if (!is_numeric($question_id) || !is_numeric($time_offset) || !is_string($time_definition)) {
            return $input;
        }

        // Get and validate the answer from the answers set
        $answer = $answers_set->getAnswerByQuestionId((int) $question_id);
        if (!$answer instanceof Answer) {
            return $input;
        }

        // Get and validate the raw value from the answer
        $string_date = $answer->getRawAnswer();
        if (empty($string_date) || !is_string($string_date)) {
            return $input;
        }

        // Create DateTime object from the answer's raw value
        try {
            $date = new DateTime($string_date);
        } catch (DateMalformedStringException) {
            return $input;
        }

        // Apply the offset based on the time definition
        $interval_spec = match ($time_definition) {
            'minute' => 'PT' . abs((int) $time_offset) . 'M', // Minutes
            'hour'   => 'PT' . abs((int) $time_offset) . 'H', // Hours
            'day'    => 'P' . abs((int) $time_offset) . 'D', // Days
            default  => 'P' . abs((int) $time_offset) . 'M', // Months
        };

        $interval = new DateInterval($interval_spec);
        if ((int) $time_offset < 0) {
            $date->sub($interval);
        } else {
            $date->add($interval);
        }

        // Apply the computed date to the input array
        $slm = $field->getSLM();
        $field_names = $slm::getFieldNames($field->getType());
        if (!empty($field_names) && is_string($field_names[0])) {
            $input[$field_names[0]] = $date->format('Y-m-d H:i:s');
        }

        return $input;
    }

    #[Override]
    public function renderExtraConfigFields(
        Form $form,
        SLMField $field,
        SLMFieldConfig $config,
        string $input_name,
        array $display_options,
    ): string {
        $twig = TemplateRenderer::getInstance();

        return $twig->render('@advancedforms/editor/destinations/strategies/computed_date_from_specific_date_answer_slm_strategy.html.twig', [
            'strategy_key'   => self::KEY,
            'options'        => $display_options,
            'extra_question_id_field'    => [
                'empty_label'     => __("Select a question..."),
                'value'           => $config->getExtraDataValue(self::EXTRA_KEY_QUESTION_ID),
                'input_name'      => $input_name . "[" . SLMFieldConfig::EXTRA_DATA . "][" . self::EXTRA_KEY_QUESTION_ID . "]",
                'possible_values' => $this->getDateQuestionsForDropdown($form),
            ],
            'extra_time_offset_field'    => [
                'aria_label'      => __('Enter time offset', 'advancedforms'),
                'value'           => $config->getExtraDataValue(self::EXTRA_KEY_TIME_OFFSET),
                'input_name'      => $input_name . "[" . SLMFieldConfig::EXTRA_DATA . "][" . self::EXTRA_KEY_TIME_OFFSET . "]",
                'min'             => -30,
                'max'             => 30,
            ],
            'extra_time_definition_field'    => [
                'aria_label'      => __('Select time definition', 'advancedforms'),
                'value'           => $config->getExtraDataValue(self::EXTRA_KEY_TIME_DEFINITION),
                'input_name'      => $input_name . "[" . SLMFieldConfig::EXTRA_DATA . "][" . self::EXTRA_KEY_TIME_DEFINITION . "]",
                'possible_values' => LevelAgreement::getDefinitionTimeValues(),
            ],
        ]);
    }

    #[Override]
    public function getExtraConfigKeys(): array
    {
        return [self::EXTRA_KEY_QUESTION_ID, self::EXTRA_KEY_TIME_OFFSET, self::EXTRA_KEY_TIME_DEFINITION];
    }

    #[Override]
    public function getWeight(): int
    {
        return 70;
    }

    /**
     * Get date questions available in the form for the dropdown.
     *
     * @return array<int, string>
     */
    private function getDateQuestionsForDropdown(Form $form): array
    {
        $values = [];
        $questions = $form->getQuestionsByType(QuestionTypeDateTime::class);

        foreach ($questions as $question) {
            $values[$question->getId()] = $question->getName();
        }

        return $values;
    }
}
