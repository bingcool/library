<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Sms;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Sms\Exception\InvalidConfigException;
use Swoolefy\Library\Sms\Exception\InvalidPhoneException;
use Swoolefy\Library\Sms\Support\PhoneNormalizer;

/**
 * 四地区号码归一化：大陆 / 香港 / 澳门 / 台湾 → E.164。
 */
final class PhoneNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function mainlandProvider(): array
    {
        return [
            'bare' => ['13800008000', '+8613800008000'],
            'plus' => ['+8613800008000', '+8613800008000'],
            'country code' => ['8613800008000', '+8613800008000'],
            '00 prefix' => ['008613800008000', '+8613800008000'],
            'formatted' => ['+86 138-0000-8000', '+8613800008000'],
            'paren country code' => ['(86)13800008000', '+8613800008000'],
        ];
    }

    #[DataProvider('mainlandProvider')]
    public function testMainlandNormalizesToE164(string $input, string $expected): void
    {
        self::assertSame($expected, PhoneNormalizer::toE164($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function hongKongProvider(): array
    {
        return [
            'plus' => ['+85261234567', '+85261234567'],
            'country code' => ['85261234567', '+85261234567'],
            '00 prefix' => ['0085261234567', '+85261234567'],
            'formatted' => ['+852 6123 4567', '+85261234567'],
            'fixed line' => ['+852 2123 4567', '+85221234567'],
            'bare with default region' => ['61234567', '+85261234567'],
        ];
    }

    #[DataProvider('hongKongProvider')]
    public function testHongKongNormalizesToE164(string $input, string $expected): void
    {
        self::assertSame($expected, PhoneNormalizer::toE164($input, 'HK'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function macauProvider(): array
    {
        return [
            'plus' => ['+85366123456', '+85366123456'],
            'country code' => ['85366123456', '+85366123456'],
            '00 prefix' => ['0085366123456', '+85366123456'],
            'fixed line' => ['+853 2812 3456', '+85328123456'],
            'bare with default region' => ['66123456', '+85366123456'],
        ];
    }

    #[DataProvider('macauProvider')]
    public function testMacauNormalizesToE164(string $input, string $expected): void
    {
        self::assertSame($expected, PhoneNormalizer::toE164($input, 'MO'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function taiwanProvider(): array
    {
        return [
            'plus' => ['+886912345678', '+886912345678'],
            'country code' => ['886912345678', '+886912345678'],
            '00 prefix' => ['00886912345678', '+886912345678'],
            'formatted' => ['+886 912 345 678', '+886912345678'],
            'local trunk 0' => ['0912345678', '+886912345678'],
            'trunk 0 after country code' => ['+8860912345678', '+886912345678'],
            'landline with area code' => ['+886 2 2345 6789', '+886223456789'],
        ];
    }

    #[DataProvider('taiwanProvider')]
    public function testTaiwanNormalizesToE164(string $input, string $expected): void
    {
        self::assertSame($expected, PhoneNormalizer::toE164($input, 'TW'));
    }

    public function testBareEightDigitNeedsHongKongOrMacauDefault(): void
    {
        $this->expectException(InvalidPhoneException::class);
        $this->expectExceptionMessage('Ambiguous 8-digit phone');

        PhoneNormalizer::toE164('61234567');
    }

    public function testBareEightDigitUsesConfiguredDefaultRegion(): void
    {
        // 6 开头的 8 位号码香港和澳门都合法，只能由默认地区决定
        self::assertSame('+85261234567', PhoneNormalizer::toE164('61234567', 'HK'));
        self::assertSame('+85361234567', PhoneNormalizer::toE164('61234567', 'MO'));
    }

    public function testNumbersOutsideFourRegionsRequireInternationalPrefix(): void
    {
        self::assertSame('+14155552671', PhoneNormalizer::toE164('+1 415-555-2671'));
        self::assertSame('+442071838750', PhoneNormalizer::toE164('00442071838750'));

        $this->expectException(InvalidPhoneException::class);
        PhoneNormalizer::toE164('442071838750');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPhoneProvider(): array
    {
        return [
            'empty' => [''],
            'letters' => ['not-a-phone'],
            'too short' => ['12345'],
            'mainland wrong prefix' => ['+8612345678901'],
            'mainland too short' => ['+861380008'],
            'hong kong wrong prefix' => ['+85212345678'],
            'macau wrong prefix' => ['+85312345678'],
            'taiwan wrong prefix' => ['+886112345678'],
            'taiwan too short' => ['+88691234567'],
        ];
    }

    #[DataProvider('invalidPhoneProvider')]
    public function testInvalidNumbersRejected(string $phone): void
    {
        $this->expectException(InvalidPhoneException::class);
        PhoneNormalizer::toE164($phone);
    }

    public function testNormalizeRegionAcceptsNamesAndCountryCodes(): void
    {
        foreach (['CN', 'cn', '86', '+86', '0086'] as $region) {
            self::assertSame('CN', PhoneNormalizer::normalizeRegion($region), $region);
        }
        foreach (['HK', 'hk', '852', '+852'] as $region) {
            self::assertSame('HK', PhoneNormalizer::normalizeRegion($region), $region);
        }
        foreach (['MO', 'mo', '853', '+853'] as $region) {
            self::assertSame('MO', PhoneNormalizer::normalizeRegion($region), $region);
        }
        foreach (['TW', 'tw', '886', '+886'] as $region) {
            self::assertSame('TW', PhoneNormalizer::normalizeRegion($region), $region);
        }
    }

    public function testNormalizeRegionRejectsUnsupportedValue(): void
    {
        $this->expectException(InvalidConfigException::class);
        PhoneNormalizer::normalizeRegion('US');
    }

    public function testRegionOfRecognizesFourRegionsAndPassesOthers(): void
    {
        self::assertSame('CN', PhoneNormalizer::regionOf('+8613800008000'));
        self::assertSame('HK', PhoneNormalizer::regionOf('+85261234567'));
        self::assertSame('MO', PhoneNormalizer::regionOf('+85366123456'));
        self::assertSame('TW', PhoneNormalizer::regionOf('+886912345678'));
        self::assertNull(PhoneNormalizer::regionOf('+14155552671'));
    }
}
