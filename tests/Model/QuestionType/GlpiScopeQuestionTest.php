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
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\GlpiScopeQuestion;
use GlpiPlugin\Advancedforms\Tests\QuestionType\QuestionTypeTestCase;
use Override;
use Symfony\Component\DomCrawler\Crawler;

final class GlpiScopeQuestionTest extends QuestionTypeTestCase
{
    #[Override]
    protected function getTestedQuestionType(): QuestionTypeInterface&ConfigurableItemInterface
    {
        return new GlpiScopeQuestion();
    }

    #[Override]
    protected function validateEditorRenderingWhenEnabled(Crawler $html): void
    {
        $this->assertNotEmpty($html->filter('input[data-af-glpi-scope-preview]'));
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenEnabled(Crawler $html): void
    {
        $this->assertCount(1, $html->filter('[data-af-glpi-scope] input[type=checkbox][value=GLPI]'));
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenDisabled(Crawler $html): void
    {
        $this->assertCount(0, $html->filter('[data-af-glpi-scope]'));
    }

    public function testPrepareEndUserAnswerNormalizesValues(): void
    {
        $question = $this->createScopeQuestion();
        $answer = array_merge(
            ['GLPI', ' GLPI ', '', 'Data injection (datainjection)', str_repeat('a', 256), ['nested']],
            array_map(fn(int $i): string => "Plugin $i", range(1, 60)),
        );

        $prepared = (new GlpiScopeQuestion())->prepareEndUserAnswer($question, $answer);

        $this->assertIsArray($prepared);
        $this->assertCount(50, $prepared);
        $this->assertSame(['GLPI', 'Data injection (datainjection)', 'Plugin 1'], array_slice($prepared, 0, 3));
        $this->assertSame([], (new GlpiScopeQuestion())->prepareEndUserAnswer($question, 'GLPI'));
    }

    public function testValidateAnswerRejectsNonScalarValues(): void
    {
        $question = $this->createScopeQuestion();
        $type = new GlpiScopeQuestion();

        $this->assertTrue($type->validateAnswer($question, ['GLPI'])->isValid());
        $this->assertFalse($type->validateAnswer($question, [['GLPI']])->isValid());
        $this->assertFalse($type->validateAnswer($question, 'GLPI')->isValid());
    }

    public function testTicketContainsSelectedValues(): void
    {
        $this->login();
        $this->enableConfigurableItem(new GlpiScopeQuestion());
        $builder = new FormBuilder('Scope form');
        $builder->addQuestion('Scope', GlpiScopeQuestion::class);

        $form = $this->createForm($builder);

        $ticket = $this->sendFormAndGetCreatedTicket($form, [
            'Scope' => ['GLPI', '<b>Fields</b> (fields)'],
        ]);

        $this->assertStringContainsString('GLPI, &lt;b&gt;Fields&lt;/b&gt; (fields)', $ticket->fields['content']);
    }

    private function createScopeQuestion(): Question
    {
        $this->enableConfigurableItem(new GlpiScopeQuestion());
        $builder = new FormBuilder('Scope form');
        $builder->addQuestion('Scope', GlpiScopeQuestion::class);

        $form = $this->createForm($builder);

        return Question::getById($this->getQuestionId($form, 'Scope'));
    }
}
