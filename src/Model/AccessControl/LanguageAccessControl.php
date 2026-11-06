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

namespace GlpiPlugin\Advancedforms\Model\AccessControl;

use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\AccessControl\AccessVote;
use Glpi\Form\AccessControl\ControlType\ControlTypeInterface;
use Glpi\Form\AccessControl\FormAccessControl;
use Glpi\Form\AccessControl\FormAccessParameters;
use Glpi\Form\Export\Context\DatabaseMapper;
use Glpi\Form\Export\Serializer\DynamicExportDataField;
use Glpi\Form\Form;
use Glpi\Locale\LanguageRegistry;
use InvalidArgumentException;
use Override;

final class LanguageAccessControl implements ControlTypeInterface
{
    public const INPUT_NAME = '_advancedforms_languages';

    #[Override]
    public function getLabel(): string
    {
        return __('Allow specific languages', 'advancedforms');
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-language';
    }

    #[Override]
    public function getConfig(): JsonFieldInterface
    {
        return new LanguageAccessControlConfig();
    }

    #[Override]
    public function getWarnings(Form $form): array
    {
        foreach ($form->getAccessControls() as $control) {
            if (
                $control->fields['is_active']
                && $control->getStrategy() instanceof self
                && $this->getValidatedConfig($control->getConfig())->getLanguages() === []
            ) {
                return [__('No language is selected in the "Allow specific languages" policy: it denies access to everyone.', 'advancedforms')];
            }
        }

        return [];
    }

    #[Override]
    public function renderConfigForm(FormAccessControl $access_control): string
    {
        $twig = TemplateRenderer::getInstance();
        return $twig->render('@advancedforms/access_control/language.html.twig', [
            'access_control' => $access_control,
            'config'         => $this->getValidatedConfig($access_control->getConfig()),
            'languages'      => Dropdown::getLanguages(),
            'input_name'     => self::INPUT_NAME,
            'label'          => $this->getLabel(),
        ]);
    }

    #[Override]
    public function getWeight(): int
    {
        // After core policies (AllowList: 10, DirectAccess: 20).
        return 30;
    }

    /** @param array<string, mixed> $input */
    #[Override]
    public function createConfigFromUserInput(array $input): LanguageAccessControlConfig
    {
        $values = $input[self::INPUT_NAME] ?? [];
        // An empty multi-select is posted as an empty string.
        if (!is_array($values)) {
            $values = [];
        }

        // Trust boundary: keep only known GLPI language codes.
        $languages = array_values(array_unique(array_filter(
            $values,
            fn($value): bool => is_string($value) && LanguageRegistry::has($value),
        )));

        return new LanguageAccessControlConfig($languages);
    }

    #[Override]
    public function canAnswer(
        Form $form,
        JsonFieldInterface $config,
        FormAccessParameters $parameters,
    ): AccessVote {
        $config = $this->getValidatedConfig($config);

        // Deny (not Abstain) so the language restricts every other policy:
        // "All users" + this policy means "all users using a listed language".
        $language = $_SESSION['glpilanguage'] ?? null;
        if (!in_array($language, $config->getLanguages(), true)) {
            return AccessVote::Deny;
        }

        // Next to other policies, only restrict: granting would let in users
        // they refuse (e.g. outside the allowed group, or without the token).
        if ($this->hasOtherActivePolicies($form)) {
            return AccessVote::Abstain;
        }

        // Never grant anonymous users: login stays the job of other policies.
        return $parameters->isAuthenticated() ? AccessVote::Grant : AccessVote::Abstain;
    }

    #[Override]
    public function allowUnauthenticated(JsonFieldInterface $config): bool
    {
        return false;
    }

    #[Override]
    public function exportDynamicConfig(JsonFieldInterface $config): DynamicExportDataField
    {
        // Language codes are not database ids: no requirement to map on import.
        return new DynamicExportDataField($config->jsonSerialize(), []);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    #[Override]
    public static function prepareDynamicConfigDataForImport(
        array $config,
        DatabaseMapper $mapper,
    ): array {
        return $config;
    }

    private function hasOtherActivePolicies(Form $form): bool
    {
        foreach ($form->getAccessControls() as $control) {
            if ($control->fields['is_active'] && $control->fields['strategy'] !== self::class) {
                return true;
            }
        }

        return false;
    }

    private function getValidatedConfig(JsonFieldInterface $config): LanguageAccessControlConfig
    {
        if (!$config instanceof LanguageAccessControlConfig) {
            throw new InvalidArgumentException('Invalid config class');
        }

        return $config;
    }
}
