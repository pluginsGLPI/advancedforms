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

use Config;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\CommonITILField\ContentField;
use Glpi\Form\Destination\CommonITILField\SimpleValueConfig;
use Glpi\Form\Destination\CommonITILField\TitleField;
use Glpi\Form\Form;
use Glpi\RichText\RichText;
use NotificationMailing;
use Toolbox;

/**
 * Queue the email of an email destination, one message per recipient.
 *
 * Sending problems are logged and never thrown: the destinations are created
 * inside the transaction that saves the answers, so an exception would cancel
 * the whole submission.
 */
final class EmailSender
{
    /** @param array<string, mixed> $config */
    public function send(Form $form, AnswersSet $answers_set, array $config): void
    {
        global $CFG_GLPI;

        if (!$CFG_GLPI['use_notifications'] || !$CFG_GLPI['notifications_mailing']) {
            return;
        }

        $emails = $this->getEmailAddresses($form, $answers_set, $config);
        if ($emails === []) {
            $this->log($form, $answers_set, 'no valid recipient, nothing was sent.');
            return;
        }

        $entities_id = $form->getEntityID();
        $sender = Config::getEmailSender($entities_id);
        if ($sender['email'] === null) {
            $this->log($form, $answers_set, 'no email sender is configured, nothing was sent.');
            return;
        }

        $title_field = new TitleField();
        $subject = $title_field->applyConfiguratedValueToInputUsingAnswers(
            $title_field->getConfig($form, $config, $answers_set),
            [],
            $answers_set,
        )['name'];

        $content_field = new ContentField();
        $content = $content_field->applyConfiguratedValueToInputUsingAnswers(
            $content_field->getConfig($form, $config, $answers_set),
            [],
            $answers_set,
        )['content'];
        $body_html = RichText::getSafeHtml(is_string($content) ? $content : '');
        $body_text = RichText::getTextFromHtml($body_html);

        $options = [
            '_itemtype'                 => AnswersSet::class,
            '_items_id'                 => $answers_set->getID(),
            '_notificationtemplates_id' => 0,
            '_entities_id'              => $entities_id,
            'from'                      => $sender['email'],
            'fromname'                  => $sender['name'],
            'subject'                   => $subject,
            'content_text'              => $body_text,
            'content_html'              => $body_html,
        ];

        $reply_to = Config::getReplyToEmailSender($entities_id);
        if ($reply_to['email'] !== null) {
            $options['replyto']     = $reply_to['email'];
            $options['replytoname'] = $reply_to['name'];
        }

        $mailing = new NotificationMailing();
        foreach ($emails as $email) {
            if (!$mailing->sendNotification($options + ['to' => $email, 'toname' => ''])) {
                $this->log($form, $answers_set, sprintf('unable to queue the email to %s.', $email));
            }
        }
    }

    /**
     * Configured recipients and additional addresses, without duplicates
     * (case insensitive, first occurrence kept).
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function getEmailAddresses(Form $form, AnswersSet $answers_set, array $config): array
    {
        $recipients_field = new EmailRecipientsField();
        $recipients_config = $recipients_field->getConfig($form, $config, $answers_set);

        $addresses_field = new EmailAddressesField();
        $addresses_config = $addresses_field->getConfig($form, $config, $answers_set);

        $emails = [
            ...($recipients_config instanceof EmailRecipientsFieldConfig
                ? $recipients_field->getEmailAddresses($recipients_config, $answers_set)
                : []),
            ...($addresses_config instanceof SimpleValueConfig
                ? $addresses_field->getEmailAddresses($addresses_config)
                : []),
        ];

        $unique = [];
        foreach ($emails as $email) {
            $unique[strtolower($email)] ??= $email;
        }

        return array_values($unique);
    }

    private function log(Form $form, AnswersSet $answers_set, string $message): void
    {
        Toolbox::logInFile('advancedforms', sprintf(
            "Email destination of form \"%s\" (answers #%d): %s\n",
            $form->getName(),
            $answers_set->getID(),
            $message,
        ));
    }
}
