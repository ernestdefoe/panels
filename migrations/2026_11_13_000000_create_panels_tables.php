<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * 🚨 No hand-named indexes or keys: Laravel doesn't prefix explicit names, so
 * two forums sharing one database would collide. Generated names carry the prefix.
 *
 * 🚨 Counters are unsigned: decrement them with a CASE (MySQL refuses a value below zero).
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('panel_series')) {
            $schema->create('panel_series', function (Blueprint $table) {
                $table->increments('id');
                $table->string('slug', 120)->unique();
                $table->string('title', 150);
                // Other names readers search by, one per line.
                $table->text('alt_titles')->nullable();
                $table->text('description')->nullable();
                $table->string('cover_key', 120)->nullable();
                $table->string('cover_color', 7)->nullable();
                // manga | manhwa | manhua | webtoon | comic | other
                $table->string('format', 10)->default('manga');
                // vertical | ltr | rtl
                $table->string('reading_mode', 8)->default('rtl');
                // ongoing | completed | hiatus | cancelled
                $table->string('status', 10)->default('ongoing');
                // all | teen | mature | explicit
                $table->string('content_rating', 8)->default('all');
                // The admin's custom fields: {fieldId: value}.
                $table->text('fields')->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->unsignedInteger('tag_id')->nullable();
                $table->boolean('is_hidden')->default(false);
                $table->boolean('is_featured')->default(false);
                // Kiosk sells the whole series: its new chapters default to paid.
                $table->boolean('is_sold')->default(false);
                $table->unsignedInteger('latest_chapter_id')->nullable();
                $table->dateTime('latest_chapter_at')->nullable();
                $table->unsignedInteger('chapter_count')->default(0);
                $table->unsignedInteger('follower_count')->default(0);
                $table->unsignedInteger('rating_sum')->default(0);
                $table->unsignedInteger('rating_count')->default(0);
                $table->unsignedInteger('read_count')->default(0);
                $table->dateTime('removed_at')->nullable();
                $table->timestamps();

                $table->index(['is_hidden', 'latest_chapter_at']);
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! $schema->hasTable('panel_genres')) {
            $schema->create('panel_genres', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 60);
                $table->string('slug', 60)->unique();
                $table->unsignedInteger('position')->default(0);
                $table->unsignedInteger('series_count')->default(0);
            });
        }

        if (! $schema->hasTable('panel_series_genre')) {
            $schema->create('panel_series_genre', function (Blueprint $table) {
                $table->unsignedInteger('series_id');
                $table->unsignedInteger('genre_id');
                $table->primary(['series_id', 'genre_id']);
                $table->index('genre_id');
                $table->foreign('series_id')->references('id')->on('panel_series')->cascadeOnDelete();
                $table->foreign('genre_id')->references('id')->on('panel_genres')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('panel_series_editors')) {
            $schema->create('panel_series_editors', function (Blueprint $table) {
                $table->unsignedInteger('series_id');
                $table->unsignedInteger('user_id');
                $table->primary(['series_id', 'user_id']);
                $table->index('user_id');
                $table->foreign('series_id')->references('id')->on('panel_series')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('panel_chapters')) {
            $schema->create('panel_chapters', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('series_id');
                $table->decimal('number', 8, 2);
                $table->unsignedInteger('volume')->nullable();
                $table->string('title', 150)->nullable();
                // draft | pending (waits for a moderator) | held (looks like a removed chapter) | published | removed
                $table->string('state', 10)->default('draft');
                $table->dateTime('published_at')->nullable();
                // Raises the series' rating for this chapter, never lowers it.
                $table->string('content_rating', 8)->nullable();
                // upload | external
                $table->string('source', 8)->default('upload');
                $table->string('external_url', 500)->nullable();
                // free | paid | early (paid until early_access_until)
                $table->string('access', 5)->default('free');
                $table->dateTime('early_access_until')->nullable();
                // Where its files are now: public (served as static files) or private (signed URLs).
                $table->string('storage', 7)->default('private');
                $table->unsignedInteger('page_count')->default(0);
                $table->unsignedInteger('discussion_id')->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->text('note')->nullable();
                $table->text('transcript')->nullable();
                $table->dateTime('attested_at')->nullable();
                $table->dateTime('notified_at')->nullable();
                $table->unsignedInteger('held_for')->nullable();
                $table->dateTime('removed_at')->nullable();
                $table->timestamps();

                $table->index(['series_id', 'state', 'number']);
                $table->index(['state', 'published_at']);
                $table->index('discussion_id');
                $table->foreign('series_id')->references('id')->on('panel_series')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! $schema->hasTable('panel_pages')) {
            $schema->create('panel_pages', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('chapter_id');
                $table->unsignedInteger('position')->default(0);
                // 0 = a page; 1, 2… = the rest of a tall strip, drawn flush under the one before.
                $table->unsignedSmallInteger('segment')->default(0);
                $table->unsignedInteger('width');
                $table->unsignedInteger('height');
                $table->unsignedInteger('bytes')->default(0);
                $table->string('color', 7)->nullable();
                $table->bigInteger('phash')->nullable();
                // The stored copies: {"720": "s/1/c/2/ab12…-720.webp", …}.
                $table->text('variants');
                $table->string('alt', 300)->nullable();
                $table->string('original_name', 150)->nullable();

                $table->index(['chapter_id', 'position']);
                $table->foreign('chapter_id')->references('id')->on('panel_chapters')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('panel_library')) {
            $schema->create('panel_library', function (Blueprint $table) {
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('series_id');
                // reading | planned | hold | completed | dropped | null (only following)
                $table->string('shelf', 10)->nullable();
                $table->boolean('notify')->default(false);
                $table->timestamps();
                $table->primary(['user_id', 'series_id']);
                $table->index(['series_id', 'notify']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('series_id')->references('id')->on('panel_series')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('panel_progress')) {
            $schema->create('panel_progress', function (Blueprint $table) {
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('series_id');
                $table->unsignedInteger('chapter_id');
                $table->unsignedInteger('page')->default(1);
                $table->unsignedInteger('pages')->default(0);
                $table->dateTime('updated_at');
                $table->primary(['user_id', 'series_id']);
                $table->index(['user_id', 'updated_at']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('series_id')->references('id')->on('panel_series')->cascadeOnDelete();
                $table->foreign('chapter_id')->references('id')->on('panel_chapters')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('panel_reads')) {
            $schema->create('panel_reads', function (Blueprint $table) {
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('chapter_id');
                $table->dateTime('read_at');
                $table->primary(['user_id', 'chapter_id']);
                $table->index('chapter_id');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('chapter_id')->references('id')->on('panel_chapters')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('panel_ratings')) {
            $schema->create('panel_ratings', function (Blueprint $table) {
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('series_id');
                $table->unsignedTinyInteger('rating');
                $table->timestamps();
                $table->primary(['user_id', 'series_id']);
                $table->index('series_id');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('series_id')->references('id')->on('panel_series')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('panel_chapter_stats')) {
            $schema->create('panel_chapter_stats', function (Blueprint $table) {
                $table->unsignedInteger('chapter_id');
                $table->unsignedInteger('series_id');
                $table->date('day');
                $table->unsignedInteger('readers')->default(0);
                $table->primary(['chapter_id', 'day']);
                $table->index(['day', 'series_id']);
                $table->foreign('chapter_id')->references('id')->on('panel_chapters')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('panel_imports')) {
            $schema->create('panel_imports', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('chapter_id');
                $table->string('temp_key', 64);
                // The archive's image entries, sorted: [index, …].
                $table->mediumText('entries')->nullable();
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('done')->default(0);
                $table->unsignedInteger('position_base')->default(0);
                // {"skipped": [names], "refused": [{name, reason}]}
                $table->text('report')->nullable();
                // running | done | failed
                $table->string('state', 8)->default('running');
                $table->timestamps();
                $table->index('chapter_id');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('chapter_id')->references('id')->on('panel_chapters')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('panel_takedowns')) {
            $schema->create('panel_takedowns', function (Blueprint $table) {
                $table->increments('id');
                // series | chapter
                $table->string('target_type', 8);
                $table->unsignedInteger('target_id');
                $table->unsignedInteger('uploader_id')->nullable();
                $table->string('claimant_name', 120);
                $table->string('claimant_email', 190);
                $table->string('claimant_org', 150)->nullable();
                $table->text('work');
                $table->text('urls');
                $table->string('signature', 120);
                $table->string('ip_address', 45)->nullable();
                // open | info | disabled | rejected | restored
                $table->string('state', 10)->default('open');
                $table->text('reason')->nullable();
                // The uploader's counter-notice: {name, address, statement, signature, at}.
                $table->text('counter')->nullable();
                $table->dateTime('restore_after')->nullable();
                $table->unsignedInteger('handled_by')->nullable();
                $table->timestamps();
                $table->index(['state', 'created_at']);
                $table->index(['target_type', 'target_id']);
                $table->foreign('uploader_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('handled_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! $schema->hasTable('panel_strikes')) {
            $schema->create('panel_strikes', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('takedown_id');
                $table->dateTime('created_at');
                $table->index('user_id');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('takedown_id')->references('id')->on('panel_takedowns')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('panel_downloads')) {
            $schema->create('panel_downloads', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('chapter_id');
                $table->dateTime('created_at');
                $table->index(['user_id', 'created_at']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('chapter_id')->references('id')->on('panel_chapters')->cascadeOnDelete();
            });
        }

        if (! $schema->hasColumn('users', 'panels_adult_at')) {
            $schema->table('users', function (Blueprint $table) {
                // When the member said they're 18 or older (Mature and Explicit).
                $table->dateTime('panels_adult_at')->nullable();
            });
        }
    },

    'down' => function (Builder $schema) {
        foreach (['panel_downloads', 'panel_strikes', 'panel_takedowns', 'panel_imports', 'panel_chapter_stats', 'panel_ratings', 'panel_reads', 'panel_progress', 'panel_library', 'panel_pages', 'panel_chapters', 'panel_series_editors', 'panel_series_genre', 'panel_genres', 'panel_series'] as $table) {
            $schema->dropIfExists($table);
        }
        if ($schema->hasColumn('users', 'panels_adult_at')) {
            $schema->table('users', fn (Blueprint $table) => $table->dropColumn('panels_adult_at'));
        }
    },
];
