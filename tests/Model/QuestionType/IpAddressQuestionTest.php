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

use Config;
use Glpi\Controller\Form\RendererController;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Tests\FormBuilder;
use Glpi\Tests\FormTesterTrait;
use GlpiPlugin\Advancedforms\Model\QuestionType\IpAddressQuestion;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use GlpiPlugin\Advancedforms\Service\InitManager;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class IpAddressQuestionTest extends AdvancedFormsTestCase
{
    use FormTesterTrait;

    public function testIpAddressIsAvailableInTypeDropdownWhenEnabled(): void
    {
        // Arrange: enable ip address type and create a form
        $this->enableIpQuestionType();
        $builder = new FormBuilder("My form");
        $builder->addQuestion("My question", QuestionTypeShortText::class);
        $form = $this->createForm($builder);

        // Act: render form editor
        $html = $this->renderFormEditor($form);

        // Assert: make sure the IP type is found
        $options = $html
            ->filter('select[name=_type_category]')
            ->eq(0)
            ->filter('option')
            ->each(fn(Crawler $node) => $node->text())
        ;
        $this->assertContains("Ip address", $options);
    }

    public function testIpAddressIsNotAvailableInTypeDropdownWhenDisabled(): void
    {
        // Arrange: create a form
        $builder = new FormBuilder("My form");
        $builder->addQuestion("My question", QuestionTypeShortText::class);
        $form = $this->createForm($builder);

        // Act: render form editor
        $html = $this->renderFormEditor($form);

        // Assert: make sure the IP type is not found
        $options = $html
            ->filter('select[name=_type_category]')
            ->eq(0)
            ->filter('option')
            ->each(fn(Crawler $node) => $node->text())
        ;
        $this->assertNotContains("Ip address", $options);
    }

    public function testEditorRenderingWhenEnabled(): void
    {
        // Arrange: enable ip address type and create a form using it
        $this->enableIpQuestionType();
        $builder = new FormBuilder("My form");
        $builder->addQuestion("My question", IpAddressQuestion::class);
        $form = $this->createForm($builder);

        // Act: render form editor
        $html = $this->renderFormEditor($form);

        // Assert: item was rendered
        $input = $html->filter('input[placeholder="123.123.123.123"]');
        $this->assertNotEmpty($input);
    }

    public function testEditorRenderingWhenDisabled(): void
    {
        // Arrange: enable ip address type and create a form using it
        $builder = new FormBuilder("My form");
        $builder->addQuestion("My question", IpAddressQuestion::class);
        $form = $this->createForm($builder);

        // Act: render form editor
        $html = $this->renderFormEditor($form);

        // Assert: the question was mentionned in a warning message
        $warning = $html
            ->filter('[data-glpi-form-editor]')
            ->filter('.alert')
            ->eq(0)
            ->filter('ul')
            ->text()
        ;
        $this->assertEquals("My question", $warning);
    }

    public function testHelpdeskRenderingWhenEnabled(): void
    {
        // Arrange: enable ip address type and create a form using it
        $this->enableIpQuestionType();
        $builder = new FormBuilder("My form");
        $builder->addQuestion("My question", IpAddressQuestion::class);
        $form = $this->createForm($builder);

        // Act: render form for end users
        $_SERVER["REMOTE_ADDR"] = "123.0.0.4";
        $html = $this->renderHelpdeskForm($form);

        // Assert: the correct input was set
        $input = $html->filter('input[value="123.0.0.4"]');
        $this->assertNotEmpty($input);
    }

    public function testHelpdeskRenderingWhenDisabled(): void
    {
        // Arrange: enable ip address type and create a form using it
        $builder = new FormBuilder("My form");
        $builder->addQuestion("My question", IpAddressQuestion::class);
        $form = $this->createForm($builder);

        // Act: render form for end users
        $_SERVER["REMOTE_ADDR"] = "123.0.0.4";
        $html = $this->renderHelpdeskForm($form);

        // Assert: input should not exist
        $input = $html->filter('input[value="123.0.0.4"]');
        $this->assertEmpty($input);
    }

    private function enableIpQuestionType(): void
    {
        Config::setConfigurationValues('advancedforms', [
            ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_IP => 1,
        ]);
        InitManager::getInstance()->init();
    }

    private function renderFormEditor(Form $form): Crawler
    {
        $this->login();
        ob_start();
        (new Form())->showForm($form->getId());
        return new Crawler(ob_get_clean());
    }

    private function renderHelpdeskForm(Form $form): Crawler
    {
        $this->login();
        $controller = new RendererController();
        $response = $controller->__invoke(
            Request::create(
                '',
                'GET',
                [
                    'id' => $form->getID(),
                ],
            ),
        );
        return new Crawler($response->getContent());
    }
}
