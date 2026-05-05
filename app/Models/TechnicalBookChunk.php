<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicalBookChunk extends Model
{
    protected $fillable = ['book_key', 'book_title', 'domain', 'page_no', 'content', 'keywords'];

    protected $casts = [
        'page_no'  => 'integer',
        'keywords' => 'array',
    ];
}
