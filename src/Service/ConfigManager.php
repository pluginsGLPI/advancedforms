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

use Glpi\Application\View\TemplateRenderer;
use Glpi\Toolbox\SingletonTrait;
use GlpiPlugin\Advancedforms\Model\Config\Config;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\HiddenQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\HostnameQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\IpAddressQuestion;

final class ConfigManager
{
    use SingletonTrait;

    public const CONFIG_ENABLE_QUESTION_TYPE_IP = 'enable_question_type_ip_address';
    public const CONFIG_ENABLE_QUESTION_TYPE_HOSTNAME = 'enable_question_type_hostname';
    public const CONFIG_ENABLE_QUESTION_TYPE_HIDDEN = 'enable_question_type_hidden';

    public function renderConfigForm(): string
    {
        $twig = TemplateRenderer::getInstance();
        return $twig->render('@advancedforms/config_form.html.twig', [
            'config' => $this->getConfig(),
            'question_types' => $this->getConfigurableQuestionTypes(),
        ]);
    }

    public function getConfig(): Config
    {
        $raw_config = \Config::getConfigurationValues(
            'advancedforms',
            [
                self::CONFIG_ENABLE_QUESTION_TYPE_IP,
                self::CONFIG_ENABLE_QUESTION_TYPE_HOSTNAME,
                self::CONFIG_ENABLE_QUESTION_TYPE_HIDDEN,
            ],
        );

        return new Config(
            enable_ip_address_question_type: ($raw_config[self::CONFIG_ENABLE_QUESTION_TYPE_IP] ?? false) == 1,
            enable_hostname_question_type: ($raw_config[self::CONFIG_ENABLE_QUESTION_TYPE_HOSTNAME] ?? false) == 1,
            enable_hidden_question_type: ($raw_config[self::CONFIG_ENABLE_QUESTION_TYPE_HIDDEN] ?? false) == 1,
        );
    }

    /** @return \Glpi\Form\QuestionType\QuestionTypeInterface[] */
    public function getEnabledQuestionsTypes(): array
    {
        $types = [];
        $config = $this->getConfig();

        if ($config->isIpAddressQuestionTypeEnabled()) {
            $types[] = new IpAddressQuestion();
        }
        if ($config->isHostnameQuestionTypeEnabled()) {
            $types[] = new HostnameQuestion();
        }
        if ($config->isHiddenQuestionTypeEnabled()) {
            $types[] = new HiddenQuestion();
        }

        return $types;
    }

    /** @return array<ConfigurableItemInterface> */
    private function getConfigurableQuestionTypes(): array
    {
        return [
            new IpAddressQuestion(),
            new HostnameQuestion(),
            new HiddenQuestion(),
        ];
    }
}
