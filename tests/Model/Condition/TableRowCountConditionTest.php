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

use Glpi\Form\AnswersHandler\AnswersHandler;
use Glpi\Form\Condition\Engine;
use Glpi\Form\Condition\EngineInput;
use Glpi\Form\Condition\LogicOperator;
use Glpi\Form\Condition\Type;
use Glpi\Form\Condition\ValidationStrategy;
use Glpi\Form\Condition\ValueOperator;
use Glpi\Form\Condition\VisibilityStrategy;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Form\ValidationResult;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestionConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;

use function Safe\json_encode;

/**
 * The number of rows is exposed as a native condition criterion, so it drives
 * both validation ("Invalid if length greater than 3") and visibility, through
 * GLPI's own engine rather than a plugin-specific mechanism.
 */
final class TableRowCountConditionTest extends AdvancedFormsTestCase
{
    private TableQuestion $type;

    public function setUp(): void
    {
        parent::setUp();
        $this->type = new TableQuestion();
        $this->login();
        $this->enableConfigurableItem($this->type);
    }

    public function testInvalidIfRowCountGreaterThanRejectsTooManyRows(): void
    {
        $form = $this->formWithRowCountValidation(
            ValidationStrategy::INVALID_IF,
            ValueOperator::LENGTH_GREATER_THAN,
            '2',
        );

        $this->assertFalse($this->validate($form, $this->rows(3))->isValid());
    }

    public function testInvalidIfRowCountGreaterThanAcceptsTheExactLimit(): void
    {
        $form = $this->formWithRowCountValidation(
            ValidationStrategy::INVALID_IF,
            ValueOperator::LENGTH_GREATER_THAN,
            '2',
        );

        $this->assertTrue($this->validate($form, $this->rows(2))->isValid());
    }

    public function testInvalidIfRowCountLessThanRejectsTooFewRows(): void
    {
        $form = $this->formWithRowCountValidation(
            ValidationStrategy::INVALID_IF,
            ValueOperator::LENGTH_LESS_THAN,
            '3',
        );

        $this->assertFalse($this->validate($form, $this->rows(2))->isValid());
    }

    public function testInvalidIfRowCountLessThanAcceptsEnoughRows(): void
    {
        $form = $this->formWithRowCountValidation(
            ValidationStrategy::INVALID_IF,
            ValueOperator::LENGTH_LESS_THAN,
            '3',
        );

        $this->assertTrue($this->validate($form, $this->rows(3))->isValid());
    }

    public function testValidIfRowCountLessThanOrEqualsAcceptsWithinBounds(): void
    {
        $form = $this->formWithRowCountValidation(
            ValidationStrategy::VALID_IF,
            ValueOperator::LENGTH_LESS_THAN_OR_EQUALS,
            '5',
        );

        $this->assertTrue($this->validate($form, $this->rows(5))->isValid());
    }

    public function testValidIfRowCountLessThanOrEqualsRejectsBeyondBounds(): void
    {
        $form = $this->formWithRowCountValidation(
            ValidationStrategy::VALID_IF,
            ValueOperator::LENGTH_LESS_THAN_OR_EQUALS,
            '5',
        );

        $this->assertFalse($this->validate($form, $this->rows(6))->isValid());
    }

    public function testValidIfRowCountGreaterThanOrEqualsRejectsTooFewRows(): void
    {
        $form = $this->formWithRowCountValidation(
            ValidationStrategy::VALID_IF,
            ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS,
            '2',
        );

        $this->assertFalse($this->validate($form, $this->rows(1))->isValid());
    }

    public function testValidIfRowCountGreaterThanOrEqualsAcceptsEnoughRows(): void
    {
        $form = $this->formWithRowCountValidation(
            ValidationStrategy::VALID_IF,
            ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS,
            '2',
        );

        $this->assertTrue($this->validate($form, $this->rows(2))->isValid());
    }

    public function testEmptyRowsDoNotCountTowardsTheLimit(): void
    {
        $form = $this->formWithRowCountValidation(
            ValidationStrategy::INVALID_IF,
            ValueOperator::LENGTH_GREATER_THAN,
            '2',
        );

        // Five submitted rows, but only two carry a value.
        $answer = [
            ['col_0' => 'a'],
            ['col_0' => ''],
            ['col_0' => 'b'],
            ['col_0' => ''],
            ['col_0' => ''],
        ];

        $this->assertTrue($this->validate($form, $answer)->isValid());
    }

    public function testTheErrorMessageComesFromTheNativeOperator(): void
    {
        $form = $this->formWithRowCountValidation(
            ValidationStrategy::INVALID_IF,
            ValueOperator::LENGTH_GREATER_THAN,
            '2',
        );

        $errors = $this->validate($form, $this->rows(3))->getErrors();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('2', $errors[0]['message']);
    }

    /**
     * A table with no filled row is treated as unanswered by AnswersHandler,
     * which skips validation conditions entirely. Requiring a minimum number of
     * rows therefore also needs the question to be mandatory.
     */
    public function testAnEmptyTableSkipsRowCountValidation(): void
    {
        $form = $this->formWithRowCountValidation(
            ValidationStrategy::VALID_IF,
            ValueOperator::LENGTH_GREATER_THAN_OR_EQUALS,
            '2',
        );

        $this->assertTrue($this->validate($form, [])->isValid());
    }

    public function testRowCountDrivesVisibilityOfAnotherQuestion(): void
    {
        $builder = new FormBuilder('Row count visibility form');
        $builder->addQuestion(
            'Table',
            TableQuestion::class,
            extra_data: $this->extraData(),
        );
        $builder->addQuestion('Follow up', QuestionTypeShortText::class);
        $builder->setQuestionVisibility(
            'Follow up',
            VisibilityStrategy::VISIBLE_IF,
            [
                [
                    'logic_operator' => LogicOperator::AND,
                    'item_name'      => 'Table',
                    'item_type'      => Type::QUESTION,
                    'value_operator' => ValueOperator::LENGTH_GREATER_THAN,
                    'value'          => '2',
                ],
            ],
        );
        $form = $this->createForm($builder);

        $table_id  = $this->getQuestionId($form, 'Table');
        $follow_up = $this->getQuestionId($form, 'Follow up');

        $this->assertTrue($this->isVisible($form, [$table_id => $this->rows(3)], $follow_up));
        $this->assertFalse($this->isVisible($form, [$table_id => $this->rows(2)], $follow_up));
    }

    /** @param array<int, mixed> $answers */
    private function isVisible(Form $form, array $answers, int $question_id): bool
    {
        return (new Engine($form, new EngineInput($answers)))
            ->computeVisibility()
            ->isQuestionVisible($question_id);
    }

    private function formWithRowCountValidation(
        ValidationStrategy $strategy,
        ValueOperator $operator,
        string $value,
    ): Form {
        $builder = new FormBuilder('Row count validation form');
        $builder->addQuestion(
            'Table',
            TableQuestion::class,
            extra_data: $this->extraData(),
        );
        $builder->setQuestionValidation(
            'Table',
            $strategy,
            [
                [
                    'logic_operator' => LogicOperator::AND,
                    'item_name'      => 'Table',
                    'item_type'      => Type::QUESTION,
                    'value_operator' => $operator,
                    'value'          => $value,
                ],
            ],
        );

        return $this->createForm($builder);
    }

    /** @param array<int, mixed> $answer */
    private function validate(Form $form, array $answer): ValidationResult
    {
        return AnswersHandler::getInstance()->validateAnswers($form, [
            $this->getQuestionId($form, 'Table') => $answer,
        ]);
    }

    /** @return list<array{col_0: string}> */
    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['col_0' => 'value ' . $i];
        }

        return $rows;
    }

    private function extraData(): string
    {
        return json_encode(new TableQuestionConfig(columns: [
            [
                TableQuestionConfig::COL_NAME          => 'Name',
                TableQuestionConfig::COL_QUESTION_TYPE => QuestionTypeShortText::class,
                TableQuestionConfig::COL_REQUIRED      => false,
                TableQuestionConfig::COL_ITEMTYPE      => '',
                TableQuestionConfig::COL_PATTERN       => '',
            ],
        ]));
    }
}
