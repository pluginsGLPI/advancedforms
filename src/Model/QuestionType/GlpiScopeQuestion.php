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
use Glpi\Form\Condition\ConditionHandler\EmptyConditionHandler;
use Glpi\Form\Condition\ConditionHandler\VisibilityConditionHandler;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionType;
use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use Glpi\Form\QuestionType\QuestionTypeValidationInterface;
use Glpi\Form\ValidationResult;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use JsonException;
use Override;

use function Safe\json_decode;

/**
 * Lets the requester tick GLPI and/or the plugins listed in a
 * "GLPI system information" answer of the same form.
 */
final class GlpiScopeQuestion extends AbstractQuestionType implements
    ConfigurableItemInterface,
    QuestionTypeValidationInterface
{
    public const CORE_VALUE = 'GLPI';

    private const MAX_VALUES = 50;

    private const MAX_VALUE_LENGTH = 255;

    #[Override]
    public function getCategory(): QuestionTypeCategoryInterface
    {
        return new AdvancedCategory();
    }

    #[Override]
    public function getName(): string
    {
        return __('GLPI core or plugins concerned', 'advancedforms');
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-puzzle';
    }

    #[Override]
    public function getWeight(): int
    {
        return 70;
    }

    #[Override]
    public function getConditionHandlers(?JsonFieldInterface $question_config): array
    {
        return [
            new VisibilityConditionHandler(),
            new EmptyConditionHandler($this, $question_config),
        ];
    }

    #[Override]
    public function validateAnswer(Question $question, mixed $answer): ValidationResult
    {
        $result = new ValidationResult();
        if ($answer !== null && (!is_array($answer) || array_filter($answer, fn($value) => !is_scalar($value)) !== [])) {
            $result->addError($question, __('Invalid selection.', 'advancedforms'));
        }

        return $result;
    }

    #[Override]
    public function prepareEndUserAnswer(Question $question, mixed $answer): mixed
    {
        if (!is_array($answer)) {
            return [];
        }

        $values = [];
        foreach ($answer as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '' && mb_strlen($value) <= self::MAX_VALUE_LENGTH && !in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return array_slice($values, 0, self::MAX_VALUES);
    }

    #[Override]
    public function formatRawAnswer(mixed $answer, Question $question): string
    {
        if (is_string($answer) && $answer !== '') {
            try {
                $answer = json_decode($answer, associative: true);
            } catch (JsonException) {
                return '';
            }
        }

        if (!is_array($answer)) {
            return '';
        }

        // Not a RawAnswerIsHtmlInterface: the core escapes this value
        return implode(', ', array_filter($answer, is_string(...)));
    }

    #[Override]
    public function renderAdministrationTemplate(?Question $question): string
    {
        $template = <<<TWIG
            <label class="form-check">
                <input class="form-check-input" type="checkbox" data-af-glpi-scope-preview disabled>
                <span class="form-check-label">{{ core_label }}</span>
            </label>
            <div class="form-text">{{ help }}</div>
TWIG;

        return TemplateRenderer::getInstance()->renderFromStringTemplate($template, [
            'core_label' => self::CORE_VALUE,
            'help'       => __('The plugins found in the "GLPI system information" answer of this form are added to the choices.', 'advancedforms'),
        ]);
    }

    #[Override]
    public function renderEndUserTemplate(Question $question): string
    {
        return TemplateRenderer::getInstance()->render('@advancedforms/glpi_scope_end_user.html.twig', [
            'question' => $question,
        ]);
    }

    #[Override]
    public static function getConfigKey(): string
    {
        return 'enable_question_type_glpi_scope';
    }

    #[Override]
    public function getConfigTitle(): string
    {
        return __('GLPI core or plugins concerned question type', 'advancedforms');
    }

    #[Override]
    public function getConfigDescription(): string
    {
        return __('Allow users to tick GLPI or the plugins found in a "GLPI system information" question of the same form.', 'advancedforms');
    }

    #[Override]
    public function getConfigIcon(): string
    {
        return $this->getIcon();
    }
}
