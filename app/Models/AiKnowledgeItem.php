<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Knowledge block that can be injected into an AI request when it matches
 * the user's current question.
 *
 * @property int        $id
 * @property string     $slug
 * @property string     $title
 * @property string     $content
 * @property array|null $keywords
 * @property bool       $is_active
 * @property int        $priority
 */
class AiKnowledgeItem extends Model
{
    use HasFactory;

    protected $table = 'ai_knowledge_items';

    protected $fillable = [
        'slug',
        'title',
        'content',
        'keywords',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'keywords' => 'array',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
