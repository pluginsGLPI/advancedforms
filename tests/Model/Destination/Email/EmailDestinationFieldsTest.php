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

use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Destination\CommonITILField\TitleField;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Form;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailAddressesField;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailDestination;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailRecipientsField;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailRecipientsFieldConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Group;
use User;

final class EmailDestinationFieldsTest extends AdvancedFormsTestCase
{
    use EmailDestinationTestTrait;

    public function testSpecificActorsAreNormalizedOnSave(): void
    {
        // Arrange: a user and a group, selected as the actors dropdown submits them
        $user = $this->createItem(User::class, ['name' => 'Email recipient']);
        $group = $this->createItem(Group::class, ['name' => 'Email recipients']);

        // Act: create an email destination with these actors
        $destination = $this->createEmailDestination([
            EmailRecipientsField::getKey() => $this->getSpecificActorsInput($user, $group),
        ]);

        // Assert: actors are stored by itemtype
        $config = $destination->getConfig()[EmailRecipientsField::getKey()];
        $this->assertSame(
            [User::class => [$user->getID()], Group::class => [$group->getID()]],
            $config['specific_itilactors_ids'],
        );
    }

    public function testAddressesSelectedInTheDropdownAreJoinedOnSave(): void
    {
        // The addresses dropdown submits one value per address
        $destination = $this->createEmailDestination([
            EmailAddressesField::getKey() => ['value' => ['a@example.com', ' b@example.com ', '']],
        ]);

        $this->assertSame(
            'a@example.com, b@example.com',
            $destination->getConfig()[EmailAddressesField::getKey()]['value'],
        );
    }

    public function testAddressesSubmittedAsTextAreKeptOnSave(): void
    {
        $destination = $this->createEmailDestination([
            EmailAddressesField::getKey() => ['value' => 'a@example.com; b@example.com'],
        ]);

        $this->assertSame(
            'a@example.com; b@example.com',
            $destination->getConfig()[EmailAddressesField::getKey()]['value'],
        );
    }

    public function testSubjectHtmlIsStrippedOnSave(): void
    {
        $destination = $this->createEmailDestination([
            TitleField::getKey() => ['value' => '<p>Hello <b>x</b></p>'],
        ]);

        $this->assertSame(
            'Hello x',
            $destination->getConfig()[TitleField::getKey()]['value'],
        );
    }

    public function testNormalizationRunsOnUpdate(): void
    {
        // Arrange: an email destination without specific actors
        $user = $this->createItem(User::class, ['name' => 'Email recipient']);
        $group = $this->createItem(Group::class, ['name' => 'Email recipients']);
        $destination = $this->createEmailDestination([]);

        // Act: select actors as the actors dropdown submits them
        $this->updateItem(FormDestination::class, $destination->getID(), [
            'config' => [
                EmailRecipientsField::getKey() => $this->getSpecificActorsInput($user, $group),
            ],
        ], ['config']);

        // Assert: actors are stored by itemtype
        $destination->getFromDB($destination->getID());
        $config = $destination->getConfig()[EmailRecipientsField::getKey()];
        $this->assertSame(
            [User::class => [$user->getID()], Group::class => [$group->getID()]],
            $config['specific_itilactors_ids'],
        );
    }

    public function testImportedConfigIsNotNormalized(): void
    {
        // Arrange: config in its stored format, as found in an export
        $user = $this->createItem(User::class, ['name' => 'Email recipient']);
        $stored = (new EmailRecipientsFieldConfig(
            strategies: [ITILActorFieldStrategy::SPECIFIC_VALUES],
            specific_itilactors_ids: [User::class => [$user->getID()]],
        ))->jsonSerialize();

        // Act: create the destination as the importer does
        $destination = $this->createItem(FormDestination::class, [
            Form::getForeignKeyField() => $this->createForm(new FormBuilder("Email form"))->getID(),
            'itemtype'                 => EmailDestination::class,
            'name'                     => 'Email',
            'config'                   => [EmailRecipientsField::getKey() => $stored],
            '_from_import'             => true,
        ], ['config']);

        // Assert: config is kept as is
        $this->assertSame(
            [User::class => [$user->getID()]],
            $destination->getConfig()[EmailRecipientsField::getKey()]['specific_itilactors_ids'],
        );
    }

    public function testOtherDestinationTypesAreUntouched(): void
    {
        // Arrange: a ticket destination holding a value under the recipients key
        $user = $this->createItem(User::class, ['name' => 'Email recipient']);
        $raw = ['strategies' => ['specific_values'], 'specific_itilactors_ids' => ['users_id-' . $user->getID()]];

        // Act
        $builder = new FormBuilder("Ticket form");
        $builder->addDestination(FormDestinationTicket::class, 'Ticket with email key', [
            EmailRecipientsField::getKey() => $raw,
        ]);
        $form = $this->createForm($builder);

        // Assert: the plugin hook did not normalize it
        $destination = FormDestination::getById($this->getDestinationId($form, 'Ticket with email key'));
        $this->assertSame($raw, $destination->getConfig()[EmailRecipientsField::getKey()]);
    }

    public function testStrategiesOffered(): void
    {
        $strategies = array_keys((new EmailRecipientsField())->getStrategiesForDropdown());

        $this->assertNotContains(ITILActorFieldStrategy::FROM_TEMPLATE->value, $strategies);
        $this->assertContains(ITILActorFieldStrategy::SPECIFIC_VALUES->value, $strategies);
        $this->assertContains(ITILActorFieldStrategy::SPECIFIC_ANSWERS->value, $strategies);
        $this->assertContains(ITILActorFieldStrategy::FORM_FILLER->value, $strategies);
        $this->assertContains(ITILActorFieldStrategy::FORM_FILLER_SUPERVISOR->value, $strategies);
    }

    public function testDefaultRecipientsConfig(): void
    {
        $form = $this->createForm(new FormBuilder("Email form"));

        $config = (new EmailRecipientsField())->getConfig($form, []);

        $this->assertInstanceOf(EmailRecipientsFieldConfig::class, $config);
        $this->assertSame([ITILActorFieldStrategy::SPECIFIC_VALUES], $config->getStrategies());
        $this->assertEmpty($config->getSpecificITILActorsIds());
    }

    public function testFormatConfigInputName(): void
    {
        $destination = new EmailDestination();

        $this->assertSame('config[foo]', $destination->formatConfigInputName('foo'));
        $this->assertSame('config[foo][]', $destination->formatConfigInputName('foo[]'));
    }

    public function testDestinationFields(): void
    {
        $keys = array_map(
            fn($field) => $field::getKey(),
            (new EmailDestination())->getConfigurableFields(),
        );

        // The stored config uses these keys: they must not change
        $this->assertSame([
            'glpi-form-destination-commonitilfield-titlefield',
            'glpi-form-destination-commonitilfield-contentfield',
            'glpiplugin-advancedforms-model-destination-email-emailrecipientsfield',
            'glpiplugin-advancedforms-model-destination-email-emailaddressesfield',
        ], $keys);
    }

    /** @param array<string, mixed> $config */
    private function createEmailDestination(array $config): FormDestination
    {
        $builder = new FormBuilder("Email form");
        $builder->addDestination(EmailDestination::class, 'Email', $config);

        $form = $this->createForm($builder);

        return FormDestination::getById($this->getDestinationId($form, 'Email'));
    }
}
