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

namespace GlpiPlugin\Advancedforms\Tests\Model\QuestionType;

use Glpi\Form\Question;
use Glpi\Form\Condition\ValueOperator;
use Glpi\Form\QuestionType\QuestionTypeCheckbox;
use Glpi\Form\QuestionType\QuestionTypeEmail;
use Glpi\Form\QuestionType\QuestionTypeFile;
use Glpi\Form\QuestionType\QuestionTypeNumber;
use Glpi\Form\QuestionType\QuestionTypeRequestType;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use GlpiPlugin\Advancedforms\Model\QuestionType\HiddenQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\LdapQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\TreeCascadeDropdownQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestionConfig;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestion;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Glpi\Form\QuestionType\AbstractQuestionType;
use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use Glpi\Form\QuestionType\QuestionTypeItem;
use Glpi\Form\QuestionType\QuestionTypeItemDropdown;
use Glpi\Form\QuestionType\QuestionTypesManager;
use GlpiPlugin\Advancedforms\Model\QuestionType\AdvancedCategory;
use Override;

final class TableQuestionTest extends AdvancedFormsTestCase
{
    private TableQuestion $type;

    public function setUp(): void
    {
        parent::setUp();
        $this->type = new TableQuestion();
    }

    public function testPrepareExtraDataReindexesColumns(): void
    {
        $input = [
            'columns' => [
                5 => ['name' => 'A', 'question_type' => QuestionTypeShortText::class, 'required' => false],
                9 => ['name' => 'B', 'question_type' => QuestionTypeNumber::class,    'required' => true],
            ],
            'min_rows' => '2',
            'max_rows' => '20',
        ];
        $result = $this->type->prepareExtraData($input);
        $this->assertArrayHasKey(0, $result[TableQuestionConfig::COLUMNS]);
        $this->assertArrayHasKey(1, $result[TableQuestionConfig::COLUMNS]);
    }

    public function testPrepareExtraDataCoercesRequiredToBool(): void
    {
        $input = [
            'columns'  => [['name' => 'X', 'question_type' => QuestionTypeShortText::class, 'required' => '1']],
            'min_rows' => 1,
            'max_rows' => 50,
        ];
        $result = $this->type->prepareExtraData($input);
        $this->assertIsBool($result[TableQuestionConfig::COLUMNS][0][TableQuestionConfig::COL_REQUIRED]);
        $this->assertTrue($result[TableQuestionConfig::COLUMNS][0][TableQuestionConfig::COL_REQUIRED]);
    }

    public function testCompatibleTypesExcludesFile(): void
    {
        $types = $this->type->getCompatibleQuestionTypes();
        $this->assertArrayNotHasKey(QuestionTypeFile::class, $types);
    }

    public function testCompatibleTypesExcludesSelf(): void
    {
        $types = $this->type->getCompatibleQuestionTypes();
        $this->assertArrayNotHasKey(TableQuestion::class, $types);
    }

    public function testCompatibleTypesIncludesShortText(): void
    {
        $types = $this->type->getCompatibleQuestionTypes();
        $this->assertArrayHasKey(QuestionTypeShortText::class, $types);
    }

    public function testCellInfoForNumber(): void
    {
        $info = $this->type->getCellInfo(QuestionTypeNumber::class);
        $this->assertSame('input', $info['mode']);
        $this->assertSame('number', $info['input_type']);
    }

    public function testCellInfoForEmail(): void
    {
        $info = $this->type->getCellInfo(QuestionTypeEmail::class);
        $this->assertSame('input', $info['mode']);
        $this->assertSame('email', $info['input_type']);
    }

    public function testCellInfoDefaultsToText(): void
    {
        $info = $this->type->getCellInfo(QuestionTypeShortText::class);
        $this->assertSame('input', $info['mode']);
        $this->assertSame('text', $info['input_type']);
    }

    public function testCellInfoForCheckbox(): void
    {
        $info = $this->type->getCellInfo(QuestionTypeCheckbox::class);
        $this->assertSame('checkbox', $info['mode']);
    }

    public function testCellInfoForHidden(): void
    {
        $info = $this->type->getCellInfo(HiddenQuestion::class, new HiddenQuestion());
        $this->assertSame('input', $info['mode']);
        $this->assertSame('hidden', $info['input_type']);
    }

    public function testCompatibleTypesExcludesRequestType(): void
    {
        $types = $this->type->getCompatibleQuestionTypes();
        $this->assertArrayNotHasKey(QuestionTypeRequestType::class, $types);
    }

    public function testCompatibleTypesExcludesLdap(): void
    {
        $types = $this->type->getCompatibleQuestionTypes();
        $this->assertArrayNotHasKey(LdapQuestion::class, $types);
    }

    public function testCompatibleTypesExcludesTreeCascadeDropdown(): void
    {
        $types = $this->type->getCompatibleQuestionTypes();
        $this->assertArrayNotHasKey(TreeCascadeDropdownQuestion::class, $types);
    }

    public function testCompatibleTypesExcludesReservation(): void
    {
        $types = $this->type->getCompatibleQuestionTypes();
        $this->assertArrayNotHasKey(ReservationQuestion::class, $types);
    }

    /**
     * Regression test for types with custom sub-type selectors, which cannot
     * be represented as flat table column types and thus must be excluded.
     */
    public function testCompatibleTypesExcludesTypesWithSubTypes(): void
    {
        $fake_type = new class extends AbstractQuestionType {
            #[Override]
            public function getCategory(): QuestionTypeCategoryInterface
            {
                return new AdvancedCategory();
            }

            #[Override]
            public function getSubTypes(): array
            {
                return ['fake' => 'Fake sub type'];
            }

            #[Override]
            public function renderAdministrationTemplate(?Question $question): string
            {
                return '';
            }

            #[Override]
            public function renderEndUserTemplate(?Question $question, mixed $answer = null): string
            {
                return '';
            }
        };

        QuestionTypesManager::getInstance()->registerPluginQuestionType($fake_type);

        $types = $this->type->getCompatibleQuestionTypes();
        $this->assertArrayNotHasKey($fake_type::class, $types);
    }

    /**
     * QuestionTypeItem and QuestionTypeItemDropdown both declare a non-empty
     * getSubTypes() but must stay selectable: Table
     * already renders them through its own dedicated itemtype picker
     * (TableQuestionConfig::COL_ITEMTYPE), independent of getSubTypes().
     */
    public function testCompatibleTypesIncludesItemAndItemDropdownDespiteSubTypes(): void
    {
        $types = $this->type->getCompatibleQuestionTypes();
        $this->assertArrayHasKey(QuestionTypeItem::class, $types);
        $this->assertArrayHasKey(QuestionTypeItemDropdown::class, $types);
    }

    public function testGetConfigKey(): void
    {
        $this->assertSame('enable_question_type_table', TableQuestion::getConfigKey());
    }

    public function testGetIcon(): void
    {
        $this->assertSame('ti ti-table', $this->type->getIcon());
    }

    public function testGetExtraDataConfigClass(): void
    {
        $this->assertSame(TableQuestionConfig::class, $this->type->getExtraDataConfigClass());
    }

    public function testValidateExtraDataInputAcceptsASingleColumn(): void
    {
        $result = $this->type->validateExtraDataInput([
            'columns' => [['name' => 'A', 'question_type' => QuestionTypeShortText::class]],
        ]);
        $this->assertTrue($result);
    }

    public function testValidateExtraDataInputRejectsAColumnLessTable(): void
    {
        $this->assertFalse($this->type->validateExtraDataInput(['columns' => []]));
    }

    /**
     * Row bounds are now validation conditions; leftover keys from 1.2.0 must
     * neither be rejected nor carried over.
     */
    public function testLegacyRowBoundsAreAccepted(): void
    {
        $result = $this->type->validateExtraDataInput([
            'columns'  => [['name' => 'A', 'question_type' => QuestionTypeShortText::class]],
            'min_rows' => 1,
            'max_rows' => 99999,
        ]);
        $this->assertTrue($result);
    }

    public function testPrepareExtraDataDropsLegacyRowBounds(): void
    {
        $result = $this->type->prepareExtraData([
            'columns'  => [['name' => 'A', 'question_type' => QuestionTypeShortText::class, 'required' => false]],
            'min_rows' => 1,
            'max_rows' => 99999,
        ]);
        $this->assertSame([TableQuestionConfig::COLUMNS], array_keys($result));
    }

    /**
     * Engine::applyValueOperator() demands exactly one handler per operator and
     * throws a LogicException otherwise — but only once an admin actually builds
     * such a condition, so the clash has to be caught here.
     */
    public function testNoValueOperatorIsClaimedByTwoConditionHandlers(): void
    {
        $seen = [];
        foreach ($this->type->getConditionHandlers(null) as $handler) {
            foreach ($handler->getSupportedValueOperators() as $operator) {
                $this->assertArrayNotHasKey(
                    $operator->value,
                    $seen,
                    sprintf(
                        'Operator "%s" is claimed by both %s and %s',
                        $operator->value,
                        $seen[$operator->value] ?? '',
                        $handler::class,
                    ),
                );
                $seen[$operator->value] = $handler::class;
            }
        }
    }

    public function testRowCountOperatorsAreAvailableAsConditionCriteria(): void
    {
        $operators = [];
        foreach ($this->type->getConditionHandlers(null) as $handler) {
            foreach ($handler->getSupportedValueOperators() as $operator) {
                $operators[] = $operator;
            }
        }

        $this->assertContains(ValueOperator::LENGTH_GREATER_THAN, $operators);
        $this->assertContains(ValueOperator::LENGTH_LESS_THAN_OR_EQUALS, $operators);
    }

    public function testWholeTableRegexIsAvailableAsAConditionCriterion(): void
    {
        $operators = [];
        foreach ($this->type->getConditionHandlers(null) as $handler) {
            foreach ($handler->getSupportedValueOperators() as $operator) {
                $operators[] = $operator;
            }
        }

        $this->assertContains(ValueOperator::MATCH_REGEX, $operators);
    }

    public function testTransformConditionValueFlattensRowsToScalars(): void
    {
        $answer = [
            ['col_0' => '172.23.0.15', 'col_1' => '172.23.1.10'],
            ['col_0' => '172.23.0.20', 'col_1' => '172.23.1.30'],
        ];
        $result = $this->type->transformConditionValueForComparisons($answer, null);
        $this->assertSame(['172.23.0.15', '172.23.1.10', '172.23.0.20', '172.23.1.30'], $result);
    }

    public function testTransformConditionValueSkipsEmptyCells(): void
    {
        $answer = [
            ['col_0' => '172.23.0.15', 'col_1' => ''],
        ];
        $result = $this->type->transformConditionValueForComparisons($answer, null);
        $this->assertSame(['172.23.0.15'], $result);
    }

    public function testTransformConditionValueEmptyTableReturnsEmptyArray(): void
    {
        $result = $this->type->transformConditionValueForComparisons([], null);
        $this->assertSame([], $result);
    }

    public function testTransformConditionValueNonArrayReturnsString(): void
    {
        $result = $this->type->transformConditionValueForComparisons('raw', null);
        $this->assertSame('raw', $result);
    }

    public function testTransformConditionValueSkipsNonArrayRows(): void
    {
        $answer = ['not_a_row', ['col_0' => '10.0.0.1']];
        $result = $this->type->transformConditionValueForComparisons($answer, null);
        $this->assertSame(['10.0.0.1'], $result);
    }
}
