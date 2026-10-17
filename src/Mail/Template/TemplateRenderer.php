<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Template;

use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;
use Swoolefy\Library\Mail\Exception\TemplateNotFoundException;

/**
 * 只读 view_path/{name}.html。name 不能含点、斜杠，避免把模板名变成路径穿越或 PHP 文件。
 * {{ key }} 会 HTML 转义，{{{ key }}} 原样输出。
 */
final class TemplateRenderer implements TemplateEngineInterface
{
    public function __construct(private string $viewPath)
    {
    }

    public function render(string $name, array $vars): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new TemplateNotFoundException('Illegal mail template name');
        }

        $root = realpath($this->viewPath);
        $file = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $name . '.html');
        if ($root === false || $file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            throw new TemplateNotFoundException(sprintf('Mail template [%s] was not found', $name));
        }

        $html = file_get_contents($file);
        if ($html === false) {
            throw new TemplateNotFoundException(sprintf('Mail template [%s] was not found', $name));
        }

        $rendered = preg_replace_callback(
            '/\{\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}\}|\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
            function (array $match) use ($vars): string {
                $raw = ($match[1] ?? '') !== '';
                $key = $raw ? $match[1] : $match[2];
                if (!array_key_exists($key, $vars)) {
                    throw new InvalidMailMessageException(sprintf('Mail template variable [%s] is missing', $key));
                }

                $value = self::stringify($vars[$key]);

                return $raw ? $value : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $html
        );

        return $rendered ?? $html;
    }

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
