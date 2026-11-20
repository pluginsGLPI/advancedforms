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

namespace GlpiPlugin\Advancedforms\Service;

use Config;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Form\QuestionType\QuestionTypeInterface;
use Glpi\Toolbox\SingletonTrait;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\HiddenQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\HostnameQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\IpAddressQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\LdapQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\TreeCascadeDropdownQuestion;

final class ConfigManager
{
    use SingletonTrait;

    /** @var array<mixed>|null */
    private ?array $config_values = null;

    public function renderConfigForm(): string
    {
        $twig = TemplateRenderer::getInstance();
        return $twig->render('@advancedforms/config_form.html.twig', [
            'config_manager' => $this,
            'question_types' => $this->getConfigurableQuestionTypes(),
        ]);
    }

    /** @return array<ConfigurableItemInterface&QuestionTypeInterface> */
    public function getConfigurableQuestionTypes(): array
    {
        return [
            new IpAddressQuestion(),
            new HostnameQuestion(),
            new HiddenQuestion(),
            new LdapQuestion(),
            new TreeCascadeDropdownQuestion(),
            new TableQuestion(),
            new ReservationQuestion(),
        ];
    }

    public function isConfigurableItemEnabled(
        ConfigurableItemInterface $item,
    ): bool {
        $config = $this->getConfigValues()[$item->getConfigKey()] ?? null;

        if ($config === null) {
            return false;
        }

        return (bool) $config;
    }

    /** Forgets the loaded configuration, the next read queries it again. */
    public function clearCache(): void
    {
        $this->config_values = null;
    }

    /** Clears the cache when a configuration value of this plugin is saved. */
    public static function onConfigChange(Config $config): void
    {
        if (($config->fields['context'] ?? null) === 'advancedforms') {
            self::getInstance()->clearCache();
        }
    }

    /**
     * The plugin configuration is read on every request (plugin init) and many
     * times per form editor display, so it is loaded once and kept in memory.
     *
     * @return array<mixed>
     */
    private function getConfigValues(): array
    {
        return $this->config_values ??= Config::getConfigurationValues('advancedforms');
    }

    /** @return QuestionTypeInterface[] */
    public function getEnabledQuestionsTypes(): array
    {
        return array_filter(
            $this->getConfigurableQuestionTypes(),
            $this->isConfigurableItemEnabled(...),
        );
    }

    public function hasAtLeastOneQuestionTypeEnabled(): bool
    {
        return $this->getEnabledQuestionsTypes() !== [];
    }
}
