<?php

namespace Common\Library\Tests\Validate;

use Common\Library\Exception\ValidateException;
use PHPUnit\Framework\TestCase;

class ValidateTest extends TestCase
{
    public function testV1()
    {
        $validate = new UserValidate();
        try {

            $data = [
                'name' => 'hhh',
                'email' => 'thinkphp@qq.com',
            ];

            if (!$validate->scene('v1')->check($data)) {
                var_dump($validate->getError());
            } else {
                var_dump('success');
            }
        } catch (ValidateException $e) {
            // 验证失败 输出错误信息
            var_dump($e->getError());
        }
    }
}