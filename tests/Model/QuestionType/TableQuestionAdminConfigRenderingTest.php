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
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestionConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Regression test for a form editor bug: the columns configuration panel had
 * no scroll of its own, so a Table question with enough columns to overflow
 * the viewport made the whole PAGE scroll to reach the last ones — which, at
 * least in Chromium/Bootstrap 5, could make the still-open dropdown menu jump
 * back to the top instead of staying anchored to its toggle button.
 */
final class TableQuestionAdminConfigRenderingTest extends AdvancedFormsTestCase
{
    private TableQuestion $type;

    public function setUp(): void
    {
        parent::setUp();
        $this->type = new TableQuestion();
        $this->login();
    }

    public function testColumnsContainerScrollsOnItsOwnInsteadOfThePage(): void
    {
        $html = $this->renderConfig([
            $this->column('A', QuestionTypeShortText::class),
            $this->column('B', QuestionTypeShortText::class),
        ]);

        $crawler   = new Crawler($html);
        $container = $crawler->filter('[data-af-table-columns-container]')->first();

        $this->assertGreaterThan(
            0,
            $container->count(),
            'The columns configuration panel must be present in the rendered admin template.',
        );

        $style = (string) $container->attr('style');
        $this->assertStringContainsString(
            'overflow-y: auto',
            $style,
            'The columns container must scroll on its own once it has enough columns, ' .
            'instead of forcing the whole page (and the still-open dropdown menu with it) to scroll.',
        );
        $this->assertMatchesRegularExpression(
            '/max-height\s*:\s*\d+(\.\d+)?(vh|px|rem|em)/',
            $style,
            'A bare `overflow-y: auto` with no height constraint never actually scrolls.',
        );
    }

    /**
     * @param array<array{name: string, question_type: string, required: bool, itemtype: string, pattern: string}> $columns
     */
    private function renderConfig(array $columns): string
    {
        $this->enableConfigurableItem($this->type);

        $builder = new FormBuilder('Admin config rendering form');
        $builder->addQuestion(
            'Table',
            TableQuestion::class,
            extra_data: json_encode(new TableQuestionConfig(columns: $columns)),
        );
        $form = $this->createForm($builder);

        $question = Question::getById($this->getQuestionId($form, 'Table'));

        return $this->type->renderAdvancedConfigurationTemplate($question);
    }

    /**
     * @return array{name: string, question_type: string, required: bool, itemtype: string, pattern: string}
     */
    private function column(string $name, string $fqcn): array
    {
        return [
            TableQuestionConfig::COL_NAME          => $name,
            TableQuestionConfig::COL_QUESTION_TYPE => $fqcn,
            TableQuestionConfig::COL_REQUIRED      => false,
            TableQuestionConfig::COL_ITEMTYPE      => '',
            TableQuestionConfig::COL_PATTERN       => '',
        ];
    }
}
