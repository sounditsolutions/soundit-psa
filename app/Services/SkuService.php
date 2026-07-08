<?php

namespace App\Services;

use App\Models\RecurringInvoiceProfileLine;
use App\Models\Sku;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SkuService
{
    public function createSku(array $data): Sku
    {
        return Sku::create($data);
    }

    public function updateSku(Sku $sku, array $data): Sku
    {
        $sku->update($data);

        return $sku;
    }

    public function buildFilteredQuery(array $filters = []): Builder
    {
        $query = Sku::query()
            ->addSelect(['skus.*'])
            ->addSelect(['profile_count' => RecurringInvoiceProfileLine::selectRaw('count(distinct profile_id)')
                ->whereColumn('sku_id', 'skus.id'),
            ])
            ->orderBy('name');

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['quantity_type'])) {
            $query->where('default_quantity_type', $filters['quantity_type']);
        }

        return $query;
    }

    public function getList(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->buildFilteredQuery($filters)->paginate($perPage)->withQueryString();
    }
}
