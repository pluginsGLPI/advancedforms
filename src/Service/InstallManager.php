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

namespace GlpiPlugin\Advancedforms\Service;

use DBmysql;
use Config;
use DBConnection;
use Glpi\DBAL\QueryExpression;
use Glpi\Toolbox\SingletonTrait;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use Migration;
use Notification;
use Notification_NotificationTemplate;
use NotificationTemplate;
use NotificationTemplateTranslation;

use function countElementsInTable;

final class InstallManager
{
    use SingletonTrait;

    /**
     * Notifications seeded at install, keyed by event.
     * Each target is [items_id, type] as stored in glpi_notificationtargets.
     *
     * Only the requester (AUTHOR) is targeted out of the box: it is the reliable
     * recipient for a request-scoped itemtype. Notifying the ticket's assigned
     * technicians would require a custom target resolving the linked ticket, so
     * it is left for admins to add (or a follow-up), not seeded here.
     */
    private const NOTIFICATIONS = [
        'reservation_request_created' => [
            'name' => 'New pre-reservation request',
            'targets' => [[Notification::AUTHOR, Notification::USER_TYPE]],
        ],
        'reservation_request_approved' => [
            'name' => 'Pre-reservation request approved',
            'targets' => [[Notification::AUTHOR, Notification::USER_TYPE]],
        ],
        'reservation_request_refused' => [
            'name' => 'Pre-reservation request refused',
            'targets' => [[Notification::AUTHOR, Notification::USER_TYPE]],
        ],
        'reservation_request_slot_unavailable' => [
            'name' => 'Reservation slot no longer available',
            'targets' => [[Notification::AUTHOR, Notification::USER_TYPE]],
        ],
    ];

    public function install(): bool
    {
        global $DB;

        $migration = new Migration(PLUGIN_ADVANCEDFORMS_VERSION);

        $this->installTicketReservationRequestsTable($DB);
        $this->installNotifications();

        $migration->executeMigration();

        return true;
    }

    private function installTicketReservationRequestsTable(DBmysql $DB): void
    {
        $table = TicketReservationRequest::getTable();

        if ($DB->tableExists($table, false)) {
            return;
        }

        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        $query = "CREATE TABLE `{$table}` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `reservationitems_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `reservations_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `users_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `begin` timestamp NULL DEFAULT NULL,
            `end` timestamp NULL DEFAULT NULL,
            `status` int NOT NULL DEFAULT '1',
            `comment_submission` text,
            `comment_validation` text,
            `users_id_validate` int {$default_key_sign} NOT NULL DEFAULT '0',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            `validation_date` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `reservationitems_id` (`reservationitems_id`),
            KEY `reservations_id` (`reservations_id`),
            KEY `users_id` (`users_id`),
            KEY `status` (`status`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        $DB->doQuery($query);
    }

    /**
     * Seeds a mail template + one active notification per reservation event so
     * the feature works out of the box. Idempotent: existing rows are left as-is
     * (admins may have customized them).
     */
    private function installNotifications(): void
    {
        global $DB;

        $itemtype = TicketReservationRequest::class;

        $templates_id = $this->ensureNotificationTemplate($itemtype);
        if ($templates_id <= 0) {
            return;
        }

        foreach (self::NOTIFICATIONS as $event => $definition) {
            if (countElementsInTable(Notification::getTable(), ['itemtype' => $itemtype, 'event' => $event]) > 0) {
                continue;
            }

            // Raw inserts (like core install/migration seeding): no login context required.
            $DB->insert(Notification::getTable(), [
                'name'          => $definition['name'],
                'entities_id'   => 0,
                'is_recursive'  => 1,
                'is_active'     => 1,
                'itemtype'      => $itemtype,
                'event'         => $event,
                'comment'       => '',
                'date_creation' => new QueryExpression('NOW()'),
                'date_mod'      => new QueryExpression('NOW()'),
            ]);
            $insert_id = $DB->insertId();
            $notifications_id = is_numeric($insert_id) ? (int) $insert_id : 0;
            if ($notifications_id <= 0) {
                continue;
            }

            $DB->insert(Notification_NotificationTemplate::getTable(), [
                'notifications_id'         => $notifications_id,
                'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
                'notificationtemplates_id' => $templates_id,
            ]);

            foreach ($definition['targets'] as [$items_id, $type]) {
                $DB->insert('glpi_notificationtargets', [
                    'notifications_id' => $notifications_id,
                    'items_id'         => $items_id,
                    'type'             => $type,
                ]);
            }
        }
    }

    /** Returns the id of the (created if needed) shared mail template for reservation requests. */
    private function ensureNotificationTemplate(string $itemtype): int
    {
        global $DB;

        $template = new NotificationTemplate();
        if ($template->getFromDBByCrit(['itemtype' => $itemtype])) {
            return (int) $template->getID();
        }

        $DB->insert(NotificationTemplate::getTable(), [
            'name'          => 'Reservation requests',
            'itemtype'      => $itemtype,
            'comment'       => '',
            'css'           => '',
            'date_creation' => new QueryExpression('NOW()'),
            'date_mod'      => new QueryExpression('NOW()'),
        ]);
        $insert_id = $DB->insertId();
        $templates_id = is_numeric($insert_id) ? (int) $insert_id : 0;
        if ($templates_id <= 0) {
            return 0;
        }

        $content_html = <<<'HTML'
            <p>##reservationrequest.action##</p>
            <p>##reservationrequest.begin## &rarr; ##reservationrequest.end##</p>
            <p>##reservationrequest.comment##</p>
            <p><a href="##reservationrequest.ticket_url##">##reservationrequest.ticket_url##</a></p>
            HTML;

        $content_text = "##reservationrequest.action##\n"
            . "##reservationrequest.begin## -> ##reservationrequest.end##\n"
            . "##reservationrequest.comment##\n"
            . "##reservationrequest.ticket_url##";

        $DB->insert(NotificationTemplateTranslation::getTable(), [
            'notificationtemplates_id' => $templates_id,
            'language'                 => '',
            'subject'                  => '##reservationrequest.action## - ##reservationrequest.ticket_title##',
            'content_html'             => $content_html,
            'content_text'             => $content_text,
        ]);

        return $templates_id;
    }

    public function uninstall(): bool
    {
        global $DB;

        $config = new Config();
        $config->deleteByCriteria(['context' => 'advancedforms']);

        $this->uninstallNotifications();

        $table = TicketReservationRequest::getTable();
        if ($DB->tableExists($table, false)) {
            $DB->dropTable($table);
        }

        return true;
    }

    /** Removes every notification artifact seeded by installNotifications(). */
    private function uninstallNotifications(): void
    {
        global $DB;

        $itemtype = TicketReservationRequest::class;

        foreach ($DB->request(['SELECT' => 'id', 'FROM' => Notification::getTable(), 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null)) {
                continue;
            }

            $notifications_id = (int) $row['id'];
            $DB->delete('glpi_notificationtargets', ['notifications_id' => $notifications_id]);
            $DB->delete(Notification_NotificationTemplate::getTable(), ['notifications_id' => $notifications_id]);
        }

        $DB->delete(Notification::getTable(), ['itemtype' => $itemtype]);

        foreach ($DB->request(['SELECT' => 'id', 'FROM' => NotificationTemplate::getTable(), 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null)) {
                continue;
            }

            $templates_id = (int) $row['id'];
            $DB->delete(NotificationTemplateTranslation::getTable(), ['notificationtemplates_id' => $templates_id]);
        }

        $DB->delete(NotificationTemplate::getTable(), ['itemtype' => $itemtype]);
    }
}
