<?php
namespace Common\Library\Tests\Events;

use Common\Library\Events\AbstractEventHandle;
use Common\Library\Events\AbstractListener;

class RegisterUserEvent extends AbstractEventHandle
{

    public function handle(array $data, AbstractListener $listener)
    {
        return 'kkkkkkkkk';
    }
}