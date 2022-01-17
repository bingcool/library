<?php

namespace Common\Library\Tests\Events;

use Common\Library\Events\AbstractListener;


class RegisterUserListener extends AbstractListener
{

    /**
     * @inheritDoc
     */
    public function listen(): array
    {
        return  [
            RegisterUserEvent::class
        ];
    }

}