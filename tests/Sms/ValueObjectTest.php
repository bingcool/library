<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Sms;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Sms\Exception\InvalidPhoneException;
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

    public function testPhoneListAllowsInternationalPlusPrefix(): void
    {
        self::assertSame(['+8613800008000'], PhoneList::make('+8613800008000')->toArray());
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
