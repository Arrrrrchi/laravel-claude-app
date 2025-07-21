<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $slug
 * @property string $excerpt
 * @property string $content
 * @property string|null $featured_image
 * @property array|null $meta
 * @property string $status
 * @property Carbon|null $published_at
 * @property int $views_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'slug', 
        'excerpt',
        'content',
        'featured_image',
        'meta',
        'status',
        'published_at',
        'views_count',
    ];

    protected $casts = [
        'meta' => 'array',
        'published_at' => 'datetime',
        'views_count' => 'integer',
    ];

    protected $attributes = [
        'status' => 'draft',
        'views_count' => 0,
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'post_categories')
                    ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tags')
                    ->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function approvedComments(): HasMany
    {
        return $this->comments()->where('status', 'approved');
    }

    // Query Scopes
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
                    ->where('published_at', '<=', now());
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')
                    ->where('published_at', '>', now());
    }

    public function scopeByAuthor(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeWithCategory(Builder $query, string $categorySlug): Builder
    {
        return $query->whereHas('categories', function (Builder $q) use ($categorySlug) {
            $q->where('slug', $categorySlug);
        });
    }

    public function scopeWithTag(Builder $query, string $tagSlug): Builder
    {
        return $query->whereHas('tags', function (Builder $q) use ($tagSlug) {
            $q->where('slug', $tagSlug);
        });
    }

    public function scopePopular(Builder $query, int $limit = 10): Builder
    {
        return $query->orderBy('views_count', 'desc')->limit($limit);
    }

    public function scopeRecent(Builder $query, int $limit = 10): Builder
    {
        return $query->latest('published_at')->limit($limit);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereFullText(['title', 'content'], $term)
                    ->orWhere('title', 'like', "%{$term}%")
                    ->orWhere('excerpt', 'like', "%{$term}%");
    }

    // Accessors & Mutators
    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => ucfirst($value),
            set: fn (string $value): string => trim($value),
        );
    }

    protected function slug(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => Str::slug($value),
        );
    }

    protected function excerpt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): string => $value ?? Str::limit(strip_tags($this->content), 160),
            set: fn (?string $value): ?string => $value ? trim($value) : null,
        );
    }

    protected function readingTime(): Attribute
    {
        return Attribute::make(
            get: fn (): int => (int) ceil(str_word_count(strip_tags($this->content)) / 200),
        );
    }

    protected function isPublished(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status === 'published' && 
                               $this->published_at && 
                               $this->published_at->isPast(),
        );
    }

    protected function isScheduled(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status === 'scheduled' && 
                               $this->published_at && 
                               $this->published_at->isFuture(),
        );
    }

    protected function isDraft(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status === 'draft',
        );
    }

    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn (): string => route('posts.show', $this->slug),
        );
    }

    // Helper Methods
    public function incrementViewsCount(): void
    {
        $this->increment('views_count');
    }

    public function publish(?Carbon $publishedAt = null): bool
    {
        return $this->update([
            'status' => 'published',
            'published_at' => $publishedAt ?? now(),
        ]);
    }

    public function schedule(Carbon $publishedAt): bool
    {
        return $this->update([
            'status' => 'scheduled',
            'published_at' => $publishedAt,
        ]);
    }

    public function unpublish(): bool
    {
        return $this->update([
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // Boot method for model events
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Post $post) {
            if (empty($post->slug)) {
                $post->slug = Str::slug($post->title);
            }
            
            // Ensure slug uniqueness
            $originalSlug = $post->slug;
            $count = 1;
            while (static::where('slug', $post->slug)->exists()) {
                $post->slug = $originalSlug . '-' . $count;
                $count++;
            }
        });

        static::updating(function (Post $post) {
            if ($post->isDirty('title') && !$post->isDirty('slug')) {
                $post->slug = Str::slug($post->title);
                
                // Ensure slug uniqueness
                $originalSlug = $post->slug;
                $count = 1;
                while (static::where('slug', $post->slug)->where('id', '!=', $post->id)->exists()) {
                    $post->slug = $originalSlug . '-' . $count;
                    $count++;
                }
            }
        });
    }
}
