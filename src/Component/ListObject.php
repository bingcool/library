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

use Common\Library\Db\Query;
use Common\Library\Exception\ListFormatException;

abstract class ListObject
{

    /**
     * @var int
     */
    protected $page = 1;

    /**
     * @var int
     */
    protected $pageSize = 20;

    /**
     * @var array
     */
    protected $multiOrderBy = [];

    /**
     * @var bool
     */
    protected $showAll = false;

    /**
     * @var bool
     */
    protected $isEnablePage = false;

    /**
     * @var ListItemFormatter
     */
    protected $formatter;

    /**
     * @var Query
     */
    protected $query;

    /**
     * @var bool
     */
    protected $hadBuildParams = false;

    /**
     * __construct
     */
    public function __construct()
    {
        $this->query = $this->buildQuery();
        $this->formatter = $this->buildFormatter();
    }

    /**
     * @param int $offset
     * @return void
     */
    public function setPage(int $page)
    {
        $this->isEnablePage = true;
        $this->showAll = false;
        $this->page = $page;
    }

    /**
     * @param int $pageSize
     * @return void
     */
    public function setPageSize(int $pageSize)
    {
        $this->isEnablePage = true;
        $this->showAll = false;
        $this->pageSize = $pageSize;
    }


    /**
     * @return void
     */
    public function setShowAll(bool $showAll = true)
    {
        if ($showAll === false) {
            throw new ListFormatException('showAll only for boolean true');
        }

        $this->showAll = true;
        $this->isEnablePage = false;
    }

    /**
     * @param $orderByField
     * @param $orderSort
     * @return void
     */
    public function setOrder(string $orderByField, string $orderSort)
    {
        if (strpos($orderSort, ';') !== false) {
            return;
        }

        switch (strtolower($orderSort)) {
            case 'asc':
                $orderSort = 'ASC';
                break;
            case 'desc';
            default:
                $orderSort = 'DESC';
                break;
        }

        $this->multiOrderBy[$orderByField] = "{$orderSort}";
    }

    /**
     * @return string
     */
    public function buildOrderBy()
    {
        if (!empty($this->multiOrderBy)) {
            $this->query->order($this->multiOrderBy);
        }
    }

    /**
     * @return string
     */
    protected function buildLimit()
    {
        if (!empty($this->pageSize) && $this->isEnablePage && !$this->showAll) {
            $this->query->limit(($this->page - 1) * $this->pageSize, $this->pageSize);
        }
    }

    /**
     * @param ListItemFormatter $formatter
     * @return void
     */
    public function setFormatter(ListItemFormatter $formatter)
    {
        $this->formatter = $formatter;
    }

    /**
     * @return ListItemFormatter|null
     */
    public function getFormatter(): ?ListItemFormatter
    {
        return $this->formatter;
    }

    /**
     * @return Query|null
     */
    public function getQuery(): ?Query
    {
        return $this->query;
    }

    abstract protected function buildFormatter(): ?ListItemFormatter;

    abstract protected function buildQuery(): ?Query;

    abstract protected function buildParams();

    abstract public function total(): int;

    abstract public function find();


}
