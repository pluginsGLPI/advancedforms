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

namespace GlpiPlugin\Advancedforms\Model\Condition;

use Glpi\Form\Condition\ConditionData;
use Glpi\Form\Condition\ConditionHandler\ConditionHandlerInterface;
use Glpi\Form\Condition\ValueOperator;
use Override;

/**
 * Exposes the number of filled rows of a table question as a native condition
 * criterion, so row bounds are declared as validation conditions instead of
 * being enforced by a mechanism specific to this plugin. It also makes those
 * bounds usable as visibility criteria.
 *
 * The LENGTH_* family is used rather than GREATER_THAN/LESS_THAN: every one of
 * its operators is already usable for validation, comes with a wording that
 * reads as a count, and is claimed by no other handler of the table question —
 * which matters, as the engine requires exactly one handler per operator.
 */
final class TableRowCountConditionHandler implements ConditionHandlerInterface
{
    #[Override]
    public function getSupportedValueOperators(): array
    {
        return [
            ValueOperator::LENGTH_GREATER_THAN,
            ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS,
            ValueOperator::LENGTH_LESS_THAN,
            ValueOperator::LENGTH_LESS_THAN_OR_EQUALS,
        ];
    }

    #[Override]
    public function getTemplate(): string
    {
        return '/pages/admin/form/condition_handler_templates/input.html.twig';
    }

    /** @return array{attributes: array<string, string>} */
    #[Override]
    public function getTemplateParameters(ConditionData $condition): array
    {
        return [
            'attributes' => [
                'type' => 'number',
                'min'  => '0',
                'step' => '1',
            ],
        ];
    }

    #[Override]
    public function applyValueOperator(
        mixed $a,
        ValueOperator $operator,
        mixed $b,
    ): bool {
        $rows      = $this->countFilledRows($a);
        $threshold = (int) (is_scalar($b) ? $b : 0);

        return match ($operator) {
            ValueOperator::LENGTH_GREATER_THAN           => $rows > $threshold,
            ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS => $rows >= $threshold,
            ValueOperator::LENGTH_LESS_THAN              => $rows < $threshold,
            ValueOperator::LENGTH_LESS_THAN_OR_EQUALS    => $rows <= $threshold,

            // Unsupported operators
            default => false,
        };
    }

    /**
     * Counts the rows that carry at least one value. Entirely empty rows are
     * dropped when the answer is saved, so counting them would compare against
     * something that never reaches storage.
     */
    private function countFilledRows(mixed $answer): int
    {
        if (!is_array($answer)) {
            return 0;
        }

        $count = 0;
        foreach ($answer as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $cell) {
                if ($cell !== '' && $cell !== null) {
                    $count++;
                    continue 2;
                }
            }
        }

        return $count;
    }
}
