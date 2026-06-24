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

use Glpi\Form\QuestionType\QuestionTypeInterface;
use Glpi\Form\QuestionType\QuestionTypeNumber;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestionConfig;
use GlpiPlugin\Advancedforms\Tests\QuestionType\QuestionTypeTestCase;
use Override;
use Symfony\Component\DomCrawler\Crawler;

final class TableQuestionIntegrationTest extends QuestionTypeTestCase
{
    #[Override]
    protected function getTestedQuestionType(): QuestionTypeInterface&ConfigurableItemInterface
    {
        return new TableQuestion();
    }

    #[Override]
    protected function getDefaultExtraDataForQuestionType(QuestionTypeInterface $type): ?string
    {
        return json_encode(new TableQuestionConfig(
            columns: [
                [
                    TableQuestionConfig::COL_NAME          => 'Source IP',
                    TableQuestionConfig::COL_QUESTION_TYPE => QuestionTypeShortText::class,
                    TableQuestionConfig::COL_REQUIRED      => true,
                ],
                [
                    TableQuestionConfig::COL_NAME          => 'Port',
                    TableQuestionConfig::COL_QUESTION_TYPE => QuestionTypeNumber::class,
                    TableQuestionConfig::COL_REQUIRED      => false,
                ],
            ],
            min_rows: 1,
            max_rows: 50,
        ));
    }

    #[Override]
    protected function validateEditorRenderingWhenEnabled(Crawler $html): void
    {
        // Preview table must show column headers — use combined text to handle required asterisk span
        $headerText = implode(' ', $html->filter('th')->each(fn(Crawler $n) => $n->text()));
        $this->assertStringContainsString('Source IP', $headerText);
        $this->assertStringContainsString('Port', $headerText);

        // Gear settings button must be present
        $gear = $html->filter('[data-glpi-form-editor-question-extra-details]');
        $this->assertNotEmpty($gear);
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenEnabled(Crawler $html): void
    {
        // Dynamic table container must exist
        $container = $html->filter('[data-af-table-question]');
        $this->assertNotEmpty($container);

        // Required columns must be exposed to the client validation layer.
        // The default config marks the first column ("Source IP") as required.
        $this->assertSame('0', $container->attr('data-af-required-cols'));

        // At least one input row rendered (min_rows = 1)
        $rows = $html->filter('[data-af-table-body] [data-af-table-row]');
        $this->assertGreaterThanOrEqual(1, $rows->count());

        // Add-row button must be present
        $addBtn = $html->filter('[data-af-table-add-row]');
        $this->assertNotEmpty($addBtn);
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenDisabled(Crawler $html): void
    {
        $container = $html->filter('[data-af-table-question]');
        $this->assertEmpty($container);
    }
}
