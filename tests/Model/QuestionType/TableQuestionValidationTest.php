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
use Glpi\Form\QuestionType\QuestionTypeCheckbox;
use Glpi\Form\QuestionType\QuestionTypeEmail;
use Glpi\Form\QuestionType\QuestionTypeNumber;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestionConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;

use function Safe\json_encode;

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
                'Required validation should fail for column type ' . $fqcn,
            );
        }
    }

    public function testColumnPatternMismatchProducesError(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Source IP', QuestionTypeShortText::class, required: false, pattern: '/^172\.23\./'),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => '10.0.0.1'],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->getErrors());
    }

    public function testColumnPatternMatchIsValid(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Source IP', QuestionTypeShortText::class, required: false, pattern: '/^172\.23\./'),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => '172.23.0.1'],
        ]);

        $this->assertTrue($result->isValid());
        $this->assertCount(0, $result->getErrors());
    }

    public function testOptionalColumnWithPatternEmptyIsValid(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Source IP', QuestionTypeShortText::class, required: false, pattern: '/^172\.23\./'),
            $this->column('Comment', QuestionTypeShortText::class, required: false),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => '', 'col_1' => 'a comment'],
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testPatternAppliesOnlyToItsOwnColumn(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Source IP', QuestionTypeShortText::class, required: false, pattern: '/^172\.23\./'),
            $this->column('Checkbox', QuestionTypeShortText::class, required: false),
            $this->column('Port', QuestionTypeShortText::class, required: false, pattern: '/^\d+$/'),
        ]);

        // Source IP is invalid, Checkbox has no pattern, Port is valid.
        $result = $this->type->validateAnswer($question, [
            ['col_0' => '10.0.0.1', 'col_1' => 'anything', 'col_2' => '8080'],
        ]);

        $errors = $result->getErrors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Source IP', $errors[0]['message']);
    }

    public function testTwoIndependentColumnPatternsBothEnforced(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Source IP', QuestionTypeShortText::class, required: false, pattern: '/^172\.23\./'),
            $this->column('Port', QuestionTypeShortText::class, required: false, pattern: '/^\d+$/'),
        ]);

        // Both columns are invalid: two independent errors expected.
        $result = $this->type->validateAnswer($question, [
            ['col_0' => '10.0.0.1', 'col_1' => 'not-a-port'],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertCount(2, $result->getErrors());
    }

    public function testInvalidRegexPatternIsRejected(): void
    {
        $this->assertFalse($this->type->validateExtraDataInput([
            'columns' => [
                $this->column('Source IP', QuestionTypeShortText::class, required: false, pattern: '/[/'),
            ],
        ]));
    }

    public function testNonSlashDelimitedPatternIsRejected(): void
    {
        // Valid PCRE with a non-`/` delimiter, but only `/…/` is stripped for HTML/JS.
        $this->assertFalse($this->type->validateExtraDataInput([
            'columns' => [
                $this->column('Source IP', QuestionTypeShortText::class, required: false, pattern: '#^172\.23\.#'),
            ],
        ]));
    }

    public function testPatternWithASharedFlagIsAccepted(): void
    {
        $this->assertTrue($this->type->validateExtraDataInput([
            'columns' => [
                $this->column('Env', QuestionTypeShortText::class, pattern: '/^prod$/i'),
            ],
        ]));
    }

    public function testPatternWithAJsOnlyFlagIsRejected(): void
    {
        // `g` is not a PCRE modifier, and it would make the client-side `test()`
        // stateful from one cell to the next.
        $this->assertFalse($this->type->validateExtraDataInput([
            'columns' => [
                $this->column('Env', QuestionTypeShortText::class, pattern: '/prod/g'),
            ],
        ]));
    }

    public function testPatternWithoutDelimitersIsRejected(): void
    {
        $this->assertFalse($this->type->validateExtraDataInput([
            'columns' => [
                $this->column('Env', QuestionTypeShortText::class, pattern: '^prod$'),
            ],
        ]));
    }

    public function testPatternIsIgnoredOnNonShortAnswerColumn(): void
    {
        // Config UI never exposes pattern for non-short-answer columns; a hand-crafted one must be ignored.
        $question = $this->makeTableQuestion([
            $this->column('Flag', QuestionTypeCheckbox::class, required: false, pattern: '/^yes$/'),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => '1'],
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testNumberColumnRejectsNonNumericValue(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Quantity', QuestionTypeNumber::class, required: false),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => 'not-a-number'],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->getErrors());
    }

    public function testNumberColumnAcceptsNumericValue(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Quantity', QuestionTypeNumber::class, required: false),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => '42.5'],
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testEmailColumnRejectsInvalidAddress(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Contact', QuestionTypeEmail::class, required: false),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => 'not-an-email'],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->getErrors());
    }

    public function testEmailColumnAcceptsValidAddress(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Contact', QuestionTypeEmail::class, required: false),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => 'user@example.com'],
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testOptionalNumberColumnEmptyIsValid(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Quantity', QuestionTypeNumber::class, required: false),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => ''],
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testAnswersHandlerReportsMissingRequiredColumn(): void
    {
        // The condition engine only validates visible questions; plugin types require authentication.
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

    public function testUnanchoredPatternMatchesAPrefix(): void
    {
        // The HTML `pattern` attribute would anchor this and reject the value.
        $question = $this->makeTableQuestion([
            $this->column('Source IP', QuestionTypeShortText::class, pattern: '/^172\.23\./'),
        ]);

        $result = $this->type->validateAnswer($question, [['col_0' => '172.23.0.1']]);

        $this->assertTrue($result->isValid());
    }

    public function testUnanchoredPatternMatchesAnywhereInTheValue(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Label', QuestionTypeShortText::class, pattern: '/PROD/'),
        ]);

        $result = $this->type->validateAnswer($question, [['col_0' => 'srv-PROD-01']]);

        $this->assertTrue($result->isValid());
    }

    public function testCaseInsensitiveFlagIsHonoured(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Env', QuestionTypeShortText::class, pattern: '/^prod$/i'),
        ]);

        $result = $this->type->validateAnswer($question, [['col_0' => 'PROD']]);

        $this->assertTrue($result->isValid());
    }

    public function testCaseSensitivePatternStillRejectsWrongCase(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Env', QuestionTypeShortText::class, pattern: '/^prod$/'),
        ]);

        $result = $this->type->validateAnswer($question, [['col_0' => 'PROD']]);

        $this->assertFalse($result->isValid());
    }

    public function testDotAllFlagIsHonoured(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Blob', QuestionTypeShortText::class, pattern: '/^a.b$/s'),
        ]);

        $result = $this->type->validateAnswer($question, [['col_0' => "a\nb"]]);

        $this->assertTrue($result->isValid());
    }

    public function testUnicodeValueIsMatchedWithTheUnicodeFlag(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('City', QuestionTypeShortText::class, pattern: '/^\p{L}+$/u'),
        ]);

        $result = $this->type->validateAnswer($question, [['col_0' => 'Besançon']]);

        $this->assertTrue($result->isValid());
    }

    public function testMalformedStoredPatternDoesNotBlockSubmission(): void
    {
        // validateExtraDataInput() rejects these, so it has to be injected as
        // already-stored data: a pattern that predates a rule change must never
        // lock a user out of the form.
        $question = $this->makeTableQuestionWithStoredConfig([
            $this->column('Broken', QuestionTypeShortText::class, pattern: '/[/'),
        ]);

        $result = $this->type->validateAnswer($question, [['col_0' => 'anything']]);

        $this->assertTrue($result->isValid());
    }

    public function testUnknownStoredColumnTypeDoesNotBlockSubmission(): void
    {
        $question = $this->makeTableQuestionWithStoredConfig([
            $this->column('Ghost', 'GlpiPlugin\\Removed\\QuestionType\\Gone'),
        ]);

        $result = $this->type->validateAnswer($question, [['col_0' => 'anything']]);

        $this->assertTrue($result->isValid());
    }

    public function testMiddleColumnWithoutPatternIsNeverFlagged(): void
    {
        // Exact shape of the customer report: regex on the outer columns only.
        $question = $this->makeTableQuestion([
            $this->column('SRC IP', QuestionTypeShortText::class, pattern: '/^[0-9.]+$/'),
            $this->column('description', QuestionTypeShortText::class),
            $this->column('DST IP', QuestionTypeShortText::class, pattern: '/^[0-9.]+$/'),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => '10.0.0.1', 'col_1' => 'asdasd', 'col_2' => '10.0.0.2'],
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testPatternOnTheLastColumnOnlyLeavesTheFirstColumnFree(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Free text', QuestionTypeShortText::class),
            $this->column('Digits', QuestionTypeShortText::class, pattern: '/^\d+$/'),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => 'anything goes', 'col_1' => '42'],
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testPatternOnTheLastColumnOnlyStillFlagsThatColumn(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Free text', QuestionTypeShortText::class),
            $this->column('Digits', QuestionTypeShortText::class, pattern: '/^\d+$/'),
        ]);

        $errors = $this->type->validateAnswer($question, [
            ['col_0' => 'anything goes', 'col_1' => 'not digits'],
        ])->getErrors();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Digits', $errors[0]['message']);
    }

    public function testEveryColumnPatternIsEnforcedIndependently(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('A', QuestionTypeShortText::class, pattern: '/^a+$/'),
            $this->column('B', QuestionTypeShortText::class, pattern: '/^b+$/'),
            $this->column('C', QuestionTypeShortText::class, pattern: '/^c+$/'),
        ]);

        // Only the middle column is wrong.
        $errors = $this->type->validateAnswer($question, [
            ['col_0' => 'aaa', 'col_1' => 'xxx', 'col_2' => 'ccc'],
        ])->getErrors();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('"B"', $errors[0]['message']);
    }

    public function testAllColumnPatternsCanFailAtOnce(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('A', QuestionTypeShortText::class, pattern: '/^a+$/'),
            $this->column('B', QuestionTypeShortText::class, pattern: '/^b+$/'),
            $this->column('C', QuestionTypeShortText::class, pattern: '/^c+$/'),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => 'x', 'col_1' => 'y', 'col_2' => 'z'],
        ]);

        $this->assertCount(3, $result->getErrors());
    }

    public function testRequiredColumnWithPatternReportsOnlyTheMissingValue(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Source IP', QuestionTypeShortText::class, required: true, pattern: '/^[0-9.]+$/'),
            $this->column('Comment', QuestionTypeShortText::class),
        ]);

        $errors = $this->type->validateAnswer($question, [
            ['col_0' => '', 'col_1' => 'a comment'],
        ])->getErrors();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('required', $errors[0]['message']);
    }

    public function testRequiredColumnWithPatternReportsTheFormatWhenFilled(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Source IP', QuestionTypeShortText::class, required: true, pattern: '/^[0-9.]+$/'),
        ]);

        $errors = $this->type->validateAnswer($question, [['col_0' => 'nope']])->getErrors();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('format', $errors[0]['message']);
    }

    public function testPatternOnAnEmailColumnDoesNotDisableEmailValidation(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Contact', QuestionTypeEmail::class, pattern: '/@example\.com$/'),
        ]);

        // Satisfies the pattern, yet is not a valid address: only the native
        // check can catch it.
        $result = $this->type->validateAnswer($question, [['col_0' => 'bob bob@example.com']]);

        $this->assertFalse($result->isValid(), 'A pattern must not shadow the native email check.');
    }

    public function testEmailColumnPatternRejectsAWrongDomain(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Contact', QuestionTypeEmail::class, pattern: '/@example\.com$/'),
        ]);

        $result = $this->type->validateAnswer($question, [['col_0' => 'bob@other.com']]);

        $this->assertFalse($result->isValid());
    }

    public function testEmailColumnAcceptsAnAddressMatchingBothRules(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Contact', QuestionTypeEmail::class, pattern: '/@example\.com$/'),
        ]);

        $result = $this->type->validateAnswer($question, [['col_0' => 'bob@example.com']]);

        $this->assertTrue($result->isValid());
    }

    public function testInvalidEmailIsReportedOnceWithTheNativeMessage(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Contact', QuestionTypeEmail::class, pattern: '/@example\.com$/'),
        ]);

        // Fails the native check and the pattern; the user gets one message, and
        // the type-specific one is the most helpful of the two.
        $errors = $this->type->validateAnswer($question, [['col_0' => 'not-an-email']])->getErrors();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('email', $errors[0]['message']);
    }

    public function testPatternOnANumberColumnDoesNotDisableNumberValidation(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Port', QuestionTypeNumber::class, pattern: '/^[0-9a-f]+$/'),
        ]);

        // Hexadecimal letters satisfy the pattern but are not a number.
        $result = $this->type->validateAnswer($question, [['col_0' => 'abc']]);

        $this->assertFalse($result->isValid(), 'A pattern must not shadow the native number check.');
    }

    public function testNumberColumnWithPatternRejectsAnOutOfShapeNumber(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Port', QuestionTypeNumber::class, pattern: '/^\d{1,4}$/'),
        ]);

        $this->assertFalse(
            $this->type->validateAnswer($question, [['col_0' => '99999']])->isValid(),
        );
        $this->assertTrue(
            $this->type->validateAnswer($question, [['col_0' => '8080']])->isValid(),
        );
    }

    public function testErrorMessageCarriesTheSubmittedRowNumber(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Digits', QuestionTypeShortText::class, pattern: '/^\d+$/'),
        ]);

        $errors = $this->type->validateAnswer($question, [
            ['col_0' => '1'],
            ['col_0' => '2'],
            ['col_0' => 'bad'],
        ])->getErrors();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('3', $errors[0]['message']);
    }

    public function testEachFaultyRowIsReportedSeparately(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Digits', QuestionTypeShortText::class, pattern: '/^\d+$/'),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => 'bad'],
            ['col_0' => '2'],
            ['col_0' => 'worse'],
        ]);

        $this->assertCount(2, $result->getErrors());
    }

    public function testManyValidRowsProduceNoError(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('Digits', QuestionTypeShortText::class, required: true, pattern: '/^\d+$/'),
            $this->column('Comment', QuestionTypeShortText::class),
        ]);

        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = ['col_0' => (string) $i, 'col_1' => 'row ' . $i];
        }

        $this->assertTrue($this->type->validateAnswer($question, $rows)->isValid());
    }

    public function testTableWithoutAnyRuleAcceptsAnything(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('A', QuestionTypeShortText::class),
            $this->column('B', QuestionTypeShortText::class),
        ]);

        $result = $this->type->validateAnswer($question, [
            ['col_0' => '!!!', 'col_1' => '???'],
            ['col_0' => '',    'col_1' => ''],
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testNoColumnConfiguredAcceptsAnyAnswer(): void
    {
        // A column-less table cannot be configured, but stored data could end up
        // that way; validateAnswer() must not choke on it.
        $question = $this->makeTableQuestionWithStoredConfig([]);

        $result = $this->type->validateAnswer($question, [['col_0' => 'orphan value']]);

        $this->assertTrue($result->isValid());
    }

    public function testCellForAnUnknownColumnIsIgnored(): void
    {
        $question = $this->makeTableQuestion([
            $this->column('A', QuestionTypeShortText::class, pattern: '/^a+$/'),
        ]);

        // col_9 has no matching column; it must not be validated against col_0.
        $result = $this->type->validateAnswer($question, [
            ['col_0' => 'aaa', 'col_9' => 'zzz'],
        ]);

        $this->assertTrue($result->isValid());
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
     * Builds a question whose stored configuration would be refused by
     * validateExtraDataInput(), to exercise the tolerance of validateAnswer()
     * towards data written by an older version.
     *
     * @param array<array{name: string, question_type: string, required: bool, itemtype: string, pattern: string}> $columns
     */
    private function makeTableQuestionWithStoredConfig(array $columns): Question
    {
        $question = $this->makeTableQuestion([
            $this->column('Placeholder', QuestionTypeShortText::class),
        ]);
        $question->fields['extra_data'] = json_encode(new TableQuestionConfig(columns: $columns));

        return $question;
    }


    /**
     * @return array{name: string, question_type: string, required: bool, itemtype: string, pattern: string}
     */
    private function column(string $name, string $fqcn, bool $required = false, string $pattern = ''): array
    {
        return [
            TableQuestionConfig::COL_NAME          => $name,
            TableQuestionConfig::COL_QUESTION_TYPE => $fqcn,
            TableQuestionConfig::COL_REQUIRED      => $required,
            TableQuestionConfig::COL_ITEMTYPE      => '',
            TableQuestionConfig::COL_PATTERN       => $pattern,
        ];
    }
}
