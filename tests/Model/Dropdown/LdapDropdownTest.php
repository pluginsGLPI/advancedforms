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
use Ramsey\Uuid\Uuid;

use function Safe\json_decode;

final class LdapDropdownTest extends AdvancedFormsTestCase
{
    public static function provideLdapFilters(): iterable
    {
        yield 'search for user01' => [
            'search_text' => '01',
            'page'        => 1,
            'page_limit'  => 20,
            'expected'    => [
                'results' => [
                    ['id' => 'user01', 'text' => 'user01'],
                ],
                'count' => 1,
            ],
        ];
        yield 'search for user02' => [
            'search_text' => '02',
            'page'        => 1,
            'page_limit'  => 20,
            'expected'    => [
                'results' => [
                    ['id' => 'user02', 'text' => 'user02'],
                ],
                'count' => 1,
            ],
        ];
        yield 'search for all users' => [
            'search_text' => '',
            'page'        => 1,
            'page_limit'  => 20,
            'expected'    => [
                'results' => [
                    ['id' => 'user01', 'text' => 'user01'],
                    ['id' => 'user02', 'text' => 'user02'],
                ],
                'count' => 2,
            ],
        ];
        yield 'search for all users, with pagination (page 1)' => [
            'search_text' => '',
            'page'        => 1,
            'page_limit'  => 1,
            'expected'    => [
                'results' => [
                    ['id' => 'user01', 'text' => 'user01'],
                ],
                'count' => 1,
            ],
        ];
        yield 'search for all users, with pagination (page 2)' => [
            'search_text' => '',
            'page'        => 2,
            'page_limit'  => 1,
            'expected'    => [
                'results' => [
                    ['id' => 'user02', 'text' => 'user02'],
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
        // Arrange: create a form with an LDAP question
        $this->enableConfigurableItem(LdapQuestion::class);
        $ldap = $this->setupAuthLdap();
        $form = $this->createFormWithLdapQuestion($ldap);

        // Act: fetch ldap values for the ldap select question
        // $uuid = Uuid::uuid4()->toString();
        // $fkey = Question::getForeignKeyField();
        // $_SESSION['glpicondition'][$uuid][$fkey] = $this->getQuestionId(
        //     $form,
        //     'LDAP select'
        // );
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
