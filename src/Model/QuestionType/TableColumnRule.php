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

use Glpi\Form\Condition\ConditionHandler\RegexConditionHandler;
use Glpi\Form\Condition\ValueOperator;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionTypeShortAnswer;
use Glpi\Form\QuestionType\QuestionTypeInterface;
use Glpi\Form\QuestionType\QuestionTypeValidationInterface;

/**
 * The validation rules attached to one column of a table question: whether the
 * cell is required, the format its own question type enforces, and the optional
 * pattern configured by the administrator.
 *
 * Columns that carry no rule at all produce no instance, so validateAnswer() can
 * skip whole tables without walking their rows.
 */
final readonly class TableColumnRule
{
    private function __construct(
        public string $name,
        public bool $required,
        public string $pattern,
        private ?QuestionTypeValidationInterface $validator,
        private bool $numeric,
        private ?RegexConditionHandler $pattern_handler,
    ) {}

    /**
     * @param array{name: string, question_type: string, required: bool, itemtype: string, pattern: string} $column
     * @param ?QuestionTypeInterface $type Resolved column type, null when unknown.
     */
    public static function fromColumn(array $column, ?QuestionTypeInterface $type): ?self
    {
        $required = (bool) ($column[TableQuestionConfig::COL_REQUIRED] ?? false);

        // Only scalar cells can be pattern-matched or handed to a type validator.
        // Restricting to short answers also rules out delegating to a nested
        // table question, which a hand-crafted configuration could otherwise ask
        // for and which would recurse.
        $scalar_type = $type instanceof AbstractQuestionTypeShortAnswer ? $type : null;

        $pattern = $scalar_type instanceof AbstractQuestionTypeShortAnswer
            ? (string) ($column[TableQuestionConfig::COL_PATTERN] ?? '')
            : '';

        // A pattern stored before a validation rule changed may no longer be
        // usable. Dropping it is the only safe outcome: the native handler reads
        // an unusable regex as "matches nothing", which would lock every user out
        // of the form.
        if ($pattern !== '' && !self::isUsablePattern($pattern)) {
            $pattern = '';
        }

        $validator = $scalar_type instanceof QuestionTypeValidationInterface ? $scalar_type : null;

        // Core exposes no server-side validator for the Number type: it relies on
        // the browser input and on a custom mandatory message.
        $numeric = $validator === null
            && $scalar_type instanceof AbstractQuestionTypeShortAnswer
            && $scalar_type->getInputType() === 'number';

        if (!$required && $pattern === '' && $validator === null && !$numeric) {
            return null;
        }

        // Delegating the match itself to the native handler keeps a single regex
        // implementation across GLPI. A pattern is only ever set on a scalar
        // column, so the type is guaranteed here.
        $pattern_handler = ($pattern !== '' && $scalar_type instanceof AbstractQuestionTypeShortAnswer)
            ? new RegexConditionHandler($scalar_type, null)
            : null;

        return new self(
            name: (string) ($column[TableQuestionConfig::COL_NAME] ?? ''),
            required: $required,
            pattern: $pattern,
            validator: $validator,
            numeric: $numeric,
            pattern_handler: $pattern_handler,
        );
    }

    /**
     * A configured pattern is usable when it is a `/…/flags` PCRE that actually
     * compiles. The delimited form is the one the JS side parses, so anything
     * else would end up evaluated differently on each side.
     *
     * Core has no primitive for this — its handlers only ever answer "does this
     * value match" — and a malformed regex raises a warning, hence the silenced
     * call, exactly as RegexConditionHandler does.
     */
    public static function isUsablePattern(string $pattern): bool
    {
        // @phpstan-ignore theCodingMachineSafe.function
        if (@preg_match('/^\/.*\/[a-z]*$/s', $pattern) !== 1) {
            return false;
        }

        // @phpstan-ignore theCodingMachineSafe.function
        return @preg_match($pattern, '') !== false;
    }

    /**
     * Runs the format check owned by the column's question type.
     *
     * @return string|null The type's own message, or null when it is satisfied.
     */
    public function validateNatively(Question $question, string $value): ?string
    {
        if ($this->validator instanceof QuestionTypeValidationInterface) {
            $result = $this->validator->validateAnswer($question, $value);
            if ($result->isValid()) {
                return null;
            }

            $error   = $result->getErrors()[0] ?? null;
            $message = is_array($error) ? ($error['message'] ?? '') : '';

            return is_string($message) && $message !== ''
                ? $message
                : __('the value is not valid', 'advancedforms');
        }

        if ($this->numeric && !is_numeric($value)) {
            return __('the value is not a number', 'advancedforms');
        }

        return null;
    }

    /**
     * True when no pattern is configured, or when the value satisfies it. A
     * malformed stored pattern matches everything rather than locking the user
     * out of the form.
     */
    public function matchesPattern(string $value): bool
    {
        if (!$this->pattern_handler instanceof RegexConditionHandler) {
            return true;
        }

        return $this->pattern_handler->applyValueOperator(
            $value,
            ValueOperator::MATCH_REGEX,
            $this->pattern,
        );
    }
}
