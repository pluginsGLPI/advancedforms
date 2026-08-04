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

use Glpi\DBAL\JsonFieldInterface;
use Override;

final readonly class TableQuestionConfig implements JsonFieldInterface
{
    public const COLUMNS          = 'columns';

    // Column sub-keys
    public const COL_NAME          = 'name';

    public const COL_QUESTION_TYPE = 'question_type';

    public const COL_REQUIRED      = 'required';

    public const COL_ITEMTYPE      = 'itemtype';

    public const COL_PATTERN       = 'pattern';

    /**
     * @param array<array{name: string, question_type: string, required: bool, itemtype: string, pattern: string}> $columns
     */
    public function __construct(
        private array $columns = [],
    ) {}

    /**
     * Row bounds used to live here as `min_rows` / `max_rows`. They are now
     * declared as native validation conditions, so those keys are ignored when
     * they are found in data written by version 1.2.0.
     *
     * @param array{
     *   columns?: array<array{name?: string, question_type?: string, required?: bool, itemtype?: string, pattern?: string}>
     * } $data
     */
    #[Override]
    public static function jsonDeserialize(array $data): self
    {
        $columns = array_values(array_map(
            fn($col) => [
                self::COL_NAME          => (string) ($col[self::COL_NAME] ?? ''),
                self::COL_QUESTION_TYPE => (string) ($col[self::COL_QUESTION_TYPE] ?? ''),
                self::COL_REQUIRED      => (bool) ($col[self::COL_REQUIRED] ?? false),
                self::COL_ITEMTYPE      => (string) ($col[self::COL_ITEMTYPE] ?? ''),
                self::COL_PATTERN       => (string) ($col[self::COL_PATTERN] ?? ''),
            ],
            array_filter($data[self::COLUMNS] ?? [], is_array(...)),
        ));

        return new self(columns: $columns);
    }

    /**
     * @return array{
     *   columns: array<array{name: string, question_type: string, required: bool, itemtype: string, pattern: string}>
     * }
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            self::COLUMNS => $this->columns,
        ];
    }

    /** @return array<array{name: string, question_type: string, required: bool, itemtype: string, pattern: string}> */
    public function getColumns(): array
    {
        return $this->columns;
    }
}
