<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Template;

use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;
use Swoolefy\Library\Mail\Exception\TemplateNotFoundException;

/**
 * 只读 view_path/{name}.html。name 不能含点、斜杠，避免把模板名变成路径穿越或 PHP 文件。
 * {{ key }} 会 HTML 转义，{{{ key }}} 原样输出。
 * 根目录在首次使用时解析；空配置不会回退到 cwd。
 * 模板内容按 path + mtime + size + inode 做有界进程内缓存，文件变化自动失效。
 */
final class TemplateRenderer implements TemplateEngineInterface
{
    private const MAX_CACHE = 128;

    /** 根目录只解析一次，后续渲染复用结果 */
    private bool $rootResolved = false;

    private ?string $root = null;

    /** @var array<string, array{signature: string, html: string}> 文件真实路径 => 内容与失效签名 */
    private array $cache = [];

    /**
     * @param string $viewPath 模板根目录；允许为空，只有真正渲染时才会报错
     */
    public function __construct(private string $viewPath)
    {
    }

    /**
     * 渲染模板。
     * 流程：模板名白名单 → 解析并校验根目录 → realpath + 根目录前缀判断 → 普通文件检查
     * → 读缓存/磁盘 → 占位符替换。
     *
     * 两处失败必须抛异常而不是原样返回：变量缺失或非标量；PCRE 返回 null（回溯超限等）。
     * 否则占位符会以原始形式发给用户，或模板静默失效。
     *
     * @param string $name 只允许 [A-Za-z0-9_-]
     * @param array<string, scalar|null> $vars 变量名已由 MessageCompiler 校验
     * @throws TemplateNotFoundException 模板名非法、根目录不可用或文件不存在/不可读
     * @throws InvalidMailMessageException 变量缺失/非标量或正则渲染失败
     */
    public function render(string $name, array $vars): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new TemplateNotFoundException('Illegal mail template name');
        }

        $root = $this->root();
        if ($root === null) {
            throw new TemplateNotFoundException('Mail template view_path is not configured or is not a directory');
        }

        $file = realpath($root . DIRECTORY_SEPARATOR . $name . '.html');
        if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            throw new TemplateNotFoundException(sprintf('Mail template [%s] was not found', $name));
        }

        $stat = @stat($file);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
            throw new TemplateNotFoundException(sprintf('Mail template [%s] was not found', $name));
        }

        $html = $this->read($file, $stat);

        $rendered = preg_replace_callback(
            '/\{\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}\}|\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
            function (array $match) use ($vars): string {
                $raw = ($match[1] ?? '') !== '';
                $key = $raw ? $match[1] : $match[2];
                if (!array_key_exists($key, $vars)) {
                    throw new InvalidMailMessageException(sprintf('Mail template variable [%s] is missing', $key));
                }

                $value = self::stringify($vars[$key]);

                // 三花括号表示调用方明确标记的可信 HTML；双花括号一律转义
                return $raw ? $value : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $html
        );

        if ($rendered === null) {
            throw new InvalidMailMessageException('Mail template could not be rendered: ' . preg_last_error_msg());
        }

        return $rendered;
    }

    /**
     * 首次渲染时解析根目录并缓存结果（含失败结果），避免每封信重复 realpath。
     * 空字符串不调用 realpath，防止 PHP 把空路径解析成当前工作目录。
     */
    private function root(): ?string
    {
        if (!$this->rootResolved) {
            $this->rootResolved = true;
            $path = trim($this->viewPath);
            if ($path !== '') {
                $root = realpath($path);
                if ($root !== false && is_dir($root)) {
                    $this->root = $root;
                }
            }
        }

        return $this->root;
    }

    /**
     * 按 mtime + size + inode 判断缓存是否仍然有效。
     * inode 能覆盖「同路径被替换成新文件但 mtime/size 恰好相同」的情况；
     * 超限时移除最早写入项（近似 LRU），避免长期运行后无界增长。
     *
     * @param array<string, int> $stat
     */
    private function read(string $file, array $stat): string
    {
        $signature = $stat['mtime'] . ':' . $stat['size'] . ':' . $stat['ino'];
        $cached = $this->cache[$file] ?? null;
        if ($cached !== null && $cached['signature'] === $signature) {
            return $cached['html'];
        }

        $html = @file_get_contents($file);
        if ($html === false) {
            throw new TemplateNotFoundException('Mail template is not readable');
        }

        if (count($this->cache) >= self::MAX_CACHE && !isset($this->cache[$file])) {
            array_shift($this->cache);
        }
        $this->cache[$file] = ['signature' => $signature, 'html' => $html];

        return $html;
    }

    /**
     * 只接受标量和 null：null → 空串，bool → '1'/'0'，数字按字符串输出。
     * 数组/对象直接拒绝，不做隐式 __toString。
     */
    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $value;
        }

        throw new InvalidMailMessageException('Mail template variables must be scalar');
    }
}
