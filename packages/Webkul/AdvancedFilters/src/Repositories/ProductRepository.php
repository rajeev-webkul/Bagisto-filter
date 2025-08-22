<?php

namespace Webkul\AdvancedFilters\Repositories;
use  Webkul\Product\Repositories\ProductRepository as BaseProductRepository;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Enums\AttributeTypeEnum;



class ProductRepository extends BaseProductRepository{


    public function searchFromDatabase(array $params = [])
    {
        $params['url_key'] ??= null;

        if (! empty($params['query'])) {
            $params['name'] = $params['query'];
        }

        $sortOptions = $this->getSortOptions($params);
        

        $query = $this->with([
            'attribute_family',
            'images',
            'videos',
            'attribute_values',
            'price_indices',
            'inventory_indices',
            'reviews',
            'variants',
            'variants.attribute_family',
            'variants.attribute_values',
            'variants.price_indices',
            'variants.inventory_indices',
        ])->scopeQuery(function ($query) use ($params) {
            $prefix = DB::getTablePrefix();

             $sortOptions = [
                'sort' => 'orders_count',
                'order' => 'desc',
            ];

            $qb = $query->distinct()
                ->select('products.*')
                ->leftJoin('products as variants', DB::raw('COALESCE('.$prefix.'variants.parent_id, '.$prefix.'variants.id)'), '=', 'products.id')
                ->leftJoin('product_price_indices', function ($join) {
                    $customerGroup = $this->customerRepository->getCurrentGroup();

                    $join->on('products.id', '=', 'product_price_indices.product_id')
                        ->where('product_price_indices.customer_group_id', $customerGroup->id);
                });

            if (! empty($params['category_id'])) {
                $qb->leftJoin('product_categories', 'product_categories.product_id', '=', 'products.id')
                    ->whereIn('product_categories.category_id', explode(',', $params['category_id']));
            }

            if (! empty($params['channel_id'])) {
                $qb->leftJoin('product_channels', 'products.id', '=', 'product_channels.product_id')
                    ->where('product_channels.channel_id', explode(',', $params['channel_id']));
            }

            // It is For Rating...

            if (! empty($params['ratings'])) {
                $qb->whereHas('reviews', fn($q) => $q->where('rating', '>=', $params['ratings']));
            }


            
            if (isset($params['availability'])) {
                if ($params['availability'] === 'out_of_stock') {
                    
                    $qb->whereHas('inventories', function ($q) {
                        $q->where('qty', '<=', 0);
                    });
                }

                if ($params['availability'] === 'exclude_out_of_stock') {
                    
                    $qb->whereHas('inventories', function ($q) {
                        $q->where('qty', '>=', 0);
                    });
                }
            }


            // Offers Filter
            if (! empty($params['offers'])) {

                if (in_array('on_sale', $params['offers'])) {
                    $qb->whereRaw("JSON_EXTRACT(products.additional, '$.special_price') IS NOT NULL")
                    ->whereRaw("JSON_EXTRACT(products.additional, '$.special_price') < JSON_EXTRACT(products.additional, '$.price')");
                }

                if (in_array('b1g1', $params['offers'])) {
                    $qb->orWhereRaw("JSON_EXTRACT(products.additional, '$.b1g1') = 1");
                }
            }


            if (!empty($params['popular'])) {
                $qb->leftJoin('order_items', 'products.id', '=', 'order_items.product_id')
                ->leftJoin('orders', function ($join) {
                    $join->on('order_items.order_id', '=', 'orders.id')
                            ->where('orders.status', 'completed'); 
                })
                ->groupBy('products.id')
                ->orderByRaw('COUNT(order_items.id) DESC') 
                ->limit(5); 
            }



            if (! empty($params['discount'])) {
                $qb->whereRaw('((price - special_price) / price) * 100 >= ?', [$params['discount']]);
            }

            $qb->leftJoin('order_items', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('orders', function ($join) {
                $join->on('order_items.order_id', '=', 'orders.id')
                        ->where('orders.status', 'completed');
            })
            ->groupBy('products.id')
            ->orderByRaw('COUNT(order_items.id) ' . $sortOptions['order']);

            if (! empty($params['type'])) {
                $qb->where('products.type', $params['type']);

                if (
                    $params['type'] === 'simple'
                    && ! empty($params['exclude_customizable_products'])
                ) {
                    $qb->leftJoin('product_customizable_options', 'products.id', '=', 'product_customizable_options.product_id')
                        ->whereNull('product_customizable_options.id');
                }
            }

            /**
             * Filter query by price.
             */
            if (! empty($params['price'])) {
                $priceRange = explode(',', $params['price']);

                $qb->whereBetween('product_price_indices.min_price', [
                    core()->convertToBasePrice(current($priceRange)),
                    core()->convertToBasePrice(end($priceRange)),
                ]);
            }

            /**
             * Retrieve all the filterable attributes.
             */
            $filterableAttributes = $this->attributeRepository->getProductDefaultAttributes(array_keys($params));

            /**
             * Filter the required attributes.
             */
            $attributes = $filterableAttributes->whereIn('code', [
                'name',
                'status',
                'visible_individually',
                'url_key',
            ]);

            /**
             * Filter collection by required attributes.
             */
            foreach ($attributes as $attribute) {
                $alias = $attribute->code.'_product_attribute_values';

                $qb->leftJoin('product_attribute_values as '.$alias, 'products.id', '=', $alias.'.product_id')
                    ->where($alias.'.attribute_id', $attribute->id);

                if ($attribute->code == 'name') {
                    $synonyms = $this->searchSynonymRepository->getSynonymsByQuery(urldecode($params['name']));

                    $qb->where(function ($subQuery) use ($alias, $synonyms) {
                        foreach ($synonyms as $synonym) {
                            $subQuery->orWhere($alias.'.text_value', 'like', '%'.$synonym.'%');
                        }
                    });
                } elseif ($attribute->code == 'url_key') {
                    if (empty($params['url_key'])) {
                        $qb->whereNotNull($alias.'.text_value');
                    } else {
                        $qb->where($alias.'.text_value', 'like', '%'.urldecode($params['url_key']).'%');
                    }
                } else {
                    if (is_null($params[$attribute->code])) {
                        continue;
                    }

                    $qb->where($alias.'.'.$attribute->column_name, 1);
                }
            }

            /**
             * Filter the filterable attributes.
             */
            $attributes = $filterableAttributes->whereNotIn('code', [
                'price',
                'name',
                'status',
                'visible_individually',
                'url_key',
            ]);

            /**
             * Filter query by attributes.
             */
            if ($attributes->isNotEmpty()) {
                $qb->where(function ($filterQuery) use ($qb, $params, $attributes, $prefix) {
                    $aliases = [
                        'products' => 'product_attribute_values',
                        'variants' => 'variant_attribute_values',
                    ];

                    foreach ($aliases as $table => $tableAlias) {
                        $filterQuery->orWhere(function ($subFilterQuery) use ($qb, $params, $attributes, $prefix, $table, $tableAlias) {
                            foreach ($attributes as $attribute) {
                                $alias = $attribute->code.'_'.$tableAlias;

                                $qb->leftJoin('product_attribute_values as '.$alias, function ($join) use ($table, $alias, $attribute) {
                                    $join->on($table.'.id', '=', $alias.'.product_id');

                                    $join->where($alias.'.attribute_id', $attribute->id);
                                });

                                if (in_array($attribute->type, [
                                    AttributeTypeEnum::CHECKBOX->value,
                                    AttributeTypeEnum::MULTISELECT->value,
                                ])) {
                                    $paramValues = explode(',', $params[$attribute->code]);

                                    $subFilterQuery->where(function ($query) use ($paramValues, $alias, $attribute, $prefix) {
                                        foreach ($paramValues as $value) {
                                            $query->orWhereRaw("FIND_IN_SET(?, {$prefix}{$alias}.{$attribute->column_name})", [$value]);
                                        }
                                    });
                                } else {
                                    $subFilterQuery->whereIn($alias.'.'.$attribute->column_name, explode(',', $params[$attribute->code]));
                                }
                            }
                        });
                    }
                });

                $qb->groupBy('products.id');
            }

            /**
             * Sort collection.
             */
            $sortOptions = $this->getSortOptions($params);

            if ($sortOptions['order'] != 'rand') {
                $attribute = $this->attributeRepository->findOneByField('code', $sortOptions['sort']);

                if ($attribute) {
                    if ($attribute->code === 'price') {
                        $qb->orderBy('product_price_indices.min_price', $sortOptions['order']);
                    } else {
                        $alias = 'sort_product_attribute_values';

                        $qb->leftJoin('product_attribute_values as '.$alias, function ($join) use ($alias, $attribute) {
                            $join->on('products.id', '=', $alias.'.product_id')
                                ->where($alias.'.attribute_id', $attribute->id);

                            if ($attribute->value_per_channel) {
                                if ($attribute->value_per_locale) {
                                    $join->where($alias.'.channel', core()->getRequestedChannelCode())
                                        ->where($alias.'.locale', core()->getRequestedLocaleCode());
                                } else {
                                    $join->where($alias.'.channel', core()->getRequestedChannelCode());
                                }
                            } else {
                                if ($attribute->value_per_locale) {
                                    $join->where($alias.'.locale', core()->getRequestedLocaleCode());
                                }
                            }
                        })
                            ->orderBy($alias.'.'.$attribute->column_name, $sortOptions['order']);
                    }
                } else {
                    /* `created_at` is not an attribute so it will be in else case */
                    $qb->orderBy('products.created_at', $sortOptions['order']);
                }
            } else {
                return $qb->inRandomOrder();
            }

            return $qb->groupBy('products.id');
        });

        $limit = $this->getPerPageLimit($params);

        return $query->paginate($limit);
    }

}