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
use Glpi\Form\QuestionType\QuestionTypesManager;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Advancedforms\Model\Config\ConfigTab;
use GlpiPlugin\Advancedforms\Model\QuestionType\AdvancedCategory;
use GlpiPlugin\Advancedforms\Model\QuestionType\IpAddressQuestion;
use Plugin;

final class InitManager
{
    use SingletonServiceTrait;

    public function init(): void
    {
        $this->registerConfiguration();
        $this->registerPluginTypes();
    }

    private function registerConfiguration(): void
    {
        global $PLUGIN_HOOKS;

        // Register config url
        $config_class = ConfigTab::class;
        $url = '../../front/config.form.php?forcetab=' . $config_class . '$1';

        // @phpstan-ignore offsetAccess.nonOffsetAccessible (we don't have type hint for this array at this time)
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['advancedforms'] = $url;

        // Add configuration tab
        Plugin::registerClass(ConfigTab::class, [
            'addtabon' => Config::class,
        ]);
    }

    private function registerPluginTypes(): void
    {
        // Get config
        $config = ConfigManager::getInstance()->getConfig();

        // Register questions types
        $types = QuestionTypesManager::getInstance();
        if ($config->isIpAddressQuestionTypeEnabled()) {
            $types->registerPluginCategory(new AdvancedCategory());
            $types->registerPluginQuestionType(new IpAddressQuestion());
        }
    }
}
