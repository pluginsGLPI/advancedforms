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

namespace GlpiPlugin\Advancedforms\Tests\Model\ConditionHandler;

use Glpi\Form\Condition\ConditionHandler\ConditionHandlerInterface;
use Glpi\Form\Condition\ConditionHandler\StringConditionHandler;
use Glpi\Form\Condition\ValueOperator;
use Glpi\Form\Migration\TypesConversionMapper;
use Glpi\Form\QuestionType\QuestionTypesManager;
use Glpi\Tests\AbstractConditionHandlerTest;
use GlpiPlugin\Advancedforms\Model\QuestionType\HiddenQuestion;
use GlpiPlugin\Advancedforms\Tests\ConfigurableItemsTrait;
use Override;

/**
 * Tests that the string condition operators (equals, contains, length, ...) can
 * be used on a hidden question.
 *
 * A hidden question submits its configured value as a plain string, thus the
 * core StringConditionHandler applies. Before this was declared by the question
 * type, only the operators of the default handlers (visibility, regex, empty)
 * were available and conditions such as "hidden question equals X" could not be
 * evaluated.
 *
 * @see HiddenQuestion::getConditionHandlers()
 */
final class HiddenQuestionStringConditionHandlerTest extends AbstractConditionHandlerTest
{
    use ConfigurableItemsTrait;

    public function setUp(): void
    {
        parent::setUp();

        // The registered question types are cached by these singletons, reset
        // them before enabling our own type.
        $this->deleteSingletonInstance([
            QuestionTypesManager::class,
            TypesConversionMapper::class,
        ]);

        $this->enableConfigurableItem(HiddenQuestion::class);
    }

    public static function getConditionHandler(): ConditionHandlerInterface
    {
        return new StringConditionHandler();
    }

    /**
     * The operators evaluated below must also be offered by the question type
     * itself, otherwise they can't be selected in the form editor.
     */
    public function testStringOperatorsAreAvailableOnHiddenQuestions(): void
    {
        $available_operators = (new HiddenQuestion())->getSupportedValueOperators(null);

        foreach (self::getConditionHandler()->getSupportedValueOperators() as $operator) {
            $this->assertContains($operator, $available_operators);
        }
    }

    #[Override]
    public static function conditionHandlerProvider(): iterable
    {
        $type = HiddenQuestion::class;

        // Test hidden values with the EQUALS operator
        yield 'Equals check - case 1' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::EQUALS,
            'condition_value'    => "premium_customer",
            'submitted_answer'   => "standard_customer",
            'expected_result'    => false,
        ];
        yield 'Equals check - case 2' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::EQUALS,
            'condition_value'    => "premium_customer",
            'submitted_answer'   => "premium",
            'expected_result'    => false,
        ];
        yield 'Equals check - case 3' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::EQUALS,
            'condition_value'    => "premium_customer",
            'submitted_answer'   => "premium_customer",
            'expected_result'    => true,
        ];
        yield 'Equals check - case 4' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::EQUALS,
            'condition_value'    => "premium_customer",
            'submitted_answer'   => "PREMIUM_Customer",
            'expected_result'    => true,
        ];
        yield 'Equals check - case 5' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::EQUALS,
            'condition_value'    => "premium_customer",
            'submitted_answer'   => "",
            'expected_result'    => false,
        ];

        // Test hidden values with the NOT_EQUALS operator
        yield 'Not equals check - case 1' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::NOT_EQUALS,
            'condition_value'    => "premium_customer",
            'submitted_answer'   => "standard_customer",
            'expected_result'    => true,
        ];
        yield 'Not equals check - case 2' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::NOT_EQUALS,
            'condition_value'    => "premium_customer",
            'submitted_answer'   => "premium",
            'expected_result'    => true,
        ];
        yield 'Not equals check - case 3' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::NOT_EQUALS,
            'condition_value'    => "premium_customer",
            'submitted_answer'   => "premium_customer",
            'expected_result'    => false,
        ];
        yield 'Not equals check - case 4' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::NOT_EQUALS,
            'condition_value'    => "premium_customer",
            'submitted_answer'   => "PREMIUM_Customer",
            'expected_result'    => false,
        ];

        // Test hidden values with the CONTAINS operator
        yield 'Contains check - case 1' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::CONTAINS,
            'condition_value'    => "premium",
            'submitted_answer'   => "standard_customer",
            'expected_result'    => false,
        ];
        yield 'Contains check - case 2' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::CONTAINS,
            'condition_value'    => "premium",
            'submitted_answer'   => "premium_customer",
            'expected_result'    => true,
        ];
        yield 'Contains check - case 3' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::CONTAINS,
            'condition_value'    => "customer",
            'submitted_answer'   => "premium_customer",
            'expected_result'    => true,
        ];
        yield 'Contains check - case 4' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::CONTAINS,
            'condition_value'    => "PREMIUM",
            'submitted_answer'   => "premium_customer",
            'expected_result'    => true,
        ];

        // Test hidden values with the NOT_CONTAINS operator
        yield 'Not contains check - case 1' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::NOT_CONTAINS,
            'condition_value'    => "premium",
            'submitted_answer'   => "standard_customer",
            'expected_result'    => true,
        ];
        yield 'Not contains check - case 2' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::NOT_CONTAINS,
            'condition_value'    => "premium",
            'submitted_answer'   => "premium_customer",
            'expected_result'    => false,
        ];
        yield 'Not contains check - case 3' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::NOT_CONTAINS,
            'condition_value'    => "PREMIUM",
            'submitted_answer'   => "premium_customer",
            'expected_result'    => false,
        ];

        // Test hidden values with the LENGTH_GREATER_THAN operator
        yield 'Length greater than check - case 1' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_GREATER_THAN,
            'condition_value'    => 10,
            'submitted_answer'   => "short",
            'expected_result'    => false,
        ];
        yield 'Length greater than check - case 2' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_GREATER_THAN,
            'condition_value'    => 10,
            'submitted_answer'   => "premium_customer",
            'expected_result'    => true,
        ];
        yield 'Length greater than check - case 3' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_GREATER_THAN,
            'condition_value'    => 10,
            'submitted_answer'   => "exactlyten",
            'expected_result'    => false,
        ];
        yield 'Length greater than check - case 4' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_GREATER_THAN,
            'condition_value'    => 2,
            'submitted_answer'   => "für", // multi byte string
            'expected_result'    => true,
        ];
        yield 'Length greater than check - case 5' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_GREATER_THAN,
            'condition_value'    => 3,
            'submitted_answer'   => "für", // multi byte string
            'expected_result'    => false,
        ];

        // Test hidden values with the LENGTH_GREATER_THAN_OR_EQUALS operator
        yield 'Length greater than or equals check - case 1' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS,
            'condition_value'    => 10,
            'submitted_answer'   => "short",
            'expected_result'    => false,
        ];
        yield 'Length greater than or equals check - case 2' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS,
            'condition_value'    => 10,
            'submitted_answer'   => "exactlyten",
            'expected_result'    => true,
        ];
        yield 'Length greater than or equals check - case 3' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS,
            'condition_value'    => 3,
            'submitted_answer'   => "für", // multi byte string
            'expected_result'    => true,
        ];
        yield 'Length greater than or equals check - case 4' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS,
            'condition_value'    => 4,
            'submitted_answer'   => "für", // multi byte string
            'expected_result'    => false,
        ];

        // Test hidden values with the LENGTH_LESS_THAN operator
        yield 'Length less than check - case 1' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_LESS_THAN,
            'condition_value'    => 10,
            'submitted_answer'   => "premium_customer",
            'expected_result'    => false,
        ];
        yield 'Length less than check - case 2' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_LESS_THAN,
            'condition_value'    => 10,
            'submitted_answer'   => "short",
            'expected_result'    => true,
        ];
        yield 'Length less than check - case 3' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_LESS_THAN,
            'condition_value'    => 10,
            'submitted_answer'   => "exactlyten",
            'expected_result'    => false,
        ];
        yield 'Length less than check - case 4' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_LESS_THAN,
            'condition_value'    => 4,
            'submitted_answer'   => "für", // multi byte string
            'expected_result'    => true,
        ];
        yield 'Length less than check - case 5' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_LESS_THAN,
            'condition_value'    => 3,
            'submitted_answer'   => "für", // multi byte string
            'expected_result'    => false,
        ];

        // Test hidden values with the LENGTH_LESS_THAN_OR_EQUALS operator
        yield 'Length less than or equals check - case 1' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_LESS_THAN_OR_EQUALS,
            'condition_value'    => 10,
            'submitted_answer'   => "premium_customer",
            'expected_result'    => false,
        ];
        yield 'Length less than or equals check - case 2' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_LESS_THAN_OR_EQUALS,
            'condition_value'    => 10,
            'submitted_answer'   => "exactlyten",
            'expected_result'    => true,
        ];
        yield 'Length less than or equals check - case 3' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_LESS_THAN_OR_EQUALS,
            'condition_value'    => 3,
            'submitted_answer'   => "für", // multi byte string
            'expected_result'    => true,
        ];
        yield 'Length less than or equals check - case 4' => [
            'question_type'      => $type,
            'condition_operator' => ValueOperator::LENGTH_LESS_THAN_OR_EQUALS,
            'condition_value'    => 2,
            'submitted_answer'   => "für", // multi byte string
            'expected_result'    => false,
        ];
    }
}
