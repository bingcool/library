<?php
namespace Swoolefy\Library\Tests\Events;

use Swoolefy\Library\Events\AbstractEventHandle;
use Swoolefy\Library\Events\AbstractListener;

class RegisterUserEvent extends AbstractEventHandle
{

    public function handle(array $data, AbstractListener $listener)
    {
        return 'kkkkkkkkk';
    }
}