<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Swoolefy\Library\Captcha\CaptchaBuilder;

$captcha = new CaptchaBuilder;
$captcha
    ->build()
    ->save('out.jpg')
;
