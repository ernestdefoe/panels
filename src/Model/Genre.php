<?php

namespace ErnestDefoe\Panels\Model;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $position
 * @property int $series_count
 */
class Genre extends AbstractModel
{
    protected $table = 'panel_genres';

    public $timestamps = false;
}
