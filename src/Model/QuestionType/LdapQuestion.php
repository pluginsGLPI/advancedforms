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

use AuthLDAP;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionType;
use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\Dropdown\LdapDropdown;
use GlpiPlugin\Advancedforms\Utils\SafeCommonDBTM;
use LogicException;
use Override;

use function Safe\json_decode;

/**
 * Legacy question type from the formcreator plugin
 * Original source: https://github.com/pluginsGLPI/formcreator/blob/2.13.10/inc/field/ldapselectfield.class.php
 */
final class LdapQuestion extends AbstractQuestionType implements ConfigurableItemInterface
{
    #[Override]
    public function getCategory(): QuestionTypeCategoryInterface
    {
        return new AdvancedCategory();
    }

    #[Override]
    public function getName(): string
    {
        return __('LDAP select', 'advancedforms');
    }

    #[Override]
    public function getIcon(): string
    {
        return SafeCommonDBTM::getIcon(AuthLDAP::class);
    }

    #[Override]
    public function getWeight(): int
    {
        return 40;
    }

    #[Override]
    public function renderAdministrationTemplate(Question|null $question): string
    {
        // Read extra config specific to this question type
        $decoded_extra_data = [];
        if ($question !== null && is_string($question->fields['extra_data'])) {
            $decoded_extra_data = json_decode(
                $question->fields['extra_data'],
                associative: true,
            );

            // Fallback to safe value
            if (!is_array($decoded_extra_data)) {
                $decoded_extra_data = [];
            }
        }
        $config = $this->getExtraDataConfig($decoded_extra_data);
        if ($config === null) {
            $config = new LdapQuestionConfig();
        }

        $twig = TemplateRenderer::getInstance();
        return $twig->render(
            '@advancedforms/editor/question_types/ldap_select_config.html.twig',
            [
                'question'   => $question,
                'extra_data' => $config,

                // Forward constants
                'AUTHLDAP_ID'       => LdapQuestionConfig::AUTHLDAP_ID,
                'LDAP_FILTER'       => LdapQuestionConfig::LDAP_FILTER,
                'LDAP_ATTRIBUTE_ID' => LdapQuestionConfig::LDAP_ATTRIBUTE_ID,
            ],
        );
    }

    /** @param array<mixed> $input */
    #[Override]
    public function validateExtraDataInput(array $input): bool
    {
        // Check if the itemtype is set
        if (
            !isset($input[LdapQuestionConfig::AUTHLDAP_ID])
            && !isset($input[LdapQuestionConfig::LDAP_FILTER])
            && !isset($input[LdapQuestionConfig::LDAP_ATTRIBUTE_ID])
        ) {
            return false;
        }

        return true;
    }

    #[Override]
    public function getExtraDataConfigClass(): string
    {
        return LdapQuestionConfig::class;
    }

    #[Override]
    public function renderEndUserTemplate(Question|null $question): string
    {
        if ($question === null) {
            throw new LogicException();
        }

        return LdapDropdown::dropdown([
            'name'                => $question->getEndUserInputName(),
            'width'               => "100%",
            'condition'           => [
                Question::getForeignKeyField() => $question->getID(),
            ],
        ]);
    }

    #[Override]
    public static function getConfigKey(): string
    {
        return "enable_question_type_ldap_select";
    }

    #[Override]
    public function getConfigTitle(): string
    {
        return __("LDAP select question type", 'advancedforms');
    }

    #[Override]
    public function getConfigDescription(): string
    {
        return __("Select some data from a LDAP directory.", 'advancedforms');
    }

    #[Override]
    public function getConfigIcon(): string
    {
        return $this->getIcon();
    }
}
