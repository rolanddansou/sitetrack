<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Analytics;

use App\Infrastructure\Analytics\CountryNameResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CountryNameResolverTest extends TestCase
{
    #[DataProvider('provideCases')]
    public function testResolve(?string $code, ?string $expected): void
    {
        $resolver = new CountryNameResolver();

        $this->assertSame($expected, $resolver->resolve($code));
    }

    public static function provideCases(): array
    {
        return [
            'known code' => ['BJ', 'Bénin'],
            'another known code' => ['FR', 'France'],
            'null passes through' => [null, null],
            'unmapped code falls back to the raw code' => ['ZZ', 'ZZ'],
        ];
    }
}
