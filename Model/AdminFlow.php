<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminFlow extends Model
{
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = true;
    protected $casts = [
        'plans_id' => 'json', // Automatically cast to JSON
    ];
    // Define the relationship to FlowLog
    public function flowLogs()
    {
        return $this->hasMany(AdminFlowLog::class, 'flow_id', 'id');
    }

    public function listAll($searchTerm)
    {
        return $this->where('deleted_at', null)
                    ->where(function ($query) use ($searchTerm) {
                        $query->where('name', 'like', '%' . $searchTerm . '%');
                    })
                    ->withCount('flowLogs')
                    ->latest()
                    ->paginate(10);

    }
}
