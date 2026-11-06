<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameAsset extends Model
{
    protected $fillable = ['game', 'category', 'slug', 'display_name', 'storage_path', 'mime_type', 'byte_size', 'uploaded_by'];
    protected $hidden = ['storage_path'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}