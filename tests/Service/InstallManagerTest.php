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
use Glpi\Form\AccessControl\ControlType\AllowList;
use Glpi\Form\AccessControl\ControlType\AllowListConfig;
use Glpi\Form\AccessControl\ControlType\DirectAccess;
use Glpi\Form\AccessControl\ControlType\DirectAccessConfig;
use Glpi\Form\AccessControl\FormAccessControl;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\AccessControl\LanguageAccessControl;
use GlpiPlugin\Advancedforms\Model\AccessControl\LanguageAccessControlConfig;
use GlpiPlugin\Advancedforms\Model\TicketReservationRequest;
use GlpiPlugin\Advancedforms\Service\InstallManager;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Notification;
use NotificationTemplate;

final class InstallManagerTest extends AdvancedFormsTestCase
{
    public function testUninstallRemoveConfig(): void
    {
        // Arrange: set multiples config values
        $config_values = [];
        foreach (self::provideQuestionTypes() as $type) {
            $config_values[$type[0]->getConfigKey()] = 1;
        }

        Config::setConfigurationValues('advancedforms', $config_values);

        $config_before = Config::getConfigurationValues('advancedforms');
        $this->assertNotEmpty($config_before);
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

    public function testInstallSeedsReservationNotifications(): void
    {
        $this->assertTrue(InstallManager::getInstance()->install());

        $itemtype = TicketReservationRequest::class;

        $events = [
            'reservation_request_created',
            'reservation_request_approved',
            'reservation_request_refused',
            'reservation_request_slot_unavailable',
        ];
        foreach ($events as $event) {
            $notification = new Notification();
            $this->assertTrue(
                $notification->getFromDBByCrit(['itemtype' => $itemtype, 'event' => $event]),
                'Missing seeded notification for event ' . $event,
            );
            $this->assertSame(1, (int) $notification->fields['is_active']);
        }

        // A single shared mail template must back those notifications.
        $template = new NotificationTemplate();
        $this->assertTrue($template->getFromDBByCrit(['itemtype' => $itemtype]));
    }

    public function testInstallNotificationsAreIdempotent(): void
    {
        $this->assertTrue(InstallManager::getInstance()->install());
        $this->assertTrue(InstallManager::getInstance()->install());

        $this->assertSame(
            1,
            countElementsInTable(
                Notification::getTable(),
                ['itemtype' => TicketReservationRequest::class, 'event' => 'reservation_request_created'],
            ),
        );
    }

    public function testUninstallRemovesOnlyLanguagePolicies(): void
    {
        // Arrange: two forms using the language policy next to core policies
        $this->login();
        $forms = [];
        foreach ([['fr_FR', 'en_GB'], []] as $languages) {
            $forms[] = $this->createForm(
                (new FormBuilder())
                    ->setUseDefaultAccessPolicies(false)
                    ->addAccessControl(AllowList::class, new AllowListConfig(user_ids: [1]))
                    ->addAccessControl(DirectAccess::class, new DirectAccessConfig())
                    ->addAccessControl(LanguageAccessControl::class, new LanguageAccessControlConfig($languages)),
            );
        }

        $form_ids = array_map(fn($form) => $form->getID(), $forms);
        $count = fn(string $strategy): int => countElementsInTable(FormAccessControl::getTable(), [
            'forms_forms_id' => $form_ids,
            'strategy'       => $strategy,
        ]);
        $this->assertSame(2, $count(LanguageAccessControl::class));

        // Act
        InstallManager::getInstance()->uninstallAccessControlPolicies();

        // Assert: language rows are gone, core policies are untouched
        $this->assertSame(0, $count(LanguageAccessControl::class));
        $this->assertSame(2, $count(AllowList::class));
        $this->assertSame(2, $count(DirectAccess::class));
    }
}
