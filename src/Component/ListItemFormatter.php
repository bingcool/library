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

namespace Common\Library\Component;

use Common\Library\Db\Collection;

abstract class ListItemFormatter
{
    protected $data;

    protected $listData;

    protected $mapData = [];

    protected $batchFlag = false;

    /**
     * 单个处理
     *
     * @param $data
     * @return void
     */
    public function setData()
    {
        $this->data = $data;
        $this->batchFlag = false;
    }

    /**
     * 列表处理
     *
     * @param $listData
     * @return $this
     */
    public function setListData($listData)
    {
        if ($listData instanceof Collection) {
            $listData = $listData->toArray();
        }

        $this->listData = $listData;
        $this->batchFlag = true;
        return $this;
    }

    /**
     * @param array $mapData
     */
    public function setMapData(array $mapData)
    {
        $this->mapData = $mapData;
    }

    /**
     * @return bool
     */
    public function hasMap()
    {
        return !empty($this->mapData);
    }

    /**
     * @param string $mapKey
     * @param string $key
     * @return bool
     */
    public function hasMapData(string $mapKey, string $key)
    {
        return array_key_exists($mapKey, $this->mapData) && array_key_exists($key, $this->mapData[$mapKey]);
    }

    /**
     * @param string $mapKey
     * @param string $key
     * @return mixed|null
     */
    public function getMapData(string $mapKey, string $key)
    {
        return $this->mapData[$mapKey][$key] ?? null;
    }

    /**
     * 集合结果
     *
     * @param array $result
     * @param $item
     * @return void
     */
    protected function collectResult(array &$result, $item)
    {
        $result[] = $item;
    }

    /**
     * @return array
     */
    public function result(): array
    {
        $result = [];

        $this->buildMapData();

        if (!$this->batchFlag) {
            $data = $this->data;
            $result = $this->format($data);
            return $result;
        }

        foreach ($this->listData as $data) {
            $this->collectResult($result, $this->format($data));
        }

        return $result;
    }
    /**
     * @return void
     */
    protected function buildMapData()
    {

    }
    abstract protected function format($data);
}