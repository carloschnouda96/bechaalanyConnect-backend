{{-- Published copy of the vendor login. The form's method, CSRF token and field names are unchanged. --}}
@extends('cms::layouts/main')

@section('main-content')

	<div id="login" class="w-100 h-100">
		<div class="card">
			<img src="{{ url(config('hellotree.logo')) }}" alt="{{ config('hellotree.home_title') }}">
			<h1>Welcome back</h1>
			<p class="bc-login-sub">Sign in to your admin account</p>
			@if ($errors->any())
				<div class="alert alert-danger">
					@foreach ($errors->all() as $error)
						<p class="m-0">{{ $error }}</p>
					@endforeach
				</div>
			@endif
			@if (Session::has('error'))
				<div class="alert alert-danger">
					<p class="m-0">{{ Session::get('error') }}</p>
				</div>
			@endif
			<form method="post">
				@csrf
				<div class="form-group">
					<label for="login-email">Email</label>
					<input id="login-email" class="form-control" type="email" name="email" value="{{ old('email') }}" autocomplete="username" autofocus required>
				</div>
				<div class="form-group">
					<label for="login-password">Password</label>
					<input id="login-password" class="form-control" type="password" name="password" autocomplete="current-password" required>
				</div>
				<button class="btn btn-primary mt-2">Sign in</button>
			</form>
		</div>
	</div>

	<div id="login-footer-wrapper">
		@include('cms::components/footer')
	</div>

@endsection
