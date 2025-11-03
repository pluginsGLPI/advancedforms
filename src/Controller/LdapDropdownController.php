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

use Glpi\Controller\AbstractController;
use Glpi\Controller\Form\Utils\CanCheckAccessPolicies;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Form\Question;
use GlpiPlugin\Advancedforms\Model\Dropdown\LdapDropdown;
use GlpiPlugin\Advancedforms\Model\Dropdown\LdapDropdownQuery;
use GlpiPlugin\Advancedforms\Utils\SafeCommonDBTM;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legacy AJAX endpoint from the formcreator plugin
 * Original source: https://github.com/pluginsGLPI/formcreator/blob/2.13.10/ajax/getldapvalues.php
 */
final class LdapDropdownController extends AbstractController
{
    use CanCheckAccessPolicies;

    #[Route(
        path: 'LdapDropdown',
        name: "ldap_dropdown",
        // methods: "POST", TODO: not sure why but this make POST request fail?
    )]
    public function __invoke(Request $request): Response
    {
        // Read submitted condition
        $condition_uuid = $request->request->getString('condition');
        if ($condition_uuid === "") {
            throw new BadRequestHttpException();
        }

        // We don't control this array
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $condition = $_SESSION['glpicondition'][$condition_uuid] ?? null;
        if ($condition === null) {
            throw new BadRequestHttpException();
        }

        // Get question id from condition
        /** @var array{condition?: int} $condition */
        $fkey = SafeCommonDBTM::getForeignKeyField(Question::class);
        if (!isset($condition[$fkey])) {
            throw new BadRequestHttpException();
        }

        $question_id = $condition[$fkey];
        $question = Question::getById($question_id);
        if (!$question) {
            throw new BadRequestHttpException();
        }

        // Validate that the form is readable for the current user
        $this->checkFormAccessPolicies($question->getForm(), $request);

        // Read others parameters
        $search_text = $request->request->getString('');
        $page        = $request->request->getInt('page', 0);
        $page_limit  = $request->request->getInt('page_limit', 0);

        // Make sure mandatory parameters are set
        if ($page == 0 || $page_limit == 0) {
            throw new BadRequestHttpException();
        }

        $dropdown = new LdapDropdown();
        $ldap_query = new LdapDropdownQuery(
            question   : $question,
            search_text: $search_text,
            page       : $page,
            page_limit : $page_limit,
        );
        return new JsonResponse($dropdown->getDropdownValues($ldap_query));
    }
}
