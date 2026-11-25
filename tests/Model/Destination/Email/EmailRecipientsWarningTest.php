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

namespace GlpiPlugin\Advancedforms\Tests\Model\Destination\Email;

use Glpi\Form\AccessControl\ControlType\DirectAccess;
use Glpi\Form\AccessControl\ControlType\DirectAccessConfig;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeEmail;
use Glpi\Form\QuestionType\QuestionTypeRequester;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailDestination;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailRecipientsField;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailRecipientsFieldConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;

final class EmailRecipientsWarningTest extends AdvancedFormsTestCase
{
    public function testNoWarningWithoutEmailAnswers(): void
    {
        $form = $this->createRecipientsForm();

        $this->assertNull($this->getWarning($form, [ITILActorFieldStrategy::SPECIFIC_VALUES]));
        $this->assertNull($this->getWarning(
            $form,
            [ITILActorFieldStrategy::SPECIFIC_ANSWERS],
            [$this->getQuestionId($form, 'Requester')],
        ));
    }

    public function testWarningForSpecificEmailAnswer(): void
    {
        $form = $this->createRecipientsForm();

        $warning = $this->getWarning(
            $form,
            [ITILActorFieldStrategy::SPECIFIC_ANSWERS],
            [$this->getQuestionId($form, 'Email')],
        );

        $this->assertSame(EmailDestination::getTypedRecipientsWarning(), $warning);
    }

    public function testWarningForLastValidAnswer(): void
    {
        $this->assertSame(
            EmailDestination::getTypedRecipientsWarning(),
            $this->getWarning($this->createRecipientsForm(), [ITILActorFieldStrategy::LAST_VALID_ANSWER]),
        );

        // Without email question, the last valid answer is always an actor
        $form = $this->createForm(
            (new FormBuilder("Without email"))->addQuestion("Requester", QuestionTypeRequester::class),
        );
        $this->assertNull($this->getWarning($form, [ITILActorFieldStrategy::LAST_VALID_ANSWER]));
    }

    public function testStrongerWarningForUnauthenticatedAccess(): void
    {
        $form = $this->createRecipientsForm(allow_unauthenticated: true);

        $warning = $this->getWarning(
            $form,
            [ITILActorFieldStrategy::SPECIFIC_ANSWERS],
            [$this->getQuestionId($form, 'Email')],
        );

        $this->assertSame(EmailDestination::getUnauthenticatedRecipientsWarning(), $warning);
    }

    private function createRecipientsForm(bool $allow_unauthenticated = false): Form
    {
        $builder = new FormBuilder("Recipients warning form");
        $builder->addQuestion("Email", QuestionTypeEmail::class);
        $builder->addQuestion("Requester", QuestionTypeRequester::class);
        if ($allow_unauthenticated) {
            $builder->addAccessControl(
                DirectAccess::class,
                new DirectAccessConfig(allow_unauthenticated: true),
            );
        }

        return $this->createForm($builder);
    }

    /**
     * @param ITILActorFieldStrategy[] $strategies
     * @param int[] $specific_question_ids
     */
    private function getWarning(Form $form, array $strategies, array $specific_question_ids = []): ?string
    {
        $recipients_config = new EmailRecipientsFieldConfig(
            strategies: $strategies,
            specific_question_ids: $specific_question_ids,
        );

        return (new EmailDestination())->getRecipientsWarning($form, [
            EmailRecipientsField::getKey() => $recipients_config->jsonSerialize(),
        ]);
    }
}
