<?php

require_once __DIR__.'/../vendor/autoload.php';

use Common\Library\Captcha\CaptchaBuilder;

$captcha = new CaptchaBuilder;
$captcha
    ->build()
    ->save('out.jpg')
;
