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

namespace GlpiPlugin\Advancedforms\Tests\Service;

use Config;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use GlpiPlugin\Advancedforms\Service\InstallManager;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;

final class InstallManagerTest extends AdvancedFormsTestCase
{
    public function testUninstallRemoveConfig(): void
    {
        global $DB;

        // Arrange: set multiples config values
        $config_values = [];
        foreach (self::provideQuestionTypes() as $type) {
            $config_values[$type[0]->getConfigKey()] = 1;
        }

        Config::setConfigurationValues('advancedforms', $config_values);

        $config_before = Config::getConfigurationValues('advancedforms');
        $this->assertNotEmpty($config_before);

        // `uninstall()` also drops the plugin's table (real `DROP TABLE`),
        // which core's `DBmysql::checkForDDLInsideTransaction()` forbids
        // while inside an active transaction (DDL causes an implicit commit
        // that would otherwise silently break `DbTestCase`'s
        // rollback-per-test isolation). `DbTestCase::setUp()` always opens
        // one such transaction, so it must be committed first, and a fresh
        // one reopened afterward so `DbTestCase::tearDown()`'s own rollback
        // still finds a transaction to close. This means the DB changes made
        // in this test are real and not rolled back by `tearDown()`; the
        // plugin's full state (config + table) is explicitly restored below
        // so any test running afterward in this same process/DB is
        // unaffected. See testUninstallDropsTicketReservationRequestsTable()
        // for the same pattern applied more narrowly to just the table.
        $DB->commit();

        try {
            InstallManager::getInstance()->uninstall();
            $config_after = Config::getConfigurationValues('advancedforms');

            // Assert: config should be empty after uninstallation
            $this->assertEmpty($config_after);

            // Restore full plugin state (table) for any test that runs
            // afterward in this same process/DB.
            $this->assertTrue(InstallManager::getInstance()->install());
            $this->assertTrue($DB->tableExists(TicketReservationRequest::getTable(), false));
        } finally {
            $DB->beginTransaction();
        }
    }

    public function testInstallCreatesTicketReservationRequestsTable(): void
    {
        global $DB;

        $this->assertTrue(InstallManager::getInstance()->install());
        $this->assertTrue($DB->tableExists(TicketReservationRequest::getTable()));
    }

    public function testInstallIsIdempotent(): void
    {
        $this->assertTrue(InstallManager::getInstance()->install());
        $this->assertTrue(InstallManager::getInstance()->install());
    }

    public function testUninstallDropsTicketReservationRequestsTable(): void
    {
        global $DB;

        // See the detailed explanation in testUninstallRemoveConfig(): the
        // real `DROP TABLE` performed by `uninstall()` cannot run inside the
        // transaction `DbTestCase::setUp()` opens, so it is committed first
        // and a fresh one is reopened afterward for `tearDown()`.
        $DB->commit();

        try {
            // Arrange: make sure the table exists before uninstalling.
            $this->assertTrue(InstallManager::getInstance()->install());
            $this->assertTrue($DB->tableExists(TicketReservationRequest::getTable(), false));

            // Act
            $this->assertTrue(InstallManager::getInstance()->uninstall());

            // Assert: the table must be gone (no orphaned table left behind).
            // `usecache: false` is required here: `tableExists()`'s cache
            // only ever grows and is never invalidated by a drop, so the
            // `true` cached just above by the "Arrange" step would otherwise
            // make this assertion pass regardless of the real DB state.
            $this->assertFalse($DB->tableExists(TicketReservationRequest::getTable(), false));

            // Restore the table for any other test that runs afterward in
            // this same process/DB: `install()` is idempotent and safe to
            // call again here.
            $this->assertTrue(InstallManager::getInstance()->install());
            $this->assertTrue($DB->tableExists(TicketReservationRequest::getTable(), false));
        } finally {
            $DB->beginTransaction();
        }
    }
}
