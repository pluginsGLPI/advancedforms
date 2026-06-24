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

    public const MIN_ROWS         = 'min_rows';

    public const MAX_ROWS         = 'max_rows';

    // Column sub-keys
    public const COL_NAME          = 'name';

    public const COL_QUESTION_TYPE = 'question_type';

    public const COL_REQUIRED      = 'required';

    public const COL_ITEMTYPE      = 'itemtype';

    /**
     * @param array<array{name: string, question_type: string, required: bool, itemtype: string}> $columns
     */
    public function __construct(
        private array $columns  = [],
        private int   $min_rows = 1,
        private int   $max_rows = 50,
    ) {}

    /**
     * @param array{
     *   columns?: array<array{name?: string, question_type?: string, required?: bool, itemtype?: string}>,
     *   min_rows?: int,
     *   max_rows?: int
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
            ],
            array_filter($data[self::COLUMNS] ?? [], is_array(...)),
        ));

        return new self(
            columns: $columns,
            min_rows: max(1, (int) ($data[self::MIN_ROWS] ?? 1)),
            max_rows: max(1, (int) ($data[self::MAX_ROWS] ?? 50)),
        );
    }

    /**
     * @return array{
     *   columns: array<array{name: string, question_type: string, required: bool, itemtype: string}>,
     *   min_rows: int,
     *   max_rows: int
     * }
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            self::COLUMNS  => $this->columns,
            self::MIN_ROWS => $this->min_rows,
            self::MAX_ROWS => $this->max_rows,
        ];
    }

    /** @return array<array{name: string, question_type: string, required: bool, itemtype: string}> */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getMinRows(): int
    {
        return $this->min_rows;
    }

    public function getMaxRows(): int
    {
        return $this->max_rows;
    }
}
