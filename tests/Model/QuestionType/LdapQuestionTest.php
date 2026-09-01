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
use GlpiPlugin\Advancedforms\Model\QuestionType\LdapQuestion;
use GlpiPlugin\Advancedforms\Tests\QuestionType\QuestionTypeTestCase;
use Override;
use Symfony\Component\DomCrawler\Crawler;

final class LdapQuestionTest extends QuestionTypeTestCase
{
    use FormTesterTrait;

    #[Override]
    protected function getTestedQuestionType(): QuestionTypeInterface&ConfigurableItemInterface
    {
        return new LdapQuestion();
    }

    /**
     * Dropdown::show(), which renders this question's field, defaults an
     * unselected value to "0" instead of an empty string. Without a guard,
     * that sentinel gets saved and displayed as if it were a real answer.
     */
    public function testPrepareEndUserAnswerFiltersEmptySelectionSentinel(): void
    {
        $type = new LdapQuestion();

        $this->enableConfigurableItem($type);
        $builder = new FormBuilder("My form");
        $builder->addQuestion(
            "My question",
            LdapQuestion::class,
            extra_data: json_encode(['authldap_id' => 1]),
        );

        $form = $this->createForm($builder);
        $questions_id = $this->getQuestionId($form, "My question");
        $question = new Question();
        $this->assertTrue($question->getFromDB($questions_id));

        $this->assertSame('', $type->prepareEndUserAnswer($question, '0'));
        $this->assertSame('', $type->prepareEndUserAnswer($question, 0));
        $this->assertSame('jdoe@example.com', $type->prepareEndUserAnswer($question, 'jdoe@example.com'));
    }

    #[Override]
    protected function validateEditorRenderingWhenEnabled(
        Crawler $html,
    ): void {
        $ldap_dropdown  = $html->filter('select[name="extra_data[authldap_id]"]');
        $filter_input   = $html->filter('input[name="extra_data[ldap_filter]"]');
        $field_dropdown = $html->filter('select[name="extra_data[ldap_attribute_id]"]');
        $this->assertNotEmpty($ldap_dropdown);
        $this->assertNotEmpty($filter_input);
        $this->assertNotEmpty($field_dropdown);
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenEnabled(
        Crawler $html,
    ): void {
        $dropdown = $html->filter('[data-glpi-form-renderer-id] select');
        $this->assertNotEmpty($dropdown);
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenDisabled(
        Crawler $html,
    ): void {
        $dropdown = $html->filter('[data-glpi-form-renderer-id] select');
        $this->assertEmpty($dropdown);
    }
}
