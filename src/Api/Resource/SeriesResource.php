<?php

namespace ErnestDefoe\Panels\Api\Resource;

use ErnestDefoe\Panels\Model\Series;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;

/**
 * Only so notifications can include their subject: a notification subject without a resource
 * 500s every member's notification list. Panels' own pages read /api/panels/….
 *
 * @extends AbstractDatabaseResource<Series>
 */
class SeriesResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'panels-series';
    }

    public function model(): string
    {
        return Series::class;
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title'),
            Schema\Str::make('slug'),
        ];
    }
}
