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

use AuthLDAP;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Advancedforms\Controller\GetAuthLdapFilterController;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function Safe\json_decode;

final class GetAuthLdapFilterControllerTest extends AdvancedFormsTestCase
{
    public static function usersThatCanAccessThisRoute(): array
    {
        return [['glpi']];
    }

    #[DataProvider('usersThatCanAccessThisRoute')]
    public function testAdministratorCanAccessRoute(string $login): void
    {
        // Arrange: create a LDAP server
        $ldap = $this->createLdap();

        // Act: login and call the route
        $this->login($login);
        $response = $this->renderRoute($ldap->getId());

        // Assert: response was OK
        $this->assertEquals(200, $response->getStatusCode());
    }

    public static function usersThatCantAccessThisRoute(): array
    {
        return [['tech'], ['normal'], ['post-only']];
    }

    #[DataProvider('usersThatCantAccessThisRoute')]
    public function testNonAdministratorCantAccessRoute(string $login): void
    {
        // Arrange: create a LDAP server
        $ldap = $this->createLdap();

        // Act: login and call the route
        $this->expectException(BadRequestHttpException::class);
        $this->login($login);
        $this->renderRoute($ldap->getId());
    }

    public function testFilterIsReturned(): void
    {
        // Arrange: create a LDAP server with a specific filter and a condition
        $ldap = $this->createLdap(
            login_field: 'My login field',
            condition: 'My condition',
        );

        // Act: login and call the route
        $this->login('glpi');
        $response = $this->renderRoute($ldap->getId());

        // Assert: response should contain the expected string
        $this->assertEquals(
            "(& (My login field=*) My condition)",
            json_decode($response->getContent(), associative: true)['filter'],
        );
    }

    private function renderRoute(int $id): Response
    {
        $controller = new GetAuthLdapFilterController();
        $request = Request::create('', parameters: ['id' => $id]);
        return $controller($request);
    }

    private function createLdap(
        string $login_field = '',
        string $condition = '',
    ): AuthLDAP {
        $fields = [
            'name' => 'My LDAP',
            'host' => '127.0.0.1',
        ];

        if ($login_field !== '') {
            $fields['login_field'] = $login_field;
        }

        if ($condition !== '') {
            $fields['condition'] = $condition;
        }

        return $this->createItem(AuthLDAP::class, $fields);
    }
}
