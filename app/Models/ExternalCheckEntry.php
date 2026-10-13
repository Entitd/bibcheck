<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCheckEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['retry_count' => 'integer', 'request_count' => 'integer', 'input' => 'array', 'result' => 'array', 'checked_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ExternalCheckRun::class, 'run_id');
    }
}
