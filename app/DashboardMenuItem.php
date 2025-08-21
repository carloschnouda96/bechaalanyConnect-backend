<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract; use Astrotomic\Translatable\Translatable;

class DashboardMenuItem extends Model  implements TranslatableContract
{
	use Translatable;

    protected $table = 'dashboard_menu_items';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["title"];

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('dashboard_menu_items.cms_draft_flag', '!=', 1);});}

    /* Start custom functions */

    public $appends = ['full_path'];

    public function getFullPathAttribute()
    {
        $icon = Storage::url($this->icon);
        return [
            'icon' => $icon,
        ];
    }

    /* End custom functions */
}
