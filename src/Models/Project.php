<?php

namespace Convoro\Ext\Projects\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user project — a showcase entry (a book, a game, an app, …) authored by a
 * forum member.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $primary_category_id
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string|null $content
 * @property string|null $image_path
 * @property string $status
 * @property string|null $rejection_reason
 * @property int $likes_count
 */
class Project extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'projects';

    protected $guarded = ['id'];

    protected $casts = [
        'user_id' => 'integer',
        'primary_category_id' => 'integer',
        'likes_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'likes_count' => 0,
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(ProjectCategory::class, 'primary_category_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ProjectCategory::class, 'project_category', 'project_id', 'category_id');
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(ProjectFieldValue::class, 'project_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ProjectLink::class, 'project_id');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
