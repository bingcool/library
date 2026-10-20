<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Sms;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Sms\Exception\InvalidPhoneException;
use Swoolefy\Library\Sms\SmsMessage;
use Swoolefy\Library\Sms\Support\TemplateParamMapper;
use Swoolefy\Library\Sms\ValueObject\PhoneList;
use Swoolefy\Library\Sms\ValueObject\SendResult;

/**
 * 值对象与参数映射：纯逻辑，无任何外部依赖。
 */
final class ValueObjectTest extends TestCase
{
    public function testPhoneListNormalizesDedupesAndPreservesOrder(): void
    {
        $list = PhoneList::make([' 13800008000 ', '139-0000-9000', '13800008000']);

        self::assertSame(['13800008000', '13900009000'], $list->toArray());
        self::assertCount(2, $list);
        self::assertSame('13800008000', $list->first());
        self::assertSame('13800008000,13900009000', $list->toString());
    }

    public function testPhoneListAcceptsSingleStringAndInt(): void
    {
        self::assertSame(['13800008000'], PhoneList::make('13800008000')->toArray());
        // int 号码也应被接受
        self::assertSame(['13800008000'], PhoneList::make(13800008000)->toArray());
    }

    public function testPhoneListDedupesCanonicalSpellings(): void
    {
        $list = PhoneList::make(['+8613800008000', '8613800008000', '13800008000', '+86 138-0000-8000']);

        self::assertSame(['+8613800008000'], $list->toArray());
        self::assertCount(1, $list);
    }

    public function testPhoneListDedupesHongKongSpellingsWithDefaultRegion(): void
    {
        $list = PhoneList::make(
            ['+85261234567', '85261234567', '61234567'],
            PhoneList::DEFAULT_MAX,
            'HK'
        );

        self::assertSame(['+85261234567'], $list->toArray());
    }

    public function testSmsMessageToKeepsConstructorMax(): void
    {
        $message = new SmsMessage(['13800008000'], 'login_code', [], null, 2);

        $this->expectException(InvalidPhoneException::class);
        $message->to(['13800008000', '13900009000', '13700007000']);
    }

    public function testPhoneListAllowsInternationalPlusPrefix(): void
    {
        self::assertSame(['+8613800008000'], PhoneList::make('+8613800008000')->toArray());
    }

    public function testCanonicalUnifiesChinesePhoneSpellings(): void
    {
        $expected = '+8613800008000';

        foreach ([
            '13800008000',
            '+8613800008000',
            '8613800008000',
            '008613800008000',
            '+86 138-0000-8000',
            '(86)13800008000',
        ] as $phone) {
            self::assertSame($expected, PhoneList::canonical($phone), $phone);
        }
    }

    public function testCanonicalSupportsHongKongMacauAndTaiwan(): void
    {
        self::assertSame('+85261234567', PhoneList::canonical('+852 6123 4567'));
        self::assertSame('+85261234567', PhoneList::canonical('0085261234567'));
        self::assertSame('+85261234567', PhoneList::canonical('61234567', 'HK'));

        self::assertSame('+85366123456', PhoneList::canonical('85366123456'));
        self::assertSame('+85366123456', PhoneList::canonical('66123456', 'MO'));

        self::assertSame('+886912345678', PhoneList::canonical('0912345678'));
        self::assertSame('+886912345678', PhoneList::canonical('+886 912 345 678'));
    }

    public function testCanonicalKeepsOtherInternationalNumbersWithE164Prefix(): void
    {
        self::assertSame('+14155552671', PhoneList::canonical('+1 415-555-2671'));
        self::assertSame('+442071838750', PhoneList::canonical('00442071838750'));
    }

    public function testCanonicalRejectsBareNumberOutsideSupportedRegions(): void
    {
        $this->expectException(InvalidPhoneException::class);
        PhoneList::canonical('442071838750');
    }

    public function testPhoneListRejectsInvalidNumber(): void
    {
        $this->expectException(InvalidPhoneException::class);
        PhoneList::make('abc123');
    }

    public function testPhoneListRejectsTooShort(): void
    {
        $this->expectException(InvalidPhoneException::class);
        PhoneList::make('12345');
    }

    public function testPhoneListRejectsEmpty(): void
    {
        $this->expectException(InvalidPhoneException::class);
        PhoneList::make([]);
    }

    public function testPhoneListEnforcesMaxCount(): void
    {
        $this->expectException(InvalidPhoneException::class);
        PhoneList::make(['13800008000', '13900009000'], 1);
    }

    public function testAliyunBodyOkMapsToSuccess(): void
    {
        $phones = PhoneList::make('13800008000');
        $result = SendResult::fromAliyunBody([
            'Code' => 'OK',
            'Message' => 'ok',
            'RequestId' => 'req-1',
            'BizId' => 'biz-1',
        ], $phones);

        self::assertTrue($result->ok);
        self::assertSame(['13800008000'], $result->successPhones);
        self::assertSame('biz-1', $result->bizId);
        self::assertSame('req-1', $result->requestId);
        self::assertSame('aliyun', $result->gateway);
    }

    public function testAliyunBodyFailureMapsToFailure(): void
    {
        $phones = PhoneList::make('13800008000');
        $result = SendResult::fromAliyunBody([
            'Code' => 'isv.AMOUNT_NOT_ENOUGH',
            'Message' => '余额不足',
            'RequestId' => 'req-2',
        ], $phones);

        self::assertFalse($result->ok);
        self::assertCount(1, $result->failedPhones);
        self::assertSame('isv.AMOUNT_NOT_ENOUGH', $result->failedPhones[0]['code']);
    }

    public function testTencentBodySupportsPartialAndPhoneAlignment(): void
    {
        $phones = PhoneList::make(['13800008000', '13900009000']);
        $result = SendResult::fromTencentBody([
            ['PhoneNumber' => '+8613800008000', 'Code' => 'Ok', 'Message' => 'SUCCESS', 'SerialNumber' => 's-1'],
            ['PhoneNumber' => '+8613900009000', 'Code' => 'Failed', 'Message' => '空号', 'SerialNumber' => 's-2'],
        ], $phones);

        self::assertFalse($result->ok);
        self::assertTrue($result->isPartial());
        // +86 回显应能对回原始请求号码
        self::assertSame(['13800008000'], $result->successPhones);
        self::assertSame('13900009000', $result->failedPhones[0]['phone']);
    }

    public function testTencentBodyAllOk(): void
    {
        $phones = PhoneList::make(['13800008000']);
        $result = SendResult::fromTencentBody([
            ['PhoneNumber' => '+8613800008000', 'Code' => 'Ok', 'Message' => 'SUCCESS', 'SerialNumber' => 's-1'],
        ], $phones);

        self::assertTrue($result->ok);
        self::assertSame('tencent', $result->gateway);
    }

    public function testTencentBodyMarksMissingStatusAsFailure(): void
    {
        $phones = PhoneList::make(['13800008000', '13900009000']);
        $result = SendResult::fromTencentBody([
            ['PhoneNumber' => '+8613800008000', 'Code' => 'Ok', 'Message' => 'SUCCESS', 'SerialNumber' => 's-1'],
        ], $phones);

        self::assertFalse($result->ok);
        self::assertSame(['13800008000'], $result->successPhones);
        self::assertCount(1, $result->failedPhones);
        self::assertSame('13900009000', $result->failedPhones[0]['phone']);
        self::assertSame('MissingStatus', $result->failedPhones[0]['code']);
    }

    public function testTencentBodyIgnoresUnrequestedPhones(): void
    {
        $phones = PhoneList::make('13800008000');
        $result = SendResult::fromTencentBody([
            ['PhoneNumber' => '+8613800008000', 'Code' => 'Ok'],
            ['PhoneNumber' => '+8613900009999', 'Code' => 'Ok'],
        ], $phones);

        self::assertTrue($result->ok);
        self::assertSame(['13800008000'], $result->successPhones);
        self::assertSame([], $result->failedPhones);
    }

    public function testTencentBodySkipsRowsWithoutPhoneNumber(): void
    {
        $phones = PhoneList::make(['13800008000', '13900009000']);
        $result = SendResult::fromTencentBody([
            ['PhoneNumber' => '', 'Code' => 'Ok'],
            ['PhoneNumber' => '+8613900009000', 'Code' => 'Ok'],
        ], $phones);

        self::assertFalse($result->ok);
        self::assertSame(['13900009000'], $result->successPhones);
        self::assertSame('13800008000', $result->failedPhones[0]['phone']);
        self::assertSame('MissingStatus', $result->failedPhones[0]['code']);
    }

    public function testTencentBodyDedupesRepeatedStatuses(): void
    {
        $phones = PhoneList::make('13800008000');
        $result = SendResult::fromTencentBody([
            ['PhoneNumber' => '+8613800008000', 'Code' => 'Ok'],
            ['PhoneNumber' => '13800008000', 'Code' => 'Ok'],
        ], $phones);

        self::assertTrue($result->ok);
        self::assertSame(['13800008000'], $result->successPhones);
    }

    public function testTencentBodyUsesPhoneMapForOverseasAlignment(): void
    {
        $phones = PhoneList::make('61234567');
        $result = SendResult::fromTencentBody([
            ['PhoneNumber' => '+85261234567', 'Code' => 'Ok'],
        ], $phones, 'tencent', 'req-1', ['+85261234567' => '61234567']);

        self::assertTrue($result->ok);
        self::assertSame(['61234567'], $result->successPhones);
    }

    public function testTemplateParamMapperAliyunKeepsKeys(): void
    {
        $out = TemplateParamMapper::toAliyun(['code' => '123456', 'name' => '张三', 'n' => 5]);
        self::assertSame(['code' => '123456', 'name' => '张三', 'n' => '5'], $out);
    }

    public function testTemplateParamMapperTencentIsOrderedListOfStrings(): void
    {
        $out = TemplateParamMapper::toTencent(['code' => '123456', 'ttl' => 5, 'flag' => true]);
        self::assertSame(['123456', '5', 'true'], $out);
    }

    public function testTemplateParamMapperStringifySpecialValues(): void
    {
        $out = TemplateParamMapper::toAliyun(['a' => null, 'b' => ['x' => 1]]);
        self::assertSame('', $out['a']);
        self::assertSame('{"x":1}', $out['b']);
    }
}
