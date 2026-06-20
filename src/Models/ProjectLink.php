<?php

namespace Convoro\Ext\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single filled-in link on a project, optionally tied to a button slot.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $button_id
 * @property string $url
 * @property string|null $label
 * @property int $position
 */
class ProjectLink extends Model
{
    public $timestamps = false;

    protected $table = 'project_links';

    protected $guarded = ['id'];

    protected $casts = [
        'project_id' => 'integer',
        'button_id' => 'integer',
        'position' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function button(): BelongsTo
    {
        return $this->belongsTo(ProjectButton::class, 'button_id');
    }
}
