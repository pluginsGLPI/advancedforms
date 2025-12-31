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
use Glpi\Form\Form;
use Glpi\Form\Question;
use GlpiPlugin\Advancedforms\Controller\LdapDropdownController;
use GlpiPlugin\Advancedforms\Model\QuestionType\LdapQuestion;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class LdapDropdownControllerTest extends AdvancedFormsTestCase
{
    public function testValidParameters(): void
    {
        // Arrange: create a valid form
        $this->enableConfigurableItem(LdapQuestion::class);
        $ldap = $this->setupAuthLdap();
        $form = $this->createFormWithLdapQuestion($ldap);

        // Act: execute route
        $this->login('post-only');
        $response = $this->renderRoute([
            'condition'  => $this->buildAndGetConditionUuid($form),
            'page'       => 1,
            'page_limit' => 1,
        ]);

        // Assert: no error should be triggered
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testMissingPageParameters(): void
    {
        // Arrange: create a valid form
        $this->enableConfigurableItem(LdapQuestion::class);
        $ldap = $this->setupAuthLdap();
        $form = $this->createFormWithLdapQuestion($ldap);

        // Act: execute route
        $this->login('post-only');
        $this->expectException(BadRequestHttpException::class);
        $this->renderRoute([
            'condition'  => $this->buildAndGetConditionUuid($form),
            'page_limit' => 1,
        ]);
    }

    public function testMissingPageLimitParameters(): void
    {
        // Arrange: create a valid form
        $this->enableConfigurableItem(LdapQuestion::class);
        $ldap = $this->setupAuthLdap();
        $form = $this->createFormWithLdapQuestion($ldap);

        // Act: execute route
        $this->login('post-only');
        $this->expectException(BadRequestHttpException::class);
        $this->renderRoute([
            'condition' => $this->buildAndGetConditionUuid($form),
            'page'      => 1,
        ]);
    }

    public function testMissingConditionParameter(): void
    {
        // Arrange: create a disabled form
        $this->enableConfigurableItem(LdapQuestion::class);
        $ldap = $this->setupAuthLdap();
        $form = $this->createFormWithLdapQuestion($ldap);
        $form = $this->updateItem(
            Form::class,
            $form->getID(),
            ['is_active' => false],
        );

        // Act: execute route
        $this->login('post-only');
        $this->expectException(AccessDeniedHttpException::class);
        $this->renderRoute([
            'condition'  => $this->buildAndGetConditionUuid($form),
            'page'       => 1,
            'page_limit' => 1,
        ]);
    }

    public function testInvalidConditonParameter(): void
    {
        // Act: execute route
        $this->login('post-only');
        $this->expectException(BadRequestHttpException::class);
        $this->renderRoute([
            'condition' => 'not a condition',
            'page'      => 1,
        ]);
    }

    public function testSearchTextParameter(): void
    {
        // Arrange: create a valid form
        $this->enableConfigurableItem(LdapQuestion::class);
        $ldap = $this->setupAuthLdap();
        $form = $this->createFormWithLdapQuestion($ldap);

        // Act: execute route with searchText
        $this->login('post-only');
        $response = $this->renderRoute([
            'condition'  => $this->buildAndGetConditionUuid($form),
            'page'       => 1,
            'page_limit' => 10,
            'searchText' => 'pierre',
        ]);

        // Assert: response should be successful
        $this->assertEquals(200, $response->getStatusCode());

        // Assert: response should be valid JSON
        $content = $response->getContent();
        $this->assertNotFalse($content);
        $data = json_decode($content, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('count', $data);
        $this->assertEquals([['id' => 'pierre', 'text' => 'pierre']], $data['results']);
    }

    public function testEmptySearchTextParameter(): void
    {
        // Arrange: create a valid form
        $this->enableConfigurableItem(LdapQuestion::class);
        $ldap = $this->setupAuthLdap();
        $form = $this->createFormWithLdapQuestion($ldap);

        // Act: execute route with empty searchText
        $this->login('post-only');
        $response = $this->renderRoute([
            'condition'  => $this->buildAndGetConditionUuid($form),
            'page'       => 1,
            'page_limit' => 10,
            'searchText' => '',
        ]);

        // Assert: response should be successful
        $this->assertEquals(200, $response->getStatusCode());

        // Assert: response should be valid JSON
        $content = $response->getContent();
        $this->assertNotFalse($content);
        $data = json_decode($content, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('count', $data);
        $this->assertCount(10, $data['results']);
    }

    private function renderRoute(array $post): Response
    {
        $controller = new LdapDropdownController();
        $request = Request::create('', 'POST', parameters: $post);
        return $controller($request);
    }

    private function buildAndGetConditionUuid(Form $form): string
    {
        $uuid = Uuid::uuid4()->toString();
        $fkey = Question::getForeignKeyField();
        $_SESSION['glpicondition'][$uuid][$fkey] = $this->getQuestionId(
            $form,
            'LDAP select',
        );

        return $uuid;
    }
}
