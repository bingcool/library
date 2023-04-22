<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */


namespace Common\Library\Jwt\Signer\Key;

use Common\Library\Jwt\Signer\Key;

final class LocalFileReference implements Key
{
    /**
     * string
     */
    private const PATH_PREFIX = 'file://';

    /**
     * @var string
     */
    private $path;

    /**
     * @var string
     */
    private $passphrase;

    /**
     * @var string
     */
    private $contents;

    /**
     * @param string $path
     * @param string $passphrase
     */
    private function __construct(string $path, string $passphrase)
    {
        $this->path       = $path;
        $this->passphrase = $passphrase;
    }

    /**
     * @throws FileCouldNotBeRead
     */
    public static function file(string $path, string $passphrase = ''): self
    {
        if (strpos($path, self::PATH_PREFIX) === 0) {
            $path = substr($path, 7);
        }

        if (! file_exists($path)) {
            throw FileCouldNotBeRead::onPath($path);
        }

        return new self($path, $passphrase);
    }

    /**
     * @return string
     */
    public function contents(): string
    {
        if (! isset($this->contents)) {
            $this->contents = InMemory::file($this->path)->contents();
        }

        return $this->contents;
    }

    /**
     * @return string
     */
    public function passphrase(): string
    {
        return $this->passphrase;
    }
}
