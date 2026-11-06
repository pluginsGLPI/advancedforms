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
use Glpi\Form\AccessControl\ControlType\DirectAccess;
use Glpi\Form\AccessControl\ControlType\DirectAccessConfig;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Controller\SystemInfoPreviewController;
use GlpiPlugin\Advancedforms\Model\QuestionType\SystemInfoQuestion;
use GlpiPlugin\Advancedforms\Service\LatestVersionProvider;
use GlpiPlugin\Advancedforms\Service\SystemInfoParser;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use GlpiPlugin\Advancedforms\Tests\SystemInfoSamples;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function Safe\json_decode;

final class SystemInfoPreviewControllerTest extends AdvancedFormsTestCase
{
    public function setUp(): void
    {
        global $GLPI_CACHE;

        parent::setUp();

        // No known release: latest versions resolve to "unknown" without any network call
        $GLPI_CACHE->set(LatestVersionProvider::RELEASES_CACHE_KEY, []);
        $this->enableConfigurableItem(new SystemInfoQuestion());
    }

    public function tearDown(): void
    {
        global $GLPI_CACHE;

        $GLPI_CACHE->delete(LatestVersionProvider::RELEASES_CACHE_KEY);

        parent::tearDown();
    }

    public function testDeniedWhenFormIsNotAccessible(): void
    {
        $form = $this->createSystemInfoForm(use_default_access_policies: false);
        $this->login('post-only', 'postonly');

        $this->expectException(AccessDeniedHttpException::class);
        $this->callController($form, 'System information', SystemInfoSamples::MODERN);
    }

    public function testAnonymousAccessWithValidToken(): void
    {
        $form = $this->createAnonymousSystemInfoForm();
        $this->logOut();

        $response = $this->callController($form, 'System information', SystemInfoSamples::MODERN, '?token=my_token');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('GLPI', json_decode((string) $response->getContent(), true)['components']);
    }

    public function testAnonymousAccessWithoutTokenIsDenied(): void
    {
        $form = $this->createAnonymousSystemInfoForm();
        $this->logOut();

        $this->expectException(AccessDeniedHttpException::class);
        $this->callController($form, 'System information', SystemInfoSamples::MODERN);
    }

    public function testOtherQuestionTypeIsRejected(): void
    {
        $this->login();
        $builder = new FormBuilder('Other form');
        $builder->addQuestion('Name', QuestionTypeShortText::class);

        $form = $this->createForm($builder);

        $this->expectException(BadRequestHttpException::class);
        $this->callController($form, 'Name', SystemInfoSamples::MODERN);
    }

    public function testOversizedTextIsRejected(): void
    {
        $this->login();
        $form = $this->createSystemInfoForm();

        $this->expectException(BadRequestHttpException::class);
        $this->callController($form, 'System information', str_repeat('a', SystemInfoParser::MAX_LENGTH + 1));
    }

    public function testValidTextReturnsPreviewAndComponents(): void
    {
        $this->login();
        $form = $this->createSystemInfoForm();

        $response = $this->callController($form, 'System information', SystemInfoSamples::MODERN);
        $content = json_decode((string) $response->getContent(), true);

        $this->assertSame(
            [
                'GLPI',
                'Data injection (datainjection)',
                'Additional fields & co (fields)',
                'A very long plugin (averyveryverylongpla)',
            ],
            $content['components'],
        );
        $this->assertStringContainsString('11.0.12', $content['html']);
        $this->assertStringNotContainsString('<details>', $content['html']);
    }

    public function testUnrecognizedTextReturnsWarning(): void
    {
        $this->login();
        $form = $this->createSystemInfoForm();

        $response = $this->callController($form, 'System information', 'I am on the latest version');
        $content = json_decode((string) $response->getContent(), true);

        $this->assertSame(['GLPI'], $content['components']);
        $this->assertStringContainsString(SystemInfoQuestion::getUnrecognizedTextMessage(), $content['html']);
    }

    private function createSystemInfoForm(bool $use_default_access_policies = true): Form
    {
        $builder = new FormBuilder('System information form');
        $builder->addQuestion('System information', SystemInfoQuestion::class);
        $builder->setUseDefaultAccessPolicies($use_default_access_policies);

        return $this->createForm($builder);
    }

    private function createAnonymousSystemInfoForm(): Form
    {
        $builder = new FormBuilder('Anonymous system information form');
        $builder->addQuestion('System information', SystemInfoQuestion::class);
        $builder->setUseDefaultAccessPolicies(false);
        $builder->addAccessControl(DirectAccess::class, new DirectAccessConfig(
            token: 'my_token',
            allow_unauthenticated: true,
        ));

        return $this->createForm($builder);
    }

    private function callController(Form $form, string $question_name, string $text, string $query = ''): Response
    {
        return (new SystemInfoPreviewController())(Request::create('/' . $query, 'POST', [
            'questions_id' => $this->getQuestionId($form, $question_name),
            'text'         => $text,
        ]));
    }
}
