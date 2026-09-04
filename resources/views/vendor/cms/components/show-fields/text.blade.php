{{--
	Published copy of cms::components/show-fields/text.

	One change from the package: the value goes through FieldDisplay::text() before
	strip_tags(). show-fields.blade.php routes every field without a dedicated branch
	here, including the json columns that App\Order, App\ProductsVariation and
	App\UserNotification cast to `array` — and strip_tags() is typed `string`, so View on
	any of those three rows was a 500.
--}}
<div class="mb-4">
	<label class="font-weight-bold">{{ $label }}</label>
	<div class="pl-3">
		<p class="m-0 pre-wrap">{{ strip_tags(\App\Services\Cms\FieldDisplay::text($text)) }}</p>
	</div>
	<hr>
</div>
