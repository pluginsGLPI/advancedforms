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

namespace GlpiPlugin\Advancedforms\Controller;

use Glpi\Application\View\TemplateRenderer;
use Glpi\Controller\AbstractController;
use Glpi\Controller\Form\Utils\CanCheckAccessPolicies;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Form\Question;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Advancedforms\Model\QuestionType\GlpiScopeQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\SystemInfoQuestion;
use GlpiPlugin\Advancedforms\Service\LatestVersionProvider;
use GlpiPlugin\Advancedforms\Service\SystemInfoParser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Server side preview: the GLPI Network key used for plugin versions must not reach the browser. */
final class SystemInfoPreviewController extends AbstractController
{
    use CanCheckAccessPolicies;

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)] // Some forms can be accessed anonymously, checkFormAccessPolicies() handles access
    #[Route(
        path: 'SystemInfoPreview',
        name: 'advancedforms_system_info_preview',
        methods: 'POST',
    )]
    public function __invoke(Request $request): Response
    {
        $question = new Question();
        if (
            !$question->getFromDB($request->request->getInt('questions_id'))
            || !$question->getQuestionType() instanceof SystemInfoQuestion
        ) {
            throw new BadRequestHttpException();
        }

        $text = $request->request->getString('text');
        if (mb_strlen($text) > SystemInfoParser::MAX_LENGTH) {
            throw new BadRequestHttpException();
        }

        $this->checkFormAccessPolicies($question->getForm(), $request);

        $twig = TemplateRenderer::getInstance();
        $parsed = SystemInfoParser::parse($text);
        if ($parsed === null) {
            return new JsonResponse([
                'html' => $twig->renderFromStringTemplate(
                    '<div class="alert alert-warning mb-0">{{ message }}</div>',
                    ['message' => SystemInfoQuestion::getUnrecognizedTextMessage()],
                ),
                'components' => [GlpiScopeQuestion::CORE_VALUE],
            ]);
        }

        $components = [GlpiScopeQuestion::CORE_VALUE];
        foreach ($parsed['plugins'] as $plugin) {
            $components[] = sprintf('%s (%s)', $plugin['name'], $plugin['key']);
        }

        return new JsonResponse([
            'html' => $twig->render('@advancedforms/system_info_answer.html.twig', [
                'data'     => (new LatestVersionProvider())->enrich($parsed),
                'raw'      => '',
                'show_raw' => false,
            ]),
            'components' => $components,
        ]);
    }
}
