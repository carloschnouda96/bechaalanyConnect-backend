<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract; use Astrotomic\Translatable\Translatable;

class DashboardPageSetting extends Model  implements TranslatableContract
{
	use Translatable;

    protected $table = 'dashboard_page_settings';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["homepage_button_label","logout_button","balance_label","total_purchases_label","received_amount_label","from_label","to_label","search_button","all_transfers_label","received_filter_label","purchased_filter_label","my_orders_page_title","all_payments_label","accepted_label","rejected_label","pending_label","refresh_order_button","my_payments_page_title","add_credits_page_title","account_settings_page_title","account_info_label"];

	protected static function booted(){static::addGlobalScope('cms_draft_flag', function (Builder $builder) {$builder->where('dashboard_page_settings.cms_draft_flag', '!=', 1);});}

    /* Start custom functions */



    /* End custom functions */
}