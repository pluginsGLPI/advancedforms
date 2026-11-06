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
use Glpi\Form\Condition\ConditionHandler\VisibilityConditionHandler;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionType;
use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use Glpi\Form\QuestionType\QuestionTypeValidationInterface;
use Glpi\Form\QuestionType\RawAnswerIsHtmlInterface;
use Glpi\Form\ValidationResult;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Service\LatestVersionProvider;
use GlpiPlugin\Advancedforms\Service\SystemInfoParser;
use JsonException;
use Override;

use function Safe\json_decode;

/** Pasted "Copy system information" text, rendered as a version table in the ticket. */
final class SystemInfoQuestion extends AbstractQuestionType implements
    ConfigurableItemInterface,
    QuestionTypeValidationInterface,
    RawAnswerIsHtmlInterface
{
    #[Override]
    public function getCategory(): QuestionTypeCategoryInterface
    {
        return new AdvancedCategory();
    }

    #[Override]
    public function getName(): string
    {
        return __('GLPI system information', 'advancedforms');
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-server';
    }

    #[Override]
    public function getWeight(): int
    {
        return 60;
    }

    #[Override]
    public function getConditionHandlers(?JsonFieldInterface $question_config): array
    {
        // The raw pasted text is not meaningful for value based conditions
        return [new VisibilityConditionHandler()];
    }

    #[Override]
    public function validateAnswer(Question $question, mixed $answer): ValidationResult
    {
        $result = new ValidationResult();
        $error = $this->getAnswerError($answer);
        if ($error !== null) {
            $result->addError($question, $error);
        }

        return $result;
    }

    private function getAnswerError(mixed $answer): ?string
    {
        if (!is_string($answer)) {
            return self::getUnrecognizedTextMessage();
        }

        if (mb_strlen($answer) > SystemInfoParser::MAX_LENGTH) {
            return __('The pasted text is too long.', 'advancedforms');
        }

        if (SystemInfoParser::parse($answer) === null) {
            return self::getUnrecognizedTextMessage();
        }

        return null;
    }

    #[Override]
    public function prepareEndUserAnswer(Question $question, mixed $answer): mixed
    {
        if (!is_string($answer)) {
            return [];
        }

        $parsed = SystemInfoParser::parse($answer);
        if ($parsed === null) {
            return [];
        }

        // Latest versions are frozen at submission time
        return [
            'raw'  => $answer,
            'data' => (new LatestVersionProvider())->enrich($parsed),
        ];
    }

    #[Override]
    public function formatRawAnswer(mixed $answer, Question $question): string
    {
        $answer = $this->decodeAnswer($answer);
        if (!is_array($answer) || !is_array($answer['data'] ?? null)) {
            return '';
        }

        return TemplateRenderer::getInstance()->render('@advancedforms/system_info_answer.html.twig', [
            'data'     => $answer['data'],
            'raw'      => is_string($answer['raw'] ?? null) ? $answer['raw'] : '',
            'show_raw' => true,
        ]);
    }

    #[Override]
    public function renderAdministrationTemplate(?Question $question): string
    {
        $template = <<<TWIG
            <textarea class="form-control" rows="3" disabled>{{ help }}</textarea>
TWIG;

        return TemplateRenderer::getInstance()->renderFromStringTemplate($template, [
            'help' => $this->getHelpText(),
        ]);
    }

    #[Override]
    public function renderEndUserTemplate(Question $question): string
    {
        return TemplateRenderer::getInstance()->render('@advancedforms/system_info_end_user.html.twig', [
            'question' => $question,
            'help'     => $this->getHelpText(),
        ]);
    }

    #[Override]
    public static function getConfigKey(): string
    {
        return 'enable_question_type_system_info';
    }

    #[Override]
    public function getConfigTitle(): string
    {
        return __('GLPI system information question type', 'advancedforms');
    }

    #[Override]
    public function getConfigDescription(): string
    {
        return __('Allow users to paste the system information of their GLPI, displayed in the ticket with the latest available versions.', 'advancedforms');
    }

    #[Override]
    public function getConfigIcon(): string
    {
        return $this->getIcon();
    }

    public static function getUnrecognizedTextMessage(): string
    {
        return __('The pasted text is not recognized as GLPI system information.', 'advancedforms');
    }

    private function getHelpText(): string
    {
        return __('In GLPI, go to Setup > General > System, click "Copy system information" and paste the result here.', 'advancedforms');
    }

    /** GLPI may give the stored answer as a decoded array or as its JSON string. */
    private function decodeAnswer(mixed $answer): mixed
    {
        if (!is_string($answer) || $answer === '') {
            return $answer;
        }

        try {
            return json_decode($answer, associative: true);
        } catch (JsonException) {
            return null;
        }
    }
}
