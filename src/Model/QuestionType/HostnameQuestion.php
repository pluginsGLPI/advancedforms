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

use Glpi\Application\View\TemplateRenderer;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionType;
use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use GlpiPlugin\Advancedforms\Model\Config\Config;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use Override;
use Toolbox;

final class HostnameQuestion extends AbstractQuestionType implements ConfigurableItemInterface
{
    #[Override]
    public function getCategory(): QuestionTypeCategoryInterface
    {
        return new AdvancedCategory();
    }

    #[Override]
    public function getName(): string
    {
        return __('Hostname', 'advancedforms');
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-label';
    }

    #[Override]
    public function getWeight(): int
    {
        return 20;
    }

    #[Override]
    public function renderAdministrationTemplate(Question|null $question): string
    {
        $template = <<<TWIG
            <input
                class="form-control"
                type="text"
                name="default_value"
                placeholder="{{ input_placeholder }}"
                value="{{ question is not null ? question.fields.default_value : '' }}"
                disabled
            />
TWIG;

        $twig = TemplateRenderer::getInstance();
        return $twig->renderFromStringTemplate($template, [
            'question'          => $question,
            'input_placeholder' => "hostname",
        ]);
    }

    #[Override]
    public function renderEndUserTemplate(Question|null $question): string
    {
        $template = <<<TWIG
            <input
                type="hidden"
                name="{{ question.getEndUserInputName() }}"
                value="{{ hostname }}"
            >
TWIG;

        $twig = TemplateRenderer::getInstance();
        return $twig->renderFromStringTemplate($template, [
            'question' => $question,
            'hostname' => gethostbyaddr(Toolbox::getRemoteIpAddress()),
        ]);
    }

    #[Override]
    public function isHiddenInput(): bool
    {
        return true;
    }

    #[Override]
    public function getConfigKey(): string
    {
        return ConfigManager::CONFIG_ENABLE_QUESTION_TYPE_HOSTNAME;
    }

    #[Override]
    public function getConfigTitle(): string
    {
        return __("Hostname question type", 'advancedforms');
    }

    #[Override]
    public function getConfigDescription(): string
    {
        return __("This question type will automatically register the user's hostname.", 'advancedforms');
    }

    #[Override]
    public function getConfigIcon(): string
    {
        return $this->getIcon();
    }

    #[Override]
    public function isConfigEnabled(Config $config): bool
    {
        return $config->isHostnameQuestionTypeEnabled();
    }
}
