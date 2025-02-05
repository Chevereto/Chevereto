<?php

/*
 * This file is part of Chevereto.
 *
 * (c) Rodolfo Berrios <rodolfo@chevereto.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Chevereto\Traits\Instance;

use LogicException;
use function Chevere\Message\message;

trait AssertNoInstanceTrait
{
    public function assertNoInstance(): void
    {
        if (get_object_vars($this) !== []) {
            throw new LogicException(
                message(
                    'An instance of `%type%` has been already created.',
                    type: static::class
                )
            );
        }
    }
}
