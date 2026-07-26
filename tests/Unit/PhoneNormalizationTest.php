<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PhoneNormalizationTest extends TestCase
{
    public function testNormalizePhoneDigitsMxReturnsDigitsForValidInput(): void
    {
        $this->assertSame('3318635185', normalizePhoneDigitsMx('(331) 863-5185'));
    }

    public function testNormalizePhoneDigitsMxReturnsNullForInvalidLength(): void
    {
        $this->assertNull(normalizePhoneDigitsMx('331863518'));
    }

    public function testNormalizePhoneDigitsMxReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', normalizePhoneDigitsMx(''));
    }

    public function testFormatPhoneMxDigitsReturnsMexicanFormat(): void
    {
        $this->assertSame('(331) - 863 - 5185', formatPhoneMxDigits('3318635185'));
    }

    public function testNormalizePhoneMxReturnsFormattedPhone(): void
    {
        $this->assertSame('(331) - 863 - 5185', normalizePhoneMx('(331) 863-5185'));
    }

    public function testNormalizePhoneMxReturnsNullForInvalidLength(): void
    {
        $this->assertNull(normalizePhoneMx('331863518'));
    }
}