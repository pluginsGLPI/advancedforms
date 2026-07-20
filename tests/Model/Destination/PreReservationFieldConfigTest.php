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

namespace GlpiPlugin\Advancedforms\Tests\Model\Destination;

use GlpiPlugin\Advancedforms\Model\Destination\PreReservationFieldConfig;
use GlpiPlugin\Advancedforms\Model\Destination\PreReservationFieldStrategy;
use PHPUnit\Framework\TestCase;

class PreReservationFieldConfigTest extends TestCase
{
    public function testJsonRoundTripWithDefaults(): void
    {
        $config = new PreReservationFieldConfig(PreReservationFieldStrategy::NO_PRERESERVATION);
        $rebuilt = PreReservationFieldConfig::jsonDeserialize($config->jsonSerialize());

        $this->assertSame(PreReservationFieldStrategy::NO_PRERESERVATION, $rebuilt->getStrategies()[0]);
        $this->assertNull($rebuilt->getQuestionId());
        $this->assertTrue($rebuilt->isApprovalRequired());
    }

    public function testJsonRoundTripFromSpecificQuestion(): void
    {
        $config = new PreReservationFieldConfig(
            PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION,
            question_id: 42,
            require_approval: false,
        );
        $rebuilt = PreReservationFieldConfig::jsonDeserialize($config->jsonSerialize());

        $this->assertSame(PreReservationFieldStrategy::FROM_SPECIFIC_QUESTION, $rebuilt->getStrategies()[0]);
        $this->assertSame(42, $rebuilt->getQuestionId());
        $this->assertFalse($rebuilt->isApprovalRequired());
    }
}
