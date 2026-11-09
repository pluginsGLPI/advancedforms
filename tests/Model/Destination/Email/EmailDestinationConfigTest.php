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

namespace GlpiPlugin\Advancedforms\Tests\Model\Destination\Email;

use Glpi\Form\Destination\FormDestinationInterface;
use Glpi\Form\Destination\FormDestinationManager;
use GlpiPlugin\Advancedforms\Model\Destination\Email\EmailDestination;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use GlpiPlugin\Advancedforms\Service\InitManager;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;

final class EmailDestinationConfigTest extends AdvancedFormsTestCase
{
    public function testDestinationIsNotRegisteredWhenDisabled(): void
    {
        // Arrange: start from a fresh destination manager
        $this->deleteSingletonInstance([FormDestinationManager::class]);

        // Act: init the plugin with the destination disabled
        $this->disableConfigurableItem(new EmailDestination());

        // Assert: the destination type is not available
        $this->assertCount(0, $this->getRegisteredEmailDestinations());
    }

    public function testDestinationIsRegisteredOnceWhenEnabled(): void
    {
        // Arrange: start from a fresh destination manager
        $this->deleteSingletonInstance([FormDestinationManager::class]);

        // Act: init the plugin twice with the destination enabled
        $this->enableConfigurableItem(new EmailDestination());
        InitManager::getInstance()->init();

        // Assert: the destination type is available once
        $this->assertCount(1, $this->getRegisteredEmailDestinations());
        $values = FormDestinationManager::getInstance()->getDestinationTypesDropdownValues();
        $this->assertSame('Email', $values[EmailDestination::class]);
    }

    public function testConfigurableDestinations(): void
    {
        $manager = ConfigManager::getInstance();

        $destinations = $manager->getConfigurableDestinations();
        $this->assertCount(1, $destinations);
        $this->assertInstanceOf(EmailDestination::class, current($destinations));

        $this->disableConfigurableItem(new EmailDestination());
        $this->assertCount(0, $manager->getEnabledDestinations());

        $this->enableConfigurableItem(new EmailDestination());
        $this->assertCount(1, $manager->getEnabledDestinations());
    }

    /** @return EmailDestination[] */
    private function getRegisteredEmailDestinations(): array
    {
        return array_filter(
            FormDestinationManager::getInstance()->getDestinationTypes(),
            fn(FormDestinationInterface $type): bool => $type instanceof EmailDestination,
        );
    }
}
