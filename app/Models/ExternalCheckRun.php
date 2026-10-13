<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalCheckRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'check_history_id' => 'integer', 'total_entries' => 'integer', 'finished_entries' => 'integer', 'input_analysis' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ExternalCheckEntry::class, 'run_id')->orderBy('position');
    }
}
