<?php

declare(strict_types=1);

namespace Swoolefy\Library\FileStorageSystem\Exception;

/** PathNormalizer 拒绝的路径（逃逸、绝对路径、URL、NUL 等）。 */
final class InvalidObjectPathException extends FileStorageException
{
}
