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

use AuthLDAP;
use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Advancedforms\Utils\SafeCommonDBTM;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GetAuthLdapFilterController extends AbstractController
{
    #[Route(
        path: 'GetAuthLdapFilter',
        name: "get_auth_ldap_filter",
        methods: "GET",
    )]
    public function __invoke(Request $request): Response
    {
        if (!AuthLDAP::canView()) {
            throw new BadRequestHttpException();
        }

        $ldap = AuthLDAP::getById($request->query->getInt('id'));
        if (!$ldap) {
            throw new BadRequestHttpException();
        }

        $filter = "(" . SafeCommonDBTM::getStringField($ldap, "login_field") . "=*)";
        $ldap_condition = $ldap->fields['condition'] !== null
            ? SafeCommonDBTM::getStringField($ldap, "condition")
            : ''
        ;
        return new JsonResponse([
            'filter' => "(& $filter $ldap_condition)",
        ]);
    }
}
