<?php

namespace Convoro\Ext\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single custom-parameter value for one project.
 *
 * @property int $id
 * @property int $project_id
 * @property int $field_id
 * @property string|null $value
 */
class ProjectFieldValue extends Model
{
    public $timestamps = false;

    protected $table = 'project_field_values';

    protected $guarded = ['id'];

    protected $casts = [
        'project_id' => 'integer',
        'field_id' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ProjectField::class, 'field_id');
    }
}
