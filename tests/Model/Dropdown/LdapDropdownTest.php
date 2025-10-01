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

namespace GlpiPlugin\Advancedforms\Tests\Model\Dropdown;

use Glpi\Form\Question;
use GlpiPlugin\Advancedforms\Model\Dropdown\LdapDropdown;
use GlpiPlugin\Advancedforms\Model\Dropdown\LdapDropdownQuery;
use GlpiPlugin\Advancedforms\Model\QuestionType\LdapQuestion;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class LdapDropdownTest extends AdvancedFormsTestCase
{
    public static function provideLdapFilters(): iterable
    {
        yield 'search for brazil159' => [
            'search_text' => 'brazil159',
            'page'        => 1,
            'page_limit'  => 20,
            'expected'    => [
                'results' => [
                    ['id' => 'brazil159', 'text' => 'brazil159'],
                ],
                'count' => 1,
            ],
        ];
        yield 'search for ecuador248' => [
            'search_text' => 'ecuador248',
            'page'        => 1,
            'page_limit'  => 20,
            'expected'    => [
                'results' => [
                    ['id' => 'ecuador248', 'text' => 'ecuador248'],
                ],
                'count' => 1,
            ],
        ];
        yield 'search for all users' => [
            'search_text' => '',
            'page'        => 1,
            'page_limit'  => 5,
            'expected'    => [
                'results' => [
                    ['id' => 'michel', 'text' => 'michel'],
                    ['id' => 'pierre', 'text' => 'pierre'],
                    ['id' => 'remi', 'text' => 'remi'],
                    ['id' => 'specialchar1', 'text' => 'specialchar1'],
                    ['id' => 'specialchar2', 'text' => 'specialchar2'],
                ],
                'count' => 5,
            ],
        ];
        yield 'search with pagination (page 1)' => [
            'search_text' => 'brazil',
            'page'        => 1,
            'page_limit'  => 1,
            'expected'    => [
                'results' => [
                    ['id' => 'brazil0', 'text' => 'brazil0'],
                ],
                'count' => 1,
            ],
        ];
        yield 'search with pagination (page 2)' => [
            'search_text' => 'brazil',
            'page'        => 2,
            'page_limit'  => 1,
            'expected'    => [
                'results' => [
                    ['id' => 'brazil1', 'text' => 'brazil1'],
                ],
                'count' => 1,
            ],
        ];
    }

    #[DataProvider('provideLdapFilters')]
    public function testDropdownValuesWithRealLdapConnection(
        string $search_text,
        int $page,
        int $page_limit,
        array $expected,
    ): void {
        $this->skipIfOpenldapIsNotSetup();

        // Arrange: create a form with an LDAP question
        $this->enableConfigurableItem(LdapQuestion::class);
        $ldap = $this->setupAuthLdap();
        $form = $this->createFormWithLdapQuestion($ldap);

        // Act: fetch ldap values for the ldap select question
        $query = new LdapDropdownQuery(
            question: Question::getById($this->getQuestionId($form, 'LDAP select')),
            search_text: $search_text,
            page: $page,
            page_limit: $page_limit,
        );
        $results = (new LdapDropdown())->getDropdownValues($query);
        $this->assertEquals($expected, $results);
    }
}
