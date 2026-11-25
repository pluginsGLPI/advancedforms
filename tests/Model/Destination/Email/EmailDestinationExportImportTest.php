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

use Glpi\Form\Destination\CommonITILField\ContentField;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Destination\CommonITILField\TitleField;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Export\Context\DatabaseMapper;
use Glpi\Form\Export\Serializer\FormSerializer;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeEmail;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailAddressesField;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailDestination;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailRecipientsField;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailRecipientsFieldConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Group;
use Session;
use User;

use function Safe\json_decode;

final class EmailDestinationExportImportTest extends AdvancedFormsTestCase
{
    use EmailDestinationTestTrait;

    public function testExportImportKeepsSpecificActors(): void
    {
        // Arrange
        [$user, $group] = $this->createActors();
        $form = $this->createEmailForm();
        $this->updateEmailDestinationConfig($form, [
            EmailRecipientsField::getKey() => $this->getSpecificActorsInput($user, $group),
        ]);

        // Act
        $imported = $this->exportAndImport($form);

        // Assert
        $config = $this->getEmailConfig($imported)[EmailRecipientsField::getKey()];
        $this->assertSame(
            [User::class => [$user->getID()], Group::class => [$group->getID()]],
            $config['specific_itilactors_ids'],
        );
    }

    public function testExportUsesNamesForActors(): void
    {
        [$user, $group] = $this->createActors();
        $form = $this->createEmailForm();
        $this->updateEmailDestinationConfig($form, [
            EmailRecipientsField::getKey() => $this->getSpecificActorsInput($user, $group),
        ]);

        $json = $this->export($form);

        $data = json_decode($json, true);
        $destination = $this->findEmailDestinationSpec($data);
        $this->assertSame(
            [User::class => ['Exported user'], Group::class => ['Exported group']],
            $destination['config'][EmailRecipientsField::getKey()]['specific_itilactors_ids'],
        );
        $requirements = array_column($data['forms'][0]['data_requirements'], 'name', 'itemtype');
        $this->assertSame('Exported group', $requirements[Group::class]);
        $this->assertSame('Exported user', $requirements[User::class]);
    }

    public function testExportDropsDeletedActors(): void
    {
        [$user, $group] = $this->createActors();
        $form = $this->createEmailForm();
        $this->updateEmailDestinationConfig($form, [
            EmailRecipientsField::getKey() => $this->getSpecificActorsInput($user, $group),
        ]);
        $this->assertTrue($user->delete(['id' => $user->getID()], force: true));

        $data = json_decode($this->export($form), true);

        $destination = $this->findEmailDestinationSpec($data);
        $this->assertSame(
            [User::class => [], Group::class => ['Exported group']],
            $destination['config'][EmailRecipientsField::getKey()]['specific_itilactors_ids'],
        );
    }

    public function testExportImportRemapsQuestionsAndTags(): void
    {
        // Arrange: recipients and tags referencing the form questions
        $form = $this->createEmailForm();
        $this->updateEmailDestinationConfig($form, [
            TitleField::getKey()             => ['value' => 'Hello ' . $this->getAnswerTag($form, 'Name')],
            ContentField::getKey()           => ['value' => '<p>' . $this->getAnswerTag($form, 'Name') . '</p>'],
            ContentField::getAutoConfigKey() => 0,
            EmailRecipientsField::getKey()   => $this->getSpecificAnswersInput($form),
        ]);

        // Act
        $imported = $this->exportAndImport($form);

        // Assert: references point to the imported form questions
        $this->assertReferencesQuestionsOf($imported);
    }

    public function testExportImportKeepsFreeAddressesAndAutoFlag(): void
    {
        $form = $this->createEmailForm();
        $this->updateEmailDestinationConfig($form, [
            EmailAddressesField::getKey()    => ['value' => 'hr@example.com; it@example.com'],
            ContentField::getAutoConfigKey() => 0,
        ]);

        $config = $this->getEmailConfig($this->exportAndImport($form));

        $this->assertSame('hr@example.com; it@example.com', $config[EmailAddressesField::getKey()]['value']);
        $this->assertEquals(0, $config[ContentField::getAutoConfigKey()]);
    }

    public function testCloneRemapsQuestionsAndTags(): void
    {
        $form = $this->createEmailForm();
        $this->updateEmailDestinationConfig($form, [
            TitleField::getKey()             => ['value' => 'Hello ' . $this->getAnswerTag($form, 'Name')],
            ContentField::getKey()           => ['value' => '<p>' . $this->getAnswerTag($form, 'Name') . '</p>'],
            ContentField::getAutoConfigKey() => 0,
            EmailRecipientsField::getKey()   => $this->getSpecificAnswersInput($form),
        ]);

        $clone_id = $form->clone();
        $this->assertIsInt($clone_id);

        $this->assertReferencesQuestionsOf(Form::getById($clone_id));
    }

    public function testImportedFormSendsEmail(): void
    {
        global $CFG_GLPI;

        // Arrange: the core test case restores the config after the test
        $CFG_GLPI['use_notifications']     = 1;
        $CFG_GLPI['notifications_mailing'] = 1;
        $CFG_GLPI['from_email']            = 'forms@example.com';
        $this->enableConfigurableItem(new EmailDestination());

        [$user, $group] = $this->createActors();
        $form = $this->createEmailForm();
        $this->updateEmailDestinationConfig($form, [
            EmailRecipientsField::getKey() => $this->getSpecificActorsInput($user, $group),
            EmailAddressesField::getKey()  => ['value' => 'hr@example.com'],
        ]);
        $imported = $this->exportAndImport($form);

        // Act
        $answers_set = $this->sendFormAndGetAnswerSet($imported, ['Name' => 'Jean']);

        // Assert
        $this->assertSame(
            ['user@example.com', 'member@example.com', 'hr@example.com'],
            array_column($this->getQueuedEmails($answers_set), 'recipient'),
        );
    }

    private function assertReferencesQuestionsOf(Form $form): void
    {
        $name_id = $this->getQuestionId($form, 'Name');
        $email_id = $this->getQuestionId($form, 'Email');
        $config = $this->getEmailConfig($form);

        $this->assertSame(
            [$email_id],
            array_map(intval(...), $config[EmailRecipientsField::getKey()]['specific_question_ids']),
        );
        $this->assertStringContainsString(
            'data-form-tag-value="' . $name_id . '"',
            $config[TitleField::getKey()]['value'],
        );
        $this->assertStringContainsString(
            'data-form-tag-value="' . $name_id . '"',
            $config[ContentField::getKey()]['value'],
        );
    }

    /** @return array{User, Group} */
    private function createActors(): array
    {
        $user = $this->createUserWithEmail('Exported user', 'user@example.com');
        $group = $this->createItem(Group::class, [
            'name'        => 'Exported group',
            'entities_id' => $this->getTestRootEntity(only_id: true),
        ]);
        $this->addUserToGroup($group, $this->createUserWithEmail('Exported member', 'member@example.com'));

        return [$user, $group];
    }

    private function createEmailForm(): Form
    {
        $this->login();
        $builder = new FormBuilder("Exported email form");
        $builder->setShouldInitDestinations(false);
        $builder->addQuestion("Name", QuestionTypeShortText::class);
        $builder->addQuestion("Email", QuestionTypeEmail::class);
        $builder->addDestination(EmailDestination::class, 'Email');

        return $this->createForm($builder);
    }

    /** @return array<string, mixed> */
    private function getEmailConfig(Form $form): array
    {
        return FormDestination::getById($this->getDestinationId($form, 'Email'))->getConfig();
    }

    /** @return array<string, mixed> */
    private function getSpecificAnswersInput(Form $form): array
    {
        return (new EmailRecipientsFieldConfig(
            strategies: [ITILActorFieldStrategy::SPECIFIC_ANSWERS],
            specific_question_ids: [$this->getQuestionId($form, 'Email')],
        ))->jsonSerialize();
    }

    private function export(Form $form): string
    {
        return (new FormSerializer())->exportFormsToJson([$form])->getJsonContent();
    }

    private function exportAndImport(Form $form): Form
    {
        // Same mapper as the core FormTesterTrait::exportAndImportForm()
        $active_entities = Session::getActiveEntities();
        $mapper = new DatabaseMapper(
            $active_entities !== [] ? $active_entities : [$this->getTestRootEntity(only_id: true)],
        );
        $result = (new FormSerializer())->importFormsFromJson($this->export($form), $mapper);
        $forms = $result->getImportedForms();
        $this->assertCount(1, $forms, print_r($result->getFailedFormImports(), true));

        $imported = current($forms);
        $this->assertNotSame($form->getID(), $imported->getID());

        return $imported;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function findEmailDestinationSpec(array $data): array
    {
        foreach ($data['forms'][0]['destinations'] as $destination) {
            if ($destination['itemtype'] === EmailDestination::class) {
                return $destination;
            }
        }

        $this->fail('Email destination not found in export');
    }
}
