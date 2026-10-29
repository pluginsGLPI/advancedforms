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

namespace GlpiPlugin\Advancedforms\Tests\Model\Destination;

use CommonITILActor;
use CommonITILObject;
use Glpi\Form\AnswersHandler\AnswersHandler;
use Glpi\Form\DelegationData;
use Glpi\Form\Destination\CommonITILField\AssigneeField;
use Glpi\Form\Destination\CommonITILField\AssigneeFieldConfig;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Destination\CommonITILField\ObserverField;
use Glpi\Form\Destination\CommonITILField\ObserverFieldConfig;
use Glpi\Form\Destination\CommonITILField\RequesterField;
use Glpi\Form\Destination\CommonITILField\RequesterFieldConfig;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationChange;
use Glpi\Form\Destination\FormDestinationProblem;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Export\Context\DatabaseMapper;
use Glpi\Form\Export\Serializer\FormSerializer;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeActorsExtraDataConfig;
use Glpi\Form\QuestionType\QuestionTypeAssignee;
use Glpi\Form\QuestionType\QuestionTypeEmail;
use Glpi\Form\QuestionType\QuestionTypeObserver;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\Destination\ActorNotificationField;
use GlpiPlugin\Advancedforms\Model\Destination\ActorNotificationFieldConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Group;
use Group_User;
use PHPUnit\Framework\Attributes\DataProvider;
use Profile;
use Session;
use Supplier;
use TicketTemplate;
use TicketTemplatePredefinedField;
use User;

final class ActorNotificationFieldTest extends AdvancedFormsTestCase
{
    private static FormSerializer $serializer;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$serializer = new FormSerializer();
    }

    public function testFieldIsRegisteredForEachCommonItilDestination(): void
    {
        foreach ([new FormDestinationTicket(), new FormDestinationChange(), new FormDestinationProblem()] as $destination) {
            $this->assertInstanceOf(
                ActorNotificationField::class,
                $destination->getConfigurableFieldByKey(ActorNotificationField::getKey()),
            );
        }
    }

    public function testSectionListsUsersAndSuppliersSourcesOnly(): void
    {
        $this->login();
        $tech = $this->createTechnician('Section tech');
        $group = $this->createItem(Group::class, ['name' => 'Section group', 'is_assign' => 1]);
        $form = $this->createFormWithActorsQuestions();

        $this->setDestinationConfig($form, [
            RequesterField::getKey() => $this->requesterConfig([ITILActorFieldStrategy::FORM_FILLER]),
            ObserverField::getKey()  => $this->observerConfig([ITILActorFieldStrategy::FROM_TEMPLATE]),
            AssigneeField::getKey()  => $this->assigneeConfig(
                [ITILActorFieldStrategy::SPECIFIC_VALUES],
                actors: ['users_id-' . $tech->getID(), 'groups_id-' . $group->getID()],
            ),
        ]);

        $html = $this->renderField($form);

        $this->assertStringContainsString('User who filled the form', $html);
        $this->assertStringContainsString($tech->getFriendlyName(), $html);
        $this->assertStringContainsString('[roles][assign][disabled_actors][users_id-' . $tech->getID() . ']', $html);
        $this->assertStringNotContainsString('groups_id-' . $group->getID(), $html);
        $this->assertStringNotContainsString('[roles][observer]', $html);
        // Every toggle is on by default
        $this->assertSame(2, substr_count($html, 'checked'));
    }

    public function testSupplierIsNotListedWhereTheCoreIgnoresIt(): void
    {
        $this->login();
        $supplier = $this->createItem(Supplier::class, [
            'name'        => 'Observer supplier',
            'entities_id' => $this->getTestRootEntity(true),
        ]);
        $form = $this->createFormWithActorsQuestions();

        // Observers are users or groups only
        $this->setDestinationConfig($form, [
            ObserverField::getKey() => (new ObserverFieldConfig(
                [ITILActorFieldStrategy::SPECIFIC_VALUES],
                ['suppliers_id-' . $supplier->getID()],
            ))->jsonSerialize(),
        ]);

        $this->assertStringNotContainsString('[roles][observer]', $this->renderField($form));
    }

    public function testDisabledSectionSubmitsNothing(): void
    {
        $this->login();
        $form = $this->createFormWithActorsQuestions();
        $this->setDestinationConfig($form, [
            RequesterField::getKey() => $this->requesterConfig([ITILActorFieldStrategy::FORM_FILLER]),
        ]);

        $html = $this->renderField($form, ['disabled' => true]);

        // The hidden input and the toggle of the form filler source
        $this->assertSame(2, substr_count($html, '<input'));
        $this->assertSame(2, preg_match_all('/<input[^>]*\sdisabled\s*>/', $html));
    }

    public function testDisabledSpecificActorHasNoFollowup(): void
    {
        $this->login();
        $tech = $this->createTechnician('Specific tech');
        $form = $this->createFormWithActorsQuestions();

        $this->setDestinationConfig($form, [
            AssigneeField::getKey() => $this->assigneeConfig(
                [ITILActorFieldStrategy::SPECIFIC_VALUES],
                actors: ['users_id-' . $tech->getID()],
            ),
            ActorNotificationField::getKey() => $this->notificationConfig(
                assign: ['disabled_actors' => ['users_id-' . $tech->getID()]],
            ),
        ]);

        $ticket = $this->sendFormAndGetCreatedTicket($form);

        $this->assertSame(
            [$this->userKey($tech) => 0],
            $this->getActorsNotifications($ticket, CommonITILActor::ASSIGN),
        );
        $requester = User::getById(Session::getLoginUserID());
        $this->assertInstanceOf(User::class, $requester);
        $this->assertSame(
            [$this->userKey($requester) => (int) $requester->isUserNotificationEnable()],
            $this->getActorsNotifications($ticket, CommonITILActor::REQUESTER),
        );
    }

    public function testDisabledFormFillerHasNoFollowup(): void
    {
        $this->login();
        $form = $this->createFormWithActorsQuestions();

        $this->setDestinationConfig($form, [
            RequesterField::getKey() => $this->requesterConfig([ITILActorFieldStrategy::FORM_FILLER]),
            ActorNotificationField::getKey() => $this->notificationConfig(
                requester: ['disabled_strategies' => [ITILActorFieldStrategy::FORM_FILLER->value]],
            ),
        ]);

        $ticket = $this->sendFormAndGetCreatedTicket($form);

        $this->assertSame(
            ['User-' . Session::getLoginUserID() => 0],
            $this->getActorsNotifications($ticket, CommonITILActor::REQUESTER),
        );
    }

    public static function provideDelegationFollowups(): iterable
    {
        yield 'followup chosen' => [true];
        yield 'followup refused' => [false];
    }

    #[DataProvider('provideDelegationFollowups')]
    public function testDisabledFormFillerKeepsDelegationFollowup(bool $use_notification): void
    {
        $delegated_user = $this->createItem(User::class, ['name' => 'Delegated requester']);
        $post_only_id = getItemByTypeName(User::class, 'post-only', true);
        $group = $this->createItem(Group::class, ['name' => 'Delegation group']);
        $this->createItem(Group_User::class, [
            'groups_id'       => $group->getID(),
            'users_id'        => $post_only_id,
            'is_userdelegate' => 1,
        ]);
        $this->createItem(Group_User::class, [
            'groups_id' => $group->getID(),
            'users_id'  => $delegated_user->getID(),
        ]);

        $this->login();
        $form = $this->createFormWithActorsQuestions();
        $this->setDestinationConfig($form, [
            RequesterField::getKey() => $this->requesterConfig([ITILActorFieldStrategy::FORM_FILLER]),
            ActorNotificationField::getKey() => $this->notificationConfig(
                requester: ['disabled_strategies' => [ITILActorFieldStrategy::FORM_FILLER->value]],
            ),
        ]);

        $this->login('post-only', 'postonly');
        $created_items = AnswersHandler::getInstance()->saveAnswers(
            $form,
            [],
            $post_only_id,
            delegation: new DelegationData($delegated_user->getID(), $use_notification),
        )->getCreatedItems();
        $this->assertCount(1, $created_items);
        $ticket = current($created_items);
        $this->assertInstanceOf(CommonITILObject::class, $ticket);

        $this->assertSame(
            [$this->userKey($delegated_user) => (int) $use_notification],
            $this->getActorsNotifications($ticket, CommonITILActor::REQUESTER),
        );
    }

    public function testDisabledFormFillerSelectingSelfHasNoFollowup(): void
    {
        $this->login();
        $form = $this->createFormWithActorsQuestions();
        $this->setDestinationConfig($form, [
            RequesterField::getKey() => $this->requesterConfig([ITILActorFieldStrategy::FORM_FILLER]),
            ActorNotificationField::getKey() => $this->notificationConfig(
                requester: ['disabled_strategies' => [ITILActorFieldStrategy::FORM_FILLER->value]],
            ),
        ]);

        $this->login('post-only', 'postonly');
        $post_only_id = getItemByTypeName(User::class, 'post-only', true);
        $created_items = AnswersHandler::getInstance()->saveAnswers(
            $form,
            [],
            $post_only_id,
            delegation: new DelegationData($post_only_id, true),
        )->getCreatedItems();
        $this->assertCount(1, $created_items);
        $ticket = current($created_items);
        $this->assertInstanceOf(CommonITILObject::class, $ticket);

        $this->assertSame(
            ['User-' . $post_only_id => 0],
            $this->getActorsNotifications($ticket, CommonITILActor::REQUESTER),
        );
    }

    public function testDisabledQuestionMutesEveryAnsweredPerson(): void
    {
        $this->login();
        $user_1 = $this->createItem(User::class, ['name' => 'Observer 1']);
        $user_2 = $this->createItem(User::class, ['name' => 'Observer 2']);
        $form = $this->createFormWithActorsQuestions();
        $question_id = $this->getQuestionId($form, 'Observers');

        $this->setDestinationConfig($form, [
            ObserverField::getKey() => $this->observerConfig(
                [ITILActorFieldStrategy::SPECIFIC_ANSWERS],
                question_ids: [$question_id],
            ),
            ActorNotificationField::getKey() => $this->notificationConfig(
                observer: ['disabled_question_ids' => [$question_id]],
            ),
        ]);

        $ticket = $this->sendFormAndGetCreatedTicket($form, [
            'Observers' => ['users_id-' . $user_1->getID(), 'users_id-' . $user_2->getID()],
        ]);

        $this->assertEquals(
            [$this->userKey($user_1) => 0, $this->userKey($user_2) => 0],
            $this->getActorsNotifications($ticket, CommonITILActor::OBSERVER),
        );
    }

    public function testDisabledEmailQuestionMutesTheAddress(): void
    {
        $this->login();
        $form = $this->createFormWithActorsQuestions();
        $question_id = $this->getQuestionId($form, 'Email');

        $this->setDestinationConfig($form, [
            ObserverField::getKey() => $this->observerConfig(
                [ITILActorFieldStrategy::SPECIFIC_ANSWERS],
                question_ids: [$question_id],
            ),
            ActorNotificationField::getKey() => $this->notificationConfig(
                observer: ['disabled_question_ids' => [$question_id]],
            ),
        ]);

        $ticket = $this->sendFormAndGetCreatedTicket($form, ['Email' => 'observer@example.com']);

        $this->assertSame(
            ['email-observer@example.com' => 0],
            $this->getActorsNotifications($ticket, CommonITILActor::OBSERVER),
        );
    }

    public static function provideStrategiesOrders(): iterable
    {
        yield 'specific actor first' => [true];
        yield 'question first' => [false];
    }

    #[DataProvider('provideStrategiesOrders')]
    public function testEnabledSourceWinsOverDisabledOne(bool $specific_first): void
    {
        $this->login();
        $tech = $this->createTechnician('Two sources tech');
        $form = $this->createFormWithActorsQuestions();
        $question_id = $this->getQuestionId($form, 'Assignee');

        $strategies = [ITILActorFieldStrategy::SPECIFIC_VALUES, ITILActorFieldStrategy::SPECIFIC_ANSWERS];
        $this->setDestinationConfig($form, [
            AssigneeField::getKey() => $this->assigneeConfig(
                $specific_first ? $strategies : array_reverse($strategies),
                actors: ['users_id-' . $tech->getID()],
                question_ids: [$question_id],
            ),
            ActorNotificationField::getKey() => $this->notificationConfig(
                assign: ['disabled_actors' => ['users_id-' . $tech->getID()]],
            ),
        ]);

        $ticket = $this->sendFormAndGetCreatedTicket($form, ['Assignee' => ['users_id-' . $tech->getID()]]);

        $this->assertSame(
            [$this->userKey($tech) => 1],
            $this->getActorsNotifications($ticket, CommonITILActor::ASSIGN),
        );
    }

    public function testTemplateActorKeepsFollowup(): void
    {
        $this->login();
        $tech = $this->createTechnician('Template tech');
        $other_tech = $this->createTechnician('Template other tech');
        $this->createItem(TicketTemplatePredefinedField::class, [
            'tickettemplates_id' => getItemByTypeName(TicketTemplate::class, 'Default', true),
            'num'                => 5, // User assignee
            'value'              => $tech->getID(),
        ]);
        $form = $this->createFormWithActorsQuestions();

        $this->setDestinationConfig($form, [
            AssigneeField::getKey() => $this->assigneeConfig(
                [ITILActorFieldStrategy::SPECIFIC_VALUES],
                actors: ['users_id-' . $tech->getID(), 'users_id-' . $other_tech->getID()],
            ),
            ActorNotificationField::getKey() => $this->notificationConfig(
                assign: ['disabled_actors' => ['users_id-' . $tech->getID(), 'users_id-' . $other_tech->getID()]],
            ),
        ]);

        $ticket = $this->sendFormAndGetCreatedTicket($form);

        // The template actor shifts the actors lines: the other technician
        // must still be the muted one.
        $this->assertEquals(
            [$this->userKey($tech) => 1, $this->userKey($other_tech) => 0],
            $this->getActorsNotifications($ticket, CommonITILActor::ASSIGN),
        );
    }

    public function testDisabledSupplierHasNoFollowup(): void
    {
        $this->login();
        $supplier = $this->createItem(Supplier::class, [
            'name'        => 'Muted supplier',
            'email'       => 'supplier@example.com',
            'entities_id' => $this->getTestRootEntity(true),
        ]);
        $form = $this->createFormWithActorsQuestions();

        $this->setDestinationConfig($form, [
            AssigneeField::getKey() => $this->assigneeConfig(
                [ITILActorFieldStrategy::SPECIFIC_VALUES],
                actors: ['suppliers_id-' . $supplier->getID()],
            ),
            ActorNotificationField::getKey() => $this->notificationConfig(
                assign: ['disabled_actors' => ['suppliers_id-' . $supplier->getID()]],
            ),
        ]);

        $ticket = $this->sendFormAndGetCreatedTicket($form);

        $this->assertSame(
            ['Supplier-' . $supplier->getID() => 0],
            $this->getActorsNotifications($ticket, CommonITILActor::ASSIGN),
        );
    }

    public static function provideOtherDestinations(): iterable
    {
        yield 'change' => [FormDestinationChange::class];
        yield 'problem' => [FormDestinationProblem::class];
    }

    /** @param class-string<FormDestinationChange|FormDestinationProblem> $destination_class */
    #[DataProvider('provideOtherDestinations')]
    public function testDisabledSpecificActorOnOtherDestinations(string $destination_class): void
    {
        $this->login();
        $tech = $this->createTechnician('Other destination tech');
        $form = $this->createFormWithActorsQuestions($destination_class);

        $this->setDestinationConfig($form, [
            AssigneeField::getKey() => $this->assigneeConfig(
                [ITILActorFieldStrategy::SPECIFIC_VALUES],
                actors: ['users_id-' . $tech->getID()],
            ),
            ActorNotificationField::getKey() => $this->notificationConfig(
                assign: ['disabled_actors' => ['users_id-' . $tech->getID()]],
            ),
        ]);

        $created_items = $this->sendFormAndGetAnswerSet($form)->getCreatedItems();
        $this->assertCount(1, $created_items);
        $item = current($created_items);
        $this->assertInstanceOf(CommonITILObject::class, $item);

        $this->assertSame(
            [$this->userKey($tech) => 0],
            $this->getActorsNotifications($item, CommonITILActor::ASSIGN),
        );
    }

    public function testDestinationWithoutSettingIsUnchanged(): void
    {
        $this->login();
        $tech = $this->createTechnician('Untouched tech');
        $form = $this->createFormWithActorsQuestions();

        $this->setDestinationConfig($form, [
            AssigneeField::getKey() => $this->assigneeConfig(
                [ITILActorFieldStrategy::SPECIFIC_VALUES],
                actors: ['users_id-' . $tech->getID()],
            ),
        ]);

        $ticket = $this->sendFormAndGetCreatedTicket($form);

        $this->assertSame(
            [$this->userKey($tech) => 1],
            $this->getActorsNotifications($ticket, CommonITILActor::ASSIGN),
        );
    }

    public function testFormTogglesAreConvertedToDisabledSources(): void
    {
        $this->login();
        $tech = $this->createTechnician('Toggles tech');
        $other_tech = $this->createTechnician('Toggles other tech');
        $form = $this->createFormWithActorsQuestions();

        // Unchecked toggles submit "1", checked ones "0".
        $config = $this->setDestinationConfig($form, [
            RequesterField::getKey() => $this->requesterConfig([ITILActorFieldStrategy::FORM_FILLER]),
            AssigneeField::getKey()  => $this->assigneeConfig(
                [ITILActorFieldStrategy::SPECIFIC_VALUES],
                actors: ['users_id-' . $tech->getID(), 'users_id-' . $other_tech->getID()],
            ),
            ActorNotificationField::getKey() => [
                'roles' => [
                    'requester' => ['disabled_strategies' => ['form_filler' => '0']],
                    'assign'    => ['disabled_actors' => [
                        'users_id-' . $tech->getID()       => '1',
                        'users_id-' . $other_tech->getID() => '0',
                    ]],
                ],
            ],
        ]);

        $this->assertSame(
            $this->notificationConfig(assign: ['disabled_actors' => ['users_id-' . $tech->getID()]]),
            $config[ActorNotificationField::getKey()],
        );
    }

    public function testUnknownKeysAndRemovedSourcesAreNotSaved(): void
    {
        $this->login();
        $tech = $this->createTechnician('Removed tech');
        $form = $this->createFormWithActorsQuestions();
        $question_id = $this->getQuestionId($form, 'Observers');

        $config = $this->setDestinationConfig($form, [
            RequesterField::getKey() => $this->requesterConfig([ITILActorFieldStrategy::FORM_FILLER]),
            ObserverField::getKey()  => $this->observerConfig([ITILActorFieldStrategy::FROM_TEMPLATE]),
            AssigneeField::getKey()  => $this->assigneeConfig([ITILActorFieldStrategy::FROM_TEMPLATE]),
            ActorNotificationField::getKey() => [
                'unknown' => 'value',
                'roles'   => [
                    'unknown_role' => ['disabled_strategies' => ['form_filler']],
                    'requester'    => [
                        'disabled_strategies' => ['form_filler', 'from_template', 'unknown_strategy'],
                        'unknown_list'        => ['foo'],
                    ],
                    // Sources no longer configured in the actors fields
                    'observer'     => ['disabled_question_ids' => [$question_id]],
                    'assign'       => ['disabled_actors' => ['users_id-' . $tech->getID()]],
                ],
            ],
        ]);

        $this->assertSame(
            $this->notificationConfig(requester: ['disabled_strategies' => ['form_filler']]),
            $config[ActorNotificationField::getKey()],
        );
    }

    public function testSettingsSurviveExportAndImport(): void
    {
        $this->login();
        $tech = $this->createTechnician('Exported tech');
        $form = $this->createFormWithActorsQuestions();
        $question_id = $this->getQuestionId($form, 'Observers');

        $this->setDestinationConfig($form, [
            ObserverField::getKey() => $this->observerConfig(
                [ITILActorFieldStrategy::SPECIFIC_ANSWERS],
                question_ids: [$question_id],
            ),
            AssigneeField::getKey() => $this->assigneeConfig(
                [ITILActorFieldStrategy::SPECIFIC_VALUES],
                actors: ['users_id-' . $tech->getID()],
            ),
            ActorNotificationField::getKey() => $this->notificationConfig(
                observer: ['disabled_question_ids' => [$question_id]],
                assign: ['disabled_actors' => ['users_id-' . $tech->getID()]],
            ),
        ]);

        $json = self::$serializer->exportFormsToJson([$form])->getJsonContent();
        $mapper = new DatabaseMapper([$this->getTestRootEntity(only_id: true)]);
        $imported_forms = self::$serializer->importFormsFromJson($json, $mapper)->getImportedForms();
        $this->assertCount(1, $imported_forms);
        $imported_form = current($imported_forms);
        $this->assertNotSame($form->getID(), $imported_form->getID());

        $this->assertSame(
            $this->notificationConfig(
                observer: ['disabled_question_ids' => [$this->getQuestionId($imported_form, 'Observers')]],
                assign: ['disabled_actors' => ['users_id-' . $tech->getID()]],
            ),
            $this->getDestination($imported_form)->getConfig()[ActorNotificationField::getKey()],
        );
    }

    public function testDuplicatedFormUsesItsOwnQuestions(): void
    {
        $this->login();
        $form = $this->createFormWithActorsQuestions();
        $question_id = $this->getQuestionId($form, 'Observers');

        $this->setDestinationConfig($form, [
            ObserverField::getKey() => $this->observerConfig(
                [ITILActorFieldStrategy::SPECIFIC_ANSWERS],
                question_ids: [$question_id],
            ),
            ActorNotificationField::getKey() => $this->notificationConfig(
                observer: ['disabled_question_ids' => [$question_id]],
            ),
        ]);

        $clone = Form::getById($form->clone());
        $this->assertInstanceOf(Form::class, $clone);
        $clone_question_id = $this->getQuestionId($clone, 'Observers');
        $this->assertNotSame($question_id, $clone_question_id);

        $this->assertSame(
            $this->notificationConfig(observer: ['disabled_question_ids' => [$clone_question_id]]),
            $this->getDestination($clone)->getConfig()[ActorNotificationField::getKey()],
        );
    }

    /** @param class-string<FormDestinationTicket|FormDestinationChange|FormDestinationProblem> $destination_class */
    private function createFormWithActorsQuestions(string $destination_class = FormDestinationTicket::class): Form
    {
        $multiple_actors = json_encode(new QuestionTypeActorsExtraDataConfig(is_multiple_actors: true));

        $builder = new FormBuilder('Email followup form');
        $builder->addQuestion('Observers', QuestionTypeObserver::class, '', $multiple_actors);
        $builder->addQuestion('Assignee', QuestionTypeAssignee::class, '');
        $builder->addQuestion('Email', QuestionTypeEmail::class);
        $builder->addDestination($destination_class, 'Destination');
        $builder->setShouldInitDestinations(false);

        return $this->createForm($builder);
    }

    private function createTechnician(string $name): User
    {
        return $this->createItem(User::class, [
            'name'         => $name,
            '_profiles_id' => getItemByTypeName(Profile::class, 'Technician', true),
        ]);
    }

    private function getDestination(Form $form): FormDestination
    {
        $destinations = $form->getDestinations();
        $this->assertCount(1, $destinations);

        return current($destinations);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed> The saved config
     */
    private function setDestinationConfig(Form $form, array $config): array
    {
        $destination = $this->getDestination($form);
        $this->updateItem(
            $destination::class,
            $destination->getID(),
            ['config' => $config],
            ['config'],
        );

        $destination->getFromDB($destination->getID());
        return $destination->getConfig();
    }

    /** @param array<string, mixed> $display_options */
    private function renderField(Form $form, array $display_options = []): string
    {
        $destination = $this->getDestination($form);
        $field = new ActorNotificationField();

        return $field->renderConfigForm(
            $form,
            $destination,
            $field->getConfig($form, $destination->getConfig()),
            'config[' . ActorNotificationField::getKey() . ']',
            $display_options,
        );
    }

    /**
     * @param array<string, list<string|int>> $requester
     * @param array<string, list<string|int>> $observer
     * @param array<string, list<string|int>> $assign
     * @return array<string, mixed>
     */
    private function notificationConfig(array $requester = [], array $observer = [], array $assign = []): array
    {
        $roles = [];
        foreach (['requester' => $requester, 'observer' => $observer, 'assign' => $assign] as $role => $lists) {
            $roles[$role] = array_merge(ActorNotificationFieldConfig::buildRoleConfig(), $lists);
        }

        return ['roles' => $roles];
    }

    /** @param list<ITILActorFieldStrategy> $strategies */
    private function requesterConfig(array $strategies): array
    {
        return (new RequesterFieldConfig($strategies))->jsonSerialize();
    }

    /**
     * @param list<ITILActorFieldStrategy> $strategies
     * @param list<int> $question_ids
     */
    private function observerConfig(array $strategies, array $question_ids = []): array
    {
        return (new ObserverFieldConfig($strategies, [], $question_ids))->jsonSerialize();
    }

    /**
     * @param list<ITILActorFieldStrategy> $strategies
     * @param list<string> $actors
     * @param list<int> $question_ids
     */
    private function assigneeConfig(array $strategies, array $actors = [], array $question_ids = []): array
    {
        return (new AssigneeFieldConfig($strategies, $actors, $question_ids))->jsonSerialize();
    }

    private function userKey(User $user): string
    {
        return 'User-' . $user->getID();
    }

    /** @return array<string, int> use_notification by actor */
    private function getActorsNotifications(CommonITILObject $item, int $type): array
    {
        $notifications = [];
        foreach ($item->getActorsForType($type) as $actor) {
            if ($actor['itemtype'] === Group::class) {
                continue;
            }

            $key = (int) $actor['items_id'] > 0
                ? $actor['itemtype'] . '-' . $actor['items_id']
                : 'email-' . $actor['alternative_email'];
            $notifications[$key] = (int) $actor['use_notification'];
        }

        return $notifications;
    }
}
