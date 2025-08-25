<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;



class UserNotification extends Model
{


    protected $table = 'user_notifications';

    protected $guarded = ['id'];



    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('user_notifications.cms_draft_flag', '!=', 1);
        });
    }
    public function users()
    {
        return $this->belongsTo('App\Models\User');
    }
    public function statuses()
    {
        return $this->belongsTo('App\Statuse');
    }

    /* Start custom functions */

    /**
     * Get the user that owns the notification
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to get unread notifications
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope to get notifications by type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead()
    {
        $this->read_at = now();
        $this->save();
    }

    /* End custom functions */
}
