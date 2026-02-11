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

namespace GlpiPlugin\Advancedforms\Tests\Command;

use GlpiPlugin\Advancedforms\Command\DisableFeatureCommand;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DisableFeatureCommandTest extends AdvancedFormsTestCase
{
    #[DataProvider('provideQuestionTypes')]
    public function testDisableFeature(ConfigurableItemInterface $item): void
    {
        // Arrange: enable the feature first
        $this->enableConfigurableItem($item);
        $this->assertTrue(
            ConfigManager::getInstance()->isConfigurableItemEnabled($item),
        );

        // Act: run the disable command
        $command = new DisableFeatureCommand();
        $tester = new CommandTester($command);
        $tester->execute(['feature' => $item::getConfigKey()]);

        // Assert: command succeeded and feature is disabled
        $this->assertEquals(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFalse(
            ConfigManager::getInstance()->isConfigurableItemEnabled($item),
        );
        $this->assertStringContainsString(
            $item::getConfigKey(),
            $tester->getDisplay(),
        );
    }

    public function testDisableInvalidFeature(): void
    {
        // Act: run the disable command with an invalid feature key
        $command = new DisableFeatureCommand();
        $tester = new CommandTester($command);
        $tester->execute(['feature' => 'invalid_feature_key']);

        // Assert: command failed
        $this->assertEquals(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString(
            'invalid_feature_key',
            $tester->getDisplay(),
        );
    }
}
