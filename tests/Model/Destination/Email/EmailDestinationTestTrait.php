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

use CommonDBTM;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\Tag\AnswerTagProvider;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailRecipientsFieldConfig;
use Group;
use Group_User;
use QueuedNotification;
use User;
use UserEmail;

/**
 * Helpers shared by the email destination tests.
 */
trait EmailDestinationTestTrait
{
    private function createUserWithEmail(string $name, string $email, bool $is_active = true): User
    {
        $user = $this->createItem(User::class, ['name' => $name, 'is_active' => (int) $is_active]);
        $this->createItem(UserEmail::class, [
            'users_id'   => $user->getID(),
            'email'      => $email,
            'is_default' => 1,
        ]);

        return $user;
    }

    private function addUserToGroup(Group $group, User $user): void
    {
        $this->createItem(Group_User::class, [
            'groups_id' => $group->getID(),
            'users_id'  => $user->getID(),
        ]);
    }

    /**
     * Recipients config selecting the given users and groups, as the actors
     * dropdown submits them.
     *
     * @return array<string, mixed>
     */
    private function getSpecificActorsInput(CommonDBTM ...$actors): array
    {
        return (new EmailRecipientsFieldConfig(
            strategies: [ITILActorFieldStrategy::SPECIFIC_VALUES],
            specific_itilactors_ids: array_map(
                fn(CommonDBTM $actor): string => $actor::getForeignKeyField() . '-' . $actor->getID(),
                $actors,
            ),
        ))->jsonSerialize();
    }

    /** @param array<string, mixed> $config */
    private function updateEmailDestinationConfig(Form $form, array $config): void
    {
        $id = $this->getDestinationId($form, 'Email');
        $this->updateItem(FormDestination::class, $id, [
            'config' => array_merge(FormDestination::getById($id)->getConfig(), $config),
        ], ['config']);
    }

    private function getAnswerTag(Form $form, string $question_name): string
    {
        $question = Question::getById($this->getQuestionId($form, $question_name));

        return (new AnswerTagProvider())->getTagForQuestion($question)->html;
    }

    /** @return array<int, array<string, mixed>> */
    private function getQueuedEmails(AnswersSet $answers_set): array
    {
        return array_values((new QueuedNotification())->find([
            'itemtype' => AnswersSet::class,
            'items_id' => $answers_set->getID(),
        ], ['id ASC']));
    }
}
