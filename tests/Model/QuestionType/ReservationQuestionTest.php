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
use Glpi\Form\QuestionType\QuestionTypeInterface;
use Glpi\Tests\FormBuilder;
use Glpi\Tests\FormTesterTrait;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestion;
use GlpiPlugin\Advancedforms\Tests\QuestionType\QuestionTypeTestCase;
use Override;
use Symfony\Component\DomCrawler\Crawler;

final class ReservationQuestionTest extends QuestionTypeTestCase
{
    use FormTesterTrait;

    #[Override]
    protected function getTestedQuestionType(): QuestionTypeInterface&ConfigurableItemInterface
    {
        return new ReservationQuestion();
    }

    #[Override]
    protected function validateEditorRenderingWhenEnabled(
        Crawler $html,
    ): void {
        $this->assertGreaterThan(
            0,
            $html->filter('[data-glpi-form-editor-question-extra-details]')->count(),
        );
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenEnabled(
        Crawler $html,
    ): void {
        $this->assertGreaterThan(0, $html->filter('input[name$="[reservationitems_id]"]')->count());
        $this->assertGreaterThan(0, $html->filter('input[name$="[begin]"]')->count());
        $this->assertGreaterThan(0, $html->filter('input[name$="[end]"]')->count());

        $module_scripts_text = implode(
            "\n",
            $html->filter('script[type="module"]')->each(fn(Crawler $node) => $node->text()),
        );
        $this->assertStringContainsString("ReservationQuestionWidget.js", $module_scripts_text);
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenDisabled(
        Crawler $html,
    ): void {
        $this->assertCount(0, $html->filter('input[name$="[reservationitems_id]"]'));
    }

    public function testFormatRawAnswerAndPrepareEndUserAnswerRoundTrip(): void
    {
        $type = new ReservationQuestion();
        $raw = [
            'reservationitems_id' => 42,
            'begin' => '2026-01-15 09:00:00',
            'end' => '2026-01-15 12:00:00',
        ];

        $this->enableConfigurableItem($type);
        $builder = new FormBuilder("My form");
        $builder->addQuestion("My question", ReservationQuestion::class);

        $form = $this->createForm($builder);
        $questions_id = $this->getQuestionId($form, "My question");
        $question = new Question();
        $this->assertTrue($question->getFromDB($questions_id));

        $prepared = $type->prepareEndUserAnswer($question, $raw);
        $this->assertSame([
            'reservationitems_id' => 42,
            'begin' => '2026-01-15 09:00:00',
            'end' => '2026-01-15 12:00:00',
        ], $prepared);

        $formatted = $type->formatRawAnswer($raw, $question);
        $this->assertStringContainsString('2026-01-15 09:00:00', $formatted);
        $this->assertStringContainsString('2026-01-15 12:00:00', $formatted);
    }

    public function testPrepareEndUserAnswerReturnsNullWhenAnswerIsEmptyOrIncomplete(): void
    {
        $type = new ReservationQuestion();

        $this->enableConfigurableItem($type);
        $builder = new FormBuilder("My form");
        $builder->addQuestion("My question", ReservationQuestion::class);

        $form = $this->createForm($builder);
        $questions_id = $this->getQuestionId($form, "My question");
        $question = new Question();
        $this->assertTrue($question->getFromDB($questions_id));

        $this->assertNull($type->prepareEndUserAnswer($question, [
            'reservationitems_id' => '',
            'begin' => '',
            'end' => '',
        ]));

        $this->assertNull($type->prepareEndUserAnswer($question, []));

        $this->assertNull($type->prepareEndUserAnswer($question, [
            'reservationitems_id' => 42,
            'begin' => '',
            'end' => '',
        ]));

        $this->assertNull($type->prepareEndUserAnswer($question, null));
    }

    public function testFormatRawAnswerReturnsEmptyStringWhenAnswerIsEmptyOrIncomplete(): void
    {
        $type = new ReservationQuestion();

        $this->enableConfigurableItem($type);
        $builder = new FormBuilder("My form");
        $builder->addQuestion("My question", ReservationQuestion::class);

        $form = $this->createForm($builder);
        $questions_id = $this->getQuestionId($form, "My question");
        $question = new Question();
        $this->assertTrue($question->getFromDB($questions_id));

        $this->assertSame('', $type->formatRawAnswer([
            'reservationitems_id' => '',
            'begin' => '',
            'end' => '',
        ], $question));

        $this->assertSame('', $type->formatRawAnswer([], $question));

        $this->assertSame('', $type->formatRawAnswer([
            'reservationitems_id' => 42,
            'begin' => '',
            'end' => '',
        ], $question));

        $this->assertSame('', $type->formatRawAnswer(null, $question));
    }

    public function testValidateAnswerAcceptsCompleteCoherentAnswer(): void
    {
        $type = new ReservationQuestion();
        $result = $type->validateAnswer($this->makeReservationQuestion(), [
            'reservationitems_id' => 5,
            'begin' => '2026-01-15 09:00:00',
            'end' => '2026-01-15 12:00:00',
        ]);

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->getErrors());
    }

    public function testValidateAnswerRejectsPartialAnswer(): void
    {
        $type = new ReservationQuestion();
        $result = $type->validateAnswer($this->makeReservationQuestion(), [
            'reservationitems_id' => 5,
            'begin' => '2026-01-15 09:00:00',
            'end' => '',
        ]);

        $this->assertFalse($result->isValid());
    }

    public function testValidateAnswerRejectsEndBeforeBegin(): void
    {
        $type = new ReservationQuestion();
        $result = $type->validateAnswer($this->makeReservationQuestion(), [
            'reservationitems_id' => 5,
            'begin' => '2026-01-15 12:00:00',
            'end' => '2026-01-15 09:00:00',
        ]);

        $this->assertFalse($result->isValid());
    }

    public function testValidateAnswerAcceptsFullyEmptyOptionalQuestion(): void
    {
        $type = new ReservationQuestion();
        $result = $type->validateAnswer($this->makeReservationQuestion(mandatory: false), [
            'reservationitems_id' => '',
            'begin' => '',
            'end' => '',
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testValidateAnswerRejectsFullyEmptyMandatoryQuestion(): void
    {
        $type = new ReservationQuestion();
        $result = $type->validateAnswer($this->makeReservationQuestion(mandatory: true), [
            'reservationitems_id' => '',
            'begin' => '',
            'end' => '',
        ]);

        $this->assertFalse($result->isValid());
    }

    public function testValidateAnswerRejectsNonArrayAnswer(): void
    {
        $type = new ReservationQuestion();
        $result = $type->validateAnswer($this->makeReservationQuestion(), 'not-an-array');

        $this->assertFalse($result->isValid());
    }

    private function makeReservationQuestion(bool $mandatory = false): Question
    {
        $type = new ReservationQuestion();
        $this->enableConfigurableItem($type);

        $builder = new FormBuilder("Reservation validation form");
        $builder->addQuestion("Reservation", ReservationQuestion::class);

        $form = $this->createForm($builder);

        $question = new Question();
        $this->assertTrue($question->getFromDB($this->getQuestionId($form, "Reservation")));

        if ($mandatory) {
            $question->fields['is_mandatory'] = 1;
        }

        return $question;
    }
}
