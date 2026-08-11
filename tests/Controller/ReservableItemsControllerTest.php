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

namespace GlpiPlugin\Advancedforms\Tests\Controller;

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Advancedforms\Controller\ReservableItemsController;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ReservableItemsControllerTest extends AdvancedFormsTestCase
{
    public function testAccessDeniedWithoutRights(): void
    {
        $this->login();
        $_SESSION['glpiactiveprofile']['reservation'] = 0;

        $controller = new ReservableItemsController();
        $this->expectException(AccessDeniedHttpException::class);
        $controller(Request::create('/', 'POST', []));
    }

    public function testReturnsActiveReservableItemsFilteredByAllowedItemtypes(): void
    {
        $this->login();

        $computer = $this->createItem('Computer', ['name' => 'test-reservable-computer', 'entities_id' => $this->getTestRootEntity(true)]);
        $res_item = $this->createItem('ReservationItem', [
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'is_active' => 1,
        ]);

        // Item of an itemtype not in the allowed list must not be returned
        $monitor = $this->createItem('Monitor', ['name' => 'test-reservable-monitor', 'entities_id' => $this->getTestRootEntity(true)]);
        $this->createItem('ReservationItem', [
            'itemtype' => 'Monitor',
            'items_id' => $monitor->getID(),
            'is_active' => 1,
        ]);

        $controller = new ReservableItemsController();
        $response = $controller(Request::create('/', 'POST', [
            'allowed_itemtypes' => ['Computer'],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($data);

        $ids = array_column($data, 'id');
        $this->assertContains($res_item->getID(), $ids);
        foreach ($data as $row) {
            $this->assertSame('Computer', $row['itemtype']);
            $this->assertSame('test-reservable-computer (Computer)', $row['text']);
        }
    }

    public function testInactiveItemIsExcluded(): void
    {
        $this->login();

        $computer = $this->createItem('Computer', ['name' => 'test-inactive-computer', 'entities_id' => $this->getTestRootEntity(true)]);
        $this->createItem('ReservationItem', [
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'is_active' => 0,
        ]);

        $controller = new ReservableItemsController();
        $response = $controller(Request::create('/', 'POST', [
            'allowed_itemtypes' => ['Computer'],
        ]));

        $data = json_decode((string) $response->getContent(), true);
        $names = array_column($data, 'text');
        $this->assertNotContains('test-inactive-computer (Computer)', $names);
    }

    public function testFallsBackToConfigReservationTypesWhenAllowedItemtypesOmitted(): void
    {
        $this->login();

        global $CFG_GLPI;
        $original_reservation_types = $CFG_GLPI['reservation_types'] ?? null;
        $CFG_GLPI['reservation_types'] = ['Computer'];

        try {
            $computer = $this->createItem('Computer', ['name' => 'test-fallback-computer', 'entities_id' => $this->getTestRootEntity(true)]);
            $res_item = $this->createItem('ReservationItem', [
                'itemtype' => 'Computer',
                'items_id' => $computer->getID(),
                'is_active' => 1,
            ]);

            $controller = new ReservableItemsController();
            $response = $controller(Request::create('/', 'POST', []));

            $this->assertSame(200, $response->getStatusCode());
            $data = json_decode((string) $response->getContent(), true);
            $this->assertIsArray($data);

            $ids = array_column($data, 'id');
            $this->assertContains($res_item->getID(), $ids);

            foreach ($data as $row) {
                $this->assertSame('Computer', $row['itemtype']);
            }
        } finally {
            $CFG_GLPI['reservation_types'] = $original_reservation_types;
        }
    }

    public function testSearchFiltersResults(): void
    {
        $this->login();

        $computer1 = $this->createItem('Computer', ['name' => 'alpha-computer', 'entities_id' => $this->getTestRootEntity(true)]);
        $this->createItem('ReservationItem', [
            'itemtype' => 'Computer',
            'items_id' => $computer1->getID(),
            'is_active' => 1,
        ]);

        $computer2 = $this->createItem('Computer', ['name' => 'beta-computer', 'entities_id' => $this->getTestRootEntity(true)]);
        $this->createItem('ReservationItem', [
            'itemtype' => 'Computer',
            'items_id' => $computer2->getID(),
            'is_active' => 1,
        ]);

        $controller = new ReservableItemsController();
        $response = $controller(Request::create('/', 'POST', [
            'allowed_itemtypes' => ['Computer'],
            'search' => 'alpha',
        ]));

        $data = json_decode((string) $response->getContent(), true);
        $names = array_column($data, 'text');
        $this->assertContains('alpha-computer (Computer)', $names);
        $this->assertNotContains('beta-computer (Computer)', $names);
    }
}
