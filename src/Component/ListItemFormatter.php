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

use Common\Library\Collection;

abstract class ListItemFormatter
{
    /**
     * @var array
     */
    protected $data;

    /**
     * @var array
     */
    protected $listData;

    /**
     * @var array
     */
    protected $mapData = [];

    /**
     * @var bool
     */
    protected $batchFlag = false;

    /**
     * 单个处理
     *
     * @param array|Collection $data
     * @return void
     */
    public function setData($data)
    {
        if ($data instanceof Collection) {
            $data = $data->toArray();
        }
        $this->data = $data;
        $this->batchFlag = false;
    }

    /**
     * 列表处理
     *
     * @param array|Collection $listData
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
     * @return array
     */
    protected function getBuildData(): array
    {
        return $this->batchFlag ? $this->listData : [$this->data];
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

        $this->buildMapData($this->getBuildData());

        if (!$this->batchFlag) {
            $data = $this->data;
            $result = $this->format($data);
        }else {
            foreach ($this->listData as $data) {
                if ($data instanceof Collection) {
                    $data = $data->toArray();
                }
                $this->collectResult($result, $this->format($data));
            }
        }

        return $result;
    }

    abstract protected function buildMapData(array $list);

    abstract protected function format(array $data): array;
}