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

namespace GlpiPlugin\Advancedforms\Tests\Model\QuestionType;

use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Form\QuestionType\QuestionTypeNumber;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestionConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;

final class TableQuestionConfigTest extends AdvancedFormsTestCase
{
    public function testDefaultValues(): void
    {
        $config = new TableQuestionConfig();
        $this->assertSame([], $config->getColumns());
    }

    public function testJsonRoundtrip(): void
    {
        $original = new TableQuestionConfig(
            columns: [
                ['name' => 'Source IP', 'question_type' => QuestionTypeShortText::class, 'required' => true,  'itemtype' => '', 'pattern' => '/^172\\.23\\./'],
                ['name' => 'Port',      'question_type' => QuestionTypeNumber::class,    'required' => false, 'itemtype' => '', 'pattern' => ''],
            ],
        );
        $serialized   = $original->jsonSerialize();
        $deserialized = TableQuestionConfig::jsonDeserialize($serialized);
        $this->assertSame($original->getColumns(), $deserialized->getColumns());
    }

    public function testSerializedFormCarriesNoRowBounds(): void
    {
        $serialized = (new TableQuestionConfig())->jsonSerialize();

        $this->assertSame([TableQuestionConfig::COLUMNS], array_keys($serialized));
    }

    public function testColumnPatternDefaultsToEmptyStringWhenAbsent(): void
    {
        $config = TableQuestionConfig::jsonDeserialize([
            'columns' => [
                ['name' => 'Source IP', 'question_type' => QuestionTypeShortText::class, 'required' => true],
            ],
        ]);

        $this->assertSame('', $config->getColumns()[0][TableQuestionConfig::COL_PATTERN]);
    }

    public function testJsonDeserializeFiltersNonArrayColumns(): void
    {
        $config = TableQuestionConfig::jsonDeserialize([
            'columns' => ['not_array', ['name' => 'Valid', 'question_type' => 'SomeFqcn', 'required' => false]],
        ]);
        $this->assertCount(1, $config->getColumns());
        $this->assertSame('Valid', $config->getColumns()[0]['name']);
    }

    /**
     * Row bounds moved to native validation conditions. Configurations written by
     * 1.2.0 still carry them, and must deserialize without complaint.
     */
    public function testLegacyRowBoundsAreIgnored(): void
    {
        $config = TableQuestionConfig::jsonDeserialize([
            'columns'  => [
                ['name' => 'Source IP', 'question_type' => QuestionTypeShortText::class, 'required' => true],
            ],
            'min_rows' => 2,
            'max_rows' => 20,
        ]);

        $this->assertCount(1, $config->getColumns());
        $this->assertSame('Source IP', $config->getColumns()[0][TableQuestionConfig::COL_NAME]);
        $this->assertArrayNotHasKey('min_rows', $config->jsonSerialize());
        $this->assertArrayNotHasKey('max_rows', $config->jsonSerialize());
    }
}
