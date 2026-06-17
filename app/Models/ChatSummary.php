<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatSummary extends Model
{
    use HasFactory;

    protected $table = 'chat_summaries';

    protected $fillable = [
        'session_id',
        'user_id',
        'summary_text',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
        ];
    }
}
