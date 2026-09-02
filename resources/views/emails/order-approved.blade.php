<h2>{{ __('emails.order_approved.greeting', ['name' => $user['username'] ? $user['username'] : 'User']) }}</h2>
<br>
{{ __('emails.order_approved.body') }}
<br><br>
{{ __('emails.order_approved.order_label') }} #{{ $order['id'] }}
<br>
{{ __('emails.order_approved.total_label') }} {{ number_format((float) $order['total_price'], 2) }}
<br><br>
{{-- Deliberately no code here. For a supplier order the code does not exist yet at
     the moment this is sent: FulfillSupplierOrderJob is dispatched from the same
     commit and writes orders.code afterwards. Promising one in the mail would be a
     lie for exactly the orders that take longest. --}}
{{ __('emails.order_approved.closing') }}
