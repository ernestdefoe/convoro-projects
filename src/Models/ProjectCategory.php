<?php

namespace Convoro\Ext\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An admin-defined project category with an icon + accent colour.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $icon
 * @property string|null $color
 * @property string|null $description
 * @property int $position
 */
class ProjectCategory extends Model
{
    protected $table = 'project_categories';

    protected $guarded = ['id'];

    protected $casts = [
        'position' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_category', 'category_id', 'project_id');
    }
}
