<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2021 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

declare (strict_types=1);

namespace Common\Library;

use Closure;
use Common\Library\Exception\ValidateException;
use Common\Library\Helper\StringUtil;
use Common\Library\Validate\ValidateRule;

/**
 * Class Validate
 * @package Common\Library
 */
class Validate
{
    /**
     * 自定义验证类型
     * @var array
     */
    protected $type = [];

    /**
     * 验证类型别名
     * @var array
     */
    protected $alias = [
        '>' => 'gt', '>=' => 'egt', '<' => 'lt', '<=' => 'elt', '=' => 'eq', 'same' => 'eq',
    ];

    /**
     * 当前验证规则
     * @var array
     */
    protected $rule = [];

    /**
     * 验证提示信息
     * @var array
     */
    protected $message = [];

    /**
     * 验证字段描述
     * @var array
     */
    protected $field = [];

    /**
     * 默认规则提示
     * @var array
     */
    protected $typeMsg = [
        'require' => ':attribute require',
        'required' => ':attribute require',
        'must' => ':attribute must',
        'number' => ':attribute must be numeric',
        'integer' => ':attribute must be integer',
        'int' => ':attribute must be integer',
        'float' => ':attribute must be float',
        'boolean' => ':attribute must be bool',
        'bool' => ':attribute must be bool',
        'string' => ':attribute must be string',
        'email' => ':attribute not a valid email address',
        'mobile' => ':attribute not a valid mobile',
        'array' => ':attribute must be a array',
        'json'  => ':attribute must be a json string',
        'accepted' => ':attribute must be yes,on or 1',
        'date' => ':attribute not a valid datetime',
        'file' => ':attribute not a valid file',
        'nullable' => ':attribute can not set or null value',
        'image' => ':attribute not a valid image',
        'alpha' => ':attribute must be alpha',
        'alphaNum' => ':attribute must be alpha-numeric',
        'alphaDash' => ':attribute must be alpha-numeric, dash, underscore',
        'activeUrl' => ':attribute not a valid domain or ip',
        'chs' => ':attribute must be chinese',
        'chsAlpha' => ':attribute must be chinese or alpha',
        'chsAlphaNum' => ':attribute must be chinese,alpha-numeric',
        'chsDash' => ':attribute must be chinese,alpha-numeric,underscore, dash',
        'url' => ':attribute not a valid url',
        'ip' => ':attribute not a valid ip',
        'dateFormat' => ':attribute must be dateFormat of :rule',
        'in' => ':attribute must be in :rule',
        'notIn' => ':attribute be notin :rule',
        'between' => ':attribute must between :1 - :2',
        'notBetween' => ':attribute not between :1 - :2',
        'length' => 'size of :attribute must be :rule',
        'max' => 'max size of :attribute must be :rule',
        'min' => 'min size of :attribute must be :rule',
        'after' => ':attribute cannot be less than :rule',
        'before' => ':attribute cannot exceed :rule',
        'expire' => ':attribute not within :rule',
        'allowIp' => 'access IP is not allowed',
        'denyIp' => 'access IP denied',
        'confirm' => ':attribute out of accord with :2',
        'different' => ':attribute cannot be same with :2',
        'egt' => ':attribute must greater than or equal :rule',
        'gt' => ':attribute must greater than :rule',
        'elt' => ':attribute must less than or equal :rule',
        'lt' => ':attribute must less than :rule',
        'eq' => ':attribute must equal :rule',
        'unique' => ':attribute has exists',
        'regex' => ':attribute not conform to the rules',
    ];

    /**
     * 当前验证场景
     * @var string
     */
    protected $currentScene;

    /**
     * 内置正则验证规则
     * @var array
     */
    protected $defaultRegex = [
        'alpha' => '/^[A-Za-z]+$/',
        'alphaNum' => '/^[A-Za-z0-9]+$/',
        'alphaDash' => '/^[A-Za-z0-9\-\_]+$/',
        'chs' => '/^[\x{4e00}-\x{9fa5}\x{9fa6}-\x{9fef}\x{3400}-\x{4db5}\x{20000}-\x{2ebe0}]+$/u',
        'chsAlpha' => '/^[\x{4e00}-\x{9fa5}\x{9fa6}-\x{9fef}\x{3400}-\x{4db5}\x{20000}-\x{2ebe0}a-zA-Z]+$/u',
        'chsAlphaNum' => '/^[\x{4e00}-\x{9fa5}\x{9fa6}-\x{9fef}\x{3400}-\x{4db5}\x{20000}-\x{2ebe0}a-zA-Z0-9]+$/u',
        'chsDash' => '/^[\x{4e00}-\x{9fa5}\x{9fa6}-\x{9fef}\x{3400}-\x{4db5}\x{20000}-\x{2ebe0}a-zA-Z0-9\_\-]+$/u',
        'mobile' => '/^1[3-9]\d{9}$/',
        'idCard' => '/(^[1-9]\d{5}(18|19|([23]\d))\d{2}((0[1-9])|(10|11|12))(([0-2][1-9])|10|20|30|31)\d{3}[0-9Xx]$)|(^[1-9]\d{5}\d{2}((0[1-9])|(10|11|12))(([0-2][1-9])|10|20|30|31)\d{3}$)/',
        'zip' => '/\d{6}/',
    ];

    /**
     * Filter_var 规则
     * @var array
     */
    protected $filter = [
        'email' => FILTER_VALIDATE_EMAIL,
        'ip' => [FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6],
        'integer' => FILTER_VALIDATE_INT,
        'url' => FILTER_VALIDATE_URL,
        'macAddr' => FILTER_VALIDATE_MAC,
        'float' => FILTER_VALIDATE_FLOAT,
    ];

    /**
     * 验证场景定义
     * @var array
     */
    protected $scene = [];

    /**
     * 验证失败错误信息
     * @var string|array
     */
    protected $error = [];

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batch = false;

    /**
     * 验证失败是否抛出异常
     * @var bool
     */
    protected $failException = false;

    /**
     * 场景需要验证的规则
     * @var array
     */
    protected $only = [];

    /**
     * 场景需要移除的验证规则
     * @var array
     */
    protected $remove = [];

    /**
     * 场景需要追加的验证规则
     * @var array
     */
    protected $append = [];

    /**
     * 验证正则定义
     * @var array
     */
    protected $regex = [];

    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {

    }

    /**
     * 添加字段验证规则
     * @access protected
     * @param string|array $name 字段名称或者规则数组
     * @param mixed $rule 验证规则或者字段描述信息
     * @return $this
     */
    public function rule($name, $rule = '')
    {
        if (is_array($name)) {
            $this->rule = $name + $this->rule;
            if (is_array($rule)) {
                $this->field = array_merge($this->field, $rule);
            }
        } else {
            $this->rule[$name] = $rule;
        }

        return $this;
    }

    /**
     * 注册验证（类型）规则
     * @access public
     * @param string $type 验证规则类型
     * @param callable $callback callback方法(或闭包)
     * @param string $message 验证失败提示信息
     * @return $this
     */
    public function extend(string $type, callable $callback = null, string $message = null)
    {
        $this->type[$type] = $callback;

        if ($message) {
            $this->typeMsg[$type] = $message;
        }

        return $this;
    }

    /**
     * 设置验证规则的默认提示信息
     * @access public
     * @param string|array $type 验证规则类型名称或者数组
     * @param string $msg 验证提示信息
     * @return void
     */
    public function setTypeMsg($type, string $msg = null): void
    {
        if (is_array($type)) {
            $this->typeMsg = array_merge($this->typeMsg, $type);
        } else {
            $this->typeMsg[$type] = $msg;
        }
    }

    /**
     * 设置提示信息
     * @access public
     * @param array $message 错误信息
     * @return Validate
     */
    public function message(array $message)
    {
        $this->message = array_merge($this->message, $message);

        return $this;
    }

    /**
     * 设置验证场景
     * @access public
     * @param string $name 场景名
     * @return $this
     */
    public function scene(string $name)
    {
        // 设置当前场景
        $this->currentScene = $name;

        return $this;
    }

    /**
     * 判断是否存在某个验证场景
     * @access public
     * @param string $name 场景名
     * @return bool
     */
    public function hasScene(string $name): bool
    {
        return isset($this->scene[$name]) || method_exists($this, 'scene' . $name);
    }

    /**
     * 设置批量验证
     * @access public
     * @param bool $batch 是否批量验证
     * @return $this
     */
    public function batch(bool $batch = true)
    {
        $this->batch = $batch;

        return $this;
    }

    /**
     * 设置验证失败后是否抛出异常
     * @access protected
     * @param bool $fail 是否抛出异常
     * @return $this
     */
    public function failException(bool $fail = true)
    {
        $this->failException = $fail;

        return $this;
    }

    /**
     * 指定需要验证的字段列表
     * @access public
     * @param array $fields 字段名
     * @return $this
     */
    public function only(array $fields)
    {
        $this->only = $fields;

        return $this;
    }

    /**
     * 移除某个字段的验证规则
     * @access public
     * @param string|array $field 字段名
     * @param mixed $rule 验证规则 true 移除所有规则
     * @return $this
     */
    public function remove($field, $rule = null)
    {
        if (is_array($field)) {
            foreach ($field as $key => $rule) {
                if (is_int($key)) {
                    $this->remove($rule);
                } else {
                    $this->remove($key, $rule);
                }
            }
        } else {
            if (is_string($rule)) {
                $rule = explode('|', $rule);
            }

            $this->remove[$field] = $rule;
        }

        return $this;
    }

    /**
     * 追加某个字段的验证规则
     * @access public
     * @param string|array $field 字段名
     * @param mixed $rule 验证规则
     * @return $this
     */
    public function append($field, $rule = null)
    {
        if (is_array($field)) {
            foreach ($field as $key => $rule) {
                $this->append($key, $rule);
            }
        } else {
            if (is_string($rule)) {
                $rule = explode('|', $rule);
            }

            $this->append[$field] = $rule;
        }

        return $this;
    }

    /**
     * 数据自动验证
     * @access public
     * @param array $data 数据
     * @param array $rules 验证规则
     * @return bool
     */
    public function check(array $data, array $rules = []): bool
    {
        $this->error = [];

        if ($this->currentScene) {
            $this->getScene($this->currentScene);
        }

        if (empty($rules)) {
            // 读取验证规则
            $rules = $this->rule;
        }

        foreach ($this->append as $key => $rule) {
            if (!isset($rules[$key])) {
                $rules[$key] = $rule;
                unset($this->append[$key]);
            }
        }

        foreach ($rules as $key => $rule) {
            // field => 'rule1|rule2...' field => ['rule1','rule2',...]
            if (strpos($key, '|')) {
                // 字段|描述 用于指定属性名称
                [$key, $title] = explode('|', $key);
            } else {
                $title = $this->field[$key] ?? $key;
            }

            // 场景检测
            if (!empty($this->only) && !in_array($key, $this->only)) {
                continue;
            }

            // 获取数据 支持二维数组
            $value = $this->getDataValue($data, $key);

            // 字段验证
            if ($rule instanceof Closure) {
                $result = call_user_func_array($rule, [$value, $data]);
            } elseif ($rule instanceof ValidateRule) {
                //  验证因子
                $result = $this->checkItem($key, $value, $rule->getRule(), $data, $rule->getTitle() ?: $title, $rule->getMsg());
            } else {
                $result = $this->checkItem($key, $value, $rule, $data, $title);
            }

            if (true !== $result) {
                // 没有返回true 则表示验证失败
                if (!empty($this->batch)) {
                    // 批量验证
                    $this->error[$key] = $result;
                } elseif ($this->failException) {
                    throw new ValidateException($result);
                } else {
                    $this->error = $result;
                    return false;
                }
            }
        }

        if (!empty($this->error)) {
            if ($this->failException) {
                throw new ValidateException($this->error);
            }
            return false;
        }

        return true;
    }

    /**
     * 根据验证规则验证数据
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rules 验证规则
     * @return bool
     */
    public function checkRule($value, $rules): bool
    {
        if ($rules instanceof Closure) {
            return call_user_func_array($rules, [$value]);
        } elseif ($rules instanceof ValidateRule) {
            $rules = $rules->getRule();
        } elseif (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        foreach ($rules as $key => $rule) {
            if ($rule instanceof Closure) {
                $result = call_user_func_array($rule, [$value]);
            } else {
                // 判断验证类型
                [$type, $rule] = $this->getValidateType($key, $rule);

                $callback = $this->type[$type] ?? [$this, $type];

                $result = call_user_func_array($callback, [$value, $rule]);
            }

            if (true !== $result) {
                if ($this->failException) {
                    throw new ValidateException($result);
                }

                return $result;
            }
        }

        return true;
    }

    /**
     * 验证单个字段规则
     * @access protected
     * @param string $field 字段名
     * @param mixed $value 字段值
     * @param mixed $rules 验证规则
     * @param array $data 数据
     * @param string $title 字段描述
     * @param array $msg 提示信息
     * @return mixed
     */
    protected function checkItem(string $field, $value, $rules, $data, string $title = '', array $msg = [])
    {
        if (isset($this->remove[$field]) && true === $this->remove[$field] && empty($this->append[$field])) {
            // 字段已经移除 无需验证
            return true;
        }

        // 支持多规则验证 require|in:a,b,c|... 或者 ['require','in'=>'a,b,c',...]
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        if (isset($this->append[$field])) {
            // 追加额外的验证规则
            $rules = array_unique(array_merge($rules, $this->append[$field]), SORT_REGULAR);
            unset($this->append[$field]);
        }

        if (empty($rules)) {
            return true;
        }

        $i = 0;
        foreach ($rules as $key => $rule) {
            if ($rule instanceof Closure) {
                $result = call_user_func_array($rule, [$value, $data]);
                $info = is_numeric($key) ? '' : $key;
            } else {
                // 判断验证类型
                [$type, $rule, $info] = $this->getValidateType($key, $rule);

                if (isset($this->append[$field]) && in_array($info, $this->append[$field])) {
                } elseif (isset($this->remove[$field]) && in_array($info, $this->remove[$field])) {
                    // 规则已经移除
                    $i++;
                    continue;
                }

                if (isset($this->type[$type])) {
                    $result = call_user_func_array($this->type[$type], [$value, $rule, $data, $field, $title]);
                } elseif ('must' == $info || 0 === strpos($info, 'require') || 0 === strpos($info, 'required') || (!is_null($value) && '' !== $value)) {
                    $result = call_user_func_array([$this, $type], [$value, $rule, $data, $field, $title]);
                } else {
                    $result = true;
                }
            }

            if (false === $result) {
                // 验证失败 返回错误信息
                if (!empty($msg[$i])) {
                    $message = $msg[$i];

                } else {
                    $message = $this->getRuleMsg($field, $title, $info, $rule);
                }

                return $message;
            } elseif (true !== $result) {
                // 返回自定义错误信息
                if (is_string($result) && false !== strpos($result, ':')) {
                    $result = str_replace(':attribute', $title, $result);

                    if (strpos($result, ':rule') && is_scalar($rule)) {
                        $result = str_replace(':rule', (string)$rule, $result);
                    }
                }

                return $result;
            }
            $i++;
        }

        return $result ?? true;
    }

    /**
     * 获取当前验证类型及规则
     * @access public
     * @param mixed $key
     * @param mixed $rule
     * @return array
     */
    protected function getValidateType($key, $rule): array
    {
        // 判断验证类型
        if (!is_numeric($key)) {
            if (isset($this->alias[$key])) {
                // 判断别名
                $key = $this->alias[$key];
            }
            return [$key, $rule, $key];
        }

        if (strpos($rule, ':')) {
            [$type, $rule] = explode(':', $rule, 2);
            if (isset($this->alias[$type])) {
                // 判断别名
                $type = $this->alias[$type];
            }
            $info = $type;
        } elseif (method_exists($this, $rule)) {
            $type = $rule;
            $info = $rule;
            $rule = '';
        } else {
            $type = 'is';
            $info = $rule;
        }

        return [$type, $rule, $info];
    }

    /**
     * 验证是否和某个字段的值一致
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @param string $field 字段名
     * @return bool
     */
    public function confirm($value, $rule, array $data = [], string $field = ''): bool
    {
        if ('' == $rule) {
            if (strpos($field, '_confirm')) {
                $rule = strstr($field, '_confirm', true);
            } else {
                $rule = $field . '_confirm';
            }
        }

        return $this->getDataValue($data, $rule) === $value;
    }

    /**
     * 验证是否和某个字段的值是否不同
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function different($value, $rule, array $data = []): bool
    {
        return $this->getDataValue($data, $rule) != $value;
    }

    /**
     * 验证是否大于等于某个值
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function egt($value, $rule, array $data = []): bool
    {
        return $value >= $this->getDataValue($data, $rule);
    }

    /**
     * 验证是否大于某个值
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function gt($value, $rule, array $data = []): bool
    {
        return $value > $this->getDataValue($data, $rule);
    }

    /**
     * 验证是否小于等于某个值
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function elt($value, $rule, array $data = []): bool
    {
        return $value <= $this->getDataValue($data, $rule);
    }

    /**
     * 验证是否小于某个值
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function lt($value, $rule, array $data = []): bool
    {
        return $value < $this->getDataValue($data, $rule);
    }

    /**
     * 验证是否等于某个值
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function eq($value, $rule): bool
    {
        return $value == $rule;
    }

    /**
     * 必须验证
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function must($value, $rule = null): bool
    {
        return !empty($value) || '0' == $value;
    }

    /**
     * 验证字段值是否为有效格式
     * @access public
     * @param mixed $value 字段值
     * @param string $rule 验证规则
     * @param array $data 数据
     * @param mixed $field 字段
     * @param mixed $title
     * @return bool
     */
    public function is($value, string $rule, array $data = [], $field = '', $title = ''): bool
    {
        $fn = function ($value, $rule, $isDefaultHandle) {
            switch (StringUtil::camel($rule)) {
                case 'require':
                case 'required':
                    // 必须
                    $result = !empty($value) || '0' == $value;
                    break;
                case 'accepted':
                    // 接受
                    $result = in_array($value, ['1', 'on', 'yes']);
                    break;
                case 'date':
                    // 是否是一个有效日期
                    $result = false !== strtotime($value);
                    break;
                case 'activeUrl':
                    // 是否为有效的网址
                    $result = checkdnsrr($value);
                    break;
                case 'boolean':
                case 'bool':
                    // 是否为布尔值
                    $result = in_array($value, [true, false, 0, 1, '0', '1'], true);
                    break;
                case 'number':
                    $result = ctype_digit((string)$value);
                    break;
                case 'integer':
                case 'int':
                    $result = filter_var($value, FILTER_VALIDATE_INT) !== false;
                    break;
                case 'float':
                    $result = filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
                    break;
                case 'string':
                    $result = is_string($value);
                    break;
                case 'alphaNum':
                    $result = ctype_alnum($value);
                    break;
                case 'array':
                    // 是否为数组
                    $result = is_array($value);
                    break;

                default:
                    if ($isDefaultHandle) {
                        if (isset($this->type[$rule])) {
                            // 注册的验证规则
                            $result = call_user_func_array($this->type[$rule], [$value]);
                        } elseif (function_exists('ctype_' . $rule)) {
                            // ctype验证规则
                            $ctypeFun = 'ctype_' . $rule;
                            $result = $ctypeFun($value);
                        } elseif (isset($this->filter[$rule])) {
                            // Filter_var验证规则
                            $result = $this->filter($value, $this->filter[$rule]);
                        } else {
                            // 正则验证
                            $result = $this->regex($value, $rule);
                        }
                    }else {
                        $result = true;
                    }
            }

            return $result;
        };


        if (strpos($field, '.')) {
            $fields = explode('.', $field);
            if (in_array('*', $fields) && is_array($value)) {
                foreach($value as $item) {
                    $result = $fn($item, $rule,0);
                    if ($result === false) {
                        return $result;
                    }
                }
                return true;
            }else {
                return $fn($value, $rule, 1);
            }
        }else {
            return $fn($value, $rule, 1);
        }
    }

    /**
     * 验证是否为合格的域名或者IP 支持A，MX，NS，SOA，PTR，CNAME，AAAA，A6， SRV，NAPTR，TXT 或者 ANY类型
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function activeUrl(string $value, string $rule = 'MX'): bool
    {
        if (!in_array($rule, ['A', 'MX', 'NS', 'SOA', 'PTR', 'CNAME', 'AAAA', 'A6', 'SRV', 'NAPTR', 'TXT', 'ANY'])) {
            $rule = 'MX';
        }

        return checkdnsrr($value, $rule);
    }

    /**
     * Validate the attribute is a valid JSON string.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function json(string $value, string $rule)
    {
        if (is_array($value)) {
            return false;
        }

        if (! is_scalar($value) && ! is_null($value) && ! method_exists($value, '__toString')) {
            return false;
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * 验证是否有效IP
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则 ipv4 ipv6
     * @return bool
     */
    public function ip($value, string $rule = 'ipv4'): bool
    {
        if (!in_array($rule, ['ipv4', 'ipv6'])) {
            $rule = 'ipv4';
        }

        return $this->filter($value, [FILTER_VALIDATE_IP, 'ipv6' == $rule ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4]);
    }

    /**
     * 验证时间和日期是否符合指定格式
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function dateFormat($value, $rule): bool
    {
        $info = date_parse_from_format($rule, $value);
        return 0 == $info['warning_count'] && 0 == $info['error_count'];
    }

    /**
     * 使用filter_var方式验证
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function filter($value, $rule): bool
    {
        if (is_string($rule) && strpos($rule, ',')) {
            [$rule, $param] = explode(',', $rule);
        } elseif (is_array($rule)) {
            $param = $rule[1] ?? 0;
            $rule = $rule[0];
        } else {
            $param = 0;
        }

        return false !== filter_var($value, is_int($rule) ? $rule : filter_id($rule), $param);
    }

    /**
     * 验证某个字段等于某个值的时候必须
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function requireIf($value, $rule, array $data = []): bool
    {
        [$field, $val] = explode(',', $rule);

        if ($this->getDataValue($data, $field) == $val) {
            return !empty($value) || '0' == $value;
        }

        return true;
    }

    /**
     * 通过回调方法验证某个字段是否必须
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function requireCallback($value, $rule, array $data = []): bool
    {
        $result = call_user_func_array([$this, $rule], [$value, $data]);

        if ($result) {
            return !empty($value) || '0' == $value;
        }

        return true;
    }

    /**
     * 验证某个字段有值的情况下必须
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function requireWith($value, $rule, array $data = []): bool
    {
        $val = $this->getDataValue($data, $rule);

        if (!empty($val)) {
            return !empty($value) || '0' == $value;
        }

        return true;
    }

    /**
     * 验证某个字段没有值的情况下必须
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function requireWithout($value, $rule, array $data = []): bool
    {
        $val = $this->getDataValue($data, $rule);

        if (empty($val)) {
            return !empty($value) || '0' == $value;
        }

        return true;
    }

    /**
     * 验证是否在范围内
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function in($value, $rule): bool
    {
        return in_array($value, is_array($rule) ? $rule : explode(',', $rule));
    }

    /**
     * 验证是否不在某个范围
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function notIn($value, $rule): bool
    {
        return !in_array($value, is_array($rule) ? $rule : explode(',', $rule));
    }

    /**
     * between验证数据
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function between($value, $rule): bool
    {
        if (is_string($rule)) {
            $rule = explode(',', $rule);
        }
        [$min, $max] = $rule;

        return $value >= $min && $value <= $max;
    }

    /**
     * 使用notbetween验证数据
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function notBetween($value, $rule): bool
    {
        if (is_string($rule)) {
            $rule = explode(',', $rule);
        }
        [$min, $max] = $rule;

        return $value < $min || $value > $max;
    }

    /**
     * 验证数据长度
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function length($value, $rule): bool
    {
        if (is_array($value)) {
            $length = count($value);
        } else {
            $length = mb_strlen((string)$value);
        }

        if (is_string($rule) && strpos($rule, ',')) {
            // 长度区间
            [$min, $max] = explode(',', $rule);
            return $length >= $min && $length <= $max;
        }

        // 指定长度
        return $length == $rule;
    }

    /**
     * 验证数据最大长度
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function max($value, $rule): bool
    {
        if (is_array($value)) {
            $length = count($value);
        } else {
            $length = mb_strlen((string)$value);
        }

        return $length <= $rule;
    }

    /**
     * 验证数据最小长度
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function min($value, $rule): bool
    {
        if (is_array($value)) {
            $length = count($value);
        } else {
            $length = mb_strlen((string)$value);
        }

        return $length >= $rule;
    }

    /**
     * 验证日期
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function after($value, $rule, array $data = []): bool
    {
        return strtotime($value) >= strtotime($rule);
    }

    /**
     * 验证日期
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function before($value, $rule, array $data = []): bool
    {
        return strtotime($value) <= strtotime($rule);
    }

    /**
     * 验证日期
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function afterWith($value, $rule, array $data = []): bool
    {
        $rule = $this->getDataValue($data, $rule);
        return !is_null($rule) && strtotime($value) >= strtotime($rule);
    }

    /**
     * 验证日期
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @param array $data 数据
     * @return bool
     */
    public function beforeWith($value, $rule, array $data = []): bool
    {
        $rule = $this->getDataValue($data, $rule);
        return !is_null($rule) && strtotime($value) <= strtotime($rule);
    }

    /**
     * 验证有效期
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function expire($value, $rule): bool
    {
        if (is_string($rule)) {
            $rule = explode(',', $rule);
        }

        [$start, $end] = $rule;

        if (!is_numeric($start)) {
            $start = strtotime($start);
        }

        if (!is_numeric($end)) {
            $end = strtotime($end);
        }

        return time() >= $start && time() <= $end;
    }

    /**
     * 验证IP许可
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function allowIp($value, $rule): bool
    {
        return in_array($value, is_array($rule) ? $rule : explode(',', $rule));
    }

    /**
     * 验证IP禁用
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则
     * @return bool
     */
    public function denyIp($value, $rule): bool
    {
        return !in_array($value, is_array($rule) ? $rule : explode(',', $rule));
    }

    /**
     * 验证字段可传可不传
     *
     * @return bool
     */
    public function nullable(): bool
    {
        return true;
    }


    /**
     * 使用正则验证数据
     * @access public
     * @param mixed $value 字段值
     * @param mixed $rule 验证规则 正则规则或者预定义正则名
     * @return bool
     */
    public function regex($value, $rule): bool
    {
        if (isset($this->regex[$rule])) {
            $rule = $this->regex[$rule];
        } elseif (isset($this->defaultRegex[$rule])) {
            $rule = $this->defaultRegex[$rule];
        }

        if (is_string($rule) && 0 !== strpos($rule, '/') && !preg_match('/\/[imsU]{0,4}$/', $rule)) {
            // 不是正则表达式则两端补上/
            $rule = '/^' . $rule . '$/';
        }

        return is_scalar($value) && 1 === preg_match($rule, (string)$value);
    }

    /**
     * 获取错误信息
     * @return array|string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * 获取数据值
     * @access protected
     * @param array $data 数据
     * @param string $key 数据标识 支持二维
     * @return mixed
     */
    protected function getDataValue(array $data, $key)
    {
        if (is_numeric($key)) {
            $value = $key;
        } elseif (is_string($key) && strpos($key, '.')) {
            $keys = explode('.', $key);
            if (!in_array('*', $keys)) {
                // 支持多维数组验证
                foreach (explode('.', $key) as $key) {
                    if (!isset($data[$key])) {
                        $value = null;
                        break;
                    }
                    $value = $data = $data[$key];
                }
            }else {
                $value = null;
                if (count($keys) == 2 && $keys[1] === '*') {
                    if (isset($data[$keys[0]])) {
                        $value = $data[$keys[0]];
                    }
                }else if(count($keys) == 3 && $keys[1] === '*') {
                    if (isset($data[$keys[0]])) {
                        $data = $data[$keys[0]];
                        if (is_array($data) && isset($data[0][$keys[2]])) {
                            $value = array_column($data, $keys[2]);
                        }
                    }
                }
            }
        } else {
            $value = $data[$key] ?? null;
        }

        return $value;
    }

    /**
     * 获取验证规则的错误提示信息
     * @access protected
     * @param string $attribute 字段英文名
     * @param string $title 字段描述名
     * @param string $type 验证规则名称
     * @param mixed $rule 验证规则数据
     * @return string|array
     */
    protected function getRuleMsg(string $attribute, string $title, string $type, $rule)
    {
        if (isset($this->message[$attribute . '.' . $type])) {
            $msg = $this->message[$attribute . '.' . $type];
        } elseif (isset($this->message[$attribute][$type])) {
            $msg = $this->message[$attribute][$type];
        } elseif (isset($this->message[$attribute])) {
            $msg = $this->message[$attribute];
        } elseif (isset($this->typeMsg[$type])) {
            $msg = $this->typeMsg[$type];
        } elseif (0 === strpos($type, 'require')) {
            $msg = $this->typeMsg['require'];
        } else {
            $msg = $title . ' not conform to the rules';
        }

        if (is_array($msg)) {
            return $this->errorMsgIsArray($msg, $rule, $title);
        }

        return $this->parseErrorMsg($msg, $rule, $title);
    }

    /**
     * 获取验证规则的错误提示信息
     * @access protected
     * @param string $msg 错误信息
     * @param mixed $rule 验证规则数据
     * @param string $title 字段描述名
     * @return string|array
     */
    protected function parseErrorMsg(string $msg, $rule, string $title)
    {
        if (is_array($msg)) {
            return $this->errorMsgIsArray($msg, $rule, $title);
        }

        // rule若是数组则转为字符串
        if (is_array($rule)) {
            $rule = implode(',', $rule);
        }

        if (is_scalar($rule) && false !== strpos($msg, ':')) {
            // 变量替换
            if (is_string($rule) && strpos($rule, ',')) {
                $array = array_pad(explode(',', $rule), 3, '');
            } else {
                $array = array_pad([], 3, '');
            }

            $msg = str_replace(
                [':attribute', ':1', ':2', ':3'],
                [$title, $array[0], $array[1], $array[2]],
                $msg
            );

            if (strpos($msg, ':rule')) {
                $msg = str_replace(':rule', (string)$rule, $msg);
            }
        }

        return $msg;
    }

    /**
     * 错误信息数组处理
     * @access protected
     * @param array $msg 错误信息
     * @param mixed $rule 验证规则数据
     * @param string $title 字段描述名
     * @return array
     */
    protected function errorMsgIsArray(array $msg, $rule, string $title)
    {
        foreach ($msg as $key => $val) {
            if (is_string($val)) {
                $msg[$key] = $this->parseErrorMsg($val, $rule, $title);
            }
        }
        return $msg;
    }

    /**
     * 获取数据验证的场景
     * @access protected
     * @param string $scene 验证场景
     * @return void
     */
    protected function getScene(string $scene): void
    {
        $this->only = $this->append = $this->remove = [];

        if (method_exists($this, 'scene' . $scene)) {
            call_user_func([$this, 'scene' . $scene]);
        } elseif (isset($this->scene[$scene])) {
            // 如果设置了验证适用场景
            $this->only = $this->scene[$scene];
        }
    }

    /**
     * 动态方法 直接调用is方法进行验证
     * @access public
     * @param string $method 方法名
     * @param array $args 调用参数
     * @return bool
     */
    public function __call($method, $args)
    {
        if ('is' == strtolower(substr($method, 0, 2))) {
            $method = substr($method, 2);
        }

        array_push($args, lcfirst($method));

        return call_user_func_array([$this, 'is'], $args);
    }
}