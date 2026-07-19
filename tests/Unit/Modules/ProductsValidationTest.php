<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Modules\Products;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductsValidationTest extends TestCase
{
    #[DataProvider('invalidProductInputProvider')]
    public function testCreateRejectsInvalidNumericAndMediaInputs(array $data, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new Products())->create(array_merge(['name' => 'Consulting'], $data));
    }

    public static function invalidProductInputProvider(): array
    {
        return [
            'non numeric price' => [['unit_price' => 'free-ish'], 'Unit price must be a valid number.'],
            'negative price' => [['unit_price' => '-1'], 'Unit price cannot be negative.'],
            'fractional display order' => [['display_order' => '1.5'], 'Display order must be a non-negative whole number.'],
            'negative display order' => [['display_order' => '-1'], 'Display order must be a non-negative whole number.'],
            'script media URL' => [['product_demo_video_url' => 'javascript:alert(1)'], 'Product media URL must use HTTP or HTTPS.'],
            'protocol relative URL' => [['product_image_url' => '//evil.example/image.png'], 'Product media URL is invalid.'],
        ];
    }
}
