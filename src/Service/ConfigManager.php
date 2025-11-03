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

final class ConfigManager
{
    use SingletonTrait;

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
        ];
    }

    public function isConfigurableItemEnabled(
        ConfigurableItemInterface $item,
    ): bool {
        $config = Config::getConfigurationValue(
            'advancedforms',
            $item->getConfigKey(),
        );

        if ($config === null) {
            return false;
        }

        return (bool) $config;
    }

    /** @return QuestionTypeInterface[] */
    public function getEnabledQuestionsTypes(): array
    {
        return array_filter(
            $this->getConfigurableQuestionTypes(),
            fn(ConfigurableItemInterface $c): bool => $this->isConfigurableItemEnabled($c),
        );
    }

    public function hasAtLeastOneQuestionTypeEnabled(): bool
    {
        return $this->getEnabledQuestionsTypes() !== [];
    }
}
