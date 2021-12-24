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

namespace Common\Library\Protobuf;

use Google\Protobuf\Any;
use Google\Protobuf\Descriptor;
use Google\Protobuf\DescriptorPool;
use Google\Protobuf\FieldDescriptor;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\GPBLabel;
use Common\Library\Exception\ValidationException;
use RuntimeException;

/**
 * Collection of methods to help with serialization of protobuf objects
 */
class Serializer
{
    const MAP_KEY_FIELD_NAME = 'key';
    const MAP_VALUE_FIELD_NAME = 'value';

    private static $phpArraySerializer;

    private $descriptorMaps = [];

    /**
     * Serializer constructor
     */
    public function __construct()
    {

    }

    /**
     * Encode protobuf message as a PHP array
     *
     * @param mixed $message
     * @return array
     * @throws ValidationException
     */
    public function encodeMessage($message)
    {
        // Get message descriptor
        $pool = DescriptorPool::getGeneratedPool();
        $messageType = $pool->getDescriptorByClassName(get_class($message));
        try {
            return $this->encodeMessageImpl($message, $messageType);
        } catch (\Exception $e) {
            throw new ValidationException(
                "Error encoding message: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Decode PHP array into the specified protobuf message
     *
     * @param mixed $message
     * @param array $data
     * @return mixed
     * @throws ValidationException
     */
    public function decodeMessage($message, array $data)
    {
        try {
            $message->mergeFromJsonString(json_encode($data));
            if ($message) {
                return $message;
            }
        } catch (\Exception $e) {
            // Get message descriptor
            $pool = DescriptorPool::getGeneratedPool();
            $messageType = $pool->getDescriptorByClassName(get_class($message));
            try {
                return $this->decodeMessageImpl($message, $messageType, $data);
            } catch (\Exception $e) {
                throw new ValidationException(
                    "Error decoding message: " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            }
        }

    }

    /**
     * @param Message $message
     * @return string Json representation of $message
     * @throws ValidationException
     */
    public static function serializeToJson($message)
    {
        return json_encode(self::serializeToArray($message), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @param $message
     * @param array $data
     * @return mixed
     * @throws ValidationException
     */
    public static function mergeFromArray(Message &$message, array $data)
    {
        return self::getPhpArraySerializer()->decodeMessage($message, $data);
    }

    /**
     * @param Message $message
     * @return array PHP array representation of $message
     * @throws ValidationException
     */
    public static function serializeToArray($message)
    {
        return self::getPhpArraySerializer()->encodeMessage($message);
    }

    /**
     * Decode an array of Any messages into a printable PHP array.
     *
     * @param $anyArray
     * @return array
     */
    public static function decodeAnyMessages($anyArray)
    {
        $results = [];
        foreach ($anyArray as $any) {
            try {
                /** @var Any $any */
                /** @var Message $unpacked */
                $unpacked = $any->unpack();
                $results[] = self::serializeToArray($unpacked);
            } catch (\Exception $ex) {
                // failed to unpack the $any object - show as unknown binary data
                $results[] = [
                    'typeUrl' => $any->getTypeUrl(),
                    'value' => '<Unknown Binary Data>',
                ];
            }
        }
        return $results;
    }

    /**
     * @param FieldDescriptor $field
     * @param $data
     * @return mixed array
     * @throws \Exception
     */
    private function encodeElement(FieldDescriptor $field, $data)
    {
        switch ($field->getType()) {
            case GPBType::MESSAGE:
                if (is_array($data)) {
                    $result = $data;
                } else {
                    $result = $this->encodeMessageImpl($data, $field->getMessageType());
                }
                break;
            default:
                $result = $data;
                break;
        }

        return $result;
    }

    private function getDescriptorMaps(Descriptor $descriptor)
    {
        if (!isset($this->descriptorMaps[$descriptor->getFullName()])) {
            $fieldsByName = [];
            $fieldCount = $descriptor->getFieldCount();
            for ($i = 0; $i < $fieldCount; $i++) {
                $field = $descriptor->getField($i);
                $fieldsByName[$field->getName()] = $field;
            }
            $fieldToOneof = [];
            $oneofCount = $descriptor->getOneofDeclCount();
            for ($i = 0; $i < $oneofCount; $i++) {
                $oneof = $descriptor->getOneofDecl($i);
                $oneofFieldCount = $oneof->getFieldCount();
                for ($j = 0; $j < $oneofFieldCount; $j++) {
                    $field = $oneof->getField($j);
                    $fieldToOneof[$field->getName()] = $oneof->getName();
                }
            }
            $this->descriptorMaps[$descriptor->getFullName()] = [$fieldsByName, $fieldToOneof];
        }
        return $this->descriptorMaps[$descriptor->getFullName()];
    }

    /**
     * @param Message $message
     * @param Descriptor $messageType
     * @return array
     * @throws \Exception
     */
    private function encodeMessageImpl($message, Descriptor $messageType)
    {
        $data = [];

        $fieldCount = $messageType->getFieldCount();
        for ($i = 0; $i < $fieldCount; $i++) {
            $field = $messageType->getField($i);
            $key = $field->getName();
            $getter = $this->getGetter($key);
            $v = $message->$getter();

            if (is_null($v)) {
                continue;
            }

            // Check and skip unset fields inside oneofs
            list($_, $fieldsToOneof) = $this->getDescriptorMaps($messageType);
            if (isset($fieldsToOneof[$key])) {
                $oneofName = $fieldsToOneof[$key];
                $oneofGetter = $this->getGetter($oneofName);
                if ($message->$oneofGetter() !== $key) {
                    continue;
                }
            }

            if ($field->isMap()) {
                list($mapFieldsByName, $_) = $this->getDescriptorMaps($field->getMessageType());
                $keyField = $mapFieldsByName[self::MAP_KEY_FIELD_NAME];
                $valueField = $mapFieldsByName[self::MAP_VALUE_FIELD_NAME];
                $arr = [];
                foreach ($v as $k => $vv) {
                    $arr[$this->encodeElement($keyField, $k)] = $this->encodeElement($valueField, $vv);
                }
                $v = $arr;
            } elseif ($field->getLabel() === GPBLabel::REPEATED) {
                $arr = [];
                foreach ($v as $k => $vv) {
                    $arr[$k] = $this->encodeElement($field, $vv);
                }
                $v = $arr;
            } else {
                $v = $this->encodeElement($field, $v);
            }

            $key = self::toCamelCase($key);
            $data[$key] = $v;
        }

        return $data;
    }

    /**
     * @param FieldDescriptor $field
     * @param mixed $data
     * @return mixed
     * @throws \Exception
     */
    private function decodeElement(FieldDescriptor $field, $data)
    {
        switch ($field->getType()) {
            case GPBType::MESSAGE:
                if ($data instanceof Message) {
                    return $data;
                }
                $messageType = $field->getMessageType();
                $klass = $messageType->getClass();
                $msg = new $klass();
                return $this->decodeMessageImpl($msg, $messageType, $data);
            default:
                return $data;
        }
    }

    /**
     * @param Message $message
     * @param Descriptor $messageType
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    private function decodeMessageImpl($message, Descriptor $messageType, $data)
    {
        list($fieldsByName, $_) = $this->getDescriptorMaps($messageType);
        foreach ($data as $key => $v) {
            // Get the field by tag number or name
            $fieldName = self::toSnakeCase($key);

            // Unknown field found
            if (!isset($fieldsByName[$fieldName])) {
                throw new RuntimeException(sprintf(
                    "cannot handle unknown field %s on message %s",
                    $fieldName,
                    $messageType->getFullName()
                ));
            }

            /** @var $field FieldDescriptor */
            $field = $fieldsByName[$fieldName];

            if ($field->isMap()) {
                list($mapFieldsByName, $_) = $this->getDescriptorMaps($field->getMessageType());
                $keyField = $mapFieldsByName[self::MAP_KEY_FIELD_NAME];
                $valueField = $mapFieldsByName[self::MAP_VALUE_FIELD_NAME];
                $arr = [];
                foreach ($v as $k => $vv) {
                    $arr[$this->decodeElement($keyField, $k)] = $this->decodeElement($valueField, $vv);
                }
                $value = $arr;
            } elseif ($field->getLabel() === GPBLabel::REPEATED) {
                $arr = [];
                foreach ($v as $k => $vv) {
                    $arr[$k] = $this->decodeElement($field, $vv);
                }
                $value = $arr;
            } else {
                $value = $this->decodeElement($field, $v);
            }

            $setter = $this->getSetter($field->getName());
            $message->$setter($value);

            // We must unset $value here, otherwise the protobuf c extension will mix up the references
            // and setting one value will change all others
            unset($value);
        }
        return $message;
    }

    /**
     * @param string $name
     * @return string Getter function
     */
    public static function getGetter($name)
    {
        return 'get' . ucfirst(self::toCamelCase($name));
    }

    /**
     * @param string $name
     * @return string Setter function
     */
    public static function getSetter($name)
    {
        return 'set' . ucfirst(self::toCamelCase($name));
    }

    /**
     * Convert string from camelCase to snake_case
     *
     * @param string $key
     * @return string
     */
    public static function toSnakeCase($key)
    {
        return strtolower(preg_replace(['/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'], '$1_$2', $key));
    }

    /**
     * Convert string from snake_case to camelCase
     *
     * @param string $key
     * @return string
     */
    public static function toCamelCase($key)
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
    }

    /**
     * @return Serializer
     */
    private static function getPhpArraySerializer()
    {
        if (is_null(self::$phpArraySerializer)) {
            self::$phpArraySerializer = new Serializer();
        }
        return self::$phpArraySerializer;
    }

}