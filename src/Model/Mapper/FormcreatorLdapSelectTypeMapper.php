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

namespace GlpiPlugin\Advancedforms\Model\Mapper;

use AuthLDAP;
use Glpi\Form\Migration\FormQuestionDataConverterInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\LdapQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\LdapQuestionConfig;
use LogicException;
use Override;

use function Safe\json_decode;

final class FormcreatorLdapSelectTypeMapper implements FormQuestionDataConverterInterface
{
    /** @param array<mixed> $rawData */
    #[Override]
    public function convertDefaultValue(array $rawData): null
    {
        return null;
    }

    /**
     * @param array<string, mixed> $rawData
     * @return array<mixed>
     */
    #[Override]
    public function convertExtraData(array $rawData): array
    {
        if (!isset($rawData['values']) || !is_string($rawData['values'])) {
            throw new LogicException();
        }

        /** @var array{ldap_auth: int, ldap_filter: string, ldap_attribute: int} $data */
        $data = json_decode($rawData['values'], associative: true);

        // Ensure LDAP auth exists and is active
        $authLdap = new AuthLDAP();
        $authLdapId = 0;
        if ($authLdap->getFromDB($data['ldap_auth']) && $authLdap->fields['is_active']) {
            $authLdapId = $authLdap->getId();
        }

        return [
            LdapQuestionConfig::AUTHLDAP_ID       => $authLdapId,
            LdapQuestionConfig::LDAP_FILTER       => $data['ldap_filter'],
            LdapQuestionConfig::LDAP_ATTRIBUTE_ID => $data['ldap_attribute'],
        ];
    }

    /** @param array<mixed> $rawData */
    #[Override]
    public function getTargetQuestionType(array $rawData): string
    {
        return LdapQuestion::class;
    }

    /** @param array<mixed> $rawData */
    #[Override]
    public function beforeConversion(array $rawData): void {}
}
