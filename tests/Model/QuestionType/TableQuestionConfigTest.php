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

use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestionConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;

final class TableQuestionConfigTest extends AdvancedFormsTestCase
{
    public function testDefaultValues(): void
    {
        $config = new TableQuestionConfig();
        $this->assertSame([], $config->getColumns());
        $this->assertSame(1, $config->getMinRows());
        $this->assertSame(50, $config->getMaxRows());
    }

    public function testJsonRoundtrip(): void
    {
        $original = new TableQuestionConfig(
            columns: [
                ['name' => 'Source IP', 'question_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeShortText', 'required' => true,  'itemtype' => '', 'pattern' => '/^172\\.23\\./'],
                ['name' => 'Port',      'question_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeNumber',    'required' => false, 'itemtype' => '', 'pattern' => ''],
            ],
            min_rows: 2,
            max_rows: 20,
        );
        $serialized   = $original->jsonSerialize();
        $deserialized = TableQuestionConfig::jsonDeserialize($serialized);
        $this->assertSame($original->getColumns(), $deserialized->getColumns());
        $this->assertSame($original->getMinRows(), $deserialized->getMinRows());
        $this->assertSame($original->getMaxRows(), $deserialized->getMaxRows());
    }

    public function testColumnPatternDefaultsToEmptyStringWhenAbsent(): void
    {
        $config = TableQuestionConfig::jsonDeserialize([
            'columns' => [
                ['name' => 'Source IP', 'question_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeShortText', 'required' => true],
            ],
        ]);

        $this->assertSame('', $config->getColumns()[0][TableQuestionConfig::COL_PATTERN]);
    }

    public function testJsonDeserializeFiltersNonArrayColumns(): void
    {
        $config = TableQuestionConfig::jsonDeserialize([
            'columns'  => ['not_array', ['name' => 'Valid', 'question_type' => 'SomeFqcn', 'required' => false]],
            'min_rows' => 1,
            'max_rows' => 50,
        ]);
        $this->assertCount(1, $config->getColumns());
        $this->assertSame('Valid', $config->getColumns()[0]['name']);
    }

    public function testJsonDeserializeEnforcesMinRow(): void
    {
        $config = TableQuestionConfig::jsonDeserialize(['min_rows' => 0, 'max_rows' => 10]);
        $this->assertSame(1, $config->getMinRows());
    }

    public function testJsonDeserializeEnforcesMaxRowNotZero(): void
    {
        $config = TableQuestionConfig::jsonDeserialize(['min_rows' => 1, 'max_rows' => 0]);
        $this->assertSame(1, $config->getMaxRows());
    }

    public function testJsonDeserializeEnforcesMaxRowNotLessThanMin(): void
    {
        $config = TableQuestionConfig::jsonDeserialize(['min_rows' => 10, 'max_rows' => 5]);
        $this->assertSame(10, $config->getMaxRows());
    }

    public function testJsonDeserializePreservesMaxRowAtCap(): void
    {
        $config = TableQuestionConfig::jsonDeserialize(['min_rows' => 1, 'max_rows' => 50]);
        $this->assertSame(50, $config->getMaxRows());
    }
}
