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

namespace GlpiPlugin\Advancedforms\Command;

use Config;
use Glpi\Console\AbstractCommand;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use Override;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DisableFeatureCommand extends AbstractCommand
{
    #[Override]
    protected function configure(): void
    {
        $this->setName('plugins:advancedforms:disable');
        $this->setDescription(__('Disable a feature of the advancedforms plugin.', 'advancedforms'));
        $this->setHelp(
            __('This command disables a specific feature (question type) of the advancedforms plugin.', 'advancedforms')
            . "\n\n"
            . __('Available features:', 'advancedforms')
            . "\n"
            . implode("\n", array_map(
                fn($type) => sprintf('  - <info>%s</info>: %s', $type::getConfigKey(), $type->getConfigTitle()),
                ConfigManager::getInstance()->getConfigurableQuestionTypes(),
            ))
        );

        $this->addArgument(
            'feature',
            InputArgument::REQUIRED,
            __('The feature config key to disable (e.g., enable_question_type_ip_address).', 'advancedforms'),
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $feature = $input->getArgument('feature');
        $config_manager = ConfigManager::getInstance();

        $valid_keys = array_map(
            fn($type) => $type::getConfigKey(),
            $config_manager->getConfigurableQuestionTypes(),
        );

        if (!in_array($feature, $valid_keys, true)) {
            $output->writeln(sprintf(
                '<error>' . __('Invalid feature key "%s".', 'advancedforms') . '</error>',
                $feature,
            ));
            $output->writeln(__('Available features:', 'advancedforms'));
            foreach ($config_manager->getConfigurableQuestionTypes() as $type) {
                $output->writeln(sprintf('  - <info>%s</info>: %s', $type::getConfigKey(), $type->getConfigTitle()));
            }
            return Command::FAILURE;
        }

        Config::setConfigurationValues('advancedforms', [
            $feature => 0,
        ]);

        $output->writeln(sprintf(
            '<info>' . __('Feature "%s" has been disabled.', 'advancedforms') . '</info>',
            $feature,
        ));

        return Command::SUCCESS;
    }
}
