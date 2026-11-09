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

use Computer;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Destination\CommonITILField\SimpleValueConfig;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeActorsExtraDataConfig;
use Glpi\Form\QuestionType\QuestionTypeAssignee;
use Glpi\Form\QuestionType\QuestionTypeEmail;
use Glpi\Form\QuestionType\QuestionTypeItem;
use Glpi\Form\QuestionType\QuestionTypeItemExtraDataConfig;
use Glpi\Form\QuestionType\QuestionTypeObserver;
use Glpi\Form\QuestionType\QuestionTypeRequester;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailAddressesField;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailRecipientsField;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailRecipientsFieldConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Group;
use Session;
use Supplier;
use User;
use UserEmail;

final class EmailRecipientsResolutionTest extends AdvancedFormsTestCase
{
    use EmailDestinationTestTrait;

    public function testSpecificUser(): void
    {
        $user = $this->createUserWithEmail('Recipient', 'recipient@example.com');

        $emails = $this->resolve(
            [ITILActorFieldStrategy::SPECIFIC_VALUES],
            specific_itilactors_ids: [User::class => [$user->getID()]],
        );

        $this->assertSame(['recipient@example.com'], $emails);
    }

    public function testSpecificGroupMembers(): void
    {
        // Arrange: a group with two active members, an inactive one and one without email
        $group = $this->createItem(Group::class, ['name' => 'Payroll']);
        $members = [
            $this->createUserWithEmail('Member 1', 'member1@example.com'),
            $this->createUserWithEmail('Member 2', 'member2@example.com'),
            $this->createUserWithEmail('Inactive member', 'inactive@example.com', is_active: false),
            $this->createItem(User::class, ['name' => 'Member without email']),
        ];
        foreach ($members as $member) {
            $this->addUserToGroup($group, $member);
        }

        // Act
        $emails = $this->resolve(
            [ITILActorFieldStrategy::SPECIFIC_VALUES],
            specific_itilactors_ids: [Group::class => [$group->getID()]],
        );

        // Assert: only active members with an email
        $this->assertSame(['member1@example.com', 'member2@example.com'], $emails);
    }

    public function testDefaultEmailOfEachUser(): void
    {
        // Arrange: users with several emails, the default one is not the
        // first one (adding a default email unsets the previous default)
        $group = $this->createItem(Group::class, ['name' => 'Several emails']);
        $user = $this->createUserWithEmail('Several emails', 'old-default@example.com');
        $member = $this->createUserWithEmail('Member', 'member-old@example.com');
        $this->addUserToGroup($group, $member);
        foreach ([$user, $member] as $item) {
            $this->createItem(UserEmail::class, [
                'users_id'   => $item->getID(),
                'email'      => $item->fields['name'] === 'Member' ? 'member@example.com' : 'default@example.com',
                'is_default' => 1,
            ]);
        }

        // Act
        $emails = $this->resolve(
            [ITILActorFieldStrategy::SPECIFIC_VALUES],
            specific_itilactors_ids: [
                User::class  => [$user->getID()],
                Group::class => [$group->getID()],
            ],
        );

        // Assert: only the default email of each user
        $this->assertSame(['default@example.com', 'member@example.com'], $emails);
    }

    public function testUsersOutsideTheirValidityDatesAreIgnored(): void
    {
        // Arrange: an expired account and an account not yet valid, like GLPI notifications skip
        $expired = $this->createUserWithEmail('Expired', 'expired@example.com');
        $this->updateItem(User::class, $expired->getID(), ['end_date' => '2000-01-01 00:00:00']);
        $future = $this->createUserWithEmail('Future', 'future@example.com');
        $this->updateItem(User::class, $future->getID(), ['begin_date' => '2037-01-01 00:00:00']);
        $valid = $this->createUserWithEmail('Valid', 'valid@example.com');

        // Act
        $emails = $this->resolve(
            [ITILActorFieldStrategy::SPECIFIC_VALUES],
            specific_itilactors_ids: [User::class => [$expired->getID(), $future->getID(), $valid->getID()]],
        );

        // Assert
        $this->assertSame(['valid@example.com'], $emails);
    }

    public function testGroupWithoutNotificationsIsIgnored(): void
    {
        // Arrange: a group that must not be notified, like GLPI notifications skip
        $group = $this->createItem(Group::class, ['name' => 'Not notified', 'is_notify' => 0]);
        $member = $this->createUserWithEmail('Member', 'member@example.com');
        $this->addUserToGroup($group, $member);

        // Act
        $emails = $this->resolve(
            [ITILActorFieldStrategy::SPECIFIC_VALUES],
            specific_itilactors_ids: [Group::class => [$group->getID()]],
        );

        // Assert
        $this->assertSame([], $emails);
    }

    public function testFormFiller(): void
    {
        $this->addEmailToCurrentUser('filler@example.com');

        $emails = $this->resolve([ITILActorFieldStrategy::FORM_FILLER]);

        $this->assertSame(['filler@example.com'], $emails);
    }

    public function testFormFillerSupervisor(): void
    {
        $this->addEmailToCurrentUser('filler@example.com');
        $supervisor = $this->createUserWithEmail('Supervisor', 'supervisor@example.com');
        $this->updateItem(User::class, Session::getLoginUserID(), [
            'users_id_supervisor' => $supervisor->getID(),
        ]);

        $emails = $this->resolve([ITILActorFieldStrategy::FORM_FILLER_SUPERVISOR]);

        $this->assertSame(['supervisor@example.com'], $emails);
    }

    public function testFormFillerWithNotificationsDisabled(): void
    {
        // Arrange: log in as a user who disabled notifications
        $user = $this->createItem(User::class, [
            'name'                    => 'Filler without notifications',
            'password'                => 'Filler without notifications',
            'password2'               => 'Filler without notifications',
            'is_notif_enable_default' => 0,
        ], ['password', 'password2']);
        $this->createItem(UserEmail::class, [
            'users_id'   => $user->getID(),
            'email'      => 'filler@example.com',
            'is_default' => 1,
        ]);
        $this->login($user->fields['name'], $user->fields['name']);

        $emails = $this->resolve([ITILActorFieldStrategy::FORM_FILLER], login: false);

        $this->assertSame([], $emails);
    }

    public function testSpecificAnswersEmailAndActors(): void
    {
        // Arrange
        $user = $this->createUserWithEmail('Requester', 'requester@example.com');
        $group = $this->createItem(Group::class, ['name' => 'Observers']);
        $member = $this->createUserWithEmail('Observer', 'observer@example.com');
        $this->addUserToGroup($group, $member);
        $form = $this->createFormWithRecipientQuestions();

        // Act
        $emails = $this->resolve(
            [ITILActorFieldStrategy::SPECIFIC_ANSWERS],
            specific_question_ids: [
                $this->getQuestionId($form, 'Email'),
                $this->getQuestionId($form, 'Requester'),
                $this->getQuestionId($form, 'Observer'),
            ],
            form: $form,
            answers: [
                'Email'     => 'answer@example.com',
                'Requester' => ['users_id-' . $user->getID()],
                'Observer'  => ['groups_id-' . $group->getID()],
            ],
        );

        // Assert
        $this->assertSame(
            ['answer@example.com', 'requester@example.com', 'observer@example.com'],
            $emails,
        );
    }

    public function testLastValidAnswer(): void
    {
        $builder = new FormBuilder("Last valid answer form");
        $builder->addQuestion("First email", QuestionTypeEmail::class);
        $builder->addQuestion("Last email", QuestionTypeEmail::class);

        $form = $this->createForm($builder);

        $emails = $this->resolve(
            [ITILActorFieldStrategy::LAST_VALID_ANSWER],
            form: $form,
            answers: [
                'First email' => 'first@example.com',
                'Last email'  => 'last@example.com',
            ],
        );

        $this->assertSame(['last@example.com'], $emails);
    }

    public function testUserFromItemAnswer(): void
    {
        $user = $this->createUserWithEmail('Owner', 'owner@example.com');
        $computer = $this->createItem(Computer::class, [
            'name'        => 'Owned computer',
            'entities_id' => $this->getTestRootEntity(only_id: true),
            'users_id'    => $user->getID(),
        ]);
        $form = $this->createFormWithRecipientQuestions();

        $emails = $this->resolve(
            [ITILActorFieldStrategy::USER_FROM_OBJECT_ANSWER],
            specific_question_ids: [$this->getQuestionId($form, 'Computer')],
            form: $form,
            answers: ['Computer' => ['itemtype' => Computer::class, 'items_ids' => [$computer->getID()]]],
        );

        $this->assertSame(['owner@example.com'], $emails);
    }

    public function testGroupFromItemAnswer(): void
    {
        $group = $this->createItem(Group::class, ['name' => 'Owners']);
        $member = $this->createUserWithEmail('Owner', 'owner@example.com');
        $this->addUserToGroup($group, $member);
        $computer = $this->createItem(Computer::class, [
            'name'        => 'Owned computer',
            'entities_id' => $this->getTestRootEntity(only_id: true),
            'groups_id'   => [$group->getID()],
        ], ['groups_id']);
        $form = $this->createFormWithRecipientQuestions();

        $emails = $this->resolve(
            [ITILActorFieldStrategy::GROUP_FROM_OBJECT_ANSWER],
            specific_question_ids: [$this->getQuestionId($form, 'Computer')],
            form: $form,
            answers: ['Computer' => ['itemtype' => Computer::class, 'items_ids' => [$computer->getID()]]],
        );

        $this->assertSame(['owner@example.com'], $emails);
    }

    public function testUnansweredEmailQuestion(): void
    {
        $form = $this->createFormWithRecipientQuestions();

        $emails = $this->resolve(
            [ITILActorFieldStrategy::SPECIFIC_ANSWERS],
            specific_question_ids: [$this->getQuestionId($form, 'Email')],
            form: $form,
            answers: [],
        );

        $this->assertSame([], $emails);
    }

    public function testDeletedSpecificUserIsIgnored(): void
    {
        $deleted = $this->createUserWithEmail('Deleted', 'deleted@example.com');
        $this->assertTrue($deleted->delete(['id' => $deleted->getID()]));
        $purged = $this->createUserWithEmail('Purged', 'purged@example.com');
        $purged_id = $purged->getID();
        $this->assertTrue($purged->delete(['id' => $purged_id], force: true));

        $emails = $this->resolve(
            [ITILActorFieldStrategy::SPECIFIC_VALUES],
            specific_itilactors_ids: [User::class => [$deleted->getID(), $purged_id]],
        );

        $this->assertSame([], $emails);
    }

    public function testSupplierAnswerIsIgnored(): void
    {
        $supplier = $this->createItem(Supplier::class, [
            'name'        => 'Supplier',
            'email'       => 'supplier@example.com',
            'entities_id' => $this->getTestRootEntity(only_id: true),
        ]);
        $form = $this->createFormWithRecipientQuestions();

        $emails = $this->resolve(
            [ITILActorFieldStrategy::SPECIFIC_ANSWERS],
            specific_question_ids: [$this->getQuestionId($form, 'Assignee')],
            form: $form,
            answers: ['Assignee' => ['suppliers_id-' . $supplier->getID()]],
        );

        $this->assertSame([], $emails);
    }

    public function testCombinedStrategies(): void
    {
        $this->addEmailToCurrentUser('filler@example.com');
        $user = $this->createUserWithEmail('Recipient', 'recipient@example.com');

        $emails = $this->resolve(
            [ITILActorFieldStrategy::SPECIFIC_VALUES, ITILActorFieldStrategy::FORM_FILLER],
            specific_itilactors_ids: [User::class => [$user->getID()]],
        );

        $this->assertSame(['recipient@example.com', 'filler@example.com'], $emails);
    }

    public function testFreeAddressesParsing(): void
    {
        $field = new EmailAddressesField();

        $emails = $field->getEmailAddresses(
            new SimpleValueConfig(' A@x.fr; b@y.fr ,, invalid ; '),
        );

        $this->assertSame(['A@x.fr', 'b@y.fr'], $emails);
    }

    /**
     * @param ITILActorFieldStrategy[] $strategies
     * @param array<class-string, int[]> $specific_itilactors_ids
     * @param int[] $specific_question_ids
     * @param array<string, mixed> $answers
     * @return list<string>
     */
    private function resolve(
        array $strategies,
        array $specific_itilactors_ids = [],
        array $specific_question_ids = [],
        ?Form $form = null,
        array $answers = [],
        bool $login = true,
    ): array {
        if ($login) {
            $this->login();
        }

        $form ??= $this->createForm(new FormBuilder("Email form"));
        $answers_set = $this->sendFormAndGetAnswerSet($form, $answers);

        $config = new EmailRecipientsFieldConfig(
            strategies: $strategies,
            specific_itilactors_ids: $specific_itilactors_ids,
            specific_question_ids: $specific_question_ids,
        );

        return (new EmailRecipientsField())->getEmailAddresses($config, $answers_set);
    }

    private function createFormWithRecipientQuestions(): Form
    {
        $multiple = json_encode(new QuestionTypeActorsExtraDataConfig(is_multiple_actors: true));

        $builder = new FormBuilder("Recipients form");
        $builder->addQuestion("Email", QuestionTypeEmail::class);
        $builder->addQuestion("Requester", QuestionTypeRequester::class, '', $multiple);
        $builder->addQuestion("Observer", QuestionTypeObserver::class, '', $multiple);
        $builder->addQuestion("Assignee", QuestionTypeAssignee::class, '', $multiple);
        $builder->addQuestion(
            name: "Computer",
            type: QuestionTypeItem::class,
            extra_data: json_encode(new QuestionTypeItemExtraDataConfig(itemtype: Computer::class)),
        );

        return $this->createForm($builder);
    }

    private function addEmailToCurrentUser(string $email): void
    {
        $this->login();
        $this->createItem(UserEmail::class, [
            'users_id'   => Session::getLoginUserID(),
            'email'      => $email,
            'is_default' => 1,
        ]);
    }
}
