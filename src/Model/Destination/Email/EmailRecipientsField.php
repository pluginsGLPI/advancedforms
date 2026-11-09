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
        $recipients = [];
        /** @var ITILActorFieldStrategy[] $strategies */
        $strategies = $config->getStrategies();
        foreach ($strategies as $strategy) {
            /** @var array<array<string, mixed>> $actors */
            $actors = $strategy->getITILActors($this, $config, $answers_set) ?? [];
            foreach ($actors as $actor) {
                array_push($recipients, ...$this->getActorRecipients($actor));
            }
        }

        $users_emails = $this->getUsersDefaultEmails(array_filter($recipients, is_int(...)));

        $emails = [];
        foreach ($recipients as $recipient) {
            $email = is_int($recipient) ? $users_emails[$recipient] ?? null : $recipient;
            if ($email !== null && GLPIMailer::validateAddress($email)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Email address or user ids to notify for the actor. The notification
     * settings of the actor are respected, like ITIL destinations do.
     *
     * @param array<string, mixed> $actor
     * @return list<string|int>
     */
    private function getActorRecipients(array $actor): array
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
            User::class  => [(int) $items_id],
            Group::class => $this->getGroupMembersIds((int) $items_id),
            default      => [],
        };
    }

    /**
     * Members of the group, unless the group must not be notified (same rule
     * as GLPI notifications).
     *
     * @return list<int>
     */
    private function getGroupMembersIds(int $groups_id): array
    {
        $group = Group::getById($groups_id);
        if (!$group instanceof Group || !$group->fields['is_notify']) {
            return [];
        }

        /** @var list<array{id: int}> $members */
        $members = Group_User::getGroupUsers($groups_id);

        return array_column($members, 'id');
    }

    /**
     * Default email of each user, in a single query. The users GLPI
     * notifications skip are left out: deleted, inactive or outside their
     * validity dates.
     *
     * @param array<int> $users_ids
     * @return array<int, string> email by user id
     */
    private function getUsersDefaultEmails(array $users_ids): array
    {
        global $DB;

        if ($users_ids === []) {
            return [];
        }

        $emails_table = UserEmail::getTable();
        $users_table  = User::getTable();
        $now          = $_SESSION['glpi_currenttime'];

        $iterator = $DB->request([
            'SELECT'     => [$emails_table . '.users_id', $emails_table . '.email'],
            'FROM'       => $emails_table,
            'INNER JOIN' => [
                $users_table => [
                    'FKEY' => [
                        $users_table  => 'id',
                        $emails_table => 'users_id',
                    ],
                ],
            ],
            'WHERE'      => [
                $emails_table . '.users_id'   => array_values(array_unique($users_ids)),
                $emails_table . '.is_default' => 1,
                $users_table . '.is_active'   => 1,
                $users_table . '.is_deleted'  => 0,
                ['OR' => [[$users_table . '.begin_date' => null], [$users_table . '.begin_date' => ['<=', $now]]]],
                ['OR' => [[$users_table . '.end_date' => null], [$users_table . '.end_date' => ['>=', $now]]]],
            ],
        ]);

        /** @var array<int, string> $emails */
        $emails = array_column(iterator_to_array($iterator, false), 'email', 'users_id');

        return $emails;
    }
}
