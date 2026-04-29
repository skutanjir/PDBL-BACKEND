<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = ['name', 'description', 'max_members', 'created_by', 'avatar'];

    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        if (!$this->avatar) return null;
        $bucket = config('filesystems.disks.gcs.bucket', 'pdbl-app-storage');
        return "https://storage.googleapis.com/{$bucket}/{$this->avatar}";
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members()
    {
        return $this->belongsToMany(User::class)->withPivot('status')->withTimestamps();
    }

    public function todos()
    {
        return $this->hasMany(Todo::class);
    }
}