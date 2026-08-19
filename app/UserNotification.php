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

    // Below the marker so a hellotree CMS page-schema save cannot rewrite it away.

    protected $casts = [
        // The column is a real json type now; without this cast every read would
        // hand callers a raw string and every write would need manual encoding.
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    /*
     | A second relation named user() used to live here:
     |
     |     public function user() { return $this->belongsTo(User::class); }
     |
     | It was broken twice over. `User::class` resolves to App\User inside this
     | namespace while users() above points at App\Models\User, so the two relations
     | returned different classes for the same row. And with no foreign key argument
     | Eloquent derived `user_id`, while the actual column is `users_id` — so any use
     | of ->user or ->with('user') failed with "Unknown column
     | user_notifications.user_id". It is deleted rather than fixed: users() already
     | does the job correctly.
     */

    /**
     * Scope to get unread notifications
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope to get notifications by type.
     *
     * This threw "Unknown column 'type'" until the column was added — the scope had
     * been written against a column that did not exist.
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
