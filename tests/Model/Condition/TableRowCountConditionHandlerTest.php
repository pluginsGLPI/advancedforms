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

namespace GlpiPlugin\Advancedforms\Tests\Model\Condition;

use Glpi\Form\Condition\ConditionData;
use Glpi\Form\Condition\Type;
use Glpi\Form\Condition\ValueOperator;
use GlpiPlugin\Advancedforms\Model\Condition\TableRowCountConditionHandler;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit-level coverage of the row counting itself. End-to-end wiring through the
 * form engine lives in TableRowCountConditionTest.
 */
final class TableRowCountConditionHandlerTest extends AdvancedFormsTestCase
{
    private TableRowCountConditionHandler $handler;

    public function setUp(): void
    {
        parent::setUp();
        $this->handler = new TableRowCountConditionHandler();
    }

    public function testOnlyLengthOperatorsAreSupported(): void
    {
        $this->assertSame(
            [
                ValueOperator::LENGTH_GREATER_THAN,
                ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS,
                ValueOperator::LENGTH_LESS_THAN,
                ValueOperator::LENGTH_LESS_THAN_OR_EQUALS,
            ],
            $this->handler->getSupportedValueOperators(),
        );
    }

    /**
     * Every supported operator is usable as a validation criterion, which is the
     * whole point of picking this family over GREATER_THAN/LESS_THAN.
     */
    public function testEverySupportedOperatorCanBeUsedForValidation(): void
    {
        foreach ($this->handler->getSupportedValueOperators() as $operator) {
            $this->assertTrue(
                $operator->canBeUsedForValidation(),
                $operator->value . ' must be usable as a validation criterion',
            );
        }
    }

    public function testTheAdminInputIsANumberField(): void
    {
        $parameters = $this->handler->getTemplateParameters(
            $this->conditionData(ValueOperator::LENGTH_GREATER_THAN, '3'),
        );

        $this->assertSame('number', $parameters['attributes']['type'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    #[DataProvider('provideRowCounts')]
    public function testRowCountComparison(
        array $rows,
        ValueOperator $operator,
        string $value,
        bool $expected,
    ): void {
        $this->assertSame(
            $expected,
            $this->handler->applyValueOperator($rows, $operator, $value),
        );
    }

    /** @return iterable<string, array{list<array<string, mixed>>, ValueOperator, string, bool}> */
    public static function provideRowCounts(): iterable
    {
        $one   = [['col_0' => 'a']];
        $three = [['col_0' => 'a'], ['col_0' => 'b'], ['col_0' => 'c']];

        yield '3 rows > 2'            => [$three, ValueOperator::LENGTH_GREATER_THAN, '2', true];
        yield '3 rows > 3'            => [$three, ValueOperator::LENGTH_GREATER_THAN, '3', false];
        yield '3 rows > 4'            => [$three, ValueOperator::LENGTH_GREATER_THAN, '4', false];
        yield '3 rows >= 3'           => [$three, ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS, '3', true];
        yield '3 rows >= 4'           => [$three, ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS, '4', false];
        yield '1 row < 2'             => [$one, ValueOperator::LENGTH_LESS_THAN, '2', true];
        yield '3 rows < 3'            => [$three, ValueOperator::LENGTH_LESS_THAN, '3', false];
        yield '3 rows <= 3'           => [$three, ValueOperator::LENGTH_LESS_THAN_OR_EQUALS, '3', true];
        yield '3 rows <= 2'           => [$three, ValueOperator::LENGTH_LESS_THAN_OR_EQUALS, '2', false];
        yield 'no row < 1'            => [[], ValueOperator::LENGTH_LESS_THAN, '1', true];
        yield 'no row > 0'            => [[], ValueOperator::LENGTH_GREATER_THAN, '0', false];
        yield 'no row >= 0'           => [[], ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS, '0', true];
    }

    /**
     * Empty rows are dropped when the answer is saved, so they must not be
     * counted either.
     */
    public function testEmptyRowsAreNotCounted(): void
    {
        $rows = [
            ['col_0' => 'filled', 'col_1' => ''],
            ['col_0' => '',       'col_1' => ''],
            ['col_0' => null,     'col_1' => null],
        ];

        $this->assertTrue(
            $this->handler->applyValueOperator($rows, ValueOperator::LENGTH_LESS_THAN_OR_EQUALS, '1'),
        );
    }

    public function testWhitespaceOnlyRowIsCounted(): void
    {
        // Not trimmed: a cell holding a space is a value as far as storage goes.
        $rows = [['col_0' => ' ']];

        $this->assertTrue(
            $this->handler->applyValueOperator($rows, ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS, '1'),
        );
    }

    public function testNonArrayRowsAreIgnored(): void
    {
        $rows = ['not-a-row', ['col_0' => 'a'], 42];

        $this->assertTrue(
            $this->handler->applyValueOperator($rows, ValueOperator::LENGTH_LESS_THAN_OR_EQUALS, '1'),
        );
    }

    public function testUnansweredTableCountsAsZeroRows(): void
    {
        $this->assertTrue(
            $this->handler->applyValueOperator(null, ValueOperator::LENGTH_LESS_THAN, '1'),
        );
    }

    public function testUnsupportedOperatorIsRejected(): void
    {
        $this->assertFalse(
            $this->handler->applyValueOperator(
                [['col_0' => 'a']],
                ValueOperator::EQUALS,
                '1',
            ),
        );
    }

    public function testNonNumericThresholdIsTreatedAsZero(): void
    {
        $this->assertTrue(
            $this->handler->applyValueOperator(
                [['col_0' => 'a']],
                ValueOperator::LENGTH_GREATER_THAN,
                'not-a-number',
            ),
        );
    }

    private function conditionData(ValueOperator $operator, string $value): ConditionData
    {
        return new ConditionData(
            item_uuid: 'uuid',
            item_type: Type::QUESTION->value,
            value_operator: $operator->value,
            value: $value,
        );
    }
}
