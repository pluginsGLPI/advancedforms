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

use InvalidArgumentException;

use function Safe\strtotime;

final readonly class ReservationQuestionAnswer
{
    public function __construct(
        private int $reservationitems_id,
        private string $begin,
        private string $end,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['reservationitems_id', 'begin', 'end'] as $key) {
            if (!isset($data[$key]) || $data[$key] === '') {
                throw new InvalidArgumentException('Missing or empty key: ' . $key);
            }
        }

        $reservationitems_id = $data['reservationitems_id'];
        $begin = $data['begin'];
        $end = $data['end'];

        if (!is_numeric($reservationitems_id) || !is_string($begin) || !is_string($end)) {
            throw new InvalidArgumentException('Invalid type for reservation answer data');
        }

        return new self(
            reservationitems_id: (int) $reservationitems_id,
            begin: $begin,
            end: $end,
        );
    }

    /** @return array{reservationitems_id: int, begin: string, end: string} */
    public function toArray(): array
    {
        return [
            'reservationitems_id' => $this->reservationitems_id,
            'begin' => $this->begin,
            'end' => $this->end,
        ];
    }

    public function getReservationItemsId(): int
    {
        return $this->reservationitems_id;
    }

    public function getBegin(): string
    {
        return $this->begin;
    }

    public function getEnd(): string
    {
        return $this->end;
    }

    public function isValidRange(): bool
    {
        return strtotime($this->begin) < strtotime($this->end);
    }
}
