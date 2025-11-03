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

namespace GlpiPlugin\Advancedforms\Tests\QuestionType;

use Glpi\Controller\Form\RendererController;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeInterface;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Tests\FormBuilder;
use Glpi\Tests\FormTesterTrait;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

abstract class QuestionTypeTestCase extends AdvancedFormsTestCase
{
    use FormTesterTrait;

    abstract protected function getTestedQuestionType(): QuestionTypeInterface&ConfigurableItemInterface;

    abstract protected function validateEditorRenderingWhenEnabled(
        Crawler $html,
    ): void;

    abstract protected function validateHelpdeskRenderingWhenEnabled(
        Crawler $html,
    ): void;

    abstract protected function validateHelpdeskRenderingWhenDisabled(
        Crawler $html,
    ): void;

    /**
     * Can be overriden if you need to set some specific state before the
     * helpdesk rendering process is called.
     */
    protected function beforeHelpdeskRender(): void {}

    /**
     * Can be overriden if you need a specific default value for the question
     * used as a test subject.
     */
    protected function setDefaultValueBeforeHelpdeskRendering(): mixed
    {
        return '';
    }

    final public function testQuestionIsAvailableInTypeDropdownWhenEnabled(): void
    {
        $item = $this->getTestedQuestionType();

        // Arrange: enable question type and create a form
        $this->enableConfigurableItem($item);

        $builder = new FormBuilder("My form");
        $builder->addQuestion("My question", QuestionTypeShortText::class);

        $form = $this->createForm($builder);

        // Act: render form editor
        $html = $this->renderFormEditor($form);

        // Assert: make sure the question type is found
        $options = $html
            ->filter('select[name=_type_category]')
            ->eq(0)
            ->filter('option')
            ->each(fn(Crawler $node) => $node->text())
        ;
        $this->assertContains($item->getName(), $options);
    }

    final public function testIpAddressIsNotAvailableInTypeDropdownWhenDisabled(): void
    {
        $item = $this->getTestedQuestionType();

        // Arrange: create a form
        $builder = new FormBuilder("My form");
        $builder->addQuestion("My question", QuestionTypeShortText::class);

        $form = $this->createForm($builder);

        // Act: render form editor
        $html = $this->renderFormEditor($form);

        // Assert: make sure the question type is not found
        $options = $html
            ->filter('select[name=_type_category]')
            ->eq(0)
            ->filter('option')
            ->each(fn(Crawler $node) => $node->text())
        ;
        $this->assertNotContains($item->getName(), $options);
    }

    final public function testEditorRenderingWhenEnabled(): void
    {
        $item = $this->getTestedQuestionType();

        // Arrange: enable the question type and create a form using it
        $this->enableConfigurableItem($item);
        $builder = new FormBuilder("My form");
        $builder->addQuestion(
            "My question",
            $item::class,
            extra_data: $this->getDefaultExtraDataForQuestionType($item),
        );
        $form = $this->createForm($builder);

        // Act: render form editor
        $html = $this->renderFormEditor($form);

        // Assert: item was rendered
        $this->validateEditorRenderingWhenEnabled($html);
    }

    final public function testEditorRenderingWhenDisabled(): void
    {
        $item = $this->getTestedQuestionType();

        // Arrange: enable the question type and create a form using it
        $builder = new FormBuilder("My form");
        $builder->addQuestion(
            "My question",
            $item::class,
            extra_data: $this->getDefaultExtraDataForQuestionType($item),
        );
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

    final public function testHelpdeskRenderingWhenEnabled(): void
    {
        $item = $this->getTestedQuestionType();

        // Arrange: enable the question type and create a form using it
        $this->enableConfigurableItem($item);
        $builder = new FormBuilder("My form");
        $builder->addQuestion(
            "My question",
            $item::class,
            $this->setDefaultValueBeforeHelpdeskRendering(),
            $this->getDefaultExtraDataForQuestionType($item),
        );
        $form = $this->createForm($builder);

        // Act: render form for end users
        $html = $this->renderHelpdeskForm($form);

        // Assert: the correct input was set
        $this->validateHelpdeskRenderingWhenEnabled($html);
    }

    final public function testHelpdeskRenderingWhenDisabled(): void
    {
        $item = $this->getTestedQuestionType();

        // Arrange: enable the question type and create a form using it
        $builder = new FormBuilder("My form");
        $builder->addQuestion(
            "My question",
            $item::class,
            $this->setDefaultValueBeforeHelpdeskRendering(),
            $this->getDefaultExtraDataForQuestionType($item),
        );
        $form = $this->createForm($builder);

        // Act: render form for end users
        $html = $this->renderHelpdeskForm($form);

        // Assert: input should not exist
        $this->validateHelpdeskRenderingWhenDisabled($html);
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
        $this->beforeHelpdeskRender();
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

    private function getDefaultExtraDataForQuestionType(
        QuestionTypeInterface $type,
    ): ?string {
        $class = $type->getExtraDataConfigClass();
        if (is_null($class)) {
            return null;
        }

        return json_encode(new $class());
    }
}
