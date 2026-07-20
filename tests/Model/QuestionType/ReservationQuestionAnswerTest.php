<?php

namespace GlpiPlugin\Advancedforms\Tests\Model\QuestionType;

use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestionAnswer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ReservationQuestionAnswerTest extends TestCase
{
    public function testFromArrayAndGetters(): void
    {
        $answer = ReservationQuestionAnswer::fromArray([
            'reservationitems_id' => 123,
            'begin' => '2026-01-15 09:00:00',
            'end' => '2026-01-15 12:00:00',
        ]);

        $this->assertSame(123, $answer->getReservationItemsId());
        $this->assertSame('2026-01-15 09:00:00', $answer->getBegin());
        $this->assertSame('2026-01-15 12:00:00', $answer->getEnd());
        $this->assertSame([
            'reservationitems_id' => 123,
            'begin' => '2026-01-15 09:00:00',
            'end' => '2026-01-15 12:00:00',
        ], $answer->toArray());
    }

    public function testFromArrayThrowsOnMissingKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReservationQuestionAnswer::fromArray(['reservationitems_id' => 123]);
    }

    public function testIsValidRange(): void
    {
        $valid = ReservationQuestionAnswer::fromArray([
            'reservationitems_id' => 1, 'begin' => '2026-01-15 09:00:00', 'end' => '2026-01-15 12:00:00',
        ]);
        $this->assertTrue($valid->isValidRange());

        $invalid = ReservationQuestionAnswer::fromArray([
            'reservationitems_id' => 1, 'begin' => '2026-01-15 12:00:00', 'end' => '2026-01-15 09:00:00',
        ]);
        $this->assertFalse($invalid->isValidRange());
    }
}
