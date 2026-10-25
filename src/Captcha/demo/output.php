<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Swoolefy\Library\Captcha\CaptchaBuilder;

header('Content-type: image/jpeg');

CaptchaBuilder::create()
    ->build()
    ->output()
;
