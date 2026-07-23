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

namespace GlpiPlugin\Advancedforms\Model\Destination;

use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\Destination\ConfigFieldWithStrategiesInterface;
use Glpi\Form\Destination\HasFieldWithQuestionId;
use Override;

#[HasFieldWithQuestionId(self::QUESTION_ID)]
final readonly class PreReservationFieldConfig implements JsonFieldInterface, ConfigFieldWithStrategiesInterface
{
    public const STRATEGY = 'strategy';

    public const QUESTION_ID = 'question_id';

    public const REQUIRE_APPROVAL = 'require_approval';

    public function __construct(
        private PreReservationFieldStrategy $strategy,
        private ?int $question_id = null,
        private bool $require_approval = true,
    ) {}

    /**
     * @param array{
     *      strategy?: string,
     *      question_id?: ?int,
     *      require_approval?: bool
     * } $data
     */
    #[Override]
    public static function jsonDeserialize(array $data): self
    {
        $strategy = PreReservationFieldStrategy::tryFrom($data[self::STRATEGY] ?? "");
        if ($strategy === null) {
            $strategy = PreReservationFieldStrategy::NO_PRERESERVATION;
        }

        return new self(
            strategy: $strategy,
            question_id: $data[self::QUESTION_ID] ?? null,
            require_approval: $data[self::REQUIRE_APPROVAL] ?? true,
        );
    }

    /**
     * @return array{
     *      strategy: string,
     *      question_id: ?int,
     *      require_approval: bool
     * }
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            self::STRATEGY => $this->strategy->value,
            self::QUESTION_ID => $this->question_id,
            self::REQUIRE_APPROVAL => $this->require_approval,
        ];
    }

    #[Override]
    public static function getStrategiesInputName(): string
    {
        return self::STRATEGY;
    }

    /** @return array<PreReservationFieldStrategy> */
    #[Override]
    public function getStrategies(): array
    {
        return [$this->strategy];
    }

    public function getQuestionId(): ?int
    {
        return $this->question_id;
    }

    public function isApprovalRequired(): bool
    {
        return $this->require_approval;
    }
}
