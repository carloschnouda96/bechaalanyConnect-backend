<html>

    <body align={{ $requestedLocale == 'ar' ? 'right' : 'left' }}>
        <h2><b>{{ __('emails.order_request.hello_admin') }} </b></h2>
        <h4><b>{{ __('emails.order_request.received_new_order') }} </b></h4>
        <p><b>{{ __('emails.order_request.user_details') }}</b></p>
        <p><b>{{ __('emails.order_request.fields.full_name') }} </b>{{ $order->users->username }}</p>
        @if ($requestedLocale == 'ar')
            <p>{{ $order->users->email }} <b>{{ __('emails.order_request.fields.email') }} </b></p>
        @else
            <p><b>{{ __('emails.order_request.fields.email') }} </b>{{ $order->users->email }}</p>
        @endif
        <p><b>{{ __('emails.order_request.fields.phone') }} </b>{{ $order->users->phone_number }}</p>
        <br>
        <p><b>{{ __('emails.order_request.order_details') }}</b></p>
        <p><b>{{ __('emails.order_request.fields.order_id') }} </b>{{ $order->id }}</p>
        <p><b>{{ __('emails.order_request.fields.product_name') }} </b> <br>
            {{ $order->product_variation->product->name }} | {{ $order->product_variation->name }}</p>
        <p><b>{{ __('emails.order_request.fields.quantity') }} </b>{{ $order->quantity }}</p>
        @if ($requestedLocale == 'ar')
            <p><b>{{ __('emails.order_request.fields.price') }} </b>$ {{ $order->total_price }} </p>
        @else
            <p><b>{{ __('emails.order_request.fields.price') }} </b>{{ $order->total_price }} $</p>
        @endif
        <br>
        <br>
    </body>

</html>
