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

namespace GlpiPlugin\Advancedforms\Model\ConditionHandler;

use CommonDBTM;
use CommonTreeDropdown;
use Glpi\Form\Condition\ConditionData;
use Glpi\Form\Condition\ConditionHandler\ConditionHandlerInterface;
use Glpi\Form\Condition\ValueOperator;
use Override;

/**
 * Like ItemAsTextConditionHandler but uses completename for CommonTreeDropdown
 * items so that conditions on parent nodes work correctly.
 *
 * Core's ItemAsTextConditionHandler uses getName() which returns only the
 * item's own name. For tree dropdowns, a child's own name does not include
 * its ancestors, so "contains <parent name>" always fails. Using completename
 * (e.g. "Parent > Child") makes the full path available for matching.
 */
final readonly class TreeCascadeItemAsTextConditionHandler implements ConditionHandlerInterface
{
    public function __construct(
        private string $itemtype,
    ) {}

    #[Override]
    public function getSupportedValueOperators(): array
    {
        return [
            ValueOperator::CONTAINS,
            ValueOperator::NOT_CONTAINS,
        ];
    }

    #[Override]
    public function getTemplate(): string
    {
        return '/pages/admin/form/condition_handler_templates/input.html.twig';
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getTemplateParameters(ConditionData $condition): array
    {
        return [];
    }

    #[Override]
    public function applyValueOperator(
        mixed $a,
        ValueOperator $operator,
        mixed $b,
    ): bool {
        if (!is_array($a) || !isset($a['items_id'])) {
            return false;
        }

        $item = $this->itemtype::getById($a['items_id']);
        if (!$item) {
            return false;
        }

        // Use completename for tree dropdowns so that conditions referencing
        // ancestor nodes match correctly (e.g. "contains Parent" matches a child
        // whose completename is "Parent > Child").
        /** @var CommonDBTM $item */
        if ($item instanceof CommonTreeDropdown) {
            $completename = $item->fields['completename'];
            $text = is_string($completename) ? $completename : '';
        } else {
            $text = $item->getName();
        }

        $a = strtolower($text);

        $b = is_scalar($b) || $b === null ? strtolower((string) $b) : '';

        return match ($operator) {
            ValueOperator::CONTAINS     => str_contains($a, $b),
            ValueOperator::NOT_CONTAINS => !str_contains($a, $b),
            default                     => false,
        };
    }
}
