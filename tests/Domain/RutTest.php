<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Domain\Rut;

class RutTest extends TestCase
{
    public function test_clean_removes_non_numeric_characters(): void
    {
        $this->assertSame('12345678K', Rut::clean('12.345.678-k'));
    }

    public function test_is_valid_returns_true_for_valid_rut(): void
    {
        $this->assertTrue(Rut::isValid('76.192.083-9'));
    }

    public function test_is_valid_returns_false_for_invalid_rut(): void
    {
        $this->assertFalse(Rut::isValid('76.192.083-1'));
    }

    public function test_format_outputs_standard_representation(): void
    {
        $this->assertSame('76.192.083-9', Rut::format('761920839'));
    }

    public function test_is_generic_detects_placeholder_rut(): void
    {
        $this->assertTrue(Rut::isGeneric('66.666.666-6'));
        $this->assertFalse(Rut::isGeneric('76.192.083-9'));
    }
}
