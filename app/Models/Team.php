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
        return asset('storage/' . $this->avatar);
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