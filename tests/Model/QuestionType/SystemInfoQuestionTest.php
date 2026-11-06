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
use GlpiPlugin\Advancedforms\Model\QuestionType\SystemInfoQuestion;
use GlpiPlugin\Advancedforms\Service\LatestVersionProvider;
use GlpiPlugin\Advancedforms\Service\SystemInfoParser;
use GlpiPlugin\Advancedforms\Tests\QuestionType\QuestionTypeTestCase;
use GlpiPlugin\Advancedforms\Tests\SystemInfoSamples;
use Override;
use Symfony\Component\DomCrawler\Crawler;

use function Safe\json_encode;

final class SystemInfoQuestionTest extends QuestionTypeTestCase
{
    public function setUp(): void
    {
        global $GLPI_CACHE;

        parent::setUp();

        // No known release: latest versions resolve to "unknown" without any network call
        $GLPI_CACHE->set(LatestVersionProvider::RELEASES_CACHE_KEY, []);
    }

    public function tearDown(): void
    {
        global $GLPI_CACHE;

        $GLPI_CACHE->delete(LatestVersionProvider::RELEASES_CACHE_KEY);

        parent::tearDown();
    }

    #[Override]
    protected function getTestedQuestionType(): QuestionTypeInterface&ConfigurableItemInterface
    {
        return new SystemInfoQuestion();
    }

    #[Override]
    protected function validateEditorRenderingWhenEnabled(Crawler $html): void
    {
        $this->assertStringContainsString(
            'Copy system information',
            $html->filter('textarea[disabled]')->text(),
        );
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenEnabled(Crawler $html): void
    {
        $this->assertCount(1, $html->filter('textarea[data-af-sysinfo-input]'));
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenDisabled(Crawler $html): void
    {
        $this->assertCount(0, $html->filter('textarea[data-af-sysinfo-input]'));
    }

    public function testValidateAnswerRejectsUnrecognizedText(): void
    {
        $question = $this->createSystemInfoQuestion();

        $result = (new SystemInfoQuestion())->validateAnswer($question, 'I am on the latest version');

        $this->assertFalse($result->isValid());
        $this->assertSame(
            SystemInfoQuestion::getUnrecognizedTextMessage(),
            current($result->getErrors())['message'],
        );
    }

    public function testValidateAnswerRejectsOversizedText(): void
    {
        $question = $this->createSystemInfoQuestion();
        $answer = SystemInfoSamples::PLAIN . str_repeat('a', SystemInfoParser::MAX_LENGTH);

        $result = (new SystemInfoQuestion())->validateAnswer($question, $answer);

        $this->assertFalse($result->isValid());
        $this->assertSame('The pasted text is too long.', current($result->getErrors())['message']);
    }

    public function testValidateAnswerAcceptsSystemInformation(): void
    {
        $question = $this->createSystemInfoQuestion();

        $result = (new SystemInfoQuestion())->validateAnswer($question, SystemInfoSamples::MODERN);

        $this->assertTrue($result->isValid());
    }

    public function testFormatRawAnswerEscapesPluginName(): void
    {
        $question = $this->createSystemInfoQuestion();
        $parsed = SystemInfoParser::parse(SystemInfoSamples::MODERN);
        $this->assertNotNull($parsed);
        $parsed['plugins'][0]['name'] = '<script>alert(1)</script>';
        $answer = ['raw' => '<img src=x onerror=alert(1)>', 'data' => $parsed];

        $html = (new SystemInfoQuestion())->formatRawAnswer(json_encode($answer), $question);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testTicketContainsVersionTable(): void
    {
        $this->login();
        $this->enableConfigurableItem(new SystemInfoQuestion());
        $builder = new FormBuilder('System information form');
        $builder->addQuestion('System information', SystemInfoQuestion::class);

        $form = $this->createForm($builder);

        $ticket = $this->sendFormAndGetCreatedTicket($form, [
            'System information' => SystemInfoSamples::MODERN,
        ]);

        $content = $ticket->fields['content'];
        $this->assertStringContainsString('11.0.12', $content);
        $this->assertStringContainsString('Data injection (datainjection)', $content);
        $this->assertStringContainsString('MariaDB Server 10.11.6-MariaDB-0+deb12u1', $content);
        $this->assertStringContainsString('<details>', $content);
    }

    private function createSystemInfoQuestion(): Question
    {
        $this->enableConfigurableItem(new SystemInfoQuestion());
        $builder = new FormBuilder('System information form');
        $builder->addQuestion('System information', SystemInfoQuestion::class);

        $form = $this->createForm($builder);

        return Question::getById($this->getQuestionId($form, 'System information'));
    }
}
