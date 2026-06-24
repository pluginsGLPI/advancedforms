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

use Glpi\Form\AnswersHandler\AnswersHandler;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestionConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;

final class TableQuestionValidationTest extends AdvancedFormsTestCase
{
    private TableQuestion $type;

    public function setUp(): void
    {
        parent::setUp();
        $this->type = new TableQuestion();
    }

    public function testRequiredColumnEmptyInFilledRowProducesError(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Name', QuestionTypeShortText::class, required: true),
            $this->column('Comment', QuestionTypeShortText::class, required: false),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => '', 'col_1' => 'a comment'],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->getErrors());
    }

    public function testAllRequiredColumnsFilledIsValid(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Name', QuestionTypeShortText::class, required: true),
            $this->column('Comment', QuestionTypeShortText::class, required: false),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => 'Alice', 'col_1' => ''],
        ]);

        $this->assertTrue($result->isValid());
        $this->assertCount(0, $result->getErrors());
    }

    public function testEntirelyEmptyRowIsSkipped(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Name', QuestionTypeShortText::class, required: true),
            $this->column('Comment', QuestionTypeShortText::class, required: false),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => '', 'col_1' => ''],
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testOptionalColumnEmptyIsValid(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Name', QuestionTypeShortText::class, required: false),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => ''],
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testErrorMessageMentionsColumnName(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Serial number', QuestionTypeShortText::class, required: true),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => '', 'col_1' => 'filler'],
        ]);

        $errors = $result->getErrors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Serial number', $errors[0]['message']);
    }

    public function testNonArrayAnswerIsValid(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Name', QuestionTypeShortText::class, required: true),
        ]);

        $result = $this->type->validateAnswer($question, 'not-an-array');

        $this->assertTrue($result->isValid());
    }

    public function testMultipleMissingRequiredCellsProduceMultipleErrors(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('A', QuestionTypeShortText::class, required: true),
            $this->column('B', QuestionTypeShortText::class, required: true),
        ]);

        // Row 1: A missing. Row 2: B missing. Row 3: entirely empty (ignored).
        $result = $this->type->validateAnswer($question, [
            ['col_0' => '',     'col_1' => 'b1'],
            ['col_0' => 'a2',   'col_1' => ''],
            ['col_0' => '',     'col_1' => ''],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertCount(2, $result->getErrors());
    }

    public function testRequiredValidationCoversEveryCompatibleColumnType(): void
    {
        foreach (array_keys($this->type->getCompatibleQuestionTypes()) as $fqcn) {
            $question = $this->makeTableQuestion([
                $this->column('Mandatory', $fqcn, required: true),
                $this->column('Filler', QuestionTypeShortText::class, required: false),
            ]);

            // Filler is filled so the row counts as non-empty, mandatory cell is empty.
            $result = $this->type->validateAnswer($question, [
                ['col_0' => '', 'col_1' => 'filled'],
            ]);

            $this->assertFalse(
                $result->isValid(),
                "Required validation should fail for column type {$fqcn}",
            );
        }
    }

    public function testAnswersHandlerReportsMissingRequiredColumn(): void
    {
        // The condition engine only validates questions visible to the current
        // user, and plugin question types require authentication to be visible.
        $this->login();
        $this->enableConfigurableItem($this->type);

        $builder = new FormBuilder('Validation form');
        $builder->addQuestion(
            'Table',
            TableQuestion::class,
            extra_data: json_encode(new TableQuestionConfig(
                columns: [
                    $this->column('Name', QuestionTypeShortText::class, required: true),
                    $this->column('Comment', QuestionTypeShortText::class, required: false),
                ],
            )),
        );
        $form = $this->createForm($builder);
        $question_id = $this->getQuestionId($form, 'Table');

        // Row has data (the comment) but the mandatory "Name" cell is empty.
        $result = AnswersHandler::getInstance()->validateAnswers($form, [
            $question_id => [['col_0' => '', 'col_1' => 'a comment']],
        ]);

        $this->assertFalse($result->isValid());
    }

    /**
     * @param array<array{name: string, question_type: string, required: bool, itemtype: string}> $columns
     */
    private function makeTableQuestion(array $columns): Question
    {
        $this->enableConfigurableItem($this->type);

        $builder = new FormBuilder('Validation form');
        $builder->addQuestion(
            'Table',
            TableQuestion::class,
            extra_data: json_encode(new TableQuestionConfig(columns: $columns)),
        );
        $form = $this->createForm($builder);

        return Question::getById($this->getQuestionId($form, 'Table'));
    }

    /**
     * @return array{name: string, question_type: string, required: bool, itemtype: string}
     */
    private function column(string $name, string $fqcn, bool $required): array
    {
        return [
            TableQuestionConfig::COL_NAME          => $name,
            TableQuestionConfig::COL_QUESTION_TYPE => $fqcn,
            TableQuestionConfig::COL_REQUIRED      => $required,
            TableQuestionConfig::COL_ITEMTYPE      => '',
        ];
    }
}
