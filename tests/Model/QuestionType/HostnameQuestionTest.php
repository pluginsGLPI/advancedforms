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
use Glpi\Tests\FormTesterTrait;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\HostnameQuestion;
use GlpiPlugin\Advancedforms\Tests\QuestionType\QuestionTypeTestCase;
use Override;
use Symfony\Component\DomCrawler\Crawler;

final class HostnameQuestionTest extends QuestionTypeTestCase
{
    use FormTesterTrait;

    #[Override]
    protected function getTestedQuestionType(): QuestionTypeInterface&ConfigurableItemInterface
    {
        return new HostnameQuestion();
    }

    #[Override]
    protected function validateEditorRenderingWhenEnabled(
        Crawler $html
    ): void {
        $input = $html->filter('input[placeholder="hostname"]');
        $this->assertNotEmpty($input);
    }

    #[Override]
    protected function beforeHelpdeskRender(): void
    {
        $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenEnabled(
        Crawler $html
    ): void {
        $input = $html->filter('input[value="localhost"]');
        $this->assertNotEmpty($input);
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenDisabled(
        Crawler $html
    ): void {
        $input = $html->filter('input[value="localhost"]');
        $this->assertEmpty($input);
    }
}
