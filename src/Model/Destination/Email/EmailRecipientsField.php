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

namespace GlpiPlugin\Advancedforms\Model\Destination\Email;

use CommonDBTM;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\CommonITILField\ITILActorField;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeAssignee;
use Glpi\Form\QuestionType\QuestionTypeEmail;
use Glpi\Form\QuestionType\QuestionTypeObserver;
use Glpi\Form\QuestionType\QuestionTypeRequester;
use GLPIMailer;
use Group;
use Group_User;
use Override;
use User;
use UserEmail;

/**
 * Recipients of the email, configured like the actors of an ITIL destination.
 */
final class EmailRecipientsField extends ITILActorField
{
    #[Override]
    public function getAllowedQuestionType(): array
    {
        return [
            new QuestionTypeEmail(),
            new QuestionTypeRequester(),
            new QuestionTypeObserver(),
            new QuestionTypeAssignee(),
        ];
    }

    #[Override]
    public function getAllowedQuestionItemTypes(): array
    {
        return [User::class, Group::class];
    }

    /** @return array<class-string<CommonDBTM>> */
    #[Override]
    public function getAllowedActorTypes(): array
    {
        return [User::class, Group::class];
    }

    /** Used by the core to filter the groups offered as specific actors. */
    #[Override]
    public function getActorType(): string
    {
        return 'observer';
    }

    #[Override]
    public function getLabel(): string
    {
        return __('Recipients', 'advancedforms');
    }

    #[Override]
    public function getConfigClass(): string
    {
        return EmailRecipientsFieldConfig::class;
    }

    #[Override]
    public function getDefaultConfig(Form $form): EmailRecipientsFieldConfig
    {
        return new EmailRecipientsFieldConfig([ITILActorFieldStrategy::SPECIFIC_VALUES]);
    }

    /** @return array<string, string> */
    #[Override]
    public function getStrategiesForDropdown(): array
    {
        /** @var array<string, string> $strategies */
        $strategies = parent::getStrategiesForDropdown();

        // There is no template for an email
        unset($strategies[ITILActorFieldStrategy::FROM_TEMPLATE->value]);

        return $strategies;
    }

    #[Override]
    public function getWeight(): int
    {
        return 30;
    }

    /**
     * Email addresses of the actors found by each configured strategy.
     *
     * @return list<string>
     */
    public function getEmailAddresses(EmailRecipientsFieldConfig $config, AnswersSet $answers_set): array
    {
        $emails = [];
        /** @var ITILActorFieldStrategy[] $strategies */
        $strategies = $config->getStrategies();
        foreach ($strategies as $strategy) {
            /** @var array<array<string, mixed>> $actors */
            $actors = $strategy->getITILActors($this, $config, $answers_set) ?? [];
            foreach ($actors as $actor) {
                array_push($emails, ...$this->getActorEmailAddresses($actor));
            }
        }

        return array_values(array_unique(array_filter(
            $emails,
            GLPIMailer::validateAddress(...),
        )));
    }

    /**
     * The notification settings of the actor are respected, like ITIL
     * destinations do.
     *
     * @param array<string, mixed> $actor
     * @return list<string>
     */
    private function getActorEmailAddresses(array $actor): array
    {
        if (isset($actor['use_notification']) && !$actor['use_notification']) {
            return [];
        }

        $alternative_email = $actor['alternative_email'] ?? '';
        if (is_string($alternative_email) && $alternative_email !== '') {
            return [$alternative_email];
        }

        $items_id = $actor['items_id'] ?? 0;
        if (!is_numeric($items_id)) {
            return [];
        }

        return match ($actor['itemtype'] ?? null) {
            User::class  => $this->getUserEmailAddresses((int) $items_id),
            Group::class => $this->getGroupEmailAddresses((int) $items_id),
            default      => [],
        };
    }

    /** @return list<string> */
    private function getUserEmailAddresses(int $users_id): array
    {
        $user = User::getById($users_id);

        return $user instanceof User ? $this->getUsersEmailAddresses([$user->fields]) : [];
    }

    /**
     * Default email addresses of the members of the group, unless the group
     * must not be notified (same rule as GLPI notifications).
     *
     * @return list<string>
     */
    private function getGroupEmailAddresses(int $groups_id): array
    {
        $group = Group::getById($groups_id);
        if (!$group instanceof Group || !$group->fields['is_notify']) {
            return [];
        }

        // The members come with their user fields, no need to load each user
        /** @var array<array<string, mixed>> $members */
        $members = Group_User::getGroupUsers($groups_id);

        return $this->getUsersEmailAddresses($members);
    }

    /**
     * Default email addresses of the given users, skipping the users GLPI
     * notifications skip: deleted, inactive or outside their validity dates.
     *
     * @param array<array<string, mixed>> $users rows of the users table
     * @return list<string>
     */
    private function getUsersEmailAddresses(array $users): array
    {
        $emails = [];
        foreach ($users as $user) {
            if (
                !is_numeric($user['id'] ?? null)
                || !$user['is_active']
                || $user['is_deleted']
                || ($user['begin_date'] !== null && $user['begin_date'] > $_SESSION['glpi_currenttime'])
                || ($user['end_date'] !== null && $user['end_date'] < $_SESSION['glpi_currenttime'])
            ) {
                continue;
            }

            $email = UserEmail::getDefaultForUser((int) $user['id']);
            if (is_string($email) && $email !== '') {
                $emails[] = $email;
            }
        }

        return $emails;
    }
}
