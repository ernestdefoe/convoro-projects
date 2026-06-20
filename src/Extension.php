<?php

namespace Convoro\Ext\Projects;

use App\Models\User;
use App\Support\ExtensionManager;
use App\Support\ExtPage;
use Convoro\Ext\Projects\Models\Project;
use Convoro\Ext\Projects\Models\ProjectButton;
use Convoro\Ext\Projects\Models\ProjectCategory;
use Convoro\Ext\Projects\Models\ProjectField;
use Convoro\Ext\Projects\Models\ProjectFieldValue;
use Convoro\Ext\Projects\Models\ProjectLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Projects — a user-project showcase for Convoro. Members publish projects
 * (title, image, description, custom typed fields and link buttons) that appear
 * as cards on a /projects page, filterable by admin-defined category. Each
 * project has a detail page with a like toggle, the author can feature their
 * best work on their profile, and staff moderate submissions + define the
 * categories, custom fields and button slots.
 *
 * A faithful Convoro port of the Flarum "Projects" extension, following the
 * server-rendered ExtPage pattern: pages render inside the real forum shell so
 * the header, footer and theme match exactly.
 */
class Extension extends ServiceProvider
{
    private const ID = 'convoro-projects';

    public function boot(): void
    {
        $this->registerRoutes();
    }

    // --- Permissions -------------------------------------------------------

    private static function canModerate(): bool
    {
        $u = Auth::user();

        return $u && ($u->is_admin || $u->hasPermission('projects.moderate'));
    }

    private static function canCreate(): bool
    {
        if (self::canModerate()) {
            return true;
        }

        return (bool) Auth::user()?->hasPermission('projects.create');
    }

    private static function canSkipModeration(): bool
    {
        if (self::canModerate()) {
            return true;
        }

        return (bool) Auth::user()?->hasPermission('projects.skipModeration');
    }

    private static function canEdit(Project $project): bool
    {
        if (self::canModerate()) {
            return true;
        }

        return Auth::id()
            && (int) $project->user_id === (int) Auth::id()
            && (bool) Auth::user()?->hasPermission('projects.create');
    }

    private static function setting(string $key, mixed $default = null): mixed
    {
        return ExtensionManager::setting(self::ID, $key, $default);
    }

    // --- Routes ------------------------------------------------------------

    private function registerRoutes(): void
    {
        // ---- Public web pages --------------------------------------------
        Route::middleware('web')->group(function () {
            Route::get('/projects', fn () => self::indexPage());

            Route::get('/projects/new', function () {
                abort_unless(Auth::check(), 403);
                abort_unless(self::canCreate(), 403);

                return self::formPage(null);
            });

            Route::get('/projects/{slug}', function (string $slug) {
                $project = Project::where('slug', $slug)->first();
                abort_unless($project && self::visible($project), 404);

                return self::detailPage($project);
            })->where('slug', '[A-Za-z0-9\-]+');

            Route::get('/projects/{slug}/edit', function (string $slug) {
                $project = Project::where('slug', $slug)->first();
                abort_unless($project, 404);
                abort_unless(self::canEdit($project), 403);

                return self::formPage($project);
            })->where('slug', '[A-Za-z0-9\-]+');
        });

        // ---- Public API (read) -------------------------------------------
        Route::middleware('web')->group(function () {
            Route::get('/api/ext/projects/recent', function () {
                $list = Project::where('status', Project::STATUS_PUBLISHED)
                    ->orderByDesc('created_at')->orderByDesc('id')->limit(3)->get();

                return response()->json(['projects' => $list->map(fn ($p) => self::compact($p))->all()]);
            });

            Route::get('/api/ext/projects/user/{userId}/projects', function (int $userId) {
                return response()->json(self::userShowcase($userId));
            });

            Route::get('/api/ext/projects/user/by-name/{username}', function (string $username) {
                $u = User::where('name', $username)->orWhere('id', (int) $username)->first();
                abort_unless($u, 404);

                return response()->json(self::userShowcase((int) $u->id));
            });
        });

        // ---- API (mutations, auth) ---------------------------------------
        Route::middleware(['web', 'auth'])->group(function () {
            // Create.
            Route::post('/api/ext/projects', function (Request $request) {
                abort_unless(self::canCreate(), 403);

                try {
                    $project = DB::transaction(function () use ($request) {
                        $project = new Project;
                        $project->user_id = Auth::id();
                        $project->status = self::canSkipModeration()
                            ? Project::STATUS_PUBLISHED
                            : Project::STATUS_PENDING;
                        self::applyScalars($project, (array) $request->all(), true);
                        $project->save();
                        self::syncRelations($project, (array) $request->all());

                        return $project;
                    });
                } catch (ValidationException $e) {
                    return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
                }

                return response()->json(['ok' => true, 'id' => $project->id, 'slug' => $project->slug, 'status' => $project->status]);
            });

            // Update.
            Route::post('/api/ext/projects/{id}', function (Request $request, int $id) {
                $project = Project::findOrFail($id);
                abort_unless(self::canEdit($project), 403);

                try {
                    DB::transaction(function () use ($project, $request) {
                        self::applyScalars($project, (array) $request->all(), false);
                        $project->save();
                        self::syncRelations($project, (array) $request->all());
                    });
                } catch (ValidationException $e) {
                    return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
                }

                return response()->json(['ok' => true, 'id' => $project->id, 'slug' => $project->slug]);
            });

            // Delete.
            Route::delete('/api/ext/projects/{id}', function (int $id) {
                $project = Project::findOrFail($id);
                abort_unless(self::canEdit($project), 403);
                DB::transaction(function () use ($project) {
                    ProjectFieldValue::where('project_id', $project->id)->delete();
                    ProjectLink::where('project_id', $project->id)->delete();
                    DB::table('project_category')->where('project_id', $project->id)->delete();
                    DB::table('project_likes')->where('project_id', $project->id)->delete();
                    FeaturedProject::remove($project->user_id, (int) $project->id);
                    $project->delete();
                });

                return response()->json(['ok' => true]);
            });

            // Like (toggle).
            Route::post('/api/ext/projects/{id}/like', function (int $id) {
                $project = Project::findOrFail($id);
                abort_unless(self::visible($project), 404);
                $exists = DB::table('project_likes')->where('project_id', $id)->where('user_id', Auth::id())->exists();
                if ($exists) {
                    DB::table('project_likes')->where('project_id', $id)->where('user_id', Auth::id())->delete();
                } else {
                    DB::table('project_likes')->insert(['project_id' => $id, 'user_id' => Auth::id(), 'created_at' => now()]);
                }
                $count = DB::table('project_likes')->where('project_id', $id)->count();
                $project->likes_count = $count;
                $project->save();

                return response()->json(['ok' => true, 'liked' => ! $exists, 'count' => $count]);
            });

            // Feature toggle (author only).
            Route::post('/api/ext/projects/{id}/feature', function (int $id) {
                $project = Project::findOrFail($id);
                abort_unless(Auth::id() && (int) $project->user_id === (int) Auth::id(), 403);
                abort_unless($project->isPublished(), 422);
                $ids = FeaturedProject::toggle($project);

                return response()->json(['ok' => true, 'featured' => in_array((int) $project->id, $ids, true)]);
            });

            // Moderate (approve / reject).
            Route::post('/api/ext/projects/{id}/moderate', function (Request $request, int $id) {
                abort_unless(self::canModerate(), 403);
                $project = Project::findOrFail($id);
                $data = $request->validate([
                    'action' => 'required|in:approve,reject',
                    'reason' => 'nullable|string|max:500',
                ]);
                if ($data['action'] === 'approve') {
                    $project->status = Project::STATUS_PUBLISHED;
                    $project->rejection_reason = null;
                } else {
                    $project->status = Project::STATUS_REJECTED;
                    $project->rejection_reason = $data['reason'] ?? null;
                    FeaturedProject::remove($project->user_id, (int) $project->id);
                }
                $project->save();

                return response()->json(['ok' => true, 'status' => $project->status]);
            });
        });

        // ---- Admin (web + auth + admin) ----------------------------------
        Route::middleware(['web', 'auth', 'admin'])->prefix('admin/ext/projects')->group(function () {
            Route::get('/', fn () => self::adminPage());

            // Definition lists.
            Route::get('/definitions', fn () => response()->json([
                'categories' => ProjectCategory::orderBy('position')->orderBy('name')->get()->map(fn ($c) => self::catArray($c))->all(),
                'fields' => ProjectField::orderBy('position')->orderBy('name')->get()->map(fn ($f) => self::fieldArray($f))->all(),
                'buttons' => ProjectButton::orderBy('position')->orderBy('label')->get()->map(fn ($b) => self::buttonArray($b))->all(),
            ]));

            // Categories.
            Route::post('/categories', function (Request $request) {
                $data = $request->validate([
                    'id' => 'nullable|integer',
                    'name' => 'required|string|max:100',
                    'icon' => 'nullable|string|max:100',
                    'color' => 'nullable|string|max:20',
                    'description' => 'nullable|string|max:255',
                ]);
                $c = $data['id'] ? ProjectCategory::findOrFail($data['id']) : new ProjectCategory;
                $c->name = $data['name'];
                if (empty($c->slug) || ! $data['id']) {
                    $c->slug = self::uniqueSlug(ProjectCategory::class, $data['name'], $c->id);
                }
                $c->icon = $data['icon'] ?? null;
                $c->color = $data['color'] ?? null;
                $c->description = $data['description'] ?? null;
                if (! $data['id']) {
                    $c->position = (int) ProjectCategory::max('position') + 1;
                }
                $c->save();

                return response()->json(['ok' => true, 'category' => self::catArray($c)]);
            });

            Route::delete('/categories/{id}', function (int $id) {
                DB::table('project_category')->where('category_id', $id)->delete();
                Project::where('primary_category_id', $id)->update(['primary_category_id' => null]);
                ProjectCategory::whereKey($id)->delete();

                return response()->json(['ok' => true]);
            });

            // Fields.
            Route::post('/fields', function (Request $request) {
                $data = $request->validate([
                    'id' => 'nullable|integer',
                    'name' => 'required|string|max:100',
                    'type' => 'required|in:'.implode(',', ProjectField::TYPES),
                    'options' => 'nullable|array',
                    'options.*' => 'string|max:100',
                    'icon' => 'nullable|string|max:100',
                    'prefix' => 'nullable|string|max:30',
                    'suffix' => 'nullable|string|max:30',
                    'is_required' => 'nullable|boolean',
                    'on_card' => 'nullable|boolean',
                ]);
                $f = $data['id'] ? ProjectField::findOrFail($data['id']) : new ProjectField;
                $f->name = $data['name'];
                if (empty($f->key) || ! $data['id']) {
                    $f->key = self::uniqueSlug(ProjectField::class, $data['name'], $f->id, 'key');
                }
                $f->type = $data['type'];
                $f->options = $data['type'] === 'select' ? array_values(array_filter($data['options'] ?? [])) : null;
                $f->icon = $data['icon'] ?? null;
                $f->prefix = $data['prefix'] ?? null;
                $f->suffix = $data['suffix'] ?? null;
                $f->is_required = (bool) ($data['is_required'] ?? false);
                $f->on_card = (bool) ($data['on_card'] ?? false);
                if (! $data['id']) {
                    $f->position = (int) ProjectField::max('position') + 1;
                }
                $f->save();

                return response()->json(['ok' => true, 'field' => self::fieldArray($f)]);
            });

            Route::delete('/fields/{id}', function (int $id) {
                ProjectFieldValue::where('field_id', $id)->delete();
                ProjectField::whereKey($id)->delete();

                return response()->json(['ok' => true]);
            });

            // Buttons.
            Route::post('/buttons', function (Request $request) {
                $data = $request->validate([
                    'id' => 'nullable|integer',
                    'label' => 'required|string|max:100',
                    'icon' => 'nullable|string|max:100',
                    'allowed_domains' => 'nullable|array',
                    'allowed_domains.*' => 'string|max:120',
                    'allow_custom_label' => 'nullable|boolean',
                    'is_required' => 'nullable|boolean',
                    'is_primary' => 'nullable|boolean',
                ]);
                $b = $data['id'] ? ProjectButton::findOrFail($data['id']) : new ProjectButton;
                $b->label = $data['label'];
                if (empty($b->key) || ! $data['id']) {
                    $b->key = self::uniqueSlug(ProjectButton::class, $data['label'], $b->id, 'key');
                }
                $b->icon = $data['icon'] ?? null;
                $b->allowed_domains = array_values(array_filter(array_map(
                    fn ($d) => strtolower(ltrim(trim((string) $d), '.')),
                    $data['allowed_domains'] ?? []
                )));
                $b->allow_custom_label = (bool) ($data['allow_custom_label'] ?? false);
                $b->is_required = (bool) ($data['is_required'] ?? false);
                $b->is_primary = (bool) ($data['is_primary'] ?? false);
                if (! $data['id']) {
                    $b->position = (int) ProjectButton::max('position') + 1;
                }
                $b->save();

                return response()->json(['ok' => true, 'button' => self::buttonArray($b)]);
            });

            Route::delete('/buttons/{id}', function (int $id) {
                ProjectLink::where('button_id', $id)->update(['button_id' => null]);
                ProjectButton::whereKey($id)->delete();

                return response()->json(['ok' => true]);
            });

            // Moderation list + actions.
            Route::get('/moderation', function () {
                $pending = Project::where('status', Project::STATUS_PENDING)
                    ->orderBy('created_at')->get();
                $names = User::whereIn('id', $pending->pluck('user_id')->filter()->unique())->pluck('name', 'id');

                return response()->json(['projects' => $pending->map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'slug' => $p->slug,
                    'excerpt' => $p->excerpt,
                    'author' => $p->user_id ? ($names[$p->user_id] ?? 'Member') : 'Member',
                    'createdAt' => optional($p->created_at)->toIso8601String(),
                ])->all()]);
            });

            Route::post('/moderation/{id}', function (Request $request, int $id) {
                $project = Project::findOrFail($id);
                $data = $request->validate([
                    'action' => 'required|in:approve,reject',
                    'reason' => 'nullable|string|max:500',
                ]);
                if ($data['action'] === 'approve') {
                    $project->status = Project::STATUS_PUBLISHED;
                    $project->rejection_reason = null;
                } else {
                    $project->status = Project::STATUS_REJECTED;
                    $project->rejection_reason = $data['reason'] ?? null;
                    FeaturedProject::remove($project->user_id, (int) $project->id);
                }
                $project->save();

                return response()->json(['ok' => true, 'status' => $project->status]);
            });
        });
    }

    // --- Input handling (scalar attrs + relations) -------------------------

    /** Apply title/excerpt/content/image to a project, validating as we go. */
    private static function applyScalars(Project $project, array $attrs, bool $creating): void
    {
        $errors = [];

        if (array_key_exists('title', $attrs) || $creating) {
            $title = trim((string) ($attrs['title'] ?? ''));
            if ($title === '') {
                $errors['title'] = 'A title is required.';
            } elseif (mb_strlen($title) > 255) {
                $errors['title'] = 'The title is too long.';
            } else {
                $project->title = $title;
                if ($creating || empty($project->slug)) {
                    $project->slug = self::uniqueSlug(Project::class, $title, $project->id);
                }
            }
        }

        if (array_key_exists('excerpt', $attrs)) {
            $excerpt = trim((string) $attrs['excerpt']);
            if (mb_strlen($excerpt) > 600) {
                $errors['excerpt'] = 'The excerpt is too long.';
            }
            $project->excerpt = $excerpt !== '' ? $excerpt : null;
        }

        if (array_key_exists('content', $attrs)) {
            $content = trim((string) $attrs['content']);
            $project->content = $content !== '' ? $content : null;
        }

        if (array_key_exists('image', $attrs)) {
            $image = trim((string) $attrs['image']);
            if ($image !== '' && ! self::isSafeUrl($image)) {
                $errors['image'] = 'That image link is not valid.';
            }
            $project->image_path = $image !== '' ? mb_substr($image, 0, 600) : null;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Sync categories (+ primary), field values and links. Project must be saved. */
    private static function syncRelations(Project $project, array $attrs): void
    {
        $errors = [];

        // Categories.
        if (array_key_exists('categoryIds', $attrs)) {
            $ids = collect((array) $attrs['categoryIds'])->map(fn ($i) => (int) $i)->filter()->unique()->values();
            $valid = ProjectCategory::whereIn('id', $ids->all())->pluck('id')->map(fn ($i) => (int) $i);
            $project->categories()->sync($valid->all());

            $primary = (int) ($attrs['primaryCategoryId'] ?? 0);
            $project->primary_category_id = ($primary && $valid->contains($primary))
                ? $primary
                : ($valid->first() ?: null);
            $project->save();
        }

        // Custom field values.
        if (array_key_exists('fieldValues', $attrs)) {
            $given = (array) $attrs['fieldValues']; // { fieldId|key: value }
            foreach (ProjectField::all() as $field) {
                $raw = $given[(string) $field->id] ?? ($given[$field->key] ?? null);
                $value = self::normaliseFieldValue($field, $raw, $errors);
                $existing = ProjectFieldValue::where('project_id', $project->id)->where('field_id', $field->id)->first();

                if ($value === null || $value === '') {
                    if ($field->is_required) {
                        $errors['field_'.$field->key] = $field->name.' is required.';
                    }
                    $existing?->delete();

                    continue;
                }

                $row = $existing ?: new ProjectFieldValue(['project_id' => $project->id, 'field_id' => $field->id]);
                $row->value = (string) $value;
                $row->save();
            }
        }

        // Links.
        if (array_key_exists('links', $attrs)) {
            $buttons = ProjectButton::all()->keyBy('id');
            $given = array_values((array) $attrs['links']);
            $seen = [];
            $project->links()->delete();
            $pos = 0;
            foreach ($given as $entry) {
                $url = trim((string) ($entry['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                if (! self::isSafeUrl($url)) {
                    $errors['link_'.$pos] = 'That is not a valid link.';

                    continue;
                }
                $buttonId = (int) ($entry['buttonId'] ?? 0);
                $button = $buttonId ? $buttons->get($buttonId) : null;
                if ($button && ! $button->allowsUrl($url)) {
                    $errors['link_'.$button->key] = $button->label.' only accepts links from its allowed domains.';

                    continue;
                }
                $label = trim((string) ($entry['label'] ?? ''));
                if ($button && ! $button->allow_custom_label) {
                    $label = '';
                }
                ProjectLink::create([
                    'project_id' => $project->id,
                    'button_id' => $button?->id,
                    'url' => mb_substr($url, 0, 600),
                    'label' => $label !== '' ? mb_substr($label, 0, 100) : null,
                    'position' => $pos++,
                ]);
                if ($button) {
                    $seen[$button->id] = true;
                }
            }
            foreach ($buttons as $button) {
                if ($button->is_required && empty($seen[$button->id])) {
                    $errors['link_'.$button->key] = $button->label.' is required.';
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private static function normaliseFieldValue(ProjectField $field, mixed $raw, array &$errors): ?string
    {
        if ($raw === null) {
            return null;
        }
        switch ($field->type) {
            case 'boolean':
                return filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? '1' : null;
            case 'number':
                $raw = trim((string) $raw);
                if ($raw === '') {
                    return null;
                }
                if (! is_numeric($raw)) {
                    $errors['field_'.$field->key] = $field->name.' must be a number.';

                    return null;
                }

                return $raw;
            case 'date':
                $raw = trim((string) $raw);
                if ($raw === '') {
                    return null;
                }
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                    $errors['field_'.$field->key] = $field->name.' must be a valid date.';

                    return null;
                }

                return $raw;
            case 'url':
                $raw = trim((string) $raw);
                if ($raw === '') {
                    return null;
                }
                if (! self::isSafeUrl($raw)) {
                    $errors['field_'.$field->key] = $field->name.' must be a valid link.';

                    return null;
                }

                return $raw;
            case 'select':
                $raw = trim((string) $raw);
                if ($raw === '') {
                    return null;
                }
                $options = array_map('strval', (array) ($field->options ?? []));
                if ($options && ! in_array($raw, $options, true)) {
                    $errors['field_'.$field->key] = $field->name.' has an invalid choice.';

                    return null;
                }

                return $raw;
            default: // text, textarea
                $raw = trim((string) $raw);

                return $raw !== '' ? mb_substr($raw, 0, 2000) : null;
        }
    }

    /** http/https absolute URLs or root-relative paths only (blocks javascript:/data:). */
    private static function isSafeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    // --- Serialisation helpers --------------------------------------------

    private static function catArray(ProjectCategory $c): array
    {
        return [
            'id' => (int) $c->id, 'name' => $c->name, 'slug' => $c->slug,
            'icon' => $c->icon, 'color' => $c->color, 'description' => $c->description,
            'position' => (int) $c->position,
        ];
    }

    private static function fieldArray(ProjectField $f): array
    {
        return [
            'id' => (int) $f->id, 'name' => $f->name, 'key' => $f->key, 'type' => $f->type,
            'options' => array_values((array) ($f->options ?? [])), 'icon' => $f->icon,
            'prefix' => $f->prefix, 'suffix' => $f->suffix,
            'isRequired' => (bool) $f->is_required, 'onCard' => (bool) $f->on_card,
            'position' => (int) $f->position,
        ];
    }

    private static function buttonArray(ProjectButton $b): array
    {
        return [
            'id' => (int) $b->id, 'label' => $b->label, 'key' => $b->key, 'icon' => $b->icon,
            'allowedDomains' => array_values((array) ($b->allowed_domains ?? [])),
            'allowCustomLabel' => (bool) $b->allow_custom_label,
            'isRequired' => (bool) $b->is_required, 'isPrimary' => (bool) $b->is_primary,
            'position' => (int) $b->position,
        ];
    }

    /** A lightweight project payload for the recent/sidebar widget. */
    private static function compact(Project $p): array
    {
        $cat = $p->primary_category_id ? ProjectCategory::find($p->primary_category_id) : null;

        return [
            'id' => (int) $p->id, 'title' => $p->title, 'slug' => $p->slug,
            'excerpt' => $p->excerpt, 'image' => $p->image_path,
            'category' => $cat ? ['name' => $cat->name, 'color' => $cat->color, 'icon' => $cat->icon] : null,
            'likesCount' => (int) $p->likes_count,
        ];
    }

    /** Published projects for a user + which are featured (profile showcase). */
    private static function userShowcase(int $userId): array
    {
        $featured = FeaturedProject::ids($userId);
        $list = Project::where('user_id', $userId)
            ->where('status', Project::STATUS_PUBLISHED)
            ->orderByDesc('created_at')->orderByDesc('id')->get();

        return [
            'featured' => $featured,
            'projects' => $list->map(function (Project $p) use ($featured) {
                $row = self::compact($p);
                $row['featured'] = in_array((int) $p->id, $featured, true);

                return $row;
            })->sortByDesc('featured')->values()->all(),
        ];
    }

    // --- Visibility --------------------------------------------------------

    private static function visible(Project $project): bool
    {
        if ($project->isPublished()) {
            return true;
        }
        if (self::canModerate()) {
            return true;
        }

        return Auth::id() && (int) $project->user_id === (int) Auth::id();
    }

    private static function projectsQuery()
    {
        // Index only ever lists published projects (cards page).
        return Project::where('status', Project::STATUS_PUBLISHED);
    }

    // --- Pages: index ------------------------------------------------------

    private static function indexPage(): \Inertia\Response
    {
        $cats = ProjectCategory::orderBy('position')->orderBy('name')->get();
        $projects = self::projectsQuery()->orderByDesc('created_at')->orderByDesc('id')->get();
        $names = self::authorNames($projects);
        $fieldDefs = ProjectField::orderBy('position')->get()->keyBy('id');

        // Preload like state + categories + on-card fields + links efficiently.
        $catMap = self::categoryMap($projects);
        $fieldVals = self::onCardFieldValues($projects, $fieldDefs);
        $linkMap = self::linkMap($projects);
        $liked = self::likedSet($projects->pluck('id'));

        $cards = '';
        foreach ($projects as $p) {
            $cards .= self::projectCard($p, $names, $catMap, $fieldVals, $linkMap, $liked);
        }

        $chips = '<button class="pj-chip is-on" data-cat="">'.self::e('All').'</button>';
        foreach ($cats as $cat) {
            $color = self::safeColor($cat->color);
            $style = $color ? ' style="--pj-accent:'.$color.'"' : '';
            $chips .= '<button class="pj-chip" data-cat="'.(int) $cat->id.'"'.$style.'>'
                .self::icon($cat->icon).self::e($cat->name).'</button>';
        }

        if ($cards === '') {
            $cards = '<div class="pj-blank"><div class="pj-blank-ico">📦</div><div class="pj-blank-t">'
                .self::e('No projects yet').'</div><p class="pj-muted">'
                .self::e(self::canCreate() ? 'Be the first to showcase a project.' : 'Check back soon.').'</p></div>';
        }

        $newBtn = (Auth::check() && self::canCreate())
            ? '<a class="pj-btn pj-btn-p" href="/projects/new">+ '.self::e('Submit a project').'</a>'
            : '';

        $hero = '<div class="pj-hero"><div class="pj-hero-in"><div class="pj-eyebrow">'.self::e('Showcase').'</div>'
            .'<h1 class="pj-hero-t">'.self::e('Projects').'</h1>'
            .'<div class="pj-hero-stats">'.number_format($projects->count()).' '
            .self::e($projects->count() === 1 ? 'project' : 'projects').'</div></div>'
            .'<div class="pj-hero-actions">'.$newBtn.'</div></div>';

        $filter = $cats->count() ? '<div class="pj-chips">'.$chips.'</div>' : '';

        $body = '<div class="pj-wrap">'.$hero.$filter.'<div class="pj-grid" id="pj-grid">'.$cards.'</div></div>';

        return ExtPage::render('Projects', $body, self::css(), self::indexJs());
    }

    private static function projectCard(Project $p, array $names, array $catMap, array $fieldVals, array $linkMap, $liked): string
    {
        $cats = $catMap[$p->id] ?? [];
        $catIds = implode(',', array_map(fn ($c) => $c['id'], $cats));
        $primary = $p->primary_category_id ?? ($cats[0]['id'] ?? 0);
        $accent = self::safeColor($cats[0]['color'] ?? null) ?: 'rgb(var(--c-primary))';

        $img = self::safeImage($p->image_path);
        $imgHtml = $img
            ? '<div class="pj-card-img" style="background-image:url(\''.self::e($img).'\')"></div>'
            : '<div class="pj-card-img pj-card-img-blank">'.self::icon($cats[0]['icon'] ?? null, '📦').'</div>';

        $badges = '';
        foreach (array_slice($cats, 0, 3) as $c) {
            $col = self::safeColor($c['color']);
            $st = $col ? ' style="--pj-accent:'.$col.'"' : '';
            $badges .= '<span class="pj-cat"'.$st.'>'.self::icon($c['icon']).self::e($c['name']).'</span>';
        }

        $author = $p->user_id ? ($names[$p->user_id] ?? 'Member') : 'Member';
        $fields = '';
        foreach (array_slice($fieldVals[$p->id] ?? [], 0, 4) as $fv) {
            $fields .= '<span class="pj-field" title="'.self::e($fv['name']).'">'
                .self::icon($fv['icon']).'<b>'.self::e($fv['name']).':</b> '.self::e($fv['display']).'</span>';
        }
        $fieldsHtml = $fields ? '<div class="pj-card-fields">'.$fields.'</div>' : '';

        $links = '';
        foreach (array_slice($linkMap[$p->id] ?? [], 0, 3) as $l) {
            $cls = $l['isPrimary'] ? 'pj-link pj-link-p' : 'pj-link';
            $links .= '<a class="'.$cls.'" href="'.self::e($l['url']).'" target="_blank" rel="noopener noreferrer nofollow" onclick="event.stopPropagation()">'
                .self::icon($l['icon']).self::e($l['label']).'</a>';
        }

        $isLiked = isset($liked[$p->id]) ? ' is-liked' : '';
        $likeBtn = '<button class="pj-like'.$isLiked.'" data-like="'.(int) $p->id.'" onclick="event.stopPropagation()">'
            .'<span class="pj-heart">♥</span> <span class="pj-like-n">'.number_format($p->likes_count).'</span></button>';

        return '<div class="pj-card" data-cats="'.self::e($catIds).'" data-primary="'.(int) $primary.'" style="--pj-accent:'.$accent.'"'
            .' onclick="location.href=\'/projects/'.self::e($p->slug).'\'">'
            .$imgHtml
            .'<div class="pj-card-body">'
            .($badges ? '<div class="pj-card-cats">'.$badges.'</div>' : '')
            .'<h3 class="pj-card-title">'.self::e($p->title).'</h3>'
            .'<div class="pj-card-author">'.self::e('by').' '.self::e($author).'</div>'
            .$fieldsHtml
            .($p->excerpt ? '<p class="pj-card-excerpt">'.self::e($p->excerpt).'</p>' : '')
            .'</div>'
            .'<div class="pj-card-foot"><div class="pj-card-links">'.$links.'</div>'.$likeBtn.'</div>'
            .'</div>';
    }

    // --- Pages: detail -----------------------------------------------------

    private static function detailPage(Project $project): \Inertia\Response
    {
        $author = $project->user_id ? (optional(User::find($project->user_id))->name ?? 'Member') : 'Member';
        $authorId = (int) $project->user_id;
        $cats = $project->categories()->orderBy('position')->orderBy('name')->get();
        $links = ProjectLink::where('project_id', $project->id)->orderBy('position')->get();
        $buttons = ProjectButton::all()->keyBy('id');
        $fieldVals = ProjectFieldValue::where('project_id', $project->id)->get();
        $fieldDefs = ProjectField::all()->keyBy('id');

        $canEdit = self::canEdit($project);
        $canMod = self::canModerate();
        $isAuthor = Auth::id() && $authorId === (int) Auth::id();
        $featured = in_array((int) $project->id, FeaturedProject::ids($authorId), true);
        $hasLiked = Auth::id() && DB::table('project_likes')->where('project_id', $project->id)->where('user_id', Auth::id())->exists();

        // Status banner.
        $statusBanner = '';
        if (! $project->isPublished()) {
            $label = $project->status === Project::STATUS_REJECTED ? 'This project was rejected.' : 'This project is awaiting moderation.';
            $reason = $project->rejection_reason ? ' '.$project->rejection_reason : '';
            $statusBanner = '<div class="pj-banner pj-banner-'.self::e($project->status).'">'
                .self::e($label).self::e($reason).'</div>';
        }

        $img = self::safeImage($project->image_path);
        $heroImg = $img ? '<div class="pj-detail-img" style="background-image:url(\''.self::e($img).'\')"></div>' : '';

        $catBadges = '';
        foreach ($cats as $c) {
            $col = self::safeColor($c->color);
            $st = $col ? ' style="--pj-accent:'.$col.'"' : '';
            $catBadges .= '<a class="pj-cat" href="/projects?cat='.(int) $c->id.'"'.$st.'>'
                .self::icon($c->icon).self::e($c->name).'</a>';
        }

        // Field rows.
        $fieldRows = '';
        $sorted = $fieldVals->filter(fn ($v) => $fieldDefs->has($v->field_id) && $v->value !== null && $v->value !== '')
            ->sortBy(fn ($v) => $fieldDefs[$v->field_id]->position ?? 0);
        foreach ($sorted as $v) {
            $f = $fieldDefs[$v->field_id];
            $fieldRows .= '<div class="pj-fact"><span class="pj-fact-k">'.self::icon($f->icon).self::e($f->name).'</span>'
                .'<span class="pj-fact-v">'.self::displayFieldValue($f, $v->value).'</span></div>';
        }
        $factsHtml = $fieldRows ? '<div class="pj-facts">'.$fieldRows.'</div>' : '';

        // Link buttons.
        $linkBtns = '';
        foreach ($links as $l) {
            $b = $l->button_id ? $buttons->get($l->button_id) : null;
            $label = $l->label ?: ($b ? $b->label : $l->url);
            $icon = $b ? $b->icon : null;
            $cls = ($b && $b->is_primary) ? 'pj-btn pj-btn-p' : 'pj-btn pj-btn-ghost';
            $linkBtns .= '<a class="'.$cls.'" href="'.self::e($l->url).'" target="_blank" rel="noopener noreferrer nofollow">'
                .self::icon($icon).self::e($label).'</a>';
        }
        $linksHtml = $linkBtns ? '<div class="pj-detail-links">'.$linkBtns.'</div>' : '';

        // Content (raw stored text → safe paragraphs; no server formatter hook here).
        $contentHtml = '';
        if (trim((string) $project->content) !== '') {
            $contentHtml = '<div class="pj-content">'.nl2br(self::e($project->content)).'</div>';
        }

        // Owner / mod toolbar.
        $tools = '';
        if ($canEdit) {
            $tools .= '<a class="pj-btn pj-btn-ghost" href="/projects/'.self::e($project->slug).'/edit">'.self::e('Edit').'</a>';
            $tools .= '<button class="pj-btn pj-btn-x" data-delete="'.(int) $project->id.'">'.self::e('Delete').'</button>';
        }
        if ($isAuthor && $project->isPublished()) {
            $tools .= '<button class="pj-btn pj-btn-ghost" data-feature="'.(int) $project->id.'">'
                .self::e($featured ? '★ Featured' : '☆ Feature on profile').'</button>';
        }
        if ($canMod && $project->status === Project::STATUS_PENDING) {
            $tools .= '<button class="pj-btn pj-btn-p" data-approve="'.(int) $project->id.'">'.self::e('Approve').'</button>';
            $tools .= '<button class="pj-btn pj-btn-x" data-reject="'.(int) $project->id.'">'.self::e('Reject').'</button>';
        }
        $toolsHtml = $tools ? '<div class="pj-detail-tools">'.$tools.'</div>' : '';

        $likeBtn = '<button class="pj-like pj-like-lg'.($hasLiked ? ' is-liked' : '').'" data-like="'.(int) $project->id.'">'
            .'<span class="pj-heart">♥</span> <span class="pj-like-n">'.number_format($project->likes_count).'</span></button>';

        $date = optional($project->created_at)->format('M j, Y');

        $body = '<div class="pj-wrap pj-detail" data-project="'.(int) $project->id.'" data-authed="'.(Auth::check() ? '1' : '0').'">'
            .'<div class="pj-crumbs"><a href="/projects">'.self::e('Projects').'</a> <span>›</span> '.self::e($project->title).'</div>'
            .$statusBanner
            .$heroImg
            .'<div class="pj-detail-head"><div class="pj-detail-headmain">'
            .($catBadges ? '<div class="pj-card-cats">'.$catBadges.'</div>' : '')
            .'<h1 class="pj-hero-t">'.self::e($project->title).'</h1>'
            .'<div class="pj-detail-meta">'.self::e('by').' <b>'.self::e($author).'</b>'.($date ? ' · '.self::e($date) : '').'</div>'
            .($project->excerpt ? '<p class="pj-detail-excerpt">'.self::e($project->excerpt).'</p>' : '')
            .'</div>'.$likeBtn.'</div>'
            .$toolsHtml
            .$linksHtml
            .$factsHtml
            .$contentHtml
            .'</div>';

        return ExtPage::render($project->title.' — Projects', $body, self::css(), self::detailJs());
    }

    private static function displayFieldValue(ProjectField $f, string $value): string
    {
        switch ($f->type) {
            case 'boolean':
                return self::e($value ? 'Yes' : 'No');
            case 'url':
                if (self::isSafeUrl($value)) {
                    return '<a href="'.self::e($value).'" target="_blank" rel="noopener noreferrer nofollow">'.self::e($value).'</a>';
                }

                return self::e($value);
            case 'date':
                $ts = strtotime($value);
                $shown = $ts ? date('M j, Y', $ts) : $value;

                return self::e(($f->prefix ? $f->prefix.' ' : '').$shown.($f->suffix ? ' '.$f->suffix : ''));
            default:
                return self::e(($f->prefix ? $f->prefix : '').$value.($f->suffix ? ' '.$f->suffix : ''));
        }
    }

    // --- Pages: create/edit form ------------------------------------------

    private static function formPage(?Project $project): \Inertia\Response
    {
        $editing = $project !== null;
        $cats = ProjectCategory::orderBy('position')->orderBy('name')->get();
        $fields = ProjectField::orderBy('position')->orderBy('name')->get();
        $buttons = ProjectButton::orderBy('position')->orderBy('label')->get();

        // Current values when editing.
        $vals = [];
        $selectedCats = [];
        $linkVals = collect();
        if ($editing) {
            $vals = ProjectFieldValue::where('project_id', $project->id)->pluck('value', 'field_id')->all();
            $selectedCats = $project->categories()->pluck('project_categories.id')->map(fn ($i) => (int) $i)->all();
            $linkVals = ProjectLink::where('project_id', $project->id)->get()
                ->groupBy(fn ($l) => (int) ($l->button_id ?: 0));
        }

        // Categories multi-select (checkbox grid).
        $catBoxes = '';
        foreach ($cats as $c) {
            $checked = in_array((int) $c->id, $selectedCats, true) ? ' checked' : '';
            $catBoxes .= '<label class="pj-checkpill"><input type="checkbox" name="cat" value="'.(int) $c->id.'"'.$checked.'> '
                .self::icon($c->icon).self::e($c->name).'</label>';
        }
        $catSection = $cats->count()
            ? '<div class="pj-f"><label class="pj-label">'.self::e('Categories').'</label><div class="pj-checkgrid" id="pj-cats">'.$catBoxes.'</div></div>'
            : '';

        // Custom fields.
        $fieldSection = '';
        foreach ($fields as $f) {
            $cur = (string) ($vals[$f->id] ?? '');
            $req = $f->is_required ? ' <span class="pj-req">*</span>' : '';
            $label = '<label class="pj-label">'.self::icon($f->icon).self::e($f->name).$req.'</label>';
            $name = 'field_'.(int) $f->id;
            $input = match ($f->type) {
                'textarea' => '<textarea class="pj-input" name="'.$name.'" rows="3">'.self::e($cur).'</textarea>',
                'number' => '<input class="pj-input" type="number" name="'.$name.'" value="'.self::e($cur).'">',
                'date' => '<input class="pj-input" type="date" name="'.$name.'" value="'.self::e($cur).'">',
                'url' => '<input class="pj-input" type="url" name="'.$name.'" value="'.self::e($cur).'" placeholder="https://…">',
                'boolean' => '<label class="pj-check"><input type="checkbox" name="'.$name.'" value="1"'.($cur ? ' checked' : '').'> '.self::e('Yes').'</label>',
                'select' => self::selectField($name, (array) ($f->options ?? []), $cur),
                default => '<input class="pj-input" type="text" name="'.$name.'" value="'.self::e($cur).'">',
            };
            $hint = '';
            if ($f->prefix || $f->suffix) {
                $hint = '<span class="pj-hint">'.self::e(trim(($f->prefix ?? '').' … '.($f->suffix ?? ''))).'</span>';
            }
            $fieldSection .= '<div class="pj-f">'.$label.$input.$hint.'</div>';
        }
        $fieldsBlock = $fieldSection
            ? '<div class="pj-card-panel"><h3 class="pj-panel-h">'.self::e('Details').'</h3>'.$fieldSection.'</div>'
            : '';

        // Button/link slots.
        $linkSection = '';
        foreach ($buttons as $b) {
            $existing = $editing ? ($linkVals[(int) $b->id] ?? collect())->first() : null;
            $curUrl = $existing ? (string) $existing->url : '';
            $curLabel = $existing ? (string) $existing->label : '';
            $req = $b->is_required ? ' <span class="pj-req">*</span>' : '';
            $domains = array_filter((array) ($b->allowed_domains ?? []));
            $hint = $domains ? '<span class="pj-hint">'.self::e('Allowed: '.implode(', ', $domains)).'</span>' : '';
            $labelInput = $b->allow_custom_label
                ? '<input class="pj-input pj-input-sm" type="text" data-btn-label="'.(int) $b->id.'" value="'.self::e($curLabel).'" placeholder="'.self::e('Custom label (optional)').'">'
                : '';
            $linkSection .= '<div class="pj-f pj-slot" data-slot="'.(int) $b->id.'" data-domains="'.self::e(implode(',', $domains)).'">'
                .'<label class="pj-label">'.self::icon($b->icon).self::e($b->label).$req.'</label>'
                .'<input class="pj-input" type="url" data-btn-url="'.(int) $b->id.'" value="'.self::e($curUrl).'" placeholder="https://…">'
                .$labelInput.$hint.'</div>';
        }
        // Ad-hoc links beyond configured buttons (if enabled).
        $freeLinks = (bool) self::setting('free_links', false);
        $freeBlock = '';
        if ($freeLinks) {
            $freeBlock = '<div class="pj-card-panel"><h3 class="pj-panel-h">'.self::e('Extra links').'</h3>'
                .'<div id="pj-free-links"></div>'
                .'<button type="button" class="pj-btn pj-btn-ghost" id="pj-add-link">+ '.self::e('Add a link').'</button></div>';
        }
        $linksBlock = $linkSection
            ? '<div class="pj-card-panel"><h3 class="pj-panel-h">'.self::e('Links').'</h3>'.$linkSection.'</div>'.$freeBlock
            : $freeBlock;

        $img = $editing ? self::safeImage($project->image_path) : null;
        $imgPreview = $img
            ? '<div class="pj-img-preview" id="pj-img-preview" style="background-image:url(\''.self::e($img).'\')"></div>'
            : '<div class="pj-img-preview pj-img-empty" id="pj-img-preview">'.self::e('No image').'</div>';

        $title = $editing ? self::e($project->title) : '';
        $excerpt = $editing ? self::e((string) $project->excerpt) : '';
        $content = $editing ? self::e((string) $project->content) : '';

        $heading = $editing ? 'Edit project' : 'Submit a project';

        $body = '<div class="pj-wrap pj-form" data-project="'.($editing ? (int) $project->id : '').'" data-image="'.self::e($img ?? '').'">'
            .'<div class="pj-crumbs"><a href="/projects">'.self::e('Projects').'</a> <span>›</span> '.self::e($heading).'</div>'
            .'<h1 class="pj-hero-t" style="margin-bottom:18px">'.self::e($heading).'</h1>'
            .'<div id="pj-errors" class="pj-banner pj-banner-rejected" hidden></div>'
            .'<div class="pj-card-panel">'
            .'<div class="pj-f"><label class="pj-label">'.self::e('Title').' <span class="pj-req">*</span></label>'
            .'<input class="pj-input" id="pj-title" type="text" maxlength="255" value="'.$title.'" placeholder="'.self::e('My awesome project').'"></div>'
            .'<div class="pj-f"><label class="pj-label">'.self::e('Short excerpt').'</label>'
            .'<textarea class="pj-input" id="pj-excerpt" rows="2" maxlength="600" placeholder="'.self::e('A one-line summary shown on the card').'">'.$excerpt.'</textarea></div>'
            .'<div class="pj-f"><label class="pj-label">'.self::e('Description').'</label>'
            .'<textarea class="pj-input" id="pj-content" rows="6" placeholder="'.self::e('Tell people about your project…').'">'.$content.'</textarea></div>'
            .'<div class="pj-f"><label class="pj-label">'.self::e('Cover image').'</label>'
            .'<div class="pj-img-row">'.$imgPreview
            .'<div class="pj-img-actions"><input type="file" id="pj-img-file" accept="image/*" hidden>'
            .'<button type="button" class="pj-btn pj-btn-ghost" id="pj-img-btn">'.self::e('Upload image').'</button>'
            .'<button type="button" class="pj-btn pj-btn-x" id="pj-img-clear">'.self::e('Remove').'</button></div></div></div>'
            .$catSection
            .'</div>'
            .$fieldsBlock
            .$linksBlock
            .'<div class="pj-form-foot"><button type="button" class="pj-btn pj-btn-p" id="pj-submit">'
            .self::e($editing ? 'Save changes' : 'Submit project').'</button>'
            .'<a class="pj-btn pj-btn-ghost" href="/projects">'.self::e('Cancel').'</a>'
            .'<span id="pj-msg" class="pj-muted"></span></div>'
            .'</div>';

        return ExtPage::render($heading, $body, self::css(), self::formJs());
    }

    private static function selectField(string $name, array $options, string $cur): string
    {
        $opts = '<option value="">—</option>';
        foreach ($options as $o) {
            $sel = ((string) $o === $cur) ? ' selected' : '';
            $opts .= '<option value="'.self::e((string) $o).'"'.$sel.'>'.self::e((string) $o).'</option>';
        }

        return '<select class="pj-input" name="'.$name.'">'.$opts.'</select>';
    }

    // --- Admin -------------------------------------------------------------

    private static function adminPage(): \Inertia\Response
    {
        $body = <<<'HTML'
        <div class="pj-wrap pj-admin">
          <div class="pj-tabs">
            <button class="pj-tab is-on" data-tab="categories">Categories</button>
            <button class="pj-tab" data-tab="fields">Fields</button>
            <button class="pj-tab" data-tab="buttons">Buttons</button>
            <button class="pj-tab" data-tab="moderation">Moderation</button>
          </div>

          <section class="pj-tabpane" data-pane="categories">
            <h3 class="pj-panel-h">Project categories</h3>
            <p class="pj-muted">Group projects and give each a colour + icon. The primary category drives the card accent and the author's profile badge.</p>
            <div id="pj-cat-list" class="pj-list"></div>
            <div class="pj-editor" id="pj-cat-editor">
              <input class="pj-input" id="cat-name" placeholder="Category name">
              <div class="pj-frow">
                <input class="pj-input" id="cat-icon" placeholder="Icon (emoji or short text)">
                <input class="pj-input" id="cat-color" type="color" value="#5b5bd6" title="Accent colour">
              </div>
              <input class="pj-input" id="cat-desc" placeholder="Description (optional)">
              <div class="pj-editor-foot"><button class="pj-btn pj-btn-p" id="cat-save">Add category</button>
              <button class="pj-btn pj-btn-ghost" id="cat-cancel" hidden>Cancel</button></div>
            </div>
          </section>

          <section class="pj-tabpane" data-pane="fields" hidden>
            <h3 class="pj-panel-h">Custom fields</h3>
            <p class="pj-muted">Typed parameters projects carry, e.g. Genre, Release date, Price. "Show on card" surfaces it on the project card.</p>
            <div id="pj-field-list" class="pj-list"></div>
            <div class="pj-editor" id="pj-field-editor">
              <input class="pj-input" id="field-name" placeholder="Field name">
              <div class="pj-frow">
                <select class="pj-input" id="field-type">
                  <option value="text">Text</option>
                  <option value="textarea">Long text</option>
                  <option value="number">Number</option>
                  <option value="date">Date</option>
                  <option value="url">URL</option>
                  <option value="select">Select (choices)</option>
                  <option value="boolean">Yes / No</option>
                </select>
                <input class="pj-input" id="field-icon" placeholder="Icon (optional)">
              </div>
              <input class="pj-input" id="field-options" placeholder="Choices, comma-separated (select only)" hidden>
              <div class="pj-frow">
                <input class="pj-input" id="field-prefix" placeholder="Prefix (e.g. $)">
                <input class="pj-input" id="field-suffix" placeholder="Suffix (e.g. USD)">
              </div>
              <label class="pj-check"><input type="checkbox" id="field-required"> Required</label>
              <label class="pj-check"><input type="checkbox" id="field-oncard" checked> Show on card</label>
              <div class="pj-editor-foot"><button class="pj-btn pj-btn-p" id="field-save">Add field</button>
              <button class="pj-btn pj-btn-ghost" id="field-cancel" hidden>Cancel</button></div>
            </div>
          </section>

          <section class="pj-tabpane" data-pane="buttons" hidden>
            <h3 class="pj-panel-h">Link buttons</h3>
            <p class="pj-muted">Define the link slots projects can fill, optionally restricted to certain domains (e.g. only youtube.com).</p>
            <div id="pj-btn-list" class="pj-list"></div>
            <div class="pj-editor" id="pj-btn-editor">
              <input class="pj-input" id="btn-label" placeholder="Button label (e.g. Watch trailer)">
              <div class="pj-frow">
                <input class="pj-input" id="btn-icon" placeholder="Icon (optional)">
                <input class="pj-input" id="btn-domains" placeholder="Allowed domains, comma-separated (optional)">
              </div>
              <label class="pj-check"><input type="checkbox" id="btn-customlabel" checked> Allow custom label</label>
              <label class="pj-check"><input type="checkbox" id="btn-required"> Required</label>
              <label class="pj-check"><input type="checkbox" id="btn-primary"> Primary (filled) button</label>
              <div class="pj-editor-foot"><button class="pj-btn pj-btn-p" id="btn-save">Add button</button>
              <button class="pj-btn pj-btn-ghost" id="btn-cancel" hidden>Cancel</button></div>
            </div>
          </section>

          <section class="pj-tabpane" data-pane="moderation" hidden>
            <h3 class="pj-panel-h">Pending projects</h3>
            <p class="pj-muted">Projects from members who can't publish instantly land here for approval.</p>
            <div id="pj-mod-list" class="pj-list">Loading…</div>
          </section>
        </div>
        HTML;

        return ExtPage::render('Projects', $body, self::css(), self::adminJs());
    }

    // --- Query helpers (avoid N+1 on the index) ----------------------------

    private static function authorNames($projects): array
    {
        return User::whereIn('id', collect($projects)->pluck('user_id')->filter()->unique())->pluck('name', 'id')->all();
    }

    private static function categoryMap($projects): array
    {
        $ids = collect($projects)->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }
        $rows = DB::table('project_category')
            ->join('project_categories', 'project_categories.id', '=', 'project_category.category_id')
            ->whereIn('project_category.project_id', $ids)
            ->orderBy('project_categories.position')
            ->get(['project_category.project_id as pid', 'project_categories.id', 'project_categories.name', 'project_categories.color', 'project_categories.icon', 'project_categories.slug']);
        $map = [];
        foreach ($rows as $r) {
            $map[$r->pid][] = ['id' => (int) $r->id, 'name' => $r->name, 'color' => $r->color, 'icon' => $r->icon, 'slug' => $r->slug];
        }

        return $map;
    }

    private static function onCardFieldValues($projects, $fieldDefs): array
    {
        $ids = collect($projects)->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }
        $rows = ProjectFieldValue::whereIn('project_id', $ids)->get();
        $map = [];
        foreach ($rows as $r) {
            $f = $fieldDefs[$r->field_id] ?? null;
            if (! $f || ! $f->on_card || $r->value === null || $r->value === '') {
                continue;
            }
            $map[$r->project_id][] = [
                'name' => $f->name,
                'icon' => $f->icon,
                'display' => self::plainFieldValue($f, (string) $r->value),
            ];
        }

        return $map;
    }

    private static function plainFieldValue(ProjectField $f, string $value): string
    {
        if ($f->type === 'boolean') {
            return $value ? 'Yes' : 'No';
        }
        if ($f->type === 'date') {
            $ts = strtotime($value);
            $value = $ts ? date('M j, Y', $ts) : $value;
        }

        return ($f->prefix ? $f->prefix : '').$value.($f->suffix ? ' '.$f->suffix : '');
    }

    private static function linkMap($projects): array
    {
        $ids = collect($projects)->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }
        $buttons = ProjectButton::all()->keyBy('id');
        $links = ProjectLink::whereIn('project_id', $ids)->orderBy('position')->get();
        $map = [];
        foreach ($links as $l) {
            $b = $l->button_id ? $buttons->get($l->button_id) : null;
            $map[$l->project_id][] = [
                'url' => $l->url,
                'label' => $l->label ?: ($b ? $b->label : $l->url),
                'icon' => $b ? $b->icon : null,
                'isPrimary' => $b ? (bool) $b->is_primary : false,
            ];
        }

        return $map;
    }

    private static function likedSet($projectIds)
    {
        if (! Auth::check()) {
            return collect();
        }

        return DB::table('project_likes')->where('user_id', Auth::id())
            ->whereIn('project_id', $projectIds)->pluck('project_id')->flip();
    }

    // --- Small helpers -----------------------------------------------------

    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /** Render a category/field/button icon: short emoji/text inline, else an image. */
    private static function icon(?string $icon, string $fallback = ''): string
    {
        $icon = trim((string) $icon);
        if ($icon === '') {
            return $fallback !== '' ? '<span class="pj-ico">'.self::e($fallback).'</span> ' : '';
        }
        if (str_starts_with($icon, '/') || preg_match('#^https?://#i', $icon)) {
            $safe = self::safeImage($icon);

            return $safe ? '<img class="pj-ico-img" src="'.self::e($safe).'" alt=""> ' : '';
        }

        return '<span class="pj-ico">'.self::e($icon).'</span> ';
    }

    private static function safeColor(?string $color): ?string
    {
        $color = trim((string) $color);

        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : null;
    }

    private static function safeImage(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (str_starts_with($v, '/') && ! str_starts_with($v, '//')) {
            return mb_substr($v, 0, 600);
        }
        $scheme = strtolower((string) parse_url($v, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true)) {
            return mb_substr($v, 0, 600);
        }

        return null;
    }

    private static function uniqueSlug(string $modelClass, string $title, ?int $ignoreId = null, string $column = 'slug'): string
    {
        $base = Str::slug($title) ?: 'item';
        $slug = $base;
        $i = 1;
        while ($modelClass::where($column, $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    // --- CSS ---------------------------------------------------------------

    private static function css(): string
    {
        return <<<'CSS'
        .pj-wrap{max-width:1100px;margin:0 auto;padding:24px 16px 64px}
        .pj-detail,.pj-form{max-width:840px}
        .pj-admin{max-width:780px}
        .ext-embed .pj-wrap{padding:0}
        .pj-muted{color:rgb(var(--c-muted));font-size:14px}
        .pj-btn{font:inherit;font-size:14px;font-weight:700;padding:9px 16px;border-radius:11px;border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text));cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:.15s}
        .pj-btn:hover{border-color:rgb(var(--c-primary))}
        .pj-btn-p{background:rgb(var(--c-primary));border-color:rgb(var(--c-primary));color:#fff}
        .pj-btn-p:hover{filter:brightness(1.06)}
        .pj-btn-ghost{background:rgb(var(--c-surface-2))}
        .pj-btn-x{color:#e5484d;border-color:transparent;background:rgba(229,72,77,.08)}
        .pj-req{color:#e5484d}
        .pj-hero{position:relative;display:flex;align-items:flex-end;justify-content:space-between;gap:18px;padding:30px;margin-bottom:22px;border-radius:20px;overflow:hidden;
          background:linear-gradient(135deg,rgba(91,91,214,.16),rgba(139,92,246,.10)),rgb(var(--c-surface));border:1px solid rgb(var(--c-border))}
        .pj-eyebrow{font-size:13px;font-weight:800;letter-spacing:.04em;color:rgb(var(--c-primary));margin-bottom:6px}
        .pj-hero-t{font-size:2rem;font-weight:900;letter-spacing:-.02em;margin:0;color:rgb(var(--c-text))}
        .pj-hero-stats{margin-top:7px;color:rgb(var(--c-text-2));font-weight:600}
        .pj-crumbs{font-size:13px;color:rgb(var(--c-muted));margin-bottom:14px}
        .pj-crumbs a{color:rgb(var(--c-primary));text-decoration:none;font-weight:600}
        .pj-crumbs span{margin:0 4px}
        .pj-ico-img{width:1.05em;height:1.05em;border-radius:4px;object-fit:cover;vertical-align:-2px}
        /* Filter chips */
        .pj-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:22px}
        .pj-chip{font:inherit;font-size:13px;font-weight:700;padding:7px 13px;border-radius:999px;border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text-2));cursor:pointer;display:inline-flex;align-items:center;gap:5px;--pj-accent:rgb(var(--c-primary))}
        .pj-chip:hover{border-color:var(--pj-accent)}
        .pj-chip.is-on{background:var(--pj-accent);border-color:var(--pj-accent);color:#fff}
        /* Grid + cards */
        .pj-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px}
        .pj-card{display:flex;flex-direction:column;border-radius:16px;overflow:hidden;background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));cursor:pointer;transition:.18s;--pj-accent:rgb(var(--c-primary))}
        .pj-card:hover{transform:translateY(-3px);box-shadow:0 12px 30px rgba(0,0,0,.16);border-color:var(--pj-accent)}
        .pj-card-img{aspect-ratio:16/9;background-size:cover;background-position:center;background-color:rgb(var(--c-surface-2))}
        .pj-card-img-blank{display:grid;place-items:center;font-size:38px;color:rgb(var(--c-muted))}
        .pj-card-body{padding:14px 15px 6px;flex:1}
        .pj-card-cats{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
        .pj-cat{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:999px;color:#fff;background:var(--pj-accent,rgb(var(--c-primary)));text-decoration:none;display:inline-flex;align-items:center;gap:4px;--pj-accent:rgb(var(--c-primary))}
        .pj-card-title{font-size:1.05rem;font-weight:800;margin:0 0 3px;color:rgb(var(--c-text));line-height:1.3}
        .pj-card-author{font-size:12.5px;color:rgb(var(--c-muted));margin-bottom:8px}
        .pj-card-fields{display:flex;flex-direction:column;gap:3px;margin-bottom:8px}
        .pj-field{font-size:12px;color:rgb(var(--c-text-2));overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .pj-field b{color:rgb(var(--c-muted));font-weight:600}
        .pj-card-excerpt{font-size:13px;color:rgb(var(--c-text-2));line-height:1.5;margin:0;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
        .pj-card-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 15px 13px}
        .pj-card-links{display:flex;gap:6px;flex-wrap:wrap;min-width:0}
        .pj-link{font-size:12px;font-weight:700;padding:5px 10px;border-radius:9px;border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface-2));color:rgb(var(--c-text));text-decoration:none;display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
        .pj-link-p{background:rgb(var(--c-primary));border-color:rgb(var(--c-primary));color:#fff}
        .pj-like{font:inherit;font-size:12.5px;font-weight:700;border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface));color:rgb(var(--c-text-2));border-radius:999px;padding:5px 11px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;flex-shrink:0}
        .pj-like:hover{border-color:#ff5470}
        .pj-like .pj-heart{color:rgb(var(--c-muted));transition:.15s}
        .pj-like.is-liked{border-color:#ff5470;color:#ff5470}
        .pj-like.is-liked .pj-heart{color:#ff5470}
        .pj-like-lg{font-size:14px;padding:9px 16px}
        .pj-blank{text-align:center;padding:60px 20px;border:1.5px dashed rgb(var(--c-border));border-radius:18px;grid-column:1/-1}
        .pj-blank-ico{font-size:44px;margin-bottom:8px}
        .pj-blank-t{font-weight:800;font-size:1.05rem;color:rgb(var(--c-text))}
        /* Detail */
        .pj-banner{padding:12px 15px;border-radius:12px;margin-bottom:16px;font-size:14px;font-weight:600}
        .pj-banner-pending{background:rgba(245,158,11,.12);color:#b45309}
        .pj-banner-rejected{background:rgba(229,72,77,.12);color:#e5484d}
        .pj-detail-img{aspect-ratio:21/9;border-radius:18px;background-size:cover;background-position:center;background-color:rgb(var(--c-surface-2));margin-bottom:18px}
        .pj-detail-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px}
        .pj-detail-headmain{min-width:0}
        .pj-detail-meta{margin-top:6px;color:rgb(var(--c-muted));font-size:14px}
        .pj-detail-meta b{color:rgb(var(--c-text-2))}
        .pj-detail-excerpt{margin-top:12px;font-size:1.05rem;color:rgb(var(--c-text-2));line-height:1.6}
        .pj-detail-tools{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
        .pj-detail-links{display:flex;flex-wrap:wrap;gap:9px;margin-bottom:20px}
        .pj-facts{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:22px}
        .pj-fact{background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:12px;padding:11px 13px}
        .pj-fact-k{display:block;font-size:12px;font-weight:700;color:rgb(var(--c-muted));margin-bottom:3px}
        .pj-fact-v{font-size:14px;color:rgb(var(--c-text));font-weight:600;word-break:break-word}
        .pj-fact-v a{color:rgb(var(--c-primary));text-decoration:none}
        .pj-content{font-size:15px;line-height:1.7;color:rgb(var(--c-text));margin-top:6px}
        /* Forms */
        .pj-card-panel{background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:16px;padding:20px;margin-bottom:16px}
        .pj-panel-h{font-size:1.05rem;font-weight:800;margin:0 0 14px;color:rgb(var(--c-text))}
        .pj-f{margin-bottom:15px}
        .pj-f:last-child{margin-bottom:0}
        .pj-label{display:flex;align-items:center;gap:5px;font-size:13px;font-weight:700;color:rgb(var(--c-text-2));margin-bottom:6px}
        .pj-input{font:inherit;font-size:14px;padding:10px 12px;border-radius:11px;border:1px solid rgb(var(--c-border));background:rgb(var(--c-appbg,var(--c-surface-2)));color:rgb(var(--c-text));width:100%}
        textarea.pj-input{resize:vertical}
        .pj-input:focus{outline:none;border-color:rgb(var(--c-primary))}
        .pj-input-sm{margin-top:8px}
        input[type=color].pj-input{padding:4px;height:42px;cursor:pointer}
        .pj-frow{display:flex;gap:12px;flex-wrap:wrap}
        .pj-frow>.pj-input{flex:1;min-width:140px}
        .pj-hint{display:block;margin-top:5px;font-size:12px;color:rgb(var(--c-muted))}
        .pj-check{display:inline-flex;align-items:center;gap:8px;font-size:14px;font-weight:600;color:rgb(var(--c-text));margin-right:18px}
        .pj-checkgrid{display:flex;flex-wrap:wrap;gap:8px}
        .pj-checkpill{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:rgb(var(--c-text));padding:7px 12px;border:1px solid rgb(var(--c-border));border-radius:999px;cursor:pointer;background:rgb(var(--c-surface-2))}
        .pj-checkpill input{margin:0}
        .pj-img-row{display:flex;gap:14px;align-items:center;flex-wrap:wrap}
        .pj-img-preview{width:160px;height:96px;border-radius:12px;background-size:cover;background-position:center;background-color:rgb(var(--c-surface-2));flex-shrink:0}
        .pj-img-empty{display:grid;place-items:center;font-size:12px;color:rgb(var(--c-muted));border:1px dashed rgb(var(--c-border))}
        .pj-img-actions{display:flex;flex-direction:column;gap:8px}
        .pj-form-foot{display:flex;align-items:center;gap:12px;margin-top:4px;flex-wrap:wrap}
        /* Admin */
        .pj-tabs{display:flex;gap:4px;border-bottom:1px solid rgb(var(--c-border));margin-bottom:20px;flex-wrap:wrap}
        .pj-tab{font:inherit;font-size:14px;font-weight:700;padding:10px 16px;border:0;background:none;color:rgb(var(--c-muted));cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px}
        .pj-tab.is-on{color:rgb(var(--c-primary));border-bottom-color:rgb(var(--c-primary))}
        .pj-list{display:flex;flex-direction:column;margin-bottom:18px}
        .pj-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 0;border-bottom:1px solid rgb(var(--c-border))}
        .pj-row:last-child{border-bottom:0}
        .pj-row-main{min-width:0}
        .pj-row-t{font-weight:700;color:rgb(var(--c-text));display:flex;align-items:center;gap:6px}
        .pj-row-t a{color:inherit;text-decoration:none}
        .pj-row-t a:hover{color:rgb(var(--c-primary))}
        .pj-row-m{font-size:12.5px;color:rgb(var(--c-muted));margin-top:2px}
        .pj-row-a{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end}
        .pj-swatch{width:14px;height:14px;border-radius:4px;display:inline-block;flex-shrink:0}
        .pj-editor{background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:11px}
        .pj-editor-foot{display:flex;gap:8px;margin-top:2px}
        .pj-x{width:30px;height:30px;border:0;border-radius:8px;background:transparent;color:rgb(var(--c-muted));font-size:18px;cursor:pointer}
        .pj-x:hover{background:rgba(229,72,77,.12);color:#e5484d}
        CSS;
    }

    // --- JS: index ---------------------------------------------------------

    private static function indexJs(): string
    {
        return <<<'JS'
        (function(){
          // Category filter chips + ?cat= deep link.
          var chips = document.querySelectorAll('.pj-chip');
          var cards = [].slice.call(document.querySelectorAll('.pj-card'));
          function applyFilter(cat){
            cards.forEach(function(c){
              var ids = (c.getAttribute('data-cats')||'').split(',').filter(Boolean);
              var hit = !cat || ids.indexOf(String(cat)) >= 0;
              c.style.display = hit ? '' : 'none';
            });
          }
          chips.forEach(function(chip){
            chip.addEventListener('click', function(){
              chips.forEach(function(x){x.classList.remove('is-on');});
              chip.classList.add('is-on');
              applyFilter(chip.getAttribute('data-cat'));
            });
          });
          var qp = new URLSearchParams(location.search).get('cat');
          if(qp){ var target = document.querySelector('.pj-chip[data-cat="'+qp+'"]'); if(target) target.click(); }

          // Like toggle.
          var grid = document.getElementById('pj-grid');
          grid && grid.addEventListener('click', function(ev){
            var b = ev.target.closest('.pj-like'); if(!b) return;
            ev.stopPropagation(); ev.preventDefault();
            fetch('/api/ext/projects/'+b.getAttribute('data-like')+'/like',{method:'POST',headers:H,credentials:'same-origin'})
              .then(function(r){return r.ok?r.json():null;})
              .then(function(d){if(!d)return;b.classList.toggle('is-liked',d.liked);b.querySelector('.pj-like-n').textContent=d.count;})
              .catch(function(){});
          });
        })();
        JS;
    }

    // --- JS: detail --------------------------------------------------------

    private static function detailJs(): string
    {
        return <<<'JS'
        (function(){
          var wrap = document.querySelector('.pj-detail');
          if(!wrap) return;
          var id = wrap.getAttribute('data-project');
          wrap.addEventListener('click', function(ev){
            var like = ev.target.closest('.pj-like');
            if(like){ fetch('/api/ext/projects/'+id+'/like',{method:'POST',headers:H,credentials:'same-origin'})
              .then(function(r){return r.ok?r.json():null;}).then(function(d){if(!d)return;like.classList.toggle('is-liked',d.liked);like.querySelector('.pj-like-n').textContent=d.count;}); return; }

            var del = ev.target.closest('[data-delete]');
            if(del){ if(!confirm('Delete this project? This cannot be undone.'))return;
              fetch('/api/ext/projects/'+del.getAttribute('data-delete'),{method:'DELETE',headers:H,credentials:'same-origin'})
                .then(function(r){if(r.ok)location.href='/projects';}); return; }

            var feat = ev.target.closest('[data-feature]');
            if(feat){ fetch('/api/ext/projects/'+feat.getAttribute('data-feature')+'/feature',{method:'POST',headers:H,credentials:'same-origin'})
              .then(function(r){return r.ok?r.json():null;}).then(function(d){if(!d)return;feat.textContent=d.featured?'★ Featured':'☆ Feature on profile';}); return; }

            var app = ev.target.closest('[data-approve]');
            if(app){ fetch('/api/ext/projects/'+app.getAttribute('data-approve')+'/moderate',{method:'POST',headers:H,credentials:'same-origin',body:JSON.stringify({action:'approve'})})
              .then(function(r){if(r.ok)location.reload();}); return; }

            var rej = ev.target.closest('[data-reject]');
            if(rej){ var reason=prompt('Reason for rejection (optional):','')||'';
              fetch('/api/ext/projects/'+rej.getAttribute('data-reject')+'/moderate',{method:'POST',headers:H,credentials:'same-origin',body:JSON.stringify({action:'reject',reason:reason})})
                .then(function(r){if(r.ok)location.reload();}); return; }
          });
        })();
        JS;
    }

    // --- JS: form ----------------------------------------------------------

    private static function formJs(): string
    {
        return <<<'JS'
        (function(){
          var wrap = document.querySelector('.pj-form');
          if(!wrap) return;
          var pid = wrap.getAttribute('data-project') || '';
          var image = wrap.getAttribute('data-image') || '';

          // Image upload.
          var fileInput = document.getElementById('pj-img-file');
          var preview = document.getElementById('pj-img-preview');
          document.getElementById('pj-img-btn').addEventListener('click', function(){ fileInput.click(); });
          document.getElementById('pj-img-clear').addEventListener('click', function(){
            image=''; preview.style.backgroundImage=''; preview.classList.add('pj-img-empty'); preview.textContent='No image';
          });
          fileInput.addEventListener('change', function(){
            var f = fileInput.files && fileInput.files[0]; if(!f) return;
            var fd = new FormData(); fd.append('file', f);
            preview.classList.remove('pj-img-empty'); preview.textContent='Uploading…';
            fetch('/uploads/image',{method:'POST',headers:{'X-CSRF-TOKEN':csrf,Accept:'application/json'},credentials:'same-origin',body:fd})
              .then(function(r){return r.ok?r.json():Promise.reject();})
              .then(function(d){ if(d&&d.url){ image=d.url; preview.textContent=''; preview.style.backgroundImage="url('"+d.url+"')"; } })
              .catch(function(){ preview.textContent='Upload failed'; });
          });

          // Build payload.
          function collect(){
            var catIds = [].slice.call(document.querySelectorAll('#pj-cats input:checked')).map(function(c){return parseInt(c.value,10);});
            var fieldValues = {};
            [].slice.call(document.querySelectorAll('[name^="field_"]')).forEach(function(el){
              var fid = el.name.replace('field_','');
              if(el.type==='checkbox'){ fieldValues[fid] = el.checked ? '1' : ''; }
              else { fieldValues[fid] = el.value; }
            });
            var links = [];
            [].slice.call(document.querySelectorAll('.pj-slot')).forEach(function(slot){
              var bid = slot.getAttribute('data-slot');
              var urlEl = slot.querySelector('[data-btn-url]');
              var url = urlEl ? urlEl.value : '';
              var labelEl = slot.querySelector('[data-btn-label]');
              if(url.trim()) links.push({buttonId:parseInt(bid,10),url:url.trim(),label:labelEl?labelEl.value.trim():''});
            });
            [].slice.call(document.querySelectorAll('#pj-free-links .pj-free-row')).forEach(function(row){
              var u=row.querySelector('.pj-free-url'); var l=row.querySelector('.pj-free-label');
              var url=u?u.value:''; var label=l?l.value:'';
              if(url.trim()) links.push({buttonId:0,url:url.trim(),label:label.trim()});
            });
            return {
              title: document.getElementById('pj-title').value.trim(),
              excerpt: document.getElementById('pj-excerpt').value.trim(),
              content: document.getElementById('pj-content').value,
              image: image,
              categoryIds: catIds,
              primaryCategoryId: catIds[0] || null,
              fieldValues: fieldValues,
              links: links
            };
          }

          function showErrors(errors){
            var box = document.getElementById('pj-errors');
            var msgs = Object.keys(errors||{}).map(function(k){return errors[k];});
            box.textContent = msgs.length ? msgs.join('  ') : 'Please check the form and try again.';
            box.hidden = false; box.scrollIntoView({behavior:'smooth',block:'center'});
          }

          // Ad-hoc links.
          var addLink = document.getElementById('pj-add-link');
          if(addLink){ addLink.addEventListener('click', function(){
            var row=document.createElement('div'); row.className='pj-free-row pj-f';
            row.innerHTML='<div class="pj-frow"><input class="pj-input pj-free-url" type="url" placeholder="https://…"><input class="pj-input pj-free-label" type="text" placeholder="Label (optional)"><button type="button" class="pj-x pj-free-del">×</button></div>';
            document.getElementById('pj-free-links').appendChild(row);
            row.querySelector('.pj-free-del').addEventListener('click', function(){ row.remove(); });
          }); }

          document.getElementById('pj-submit').addEventListener('click', function(){
            var btn=this; var data=collect();
            if(!data.title){ showErrors({title:'A title is required.'}); return; }
            document.getElementById('pj-errors').hidden=true;
            var msg=document.getElementById('pj-msg'); msg.textContent='Saving…'; btn.disabled=true;
            var url = pid ? '/api/ext/projects/'+pid : '/api/ext/projects';
            fetch(url,{method:'POST',headers:H,credentials:'same-origin',body:JSON.stringify(data)})
              .then(function(r){return r.json().then(function(d){return {ok:r.ok,d:d};});})
              .then(function(x){
                if(x.ok && x.d.ok){ location.href='/projects/'+x.d.slug; }
                else { btn.disabled=false; msg.textContent=''; showErrors(x.d.errors); }
              })
              .catch(function(){ btn.disabled=false; msg.textContent='Something went wrong.'; });
          });
        })();
        JS;
    }

    // --- JS: admin ---------------------------------------------------------

    private static function adminJs(): string
    {
        return <<<'JS'
        (function(){
          function notify(m,k){try{if(window.parent!==window)window.parent.postMessage({type:'convoro:toast',message:m,kind:k||'success'},location.origin);}catch(e){}}
          var esc=function(s){return (s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});};

          // Tabs.
          document.querySelectorAll('.pj-tab').forEach(function(tab){
            tab.addEventListener('click', function(){
              document.querySelectorAll('.pj-tab').forEach(function(t){t.classList.remove('is-on');});
              tab.classList.add('is-on');
              var name=tab.getAttribute('data-tab');
              document.querySelectorAll('.pj-tabpane').forEach(function(p){ p.hidden = p.getAttribute('data-pane')!==name; });
              if(name==='moderation') loadModeration();
            });
          });

          var defs={categories:[],fields:[],buttons:[]};
          function loadDefs(){
            fetch('/admin/ext/projects/definitions',{headers:H}).then(function(r){return r.json();}).then(function(d){
              defs=d; renderCats(); renderFields(); renderButtons();
            });
          }

          // ---- Categories ----
          var catEditId=null;
          function renderCats(){
            var el=document.getElementById('pj-cat-list');
            if(!defs.categories.length){ el.innerHTML='<p class="pj-muted">No categories yet.</p>'; return; }
            el.innerHTML=defs.categories.map(function(c){
              var sw=c.color?'<span class="pj-swatch" style="background:'+esc(c.color)+'"></span>':'';
              return '<div class="pj-row"><div class="pj-row-main"><div class="pj-row-t">'+sw+(c.icon?esc(c.icon)+' ':'')+esc(c.name)+'</div>'
                +(c.description?'<div class="pj-row-m">'+esc(c.description)+'</div>':'')+'</div>'
                +'<div class="pj-row-a"><button class="pj-btn pj-btn-ghost" data-edit-cat="'+c.id+'">Edit</button>'
                +'<button class="pj-x" data-del-cat="'+c.id+'">×</button></div></div>';
            }).join('');
          }
          function catForm(){return {name:val('cat-name'),icon:val('cat-icon'),color:val('cat-color'),description:val('cat-desc')};}
          document.getElementById('cat-save').addEventListener('click', function(){
            var body=catForm(); if(!body.name){notify('Name is required','error');return;}
            if(catEditId) body.id=catEditId;
            post('/admin/ext/projects/categories',body,function(){notify(catEditId?'Category updated':'Category added');resetCat();loadDefs();});
          });
          document.getElementById('cat-cancel').addEventListener('click', resetCat);
          function resetCat(){catEditId=null;['cat-name','cat-icon','cat-desc'].forEach(function(i){document.getElementById(i).value='';});
            document.getElementById('cat-color').value='#5b5bd6';document.getElementById('cat-save').textContent='Add category';document.getElementById('cat-cancel').hidden=true;}
          document.getElementById('pj-cat-list').addEventListener('click', function(ev){
            var e=ev.target.closest('[data-edit-cat]');
            if(e){ var c=defs.categories.find(function(x){return x.id==e.getAttribute('data-edit-cat');}); if(!c)return;
              catEditId=c.id; setVal('cat-name',c.name);setVal('cat-icon',c.icon);setVal('cat-desc',c.description);document.getElementById('cat-color').value=c.color||'#5b5bd6';
              document.getElementById('cat-save').textContent='Save category';document.getElementById('cat-cancel').hidden=false; return; }
            var d=ev.target.closest('[data-del-cat]');
            if(d){ if(!confirm('Delete this category?'))return; del('/admin/ext/projects/categories/'+d.getAttribute('data-del-cat'),function(){notify('Category deleted');loadDefs();}); }
          });

          // ---- Fields ----
          var fieldEditId=null;
          var typeSel=document.getElementById('field-type');
          function toggleOptions(){ document.getElementById('field-options').hidden = typeSel.value!=='select'; }
          typeSel.addEventListener('change', toggleOptions); toggleOptions();
          function renderFields(){
            var el=document.getElementById('pj-field-list');
            if(!defs.fields.length){ el.innerHTML='<p class="pj-muted">No fields yet.</p>'; return; }
            el.innerHTML=defs.fields.map(function(f){
              var tags=[f.type]; if(f.isRequired)tags.push('required'); if(f.onCard)tags.push('on card');
              return '<div class="pj-row"><div class="pj-row-main"><div class="pj-row-t">'+(f.icon?esc(f.icon)+' ':'')+esc(f.name)+'</div>'
                +'<div class="pj-row-m">'+tags.join(' · ')+(f.options&&f.options.length?' · '+esc(f.options.join(', ')):'')+'</div></div>'
                +'<div class="pj-row-a"><button class="pj-btn pj-btn-ghost" data-edit-field="'+f.id+'">Edit</button>'
                +'<button class="pj-x" data-del-field="'+f.id+'">×</button></div></div>';
            }).join('');
          }
          document.getElementById('field-save').addEventListener('click', function(){
            var body={name:val('field-name'),type:typeSel.value,icon:val('field-icon'),prefix:val('field-prefix'),suffix:val('field-suffix'),
              is_required:document.getElementById('field-required').checked,on_card:document.getElementById('field-oncard').checked,
              options:val('field-options').split(',').map(function(s){return s.trim();}).filter(Boolean)};
            if(!body.name){notify('Name is required','error');return;}
            if(fieldEditId) body.id=fieldEditId;
            post('/admin/ext/projects/fields',body,function(){notify(fieldEditId?'Field updated':'Field added');resetField();loadDefs();});
          });
          document.getElementById('field-cancel').addEventListener('click', resetField);
          function resetField(){fieldEditId=null;['field-name','field-icon','field-options','field-prefix','field-suffix'].forEach(function(i){document.getElementById(i).value='';});
            typeSel.value='text';toggleOptions();document.getElementById('field-required').checked=false;document.getElementById('field-oncard').checked=true;
            document.getElementById('field-save').textContent='Add field';document.getElementById('field-cancel').hidden=true;}
          document.getElementById('pj-field-list').addEventListener('click', function(ev){
            var e=ev.target.closest('[data-edit-field]');
            if(e){ var f=defs.fields.find(function(x){return x.id==e.getAttribute('data-edit-field');}); if(!f)return;
              fieldEditId=f.id; setVal('field-name',f.name);setVal('field-icon',f.icon);typeSel.value=f.type;toggleOptions();
              setVal('field-options',(f.options||[]).join(', '));setVal('field-prefix',f.prefix);setVal('field-suffix',f.suffix);
              document.getElementById('field-required').checked=!!f.isRequired;document.getElementById('field-oncard').checked=!!f.onCard;
              document.getElementById('field-save').textContent='Save field';document.getElementById('field-cancel').hidden=false; return; }
            var d=ev.target.closest('[data-del-field]');
            if(d){ if(!confirm('Delete this field? Existing values are removed.'))return; del('/admin/ext/projects/fields/'+d.getAttribute('data-del-field'),function(){notify('Field deleted');loadDefs();}); }
          });

          // ---- Buttons ----
          var btnEditId=null;
          function renderButtons(){
            var el=document.getElementById('pj-btn-list');
            if(!defs.buttons.length){ el.innerHTML='<p class="pj-muted">No buttons yet.</p>'; return; }
            el.innerHTML=defs.buttons.map(function(b){
              var tags=[]; if(b.isPrimary)tags.push('primary'); if(b.isRequired)tags.push('required'); if(!b.allowCustomLabel)tags.push('fixed label');
              var dom=b.allowedDomains&&b.allowedDomains.length?('domains: '+esc(b.allowedDomains.join(', '))):'any domain';
              return '<div class="pj-row"><div class="pj-row-main"><div class="pj-row-t">'+(b.icon?esc(b.icon)+' ':'')+esc(b.label)+'</div>'
                +'<div class="pj-row-m">'+dom+(tags.length?' · '+tags.join(' · '):'')+'</div></div>'
                +'<div class="pj-row-a"><button class="pj-btn pj-btn-ghost" data-edit-btn="'+b.id+'">Edit</button>'
                +'<button class="pj-x" data-del-btn="'+b.id+'">×</button></div></div>';
            }).join('');
          }
          document.getElementById('btn-save').addEventListener('click', function(){
            var body={label:val('btn-label'),icon:val('btn-icon'),
              allowed_domains:val('btn-domains').split(',').map(function(s){return s.trim();}).filter(Boolean),
              allow_custom_label:document.getElementById('btn-customlabel').checked,
              is_required:document.getElementById('btn-required').checked,is_primary:document.getElementById('btn-primary').checked};
            if(!body.label){notify('Label is required','error');return;}
            if(btnEditId) body.id=btnEditId;
            post('/admin/ext/projects/buttons',body,function(){notify(btnEditId?'Button updated':'Button added');resetBtn();loadDefs();});
          });
          document.getElementById('btn-cancel').addEventListener('click', resetBtn);
          function resetBtn(){btnEditId=null;['btn-label','btn-icon','btn-domains'].forEach(function(i){document.getElementById(i).value='';});
            document.getElementById('btn-customlabel').checked=true;document.getElementById('btn-required').checked=false;document.getElementById('btn-primary').checked=false;
            document.getElementById('btn-save').textContent='Add button';document.getElementById('btn-cancel').hidden=true;}
          document.getElementById('pj-btn-list').addEventListener('click', function(ev){
            var e=ev.target.closest('[data-edit-btn]');
            if(e){ var b=defs.buttons.find(function(x){return x.id==e.getAttribute('data-edit-btn');}); if(!b)return;
              btnEditId=b.id; setVal('btn-label',b.label);setVal('btn-icon',b.icon);setVal('btn-domains',(b.allowedDomains||[]).join(', '));
              document.getElementById('btn-customlabel').checked=!!b.allowCustomLabel;document.getElementById('btn-required').checked=!!b.isRequired;document.getElementById('btn-primary').checked=!!b.isPrimary;
              document.getElementById('btn-save').textContent='Save button';document.getElementById('btn-cancel').hidden=false; return; }
            var d=ev.target.closest('[data-del-btn]');
            if(d){ if(!confirm('Delete this button slot?'))return; del('/admin/ext/projects/buttons/'+d.getAttribute('data-del-btn'),function(){notify('Button deleted');loadDefs();}); }
          });

          // ---- Moderation ----
          function loadModeration(){
            var el=document.getElementById('pj-mod-list'); el.textContent='Loading…';
            fetch('/admin/ext/projects/moderation',{headers:H}).then(function(r){return r.json();}).then(function(d){
              if(!d.projects.length){ el.innerHTML='<p class="pj-muted">Nothing pending. 🎉</p>'; return; }
              el.innerHTML=d.projects.map(function(p){
                return '<div class="pj-row"><div class="pj-row-main"><div class="pj-row-t"><a href="/projects/'+esc(p.slug)+'" target="_blank">'+esc(p.title)+'</a></div>'
                  +'<div class="pj-row-m">by '+esc(p.author)+(p.excerpt?' · '+esc(p.excerpt):'')+'</div></div>'
                  +'<div class="pj-row-a"><button class="pj-btn pj-btn-p" data-app="'+p.id+'">Approve</button>'
                  +'<button class="pj-btn pj-btn-x" data-rej="'+p.id+'">Reject</button></div></div>';
              }).join('');
            });
          }
          document.getElementById('pj-mod-list').addEventListener('click', function(ev){
            var a=ev.target.closest('[data-app]');
            if(a){ post('/admin/ext/projects/moderation/'+a.getAttribute('data-app'),{action:'approve'},function(){notify('Approved');loadModeration();}); return; }
            var r=ev.target.closest('[data-rej]');
            if(r){ var reason=prompt('Reason (optional):','')||''; post('/admin/ext/projects/moderation/'+r.getAttribute('data-rej'),{action:'reject',reason:reason},function(){notify('Rejected');loadModeration();}); }
          });

          // ---- helpers ----
          function val(id){return (document.getElementById(id).value||'').trim();}
          function setVal(id,v){document.getElementById(id).value=v||'';}
          function post(url,body,cb){fetch(url,{method:'POST',headers:H,credentials:'same-origin',body:JSON.stringify(body)}).then(function(r){if(r.ok)cb();else r.json().then(function(d){notify((d&&d.message)||'Could not save','error');}).catch(function(){notify('Could not save','error');});});}
          function del(url,cb){fetch(url,{method:'DELETE',headers:H,credentials:'same-origin'}).then(function(r){if(r.ok)cb();else notify('Could not delete','error');});}

          loadDefs();
        })();
        JS;
    }
}
