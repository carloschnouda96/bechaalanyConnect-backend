<html>

    <body>
        <h2><b>Hello Admin: </b></h2>
        <h4><b>You Have Received A New Order : </b></h4>
        <p><b>User Details:</b></p>
        <p><b>Full Name: </b>{{ $order->users->username }}</p>
        <p><b>Email: </b>{{ $order->users->email }}</p>
        <p><b>Phone Number: </b>{{ $order->users->phone_number }}</p>
        <br>
        <p><b>Order Details:</b></p>
        <p><b>Order ID: </b>{{ $order->id }}</p>
        <p><b>Product Name: </b>{{ $order->product_variation->product->name }} {{ $order->product_variation->name }}</p>
        <p><b>Quantity: </b>{{ $order->quantity }}</p>
        <p><b>Price: </b>{{ $order->total_price }}$</p>
        <br>
        <br>
    </body>

</html>
