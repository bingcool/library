<?php

declare(strict_types=1);

namespace Swoolefy\Library\Nacos\Provider\Config\Model;

use Swoolefy\Library\Nacos\Provider\Model\BaseResponse;
use Swoolefy\Library\Nacos\Http\HttpResponse;

class HistoryListResponse extends BaseResponse
{
    protected int $totalCount = 0;

    protected int $pageNumber = 0;

    protected int $pagesAvailable = 0;

    /**
     * @var HistoryItem[]
     */
    protected array $pageItems = [];

    public function __construct(HttpResponse $response)
    {
        parent::__construct($response);
        foreach ($response->json(true) as $k => $v) {
            if ('pageItems' === $k) {
                $pageItems = [];
                foreach ($v as $row) {
                    $pageItems[] = new HistoryItem($row);
                }
                $v = $pageItems;
            }
            $this->$k = $v;
        }
    }

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function getPageNumber(): int
    {
        return $this->pageNumber;
    }

    public function getPagesAvailable(): int
    {
        return $this->pagesAvailable;
    }

    /**
     * @return HistoryItem[]
     */
    public function getPageItems(): array
    {
        return $this->pageItems;
    }
}
