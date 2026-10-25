<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Events;

use Swoolefy\Library\Events\AbstractListener;


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