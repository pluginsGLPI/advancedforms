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
use Glpi\Toolbox\SingletonTrait;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use Migration;

final class InstallManager
{
    use SingletonTrait;

    public function install(): bool
    {
        global $DB;

        $migration = new Migration(PLUGIN_ADVANCEDFORMS_VERSION);

        $this->installTicketReservationRequestsTable($DB);

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
            KEY `users_id` (`users_id`),
            KEY `status` (`status`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

        $DB->doQuery($query);
    }

    public function uninstall(): bool
    {
        global $DB;

        $config = new Config();
        $config->deleteByCriteria(['context' => 'advancedforms']);

        $table = TicketReservationRequest::getTable();
        if ($DB->tableExists($table, false)) {
            $DB->dropTable($table);
        }

        return true;
    }
}
