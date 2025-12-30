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

use Glpi\Form\Answer;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\CommonITILField\SLMField;
use Glpi\Form\Destination\CommonITILField\SLMFieldConfig;
use Glpi\Form\Destination\CommonITILField\SLMFieldStrategyInterface;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeDateTime;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use Override;

final class SpecificDateAnswerSLMStrategy implements ConfigurableItemInterface, SLMFieldStrategyInterface
{
    public const KEY = 'advancedforms_specific_date_answer';

    public const CONFIG_KEY = 'slm_strategy_specific_date_answer';

    public const EXTRA_KEY_QUESTION_ID = 'question_id';

    #[Override]
    public static function getConfigKey(): string
    {
        return self::CONFIG_KEY;
    }

    #[Override]
    public function getConfigTitle(): string
    {
        return __('Specific date answer', 'advancedforms');
    }

    #[Override]
    public function getConfigDescription(): string
    {
        return __('Set SLA/OLA based on a date answer from the form.', 'advancedforms');
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
        return __('Answer from a specific date question', 'advancedforms');
    }

    #[Override]
    public function applyStrategyToInput(
        SLMField $field,
        SLMFieldConfig $config,
        array $input,
        AnswersSet $answers_set,
    ): array {
        // Get the question ID from the configuration
        $question_id = $config->getExtraDataValue(self::EXTRA_KEY_QUESTION_ID);
        if (!is_numeric($question_id)) {
            return $input;
        }

        // Get and validate the answer from the answers set
        $answer = $answers_set->getAnswerByQuestionId((int) $question_id);
        if (!$answer instanceof Answer) {
            return $input;
        }

        // Get and validate the raw value from the answer
        $raw_value = $answer->getRawAnswer();
        if (empty($raw_value) || !is_string($raw_value)) {
            return $input;
        }

        // Apply the date to the input array
        $slm = $field->getSLM();
        $field_names = $slm::getFieldNames($field->getType());
        if (!empty($field_names) && is_string($field_names[0])) {
            $input[$field_names[0]] = $raw_value;
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

        return $twig->render('@advancedforms/editor/destinations/strategies/specific_date_answer_slm_strategy.html.twig', [
            'strategy_key'   => self::KEY,
            'options'        => $display_options,
            'extra_field'    => [
                'empty_label'     => __("Select a question..."),
                'value'           => $config->getExtraDataValue(self::EXTRA_KEY_QUESTION_ID),
                'input_name'      => $input_name . "[" . SLMFieldConfig::EXTRA_DATA . "][" . self::EXTRA_KEY_QUESTION_ID . "]",
                'possible_values' => $this->getDateQuestionsForDropdown($form),
            ],
        ]);
    }

    #[Override]
    public function getExtraConfigKeys(): array
    {
        return [self::EXTRA_KEY_QUESTION_ID];
    }

    #[Override]
    public function getWeight(): int
    {
        return 50;
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
            // Ensure the date part is enabled
            if (!(new QuestionTypeDateTime())->isDateEnabled($question)) {
                continue;
            }

            $values[$question->getId()] = $question->getName();
        }

        return $values;
    }
}
