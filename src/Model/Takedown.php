<?php

namespace ErnestDefoe\Panels\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A copyright claim against a series or a chapter.
 *
 * @property int $id
 * @property string $target_type
 * @property int $target_id
 * @property int|null $uploader_id
 * @property string $claimant_name
 * @property string $claimant_email
 * @property string|null $claimant_org
 * @property string $work
 * @property list<string> $urls
 * @property string $signature
 * @property string|null $ip_address
 * @property string $state
 * @property string|null $reason
 * @property array<string, mixed>|null $counter
 * @property Carbon|null $restore_after
 * @property int|null $handled_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property User|null $uploader
 */
class Takedown extends AbstractModel
{
    public const STATES = ['open', 'info', 'disabled', 'rejected', 'restored'];

    protected $table = 'panel_takedowns';

    public $timestamps = true;

    protected $casts = [
        'urls' => 'array',
        'counter' => 'array',
        'restore_after' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }
}
