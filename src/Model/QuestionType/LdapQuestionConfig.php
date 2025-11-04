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

namespace GlpiPlugin\Advancedforms\Model\QuestionType;

use Glpi\DBAL\JsonFieldInterface;
use Override;

final readonly class LdapQuestionConfig implements JsonFieldInterface
{
    // Unique reference to hardcoded name used for serialization
    public const AUTHLDAP_ID = "authldap_id";

    public const LDAP_FILTER = "ldap_filter";

    public const LDAP_ATTRIBUTE_ID = "ldap_attribute_id";

    public function __construct(
        private int $authldap_id = 0,
        private string $ldap_filter = '',
        private int $ldap_attribute_id = 0,
    ) {}

    /**
     * @param array{
     *      authldap_id?: int,
     *      ldap_filter?: string,
     *      ldap_attribute_id?: int
     * } $data
     */
    #[Override]
    public static function jsonDeserialize(array $data): self
    {
        return new self(
            authldap_id: $data[self::AUTHLDAP_ID] ?? 0,
            ldap_filter: $data[self::LDAP_FILTER] ?? '',
            ldap_attribute_id: $data[self::LDAP_ATTRIBUTE_ID] ?? 0,
        );
    }

    /**
     * @return array{
     *      authldap_id: int,
     *      ldap_filter: string,
     *      ldap_attribute_id: int
     * } $data
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            self::AUTHLDAP_ID => $this->authldap_id,
            self::LDAP_FILTER => $this->ldap_filter,
            self::LDAP_ATTRIBUTE_ID => $this->ldap_attribute_id,
        ];
    }

    public function getAuthLdapId(): int
    {
        return $this->authldap_id;
    }

    public function getLdapFilter(): string
    {
        return $this->ldap_filter;
    }

    public function getLdapAttributeId(): int
    {
        return $this->ldap_attribute_id;
    }
}
