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

namespace Common\Library\Captcha;

class ImageFileHandler
{
    /**
     * Name of folder for captcha images
     * @var string
     */
    protected $imageFolder;

    /**
     * Absolute path to public web folder
     * @var string
     */
    protected $webPath;

    /**
     * Frequency of garbage collection in fractions of 1
     * @var int
     */
    protected $gcFreq;

    /**
     * Maximum age of images in minutes
     * @var int
     */
    protected $expiration;

    /**
     * @param $imageFolder
     * @param $webPath
     * @param $gcFreq
     * @param $expiration
     */
    public function __construct($imageFolder, $webPath, $gcFreq, $expiration)
    {
        $this->imageFolder      = $imageFolder;
        $this->webPath          = $webPath;
        $this->gcFreq           = $gcFreq;
        $this->expiration       = $expiration;
    }

    /**
     * Saves the provided image content as a file
     *
     * @param string $contents
     *
     * @return string
     */
    public function saveAsFile($contents)
    {
        $this->createFolderIfMissing();

        $filename = md5(uniqid()) . '.jpg';
        $filePath = $this->webPath . '/' . $this->imageFolder . '/' . $filename;
        imagejpeg($contents, $filePath, 15);

        return '/' . $this->imageFolder . '/' . $filename;
    }

    /**
     * Creates the folder if it doesn't exist
     */
    protected function createFolderIfMissing()
    {
        if (!file_exists($this->webPath . '/' . $this->imageFolder)) {
            mkdir($this->webPath . '/' . $this->imageFolder, 0755);
        }
    }
}
