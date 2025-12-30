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

use Glpi\Form\Destination\CommonITILField\SLMField;
use Glpi\Form\Destination\CommonITILField\SLMFieldStrategyInterface;
use Glpi\Form\QuestionType\QuestionTypeDateTime;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\Destination\Strategies\ComputedDateFromFormSubmitDateSLMStrategy;
use PHPUnit\Framework\Attributes\DataProvider;

final class ComputedDateFromFormSubmitDateSLMStrategyTest extends AbstractSLMStrategyTestCase
{
    protected function getTestedSLMStrategy(): SLMFieldStrategyInterface&ConfigurableItemInterface
    {
        return new ComputedDateFromFormSubmitDateSLMStrategy();
    }

    #[DataProvider('provideSLMConfigFields')]
    public function testComputedDateFromFormSubmitDateSLMStrategy(
        SLMField $slm_field,
        string $slm_field_config_class,
    ): void {
        $this->enableConfigurableItem($this->getTestedSLMStrategy());

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
                    ComputedDateFromFormSubmitDateSLMStrategy::EXTRA_KEY_TIME_OFFSET     => '2',
                    ComputedDateFromFormSubmitDateSLMStrategy::EXTRA_KEY_TIME_DEFINITION => 'day',
                ],
            ),
            expected_time: '2025-12-31 10:00:00',
        );
    }
}
