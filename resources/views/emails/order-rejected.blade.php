<h2>{{ __('emails.order_rejected.greeting', ['name' => $user['username'] ? $user['username'] : 'User']) }}</h2>
<br>
{{ __('emails.order_rejected.body') }}
<br><br>
{{ __('emails.order_rejected.order_label') }} #{{ $order['id'] }}
<br>
{{ __('emails.order_rejected.total_label') }} {{ number_format((float) $order['total_price'], 2) }}
<br><br>
{{ __('emails.order_rejected.refund') }}
<br><br>
{{ __('emails.order_rejected.closing') }}
