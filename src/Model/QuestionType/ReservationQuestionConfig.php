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

final readonly class ReservationQuestionConfig implements JsonFieldInterface
{
    // Unique reference to hardcoded name used for serialization
    public const ALLOWED_ITEMTYPES = 'allowed_itemtypes';

    public function __construct(
        private array $allowed_itemtypes = [],
    ) {}

    /**
     * @param array{
     *      allowed_itemtypes?: array<string>
     * } $data
     */
    #[Override]
    public static function jsonDeserialize(array $data): self
    {
        return new self(
            allowed_itemtypes: $data[self::ALLOWED_ITEMTYPES] ?? [],
        );
    }

    /**
     * @return array{
     *      allowed_itemtypes: array<string>
     * } $data
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            self::ALLOWED_ITEMTYPES => $this->allowed_itemtypes,
        ];
    }

    public function getAllowedItemtypes(): array
    {
        return $this->allowed_itemtypes;
    }

    public function getEffectiveAllowedItemtypes(): array
    {
        global $CFG_GLPI;

        if ($this->allowed_itemtypes !== []) {
            return $this->allowed_itemtypes;
        }

        return $CFG_GLPI['reservation_types'] ?? [];
    }
}
