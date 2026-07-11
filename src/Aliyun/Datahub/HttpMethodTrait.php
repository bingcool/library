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

namespace Swoolefy\Library\Aliyun\Datahub;

/**
 * DataHub REST 请求与签名、以及 Tuple 记录解析 / DTS 操作类型判断。
 *
 * 依赖宿主类提供：`$accessId`、`$accessKey`、`getHttpClient()`。
 *
 * @see https://help.aliyun.com/zh/datahub/developer-reference/nerbcz
 */
trait HttpMethodTrait
{
    /**
     * POST JSON 请求。
     *
     * @param string $uri
     * @param array $body
     * @param array $query
     * @return array|null
     */
    public function post($uri, $body = [], $query = [])
    {
        $method = 'POST';
        $header = $this->getHeaders();
        $uri = $this->buildRequestUri($uri, $query);
        $header['Authorization'] = $this->buildSignature(
            $header,
            $method,
            $uri,
            $this->accessId,
            $this->accessKey
        );

        $client = $this->getHttpClient();
        if (!empty($body)) {
            $response = $client->request($method, $uri, [
                'headers' => $header,
                'json' => $body,
            ]);
        } else {
            $response = $client->request($method, $uri, [
                'headers' => $header,
            ]);
        }

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * GET 请求。
     *
     * @param string $uri
     * @param array $query
     * @return array|null
     */
    public function get($uri, $query = [])
    {
        $method = 'GET';
        $header = $this->getHeaders();
        $uri = $this->buildRequestUri($uri, $query);
        $header['Authorization'] = $this->buildSignature(
            $header,
            $method,
            $uri,
            $this->accessId,
            $this->accessKey
        );

        $client = $this->getHttpClient();
        $response = $client->request($method, $uri, [
            'headers' => $header,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * PUT JSON 请求。
     *
     * @param string $uri
     * @param array $body
     * @param array $query
     * @return array|null
     */
    public function put($uri, $body = [], $query = [])
    {
        $method = 'PUT';
        $header = $this->getHeaders();
        $uri = $this->buildRequestUri($uri, $query);
        $header['Authorization'] = $this->buildSignature(
            $header,
            $method,
            $uri,
            $this->accessId,
            $this->accessKey
        );

        $client = $this->getHttpClient();
        if (!empty($body)) {
            $response = $client->request($method, $uri, [
                'headers' => $header,
                'json' => $body,
            ]);
        } else {
            $response = $client->request($method, $uri, [
                'headers' => $header,
            ]);
        }

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * PUT 原始 JSON 字符串 Body（用于保留数字字符串键，如 shardId="0"）。
     *
     * @param string $uri
     * @param string $body 原始 JSON 文本
     * @param array $query
     * @return array|null
     */
    public function putRawBody($uri, $body, $query = [])
    {
        $method = 'PUT';
        $header = $this->getHeaders();
        $header['Content-Type'] = 'application/json';
        $uri = $this->buildRequestUri($uri, $query);
        $header['Authorization'] = $this->buildSignature(
            $header,
            $method,
            $uri,
            $this->accessId,
            $this->accessKey
        );

        $client = $this->getHttpClient();
        if (!empty($body)) {
            $response = $client->request($method, $uri, [
                'headers' => $header,
                'body' => $body,
            ]);
        } else {
            $response = $client->request($method, $uri, [
                'headers' => $header,
            ]);
        }

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * DELETE 请求。
     *
     * 注意：历史上使用未定义的 `$body` 变量判断；empty(未定义) 恒为 true 的否定分支，
     * 实际始终走无 body 请求。此处保持相同判断写法，行为不变。
     *
     * @param string $uri
     * @param array $query
     * @return void
     */
    public function delete($uri, $query = [])
    {
        $method = 'DELETE';
        $header = $this->getHeaders();
        $uri = $this->buildRequestUri($uri, $query);
        $header['Authorization'] = $this->buildSignature(
            $header,
            $method,
            $uri,
            $this->accessId,
            $this->accessKey
        );

        $client = $this->getHttpClient();
        if (!empty($body)) {
            $response = $client->request($method, $uri, [
                'headers' => $header,
                'body' => $body,
            ]);
        } else {
            $response = $client->request($method, $uri, [
                'headers' => $header,
            ]);
        }

        json_decode($response->getBody()->getContents(), true);
    }

    /**
     * 规范化 URI：可选 query 排序拼接，并保证以 `/` 开头。
     *
     * @param string $uri
     * @param array $query
     * @return string
     */
    protected function buildRequestUri($uri, array $query = [])
    {
        if (!empty($query)) {
            ksort($query);
            $uri = $uri . '?' . http_build_query($query);
        }

        return '/' . trim($uri, '/');
    }

    /**
     * 将 DataHub 返回的 Records + RecordSchema 解析为按字段名组织的结构。
     *
     * 每条记录格式：`['sequence' => ..., 'data' => [fieldName => ['name','index','value'], ...]]`
     * 字段 `new_dts_sync_dts_utc_timestamp` 会格式化为 `Y-m-d H:i:s`。
     *
     * @param array $result 含 Records 的 API 响应
     * @param array $recordSchema Topic RecordSchema
     * @param array $fields 仅解析这些字段名；空数组表示全部字段
     * @return array
     */
    protected function parseData($result, $recordSchema, $fields = [])
    {
        $recordSchemaIndexFieldMap = [];

        foreach ($recordSchema['fields'] as $index => $recordSchemaItem) {
            $recordSchemaItem['index'] = $index;
            $recordSchemaIndexFieldMap[$recordSchemaItem['name']] = $recordSchemaItem;
        }

        $recordSchemaIndexFieldMapNew = [];

        if (!empty($fields)) {
            foreach ($fields as $field) {
                if (isset($recordSchemaIndexFieldMap[$field])) {
                    $index = $recordSchemaIndexFieldMap[$field]['index'];
                    $recordSchemaIndexFieldMapNew[$index] = $recordSchemaIndexFieldMap[$field];
                }
            }
        } else {
            foreach ($recordSchemaIndexFieldMap as $item) {
                $index = $item['index'];
                $recordSchemaIndexFieldMapNew[$index] = $item;
            }
        }

        $newResult = [];
        foreach ($result['Records'] as $record) {
            $recordData = $record['Data'];
            foreach ($recordSchemaIndexFieldMapNew as $index => &$item) {
                $item['value'] = $recordData[$index] ?? '';
                if ($item['name'] == 'new_dts_sync_dts_utc_timestamp') {
                    $item['value'] = date('Y-m-d H:i:s', $item['value']);
                }

                if (isset($item['type'])) {
                    unset($item['type']);
                }

                if (isset($item['comment'])) {
                    unset($item['comment']);
                }
            }
            unset($item);

            $recordSchemaIndexFieldMapNew1 = array_column($recordSchemaIndexFieldMapNew, null, 'name');
            $newResult[] = [
                'sequence' => $record['Sequence'],
                'data' => $recordSchemaIndexFieldMapNew1,
            ];
        }

        return $newResult;
    }

    /**
     * 是否为 DTS 插入操作（operation_flag = I）。
     *
     * @param array $recordData parseData 返回的单条 data
     * @return bool
     */
    public function isInsertOperation($recordData)
    {
        if (isset($recordData['new_dts_sync_dts_operation_flag'])
            && $recordData['new_dts_sync_dts_operation_flag']['value'] == 'I'
        ) {
            return true;
        }

        return false;
    }

    /**
     * 是否为 DTS 更新操作（operation_flag = U）。
     *
     * @param array $recordData parseData 返回的单条 data
     * @return bool
     */
    public function isUpdateOperation($recordData)
    {
        if (isset($recordData['new_dts_sync_dts_operation_flag'])
            && $recordData['new_dts_sync_dts_operation_flag']['value'] == 'U'
        ) {
            return true;
        }

        return false;
    }

    /**
     * 是否为 DTS 删除操作（operation_flag = D）。
     *
     * @param array $recordData parseData 返回的单条 data（按引用传入，与历史签名一致）
     * @return bool
     */
    public function isDeleteOperation(&$recordData)
    {
        if (isset($recordData['new_dts_sync_dts_operation_flag'])
            && $recordData['new_dts_sync_dts_operation_flag']['value'] == 'D'
        ) {
            return true;
        }

        return false;
    }

    /**
     * 构造 DataHub 公共请求头（含 GMT Date）。
     *
     * @return array
     */
    public function getHeaders()
    {
        $date = new \DateTime();
        $date->setTimezone(new \DateTimeZone('GMT'));
        $date = $date->format('D, d M Y H:i:s \G\M\T');

        return [
            'x-datahub-client-version' => '1.1',
            'x-datahub-security-token' => '',
            'Date' => $date,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * 按阿里云 DataHub Authorization 规则计算签名。
     *
     * CanonString = METHOD\\nContent-Type\\nDate\\n[x-datahub-* 头按小写字典序]\\nURL
     * Authorization = DATAHUB {accessId}:{base64(hmac_sha1(canon, accessKey))}
     *
     * @see https://help.aliyun.com/zh/datahub/developer-reference/nerbcz
     *
     * @param array $header
     * @param string $method
     * @param string $url 已规范化、含 query 的 path
     * @param string $accountId AccessKey ID
     * @param string $accountKey AccessKey Secret
     * @return string
     */
    public function buildSignature($header, $method, $url, $accountId, $accountKey)
    {
        $builder = [];

        $builder[] = strtoupper($method);
        $builder[] = $header['Content-Type'] ?? '';
        $builder[] = $header['Date'] ?? '';

        $headersToSign = [];
        foreach ($header as $k => $v) {
            $lower = strtolower($k);
            if (str_contains($lower, 'x-datahub-')) {
                $headersToSign[$lower] = is_array($v) ? $v : [$v];
            }
        }

        ksort($headersToSign);

        foreach ($headersToSign as $k => $values) {
            foreach ($values as $v) {
                $builder[] = "$k:$v";
            }
        }

        $builder[] = $url;

        $canonString = implode("\n", $builder);
        $hash = hash_hmac('sha1', $canonString, $accountKey, true);
        $signature = base64_encode($hash);

        return "DATAHUB $accountId:$signature";
    }
}
