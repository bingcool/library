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

trait HttpMethodTrait
{
    /**
     * @param $uri
     * @param $body
     * @param $query
     * @return array
     */
    public function post($uri, $body = [], $query = [])
    {
        $method = 'POST';
        $header = $this->getHeaders();
        if (!empty($query)) {
            ksort($query);
            $queryParams = http_build_query($query);
            $uri = $uri . '?' . $queryParams;
        }
        $uri = '/'.trim($uri,'/');
        $authorization = $this->buildSignature($header, $method, $uri, $this->accessId, $this->accessKey);
        $header['Authorization'] = $authorization;

        $client = $this->getHttpClient();

        if (!empty($body)) {
            $response = $client->request($method, $uri, [
                'headers' => $header,
                'json' => $body,
            ]);
        }else {
            $response = $client->request($method, $uri, [
                'headers' => $header,
            ]);
        }

        $result = json_decode($response->getBody()->getContents(), true);

        return $result;
    }

    /**
     * @param $uri
     * @param $query
     * @return array
     */
    public function get($uri, $query = [])
    {
        $method = 'GET';
        $header = $this->getHeaders();
        if (!empty($query)) {
            ksort($query);
            $queryParams = http_build_query($query);
            $uri = $uri . '?' . $queryParams;
        }
        $uri = '/'.trim($uri,'/');
        $authorization = $this->buildSignature($header, $method, $uri, $this->accessId, $this->accessKey);
        $header['Authorization'] = $authorization;

        $client = $this->getHttpClient();
        $response = $client->request($method, $uri, [
            'headers' => $header,
        ]);

        $result = json_decode($response->getBody()->getContents(), true);

        return $result;
    }

    /**
     * @param $uri
     * @param $body
     * @param $query
     * @return mixed
     */
    public function put($uri, $body = [], $query = [])
    {
        $method = 'PUT';
        $header = $this->getHeaders();
        if (!empty($query)) {
            ksort($query);
            $queryParams = http_build_query($query);
            $uri = $uri . '?' . $queryParams;
        }
        $uri = '/'.trim($uri,'/');
        $authorization = $this->buildSignature($header, $method, $uri, $this->accessId, $this->accessKey);
        $header['Authorization'] = $authorization;

        $client = $this->getHttpClient();
        if (!empty($body)) {
            $response = $client->request($method, $uri, [
                'headers' => $header,
                'json' => $body,
            ]);
        }else {
            $response = $client->request($method, $uri, [
                'headers' => $header,
            ]);
        }

        $result = json_decode($response->getBody()->getContents(), true);

        return $result;
    }

    /**
     * @param $uri
     * @param $body
     * @param $query
     * @return mixed
     */
    public function putRawBody($uri, $body, $query = [])
    {
        $method = 'PUT';
        $header = $this->getHeaders();
        $header['Content-Type'] = 'application/json';
        if (!empty($query)) {
            ksort($query);
            $queryParams = http_build_query($query);
            $uri = $uri . '?' . $queryParams;
        }

        $uri = '/'.trim($uri,'/');
        $authorization = $this->buildSignature($header, $method, $uri, $this->accessId, $this->accessKey);
        $header['Authorization'] = $authorization;

        $client = $this->getHttpClient();
        if (!empty($body)) {
            $response = $client->request($method, $uri, [
                'headers' => $header,
                'body' => $body,
            ]);
        }else {
            $response = $client->request($method, $uri, [
                'headers' => $header,
            ]);
        }

        $result = json_decode($response->getBody()->getContents(), true);

        return $result;
    }

    public function delete($uri, $query = [])
    {
        $method = 'DELETE';
        $header = $this->getHeaders();
        if (!empty($query)) {
            ksort($query);
            $queryParams = http_build_query($query);
            $uri = $uri . '?' . $queryParams;
        }

        $uri = '/'.trim($uri,'/');
        $authorization = $this->buildSignature($header, $method, $uri, $this->accessId, $this->accessKey);
        $header['Authorization'] = $authorization;

        $client = $this->getHttpClient();
        if (!empty($body)) {
            $response = $client->request($method, $uri, [
                'headers' => $header,
                'body' => $body,
            ]);
        }else {
            $response = $client->request($method, $uri, [
                'headers' => $header,
            ]);
        }

        json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $result
     * @param $recordSchema
     * @param array $fields 获取指定的字段的值
     * @return array
     */
    protected function parseData($result, $recordSchema, $fields = [])
    {
        $recordSchemaIndexFieldMap = [];

        foreach ($recordSchema['fields'] as $index=>$recordSchemaItem) {
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
        }else {
            foreach ($recordSchemaIndexFieldMap as $item) {
                $index = $item['index'];
                $recordSchemaIndexFieldMapNew[$index] = $item;
            }
        }

        $newResult = [];
        foreach ($result['Records'] as  $record) {
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
            $recordSchemaIndexFieldMapNew1 = array_column($recordSchemaIndexFieldMapNew, null, 'name');
            $newResult[] = [
                'sequence' => $record['Sequence'],
                'data' => $recordSchemaIndexFieldMapNew1
            ];
        }

        return $newResult;
    }

    /**
     * @param $recordData
     * @return bool
     */
    public function isInsertOperation($recordData)
    {
        if (isset($recordData['new_dts_sync_dts_operation_flag']) && $recordData['new_dts_sync_dts_operation_flag']['value'] == 'I') {
            return true;
        }
        return false;
    }

    /**
     * @param $recordData
     * @return bool
     */
    public function isUpdateOperation($recordData)
    {
        if (isset($recordData['new_dts_sync_dts_operation_flag']) && $recordData['new_dts_sync_dts_operation_flag']['value'] == 'U') {
            return true;
        }
        return false;
    }

    /**
     * @param $recordData
     * @return bool
     */
    public function isDeleteOperation(&$recordData)
    {
        if (isset($recordData['new_dts_sync_dts_operation_flag']) && $recordData['new_dts_sync_dts_operation_flag']['value'] == 'D') {
            return true;
        }
        return false;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        $date = new \DateTime();
        $date->setTimezone(new \DateTimeZone('GMT'));
        $date = $date->format('D, d M Y H:i:s \G\M\T');
        $header = [
            'x-datahub-client-version' => '1.1',
            'x-datahub-security-token'  => '',
            'Date' => $date,
            'Content-Type' => 'application/json',
        ];
        return $header;
    }

    /**
     * 根据阿里云的Authorization字段计算的方法来实现
     *
     * @see https://help.aliyun.com/zh/datahub/developer-reference/nerbcz?spm=a2c4g.11186623.help-menu-53345.d_4_1_0.18b583b5a7suXq
     *
     * @param $header
     * @param $method
     * @param $url
     * @param $accountId
     * @param $accountKey
     * @return string
     */
    public function buildSignature($header, $method, $url, $accountId, $accountKey) {
        // 初始化签名构建数组
        $builder = array();

        $builder[] = strtoupper($method);
        $builder[] = $header['Content-Type'] ?? '';
        $builder[] = $header['Date'] ?? '';

        // 筛选需要签名的头信息
        $headersToSign = array();
        foreach ($header as $k => $v) {
            $lower = strtolower($k);
            if (str_contains($lower, 'x-datahub-')) {
                $headersToSign[$lower] = is_array($v) ? $v : array($v);
            }
        }

        // header字典序自小到大排序
        ksort($headersToSign);

        // 添加排序后的头信息到签名构建数组
        foreach ($headersToSign as $k => $values) {
            foreach ($values as $v) {
                $builder[] = "$k:$v";
            }
        }

        // 添加URL到签名构建数组
        $builder[] = $url;

        // 构建规范化字符串
        $canonString = implode("\n", $builder);

        // 计算HMAC-SHA1签名
        $hash = hash_hmac('sha1', $canonString, $accountKey, true);
        $signature = base64_encode($hash);

        // 构建Authorization头
        $authorization = "DATAHUB $accountId:$signature";

        return $authorization;
    }
}