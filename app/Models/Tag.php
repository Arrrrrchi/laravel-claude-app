<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    // Relationships
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_tags')
                    ->withTimestamps();
    }

    public function publishedPosts(): BelongsToMany
    {
        return $this->posts()->published();
    }

    // Query Scopes
    public function scopePopular(Builder $query, int $limit = 20): Builder
    {
        return $query->withCount('publishedPosts')
                    ->orderBy('published_posts_count', 'desc')
                    ->limit($limit);
    }

    public function scopeWithPostCount(Builder $query): Builder
    {
        return $query->withCount(['posts', 'publishedPosts']);
    }

    public function scopeAlphabetical(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    // Accessors & Mutators
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    protected function slug(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => Str::slug($value),
        );
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // Boot method
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Tag $tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
            
            // Ensure slug uniqueness
            $originalSlug = $tag->slug;
            $count = 1;
            while (static::where('slug', $tag->slug)->exists()) {
                $tag->slug = $originalSlug . '-' . $count;
                $count++;
            }
        });

        static::updating(function (Tag $tag) {
            if ($tag->isDirty('name') && !$tag->isDirty('slug')) {
                $tag->slug = Str::slug($tag->name);
                
                // Ensure slug uniqueness
                $originalSlug = $tag->slug;
                $count = 1;
                while (static::where('slug', $tag->slug)->where('id', '!=', $tag->id)->exists()) {
                    $tag->slug = $originalSlug . '-' . $count;
                    $count++;
                }
            }
        });
    }
}