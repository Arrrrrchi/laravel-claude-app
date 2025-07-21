<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_id
 * @property int|null $user_id
 * @property string|null $author_name
 * @property string|null $author_email
 * @property string $content
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'user_id',
        'author_name',
        'author_email',
        'content',
        'status',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    // Relationships
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Query Scopes
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeSpam(Builder $query): Builder
    {
        return $query->where('status', 'spam');
    }

    public function scopeRecent(Builder $query, int $limit = 10): Builder
    {
        return $query->latest()->limit($limit);
    }

    public function scopeForPost(Builder $query, Post $post): Builder
    {
        return $query->where('post_id', $post->id);
    }

    public function scopeByUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    // Accessors & Mutators
    protected function content(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    protected function authorName(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): string => $value ?? $this->user?->name ?? 'Anonymous',
            set: fn (?string $value): ?string => $value ? trim($value) : null,
        );
    }

    protected function authorEmail(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value ?? $this->user?->email,
            set: fn (?string $value): ?string => $value ? strtolower(trim($value)) : null,
        );
    }

    protected function isApproved(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status === 'approved',
        );
    }

    protected function isPending(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status === 'pending',
        );
    }

    protected function isSpam(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status === 'spam',
        );
    }

    // Helper Methods
    public function approve(): bool
    {
        return $this->update(['status' => 'approved']);
    }

    public function markAsSpam(): bool
    {
        return $this->update(['status' => 'spam']);
    }

    public function markAsPending(): bool
    {
        return $this->update(['status' => 'pending']);
    }

    // Boot method
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Comment $comment) {
            // Auto-approve comments from registered users (optional)
            if ($comment->user_id && !isset($comment->attributes['status'])) {
                $comment->status = 'approved';
            }
        });
    }
}