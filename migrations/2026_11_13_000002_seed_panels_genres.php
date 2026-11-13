<?php

use Illuminate\Database\Schema\Builder;

/*
 * A starting genre list the admin renames, reorders or deletes. Only on a fresh install.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        if ($db->table('panel_genres')->exists()) {
            return;
        }

        $names = ['Action', 'Adventure', 'Comedy', 'Drama', 'Fantasy', 'Horror', 'Mystery', 'Romance', 'Sci-fi', 'Slice of life', 'Sports', 'Supernatural'];
        foreach ($names as $i => $name) {
            $db->table('panel_genres')->insert([
                'name' => $name,
                'slug' => strtolower(str_replace(' ', '-', $name)),
                'position' => $i,
            ]);
        }
    },

    'down' => function (Builder $schema) {
    },
];
