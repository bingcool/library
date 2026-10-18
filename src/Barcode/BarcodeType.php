<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode;

/**
 * 支持的条码制式，各 case 映射 picqer `Type*` 编码类。
 *
 * ITF 对应交错 2 of 5：{@see \Picqer\Barcode\Types\TypeInterleaved25}。
 */
enum BarcodeType: string
{
    /** 可变长度，ASCII 子集，通用物流/内部码 */
    case CODE_128 = 'CODE_128';
    /** 大写字母、数字及有限符号 */
    case CODE_39 = 'CODE_39';
    /** 商品条码，12 或 13 位数字（含校验位规则由 picqer 处理） */
    case EAN_13 = 'EAN_13';
    /** 短商品码，7 或 8 位数字 */
    case EAN_8 = 'EAN_8';
    /** 北美零售，11 或 12 位数字 */
    case UPC_A = 'UPC_A';
    /** 交错 2 of 5，纯数字 */
    case ITF = 'ITF';
}
