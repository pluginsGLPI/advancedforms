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

namespace GlpiPlugin\Advancedforms\Tests\Model\Destination\Strategies;

use Glpi\Controller\Form\Destination\GetDestinationFormController;
use Glpi\Form\AnswersHandler\AnswersHandler;
use Glpi\Form\Destination\CommonITILField\OLATTOField;
use Glpi\Form\Destination\CommonITILField\OLATTOFieldConfig;
use Glpi\Form\Destination\CommonITILField\OLATTRField;
use Glpi\Form\Destination\CommonITILField\OLATTRFieldConfig;
use Glpi\Form\Destination\CommonITILField\SLATTOField;
use Glpi\Form\Destination\CommonITILField\SLATTOFieldConfig;
use Glpi\Form\Destination\CommonITILField\SLATTRField;
use Glpi\Form\Destination\CommonITILField\SLATTRFieldConfig;
use Glpi\Form\Destination\CommonITILField\SLMField;
use Glpi\Form\Destination\CommonITILField\SLMFieldConfig;
use Glpi\Form\Destination\CommonITILField\SLMFieldStrategyInterface;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeDateTime;
use Glpi\Form\QuestionType\QuestionTypeDateTimeExtraDataConfig;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\Destination\Strategies\SpecificDateAnswerSLMStrategy;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Ticket;

final class SpecificDateAnswerSLMStrategyTest extends AdvancedFormsTestCase
{
    protected function getTestedSLMStrategy(): SLMFieldStrategyInterface&ConfigurableItemInterface
    {
        return new SpecificDateAnswerSLMStrategy();
    }

    public static function provideSLMConfigFields(): iterable
    {
        yield 'SLATTOField' => ['slm_field' => new SLATTOField(), 'slm_field_config_class' => SLATTOFieldConfig::class];
        yield 'SLATTRField' => ['slm_field' => new SLATTRField(), 'slm_field_config_class' => SLATTRFieldConfig::class];
        yield 'OLATTOField' => ['slm_field' => new OLATTOField(), 'slm_field_config_class' => OLATTOFieldConfig::class];
        yield 'OLATTRField' => ['slm_field' => new OLATTRField(), 'slm_field_config_class' => OLATTRFieldConfig::class];
    }

    #[DataProvider('provideSLMConfigFields')]
    public function testSLMDestinationStrategyIsAvailableInStrategiesDropdownWhenEnabled(
        SLMField $slm_field,
        string $slm_field_config_class,
    ): void {
        // Arrange: enable strategy and create a form
        $this->enableConfigurableItem($this->getTestedSLMStrategy());
        $form = $this->createAndGetFormWithTicketDestination();

        // Act: render form destination
        $html = $this->renderFormDestination($form);

        // Assert: make sure the strategy is found
        $options = $html
            ->filter(sprintf(
                'select[name="config[%s][%s][]"]',
                $slm_field->getKey(),
                SLMFieldConfig::getStrategiesInputName(),
            ))
            ->eq(0)
            ->filter('option')
            ->each(fn(Crawler $node) => $node->text())
        ;
        $this->assertContains($this->getTestedSLMStrategy()->getLabel($slm_field), $options);
    }

    #[DataProvider('provideSLMConfigFields')]
    public function testSLMDestinationStrategyIsAvailableInStrategiesDropdownWhenDisabled(
        SLMField $slm_field,
        string $slm_field_config_class,
    ): void {
        // Arrange: disable strategy and create a form
        $this->disableConfigurableItem($this->getTestedSLMStrategy());
        $form = $this->createAndGetFormWithTicketDestination();

        // Act: render form destination
        $html = $this->renderFormDestination($form);

        // Assert: make sure the strategy is not found
        $options = $html
            ->filter(sprintf(
                'select[name="config[%s][%s][]"]',
                $slm_field->getKey(),
                SLMFieldConfig::getStrategiesInputName(),
            ))
            ->eq(0)
            ->filter('option')
            ->each(fn(Crawler $node) => $node->text())
        ;
        $this->assertNotContains($this->getTestedSLMStrategy()->getLabel($slm_field), $options);
    }

    #[DataProvider('provideSLMConfigFields')]
    public function testSpecificDateAnswerStrategy(
        SLMField $slm_field,
        string $slm_field_config_class,
    ): void {
        // Arrange: enable strategy
        $this->enableConfigurableItem($this->getTestedSLMStrategy());

        // Arrange: login as normal user and create form
        $this->login('normal');
        $form = $this->createAndGetFormWithTicketDestination();
        $question_id = current($form->getQuestionsByType(QuestionTypeDateTime::class))->getID();

        $this->checkSLMFieldConfiguration(
            slm_field: $slm_field,
            form: $form,
            formatted_answers: [$question_id => '2025-12-31 15:30:00'],
            config: new $slm_field_config_class(
                strategy: $this->getTestedSLMStrategy()->getKey(),
                extra_data: [
                    SpecificDateAnswerSLMStrategy::EXTRA_KEY_QUESTION_ID => $question_id,
                ],
            ),
            expected_time: '2025-12-31 15:30:00',
        );
    }

    private function checkSLMFieldConfiguration(
        SLMField $slm_field,
        Form $form,
        array $formatted_answers,
        SLMFieldConfig $config,
        string $expected_time,
    ): Ticket {
        // Insert config
        $destinations = $form->getDestinations();
        $this->assertCount(1, $destinations);
        $destination = current($destinations);
        $this->updateItem(
            $destination::getType(),
            $destination->getId(),
            ['config' => [$slm_field::getKey() => $config->jsonSerialize()]],
            ["config"],
        );

        // Submit form
        $answers_handler = AnswersHandler::getInstance();
        $answers = $answers_handler->saveAnswers(
            $form,
            $formatted_answers,
            getItemByTypeName(\User::class, TU_USER, true),
        );

        // Get created ticket
        $created_items = $answers->getCreatedItems();
        $this->assertCount(1, $created_items);
        $ticket = current($created_items);

        // Get field name
        $time_field_name = $slm_field->getSLM()::getFieldNames($slm_field->getType())[0];

        // Check time field
        $this->assertEquals($expected_time, $ticket->fields[$time_field_name]);

        // Return the created ticket to be able to check other fields
        return $ticket;
    }

    private function createAndGetFormWithTicketDestination(): Form
    {
        $builder = new FormBuilder();
        $builder->addQuestion(
            name: "Date question",
            type: QuestionTypeDateTime::class,
            default_value: '2025-12-31 15:30:00',
            extra_data: json_encode(
                new QuestionTypeDateTimeExtraDataConfig(
                    is_date_enabled: true,
                    is_time_enabled: true,
                ),
            ),
        );
        return $this->createForm($builder);
    }

    private function renderFormDestination(Form $form): Crawler
    {
        $this->login();
        $controller = new GetDestinationFormController();
        return new Crawler($controller->__invoke(
            Request::create(''),
            $form->getID(),
            current($form->getDestinations())->getID(),
        )->getContent());
    }
}
