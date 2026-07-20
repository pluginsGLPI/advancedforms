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

        // The rendered helpdesk page contains several `<script type="module">`
        // tags (fuzzy search, other widgets, ...), so every module script's
        // text is concatenated before asserting instead of only looking at
        // the first matched node (Crawler::text() only reads node 0).
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

        // `Glpi\Form\Question` is final and cannot be mocked, and neither
        // `prepareEndUserAnswer()` nor `formatRawAnswer()` actually read
        // from the question here, so a real question is built instead.
        // The question type must be enabled or `Section::getQuestions()`
        // will silently skip it (treating it as an unknown/disabled type).
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

        // Entirely empty answer, as submitted by the widget's untouched
        // hidden inputs when the question is left unanswered.
        $this->assertNull($type->prepareEndUserAnswer($question, [
            'reservationitems_id' => '',
            'begin' => '',
            'end' => '',
        ]));

        // Missing keys entirely.
        $this->assertNull($type->prepareEndUserAnswer($question, []));

        // Partially filled: should still be treated as "not answered"
        // rather than throwing.
        $this->assertNull($type->prepareEndUserAnswer($question, [
            'reservationitems_id' => 42,
            'begin' => '',
            'end' => '',
        ]));

        // Not an array at all.
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
}
