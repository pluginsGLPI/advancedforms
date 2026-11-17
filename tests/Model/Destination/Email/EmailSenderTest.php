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

use Glpi\Form\Condition\CreationStrategy;
use Glpi\Form\Condition\LogicOperator;
use Glpi\Form\Condition\Type;
use Glpi\Form\Condition\ValueOperator;
use Glpi\Form\Destination\CommonITILField\ContentField;
use Glpi\Form\Destination\CommonITILField\TitleField;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeLongText;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailAddressesField;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailDestination;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailRecipientsField;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Psr\Log\LogLevel;
use Ticket;

use function Safe\json_encode;

final class EmailSenderTest extends AdvancedFormsTestCase
{
    use EmailDestinationTestTrait;

    public function setUp(): void
    {
        global $CFG_GLPI;

        // The core test case restores the config after each test
        parent::setUp();

        $CFG_GLPI['use_notifications']     = 1;
        $CFG_GLPI['notifications_mailing'] = 1;
        $CFG_GLPI['from_email']            = 'forms@example.com';
        $CFG_GLPI['from_email_name']       = 'GLPI forms';
        $CFG_GLPI['mailing_signature']     = 'SIG-XYZ';

        $this->login();
        $this->enableConfigurableItem(new EmailDestination());
    }

    public function testOneQueuedEmailPerRecipient(): void
    {
        // Arrange
        $user = $this->createUserWithEmail('Payroll', 'payroll@example.com');
        $other = $this->createUserWithEmail('Manager', 'manager@example.com');
        $form = $this->createFormWithEmailDestination([
            EmailRecipientsField::getKey() => $this->getSpecificActorsInput($user, $other),
            EmailAddressesField::getKey()  => ['value' => 'accounting@example.com'],
        ]);

        // Act
        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        // Assert
        $queued = $this->getQueuedEmails($answers_set);
        $this->assertSame(
            ['payroll@example.com', 'manager@example.com', 'accounting@example.com'],
            array_column($queued, 'recipient'),
        );
        foreach ($queued as $row) {
            $this->assertSame('mailing', $row['mode']);
            $this->assertSame(0, (int) $row['notificationtemplates_id']);
            $this->assertSame((int) $form->fields['entities_id'], (int) $row['entities_id']);
            $this->assertSame('forms@example.com', $row['sender']);
            $this->assertSame('GLPI forms', $row['sendername']);
        }
    }

    public function testSubjectWithTags(): void
    {
        // Arrange: a subject made of answer tags
        $form = $this->createFormWithEmailDestination();
        $this->updateEmailDestinationConfig($form, [
            TitleField::getKey() => [
                'value' => 'ARRIVEE : ' . $this->getAnswerTag($form, 'First name')
                    . ' ' . $this->getAnswerTag($form, 'Last name'),
            ],
        ]);

        // Act
        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        // Assert
        $queued = $this->getQueuedEmails($answers_set);
        $this->assertCount(1, $queued);
        $this->assertSame('ARRIVEE : Jean Dupont', current($queued)['name']);
    }

    public function testBodyIsAutoGeneratedByDefault(): void
    {
        $form = $this->createFormWithEmailDestination();

        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        $row = current($this->getQueuedEmails($answers_set));
        $this->assertStringContainsString('First name', $row['body_html']);
        $this->assertStringContainsString('Jean', $row['body_html']);
        $this->assertStringContainsString('Jean', $row['body_text']);
        $this->assertStringNotContainsString('<', $row['body_text']);
    }

    public function testCustomBodyWithTags(): void
    {
        $form = $this->createFormWithEmailDestination();
        $this->updateEmailDestinationConfig($form, [
            ContentField::getKey()            => ['value' => '<p>Bonjour ' . $this->getAnswerTag($form, 'Last name') . '</p>'],
            ContentField::getAutoConfigKey()  => 0,
        ]);

        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        $row = current($this->getQueuedEmails($answers_set));
        $this->assertStringContainsString('Bonjour Dupont', $row['body_html']);
        $this->assertStringNotContainsString('First name', $row['body_html']);
    }

    public function testSubjectAndBodySentAsConfigured(): void
    {
        $form = $this->createFormWithEmailDestination();
        $this->updateEmailDestinationConfig($form, [
            TitleField::getKey()             => ['value' => 'Exact subject'],
            ContentField::getKey()           => ['value' => '<p>Exact body</p>'],
            ContentField::getAutoConfigKey() => 0,
        ]);

        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        $row = current($this->getQueuedEmails($answers_set));
        $this->assertSame('Exact subject', $row['name']);
        $this->assertStringContainsString('Exact body', $row['body_html']);
        $this->assertStringNotContainsString('SIG-XYZ', $row['body_html']);
        $this->assertStringNotContainsString('SIG-XYZ', $row['body_text']);
    }

    public function testAnswerHtmlIsSanitized(): void
    {
        // Arrange: a rich text answer, which is not escaped by its question type
        $form = $this->createFormWithEmailDestination();
        $this->updateEmailDestinationConfig($form, [
            ContentField::getKey()           => ['value' => '<p>' . $this->getAnswerTag($form, 'Comment') . '</p>'],
            ContentField::getAutoConfigKey() => 0,
        ]);

        // Act
        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers() + [
            'Comment' => '<p>Hi</p><script>alert(1)</script><img src="x" onerror="alert(1)">',
        ]);

        // Assert
        $row = current($this->getQueuedEmails($answers_set));
        $this->assertStringContainsString('Hi', $row['body_html']);
        $this->assertStringNotContainsString('<script', $row['body_html']);
        $this->assertStringNotContainsString('onerror', $row['body_html']);
    }

    public function testDeletedQuestionTagIsEmpty(): void
    {
        // Arrange: a subject referencing a question that is then deleted
        $form = $this->createFormWithEmailDestination();
        $this->updateEmailDestinationConfig($form, [
            TitleField::getKey() => ['value' => 'Hello ' . $this->getAnswerTag($form, 'Send')],
        ]);
        $question = Question::getById($this->getQuestionId($form, 'Send'));
        $this->assertTrue($question->delete(['id' => $question->getID()]));

        // Act
        $answers_set = $this->sendFormAndGetAnswerSet($form, [
            'First name' => 'Jean',
            'Last name'  => 'Dupont',
        ]);

        // Assert: the email is still sent, without the tag
        $row = current($this->getQueuedEmails($answers_set));
        $this->assertSame('Hello', trim($row['name']));
    }

    public function testDuplicateAddressesAcrossSourcesAreMerged(): void
    {
        $user = $this->createUserWithEmail('Payroll', 'payroll@example.com');
        $form = $this->createFormWithEmailDestination([
            EmailRecipientsField::getKey() => $this->getSpecificActorsInput($user),
            EmailAddressesField::getKey()  => ['value' => 'PAYROLL@example.com'],
        ]);

        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        $this->assertSame(
            ['payroll@example.com'],
            array_column($this->getQueuedEmails($answers_set), 'recipient'),
        );
    }

    public function testNothingQueuedWhenFeatureDisabled(): void
    {
        $form = $this->createFormWithEmailDestination();
        $this->disableConfigurableItem(new EmailDestination());

        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        $this->assertCount(0, $this->getQueuedEmails($answers_set));
    }

    public function testNothingQueuedWhenNotificationsDisabled(): void
    {
        global $CFG_GLPI;

        $form = $this->createFormWithEmailDestination();
        $CFG_GLPI['notifications_mailing'] = 0;

        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        $this->assertCount(0, $this->getQueuedEmails($answers_set));
    }

    public function testNothingQueuedWithoutRecipients(): void
    {
        $form = $this->createFormWithEmailDestination([
            EmailAddressesField::getKey() => ['value' => ''],
        ]);

        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        $this->assertGreaterThan(0, $answers_set->getID());
        $this->assertCount(0, $this->getQueuedEmails($answers_set));
    }

    public function testNothingQueuedWithoutSender(): void
    {
        $form = $this->createFormWithEmailDestination();
        $this->removeEmailSender();

        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        $this->assertGreaterThan(0, $answers_set->getID());
        $this->assertCount(0, $this->getQueuedEmails($answers_set));
        $this->hasPhpLogRecordThatContains(
            'No email address is defined in configuration.',
            LogLevel::WARNING,
        );
    }

    public function testNoCreatedItemIsLinked(): void
    {
        $form = $this->createFormWithEmailDestination();

        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        $this->assertCount(1, $this->getQueuedEmails($answers_set));
        $this->assertSame([], $answers_set->getCreatedItems());
    }

    public function testCreatedIfCondition(): void
    {
        $form = $this->createFormWithEmailDestination(strategy: CreationStrategy::CREATED_IF);

        $sent = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers() + ['Send' => 'Yes']);
        $not_sent = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers() + ['Send' => 'No']);

        $this->assertCount(1, $this->getQueuedEmails($sent));
        $this->assertCount(0, $this->getQueuedEmails($not_sent));
    }

    public function testCreatedUnlessCondition(): void
    {
        $form = $this->createFormWithEmailDestination(strategy: CreationStrategy::CREATED_UNLESS);

        $not_sent = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers() + ['Send' => 'Yes']);
        $sent = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers() + ['Send' => 'No']);

        $this->assertCount(1, $this->getQueuedEmails($sent));
        $this->assertCount(0, $this->getQueuedEmails($not_sent));
    }

    public function testOtherDestinationStillCreatedWhenEmailFails(): void
    {
        // Arrange: a form with a ticket and an email that can't be sent
        $form = $this->createFormWithEmailDestination(with_ticket: true);
        $this->removeEmailSender();

        // Act
        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        // Assert: the ticket is created anyway
        $created_items = $answers_set->getCreatedItems();
        $this->assertCount(1, $created_items);
        $this->assertInstanceOf(Ticket::class, current($created_items));
        $this->assertCount(0, $this->getQueuedEmails($answers_set));

        // Once for the email destination, once for the ticket notifications
        for ($i = 0; $i < 2; $i++) {
            $this->hasPhpLogRecordThatContains(
                'No email address is defined in configuration.',
                LogLevel::WARNING,
            );
        }
    }

    public function testUnexpectedErrorDoesNotCancelSubmission(): void
    {
        global $DB;

        // Arrange: a stored config the fields can't read (hand-edited import, API, ...)
        $form = $this->createFormWithEmailDestination(with_ticket: true);
        $DB->update(FormDestination::getTable(), [
            'config' => json_encode([EmailAddressesField::getKey() => []]),
        ], ['id' => $this->getDestinationId($form, 'Email')]);

        // Act
        $answers_set = $this->sendFormAndGetAnswerSet($form, $this->getDefaultAnswers());

        // Assert: the answers and the ticket are kept, the error is logged
        $this->assertGreaterThan(0, $answers_set->getID());
        $created_items = $answers_set->getCreatedItems();
        $this->assertCount(1, $created_items);
        $this->assertInstanceOf(Ticket::class, current($created_items));
        $this->assertCount(0, $this->getQueuedEmails($answers_set));
        $this->hasPhpLogRecordThatContains('Undefined array key "value"', LogLevel::WARNING);
        $this->hasPhpLogRecordThatContains('Caught TypeError', LogLevel::ERROR);
    }

    /** @param ?array<string, mixed> $config */
    private function createFormWithEmailDestination(
        ?array $config = null,
        ?CreationStrategy $strategy = null,
        bool $with_ticket = false,
    ): Form {
        $config ??= [EmailAddressesField::getKey() => ['value' => 'hr@example.com']];

        $builder = new FormBuilder("Arrival form");
        $builder->setShouldInitDestinations(false);
        $builder->addQuestion("First name", QuestionTypeShortText::class);
        $builder->addQuestion("Last name", QuestionTypeShortText::class);
        $builder->addQuestion("Send", QuestionTypeShortText::class);
        $builder->addQuestion("Comment", QuestionTypeLongText::class);
        $builder->addDestination(EmailDestination::class, 'Email', $config);
        if ($with_ticket) {
            $builder->addDestination(FormDestinationTicket::class, 'Ticket');
        }

        if ($strategy instanceof CreationStrategy) {
            $builder->setDestinationCondition('Email', $strategy, [
                [
                    'logic_operator' => LogicOperator::AND,
                    'item_name'      => "Send",
                    'item_type'      => Type::QUESTION,
                    'value_operator' => ValueOperator::EQUALS,
                    'value'          => "Yes",
                ],
            ]);
        }

        return $this->createForm($builder);
    }

    /** @return array<string, string> */
    private function getDefaultAnswers(): array
    {
        return [
            'First name' => 'Jean',
            'Last name'  => 'Dupont',
        ];
    }

    private function removeEmailSender(): void
    {
        global $CFG_GLPI;

        $CFG_GLPI['from_email']  = '';
        $CFG_GLPI['admin_email'] = '';
    }
}
