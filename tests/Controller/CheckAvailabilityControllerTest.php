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
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Advancedforms\Controller\CheckAvailabilityController;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Reservation;
use ReservationItem;
use Session;
use Symfony\Component\HttpFoundation\Request;

final class CheckAvailabilityControllerTest extends AdvancedFormsTestCase
{
    public function testAccessDeniedWithoutRights(): void
    {
        $this->login();
        $_SESSION['glpiactiveprofile']['reservation'] = 0;

        $controller = new CheckAvailabilityController();
        $this->expectException(AccessDeniedHttpException::class);
        $controller(Request::create('/', 'POST', []));
    }

    public function testMissingParamsThrowsBadRequest(): void
    {
        $this->login();
        $controller = new CheckAvailabilityController();
        $this->expectException(BadRequestHttpException::class);
        $controller(Request::create('/', 'POST', []));
    }

    public function testAvailableSlotReturnsTrue(): void
    {
        $this->login();
        $item = $this->getReservableItem();

        $controller = new CheckAvailabilityController();
        $response = $controller(Request::create('/', 'POST', [
            'reservationitems_id' => $item->getID(),
            'begin' => '2026-02-01 09:00:00',
            'end' => '2026-02-01 10:00:00',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['available' => true], json_decode((string) $response->getContent(), true));
    }

    public function testConflictingSlotReturnsFalse(): void
    {
        $this->login();
        $item = $this->getReservableItem();

        $reservation = new Reservation();
        $this->assertGreaterThan(0, $reservation->add([
            'reservationitems_id' => $item->getID(),
            'begin' => '2026-02-01 09:00:00',
            'end' => '2026-02-01 11:00:00',
            'users_id' => Session::getLoginUserID(),
            'comment' => '',
        ]));

        $controller = new CheckAvailabilityController();
        $response = $controller(Request::create('/', 'POST', [
            'reservationitems_id' => $item->getID(),
            'begin' => '2026-02-01 10:00:00', // overlaps
            'end' => '2026-02-01 12:00:00',
        ]));

        $this->assertSame(['available' => false], json_decode((string) $response->getContent(), true));
    }

    public function testBackToBackSlotIsAvailable(): void
    {
        $this->login();
        $item = $this->getReservableItem();

        $reservation = new Reservation();
        $this->assertGreaterThan(0, $reservation->add([
            'reservationitems_id' => $item->getID(),
            'begin' => '2026-02-01 09:00:00',
            'end' => '2026-02-01 11:00:00',
            'users_id' => Session::getLoginUserID(),
            'comment' => '',
        ]));

        $controller = new CheckAvailabilityController();
        $response = $controller(Request::create('/', 'POST', [
            'reservationitems_id' => $item->getID(),
            'begin' => '2026-02-01 11:00:00', // starts exactly when the other ends: no overlap
            'end' => '2026-02-01 12:00:00',
        ]));

        $this->assertSame(['available' => true], json_decode((string) $response->getContent(), true));
    }

    private function getReservableItem(): ReservationItem
    {
        $computer = $this->createItem('Computer', [
            'name' => 'test-computer',
            'entities_id' => $this->getTestRootEntity(true),
        ]);
        return $this->createItem('ReservationItem', [
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
            'is_active' => 1,
        ]);
    }
}
