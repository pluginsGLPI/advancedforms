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

namespace GlpiPlugin\Advancedforms\Model\Dropdown;

use AuthLDAP;
use CommonGLPI;
use Dropdown;
use ErrorException;
use GlpiPlugin\Advancedforms\Model\QuestionType\LdapQuestionConfig;
use Html;
use LDAP\Connection;
use LDAP\Result;
use LogicException;
use RuleRightParameter;
use RuntimeException;
use Throwable;

use function Safe\ldap_get_entries;
use function Safe\ldap_parse_result;
use function Safe\ldap_set_option;

/**
 * Legacy custom dropdown from the formcreator plugin
 * Original source: https://github.com/pluginsGLPI/formcreator/blob/2.13.10/inc/ldapdropdown.class.php
 */
final class LdapDropdown extends CommonGLPI
{
    // Required despite being a method of CommonDBTM, not CommonGLPI.
    // TODO: fix in core
    public static function getTable(): string
    {
        return '';
    }

    // Required despite being a method of CommonDBTM, not CommonGLPI.
    // TODO: fix in core
    public function getForeignKeyField(): string
    {
        return '';
    }

    // Required despite being a method of CommonDBTM, not CommonGLPI.
    // TODO: fix in core
    public function isField(): false
    {
        return false;
    }

    /** @param array<string, mixed> $options */
    public static function dropdown($options = []): string
    {
        $options['display'] = false;
        $options['url'] = Html::getPrefixedUrl('plugins/advancedforms/LdapDropdown');

        $html = Dropdown::show(self::class, $options);
        if (!is_string($html)) {
            throw new LogicException();
        }

        return $html;
    }

    public static function ldapErrorHandler(
        int $errno,
        string $errstr,
        ?string $errfile,
        ?int $errline,
    ): false {
        if (error_reporting() === 0) {
            return false;
        }

        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /** @return array{results: list<array{id: string, text: string}>, count: int} */
    public function getDropdownValues(LdapDropdownQuery $query): array
    {
        // Read query parameters
        $question    = $query->getQuestion();
        $search_text = $query->getSearchText();
        $page        = $query->getPage();
        $page_size   = $query->getPageLimit();

        // Read question config
        $config = $question->getExtraDataConfig();
        if (!$config instanceof LdapQuestionConfig) {
            throw new LogicException(); // Impossible
        }

        // Load target LDAP attribute
        $attribute = RuleRightParameter::getById($config->getLdapAttributeId());
        if (!$attribute) {
            throw new RuntimeException();
        }
        $attribute = $attribute->fields['value'];
        if (!is_string($attribute)) {
            throw new LogicException();
        }

        // Load target AuthLDAP
        $auth_ldap = AuthLDAP::getById($config->getAuthLdapId());
        if (!$auth_ldap) {
            throw new RuntimeException();
        }

        // Insert search text into filter if specified
        if ($search_text != '') {
            $ldap_filter = sprintf(
                "(& %s (%s))",
                $config->getLdapFilter(),
                $attribute . '=*' . $search_text . '*',
            );
        } else {
            $ldap_filter = $config->getLdapFilter();
        }

        try {
            // Transform LDAP warnings into errors
            set_error_handler([self::class, 'ldapErrorHandler'], E_WARNING);

            // Execute search
            $ldap_values = $this->executeLdapSearch(
                $auth_ldap,
                $attribute,
                $page,
                $page_size,
                $ldap_filter,
            );
        } catch (Throwable $e) {
            throw new RuntimeException("Failed LDAP query", previous: $e);
        } finally {
            restore_error_handler();
        }

        // Sort results
        usort($ldap_values, function ($a, $b) {
            return strnatcmp($a['text'], $b['text']);
        });

        // Set expected select2 format
        return [
            'results' => $ldap_values,
            'count'   => count($ldap_values),
        ];
    }

    /** @return list<array{id: string, text: string}> */
    private function executeLdapSearch(
        AuthLDAP $auth_ldap,
        string $attribute,
        int $page,
        int $page_size,
        string $ldap_filter,
    ): array {
        $ldap_values = [];

        $count = 0;
        $attributes = [$attribute];

        $cookie = '';
        $ds = $auth_ldap->connect();
        if (!$ds instanceof Connection) {
            throw new RuntimeException();
        }

        $base_dn = $auth_ldap->fields['basedn'];
        if (!is_string($base_dn)) {
            throw new RuntimeException();
        }

        // For some reason Safe\ldap_set_option expect resource instead of Connection
        // @phpstan-ignore argument.type
        ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
        $found_count = 0;
        do {
            if (AuthLDAP::isLdapPageSizeAvailable($auth_ldap)) {
                $controls = [
                    [
                        'oid'        => LDAP_CONTROL_PAGEDRESULTS,
                        'iscritical' => true,
                        'value'      => [
                            'size'    => $auth_ldap->fields['pagesize'],
                            'cookie'  => $cookie,
                        ],
                    ],
                ];
                $result = ldap_search($ds, $base_dn, $ldap_filter, $attributes, 0, -1, -1, LDAP_DEREF_NEVER, $controls);
                if (!$result instanceof Result) {
                    throw new RuntimeException();
                }
                ldap_parse_result($ds, $result, $errcode, $matcheddn, $errmsg, $referrals, $controls);

                // PHPstan doens't know that this is safe
                // @phpstan-ignore offsetAccess.nonOffsetAccessible,offsetAccess.nonOffsetAccessible
                $cookie = $controls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '';
            } else {
                $result = ldap_search($ds, $base_dn, $ldap_filter, $attributes);
                if (!$result instanceof Result) {
                    throw new RuntimeException();
                }
            }

            $entries = ldap_get_entries($ds, $result);

            // openldap return 4 for Size limit exceeded
            $limitexceeded = in_array(ldap_errno($ds), [4, 11]);

            if ($limitexceeded) {
                trigger_error("LDAP size limit exceeded", E_USER_WARNING);
            }

            unset($entries['count']);

            foreach ($entries as $attr) {
                if (
                    // @phpstan-ignore offsetAccess.nonOffsetAccessible
                    !isset($attr[$attribute])
                    // @phpstan-ignore offsetAccess.nonOffsetAccessible
                    || in_array($attr[$attribute][0], $ldap_values)
                ) {
                    continue;
                }

                $found_count++;
                if ($found_count < ((int) $page - 1) * (int) $page_size + 1) {
                    // before the requested page
                    continue;
                }
                if ($found_count > ((int) $page) * (int) $page_size) {
                    // after the requested page
                    break;
                }

                $ldap_values[] = [
                    // @phpstan-ignore cast.string,offsetAccess.nonOffsetAccessible
                    'id'   => (string) $attr[$attribute][0],
                    // @phpstan-ignore cast.string,offsetAccess.nonOffsetAccessible
                    'text' => (string) $attr[$attribute][0],
                ];
                $count++;
                if ($count >= $page_size) {
                    break;
                }
            }
            // @phpstan-ignore notIdentical.alwaysTrue
        } while ($cookie !== null && $cookie != '' && $count < $page_size);

        return $ldap_values;
    }
}
