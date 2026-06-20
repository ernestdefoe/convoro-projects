<?php

namespace Convoro\Ext\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-defined typed custom parameter projects can carry (e.g. Genre,
 * Release date). Drives a dynamically-generated form input.
 *
 * @property int $id
 * @property string $name
 * @property string $key
 * @property string $type
 * @property array|null $options
 * @property string|null $icon
 * @property string|null $prefix
 * @property string|null $suffix
 * @property bool $is_required
 * @property bool $on_card
 * @property int $position
 */
class ProjectField extends Model
{
    public const TYPES = ['text', 'textarea', 'number', 'date', 'url', 'select', 'boolean'];

    protected $table = 'project_fields';

    protected $guarded = ['id'];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'on_card' => 'boolean',
        'position' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'type' => 'text',
        'on_card' => true,
    ];

    public function values(): HasMany
    {
        return $this->hasMany(ProjectFieldValue::class, 'field_id');
    }
}
