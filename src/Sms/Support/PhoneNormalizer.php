<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Support;

use Swoolefy\Library\Sms\Exception\InvalidConfigException;
use Swoolefy\Library\Sms\Exception\InvalidPhoneException;

/**
 * 号码归一化：统一产出 E.164（`+国家码` + 国内号码）。
 *
 * 支持中国大陆（+86）、中国香港（+852）、中国澳门（+853）、中国台湾（+886）。
 * 四地之外的号码必须带 `+` 或 `00` 国际前缀，原样保留为 E.164。
 *
 * 裸号码的归属：
 * - 大陆 11 位、台湾本地 `09xxxxxxxx` 可从号段唯一识别；
 * - 香港 / 澳门本地都是 8 位，无法从号段区分，必须由 `$defaultRegion` 指定（HK / MO）。
 *
 * 注意：大陆手机号与部分美国号码（`1` + 10 位）在裸 11 位时存在天然歧义，
 * 本类按「裸号码 = 默认地区」处理；境外号码请显式使用 `+` / `00` 前缀。
 */
final class PhoneNormalizer
{
    public const REGION_CN = 'CN';

    public const REGION_HK = 'HK';

    public const REGION_MO = 'MO';

    public const REGION_TW = 'TW';

    public const DEFAULT_REGION = self::REGION_CN;

    /** @var array<string, string> 地区 → 国际区号 */
    private const REGION_CODES = [
        self::REGION_CN => '86',
        self::REGION_HK => '852',
        self::REGION_MO => '853',
        self::REGION_TW => '886',
    ];

    /** @var array<string, string> 国际区号 → 地区；长区号在前，避免前缀误判 */
    private const CODE_REGIONS = [
        '886' => self::REGION_TW,
        '852' => self::REGION_HK,
        '853' => self::REGION_MO,
        '86' => self::REGION_CN,
    ];

    /**
     * 归一化为 E.164。
     *
     * 接受（以四地为例）：
     * - 大陆：`13800008000` / `+8613800008000` / `8613800008000` / `008613800008000`
     * - 香港：`+85261234567` / `85261234567` / `0085261234567`；裸 `61234567` 需默认地区 HK
     * - 澳门：`+85366123456` / `85366123456` / `0085366123456`；裸 `66123456` 需默认地区 MO
     * - 台湾：`+886912345678` / `886912345678` / `00886912345678` / 本地 `0912345678`
     *
     * @param string $phone 号码，可含空格 / `-` / `()` 分隔
     * @param string $defaultRegion 裸 8 位号码的默认地区：CN/HK/MO/TW 或 86/852/853/886
     * @throws InvalidPhoneException 号码非法、裸 8 位未指定 HK/MO 默认地区、或无法判断归属
     * @throws InvalidConfigException 默认地区配置非法
     */
    public static function toE164(string $phone, string $defaultRegion = self::DEFAULT_REGION): string
    {
        $original = $phone;
        $phone = preg_replace('/[\s\-()]/', '', $phone) ?? '';

        if ($phone === '') {
            throw new InvalidPhoneException('Phone number is empty');
        }

        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }

        if (str_starts_with($phone, '+')) {
            $digits = substr($phone, 1);
            if (!self::isDigits($digits, 7, 15)) {
                throw new InvalidPhoneException(sprintf('Invalid phone number: %s', $original));
            }

            return self::fromInternational($digits, $original);
        }

        if (!self::isDigits($phone, 7, 15)) {
            throw new InvalidPhoneException(sprintf('Invalid phone number: %s', $original));
        }

        // 已带国家码但漏写 `+`
        foreach (self::CODE_REGIONS as $code => $region) {
            $code = (string) $code;
            if (!str_starts_with($phone, $code)) {
                continue;
            }
            $national = self::normalizeNational($region, (string) substr($phone, strlen($code)));
            if ($national !== null) {
                return '+' . $code . $national;
            }
        }

        // 大陆裸 11 位（容忍误加的本地 0）
        $mainland = self::normalizeNational(self::REGION_CN, $phone);
        if ($mainland !== null) {
            return '+86' . $mainland;
        }

        // 台湾本地 09xxxxxxxx，国际格式去掉本地 0
        if (preg_match('/^09\d{8}$/', $phone) === 1) {
            return '+886' . substr($phone, 1);
        }

        // 香港 / 澳门裸 8 位只能靠默认地区
        if (preg_match('/^\d{8}$/', $phone) === 1) {
            $region = self::normalizeRegion($defaultRegion);
            if ($region === self::REGION_HK || $region === self::REGION_MO) {
                $national = self::normalizeNational($region, $phone);
                if ($national !== null) {
                    return '+' . self::REGION_CODES[$region] . $national;
                }
            }

            throw new InvalidPhoneException(sprintf(
                'Ambiguous 8-digit phone: %s; use +852/+853 or set default_region to HK/MO',
                $original
            ));
        }

        throw new InvalidPhoneException(sprintf(
            'Unsupported phone number: %s; use E.164 (+country code) outside CN/HK/MO/TW',
            $original
        ));
    }

    /**
     * 规范化默认地区配置：接受 CN/HK/MO/TW（大小写不敏感）或 86/852/853/886（可带 `+` / `00`）。
     *
     * @throws InvalidConfigException 不支持的地区
     */
    public static function normalizeRegion(string $region): string
    {
        $value = strtoupper(trim($region));
        if (isset(self::REGION_CODES[$value])) {
            return $value;
        }

        $value = preg_replace('/^(?:\+|00)/', '', $value) ?? '';
        if (isset(self::CODE_REGIONS[$value])) {
            return self::CODE_REGIONS[$value];
        }

        throw new InvalidConfigException(sprintf(
            'Unsupported phone region [%s]; expected CN/HK/MO/TW or 86/852/853/886',
            $region
        ));
    }

    /**
     * E.164 号码所属地区；四地之外返回 null。
     */
    public static function regionOf(string $e164): ?string
    {
        $digits = ltrim(trim($e164), '+');
        foreach (self::CODE_REGIONS as $code => $region) {
            if (str_starts_with($digits, (string) $code)) {
                return $region;
            }
        }

        return null;
    }

    /**
     * @throws InvalidPhoneException 已知地区但号码不符合该地区格式
     */
    private static function fromInternational(string $digits, string $original): string
    {
        foreach (self::CODE_REGIONS as $code => $region) {
            $code = (string) $code;
            if (!str_starts_with($digits, $code)) {
                continue;
            }
            $national = self::normalizeNational($region, (string) substr($digits, strlen($code)));
            if ($national === null) {
                throw new InvalidPhoneException(sprintf('Invalid %s phone number: %s', $region, $original));
            }

            return '+' . $code . $national;
        }

        // 四地之外：保留 E.164 原样，由厂商判断可达性
        return '+' . $digits;
    }

    /**
     * 去掉误加的本地 0 并校验国内号码格式。
     *
     * - CN：11 位手机号 `1[3-9]xxxxxxxxx`
     * - HK / MO：8 位号码（香港 2-9 开头；澳门固话 28/29、手机 6）
     * - TW：手机 `9xxxxxxxx`（9 位）；固话含区号为 8~9 位、2~8 开头
     */
    private static function normalizeNational(string $region, string $national): ?string
    {
        $national = preg_replace('/^0+/', '', $national) ?? '';
        $pattern = match ($region) {
            self::REGION_CN => '/^1[3-9]\d{9}$/',
            self::REGION_HK, self::REGION_MO => '/^[2-9]\d{7}$/',
            self::REGION_TW => '/^(?:9\d{8}|[2-8]\d{7,8})$/',
            default => null,
        };

        return $pattern !== null && preg_match($pattern, $national) === 1 ? $national : null;
    }

    private static function isDigits(string $value, int $min, int $max): bool
    {
        $length = strlen($value);

        return $length >= $min && $length <= $max && ctype_digit($value);
    }
}
