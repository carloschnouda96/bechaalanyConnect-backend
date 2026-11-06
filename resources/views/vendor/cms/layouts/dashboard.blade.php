{{--
    Published copy of the vendor layout. Keep every id and class main.js depends on:
    #burger, #content-overlay, .user-info, .toast, .menu-dropdown-wrapper, #dashboard-content.

    Differences from the vendor:
    - white-text logo (hellotree.logo_sidebar) linking to Home;
    - the section holding the current page starts open, with that page marked. A page is
      matched as `route` or `route/*`, because the vendor's `route*` also marks Products
      while on Products Variations;
    - Profile and Logout are reachable below lg, where the vendor hid them with no other
      way to log out;
    - a flashed `error` is shown. The vendor opened the toast for it but printed only
      `success`, so a refused action showed an empty green box.
--}}
@extends('cms::layouts/main')

@section('main-content')

@php
    $cmsPrefix = config('hellotree.cms_route_prefix');
    $isCurrentPage = fn ($route) => request()->is($cmsPrefix . '/' . $route) || request()->is($cmsPrefix . '/' . $route . '/*');
@endphp

<nav class="main">
	<div class="header">
		<a class="bc-brand" href="{{ route('admin-home') }}" title="Home">
			<img src="{{ url(config('hellotree.logo_sidebar')) }}" alt="{{ config('hellotree.home_title') }}">
		</a>
		<ul class="p-0">
			<li class="position-relative {{ Route::currentRouteName() == 'admin-home' ? 'active' : '' }}">
				<a class="d-block" href="{{ route('admin-home') }}" title="Home">
					<i class="text-center mr-2 fa fa-home" aria-hidden="true"></i>
					Home
				</a>
			</li>
			@foreach(request()->get('admin')['cms_pages_grouped'] as $group)
				@if (!$group['icon'] && !$group['title'])
					@foreach($group['pages'] as $page)
						@if (!$page['display_name_plural']) @continue @endif
						<li class="position-relative {{ $isCurrentPage($page['route']) ? 'active' : '' }}">
							<a class="d-block" href="{{ url($cmsPrefix . '/' . $page['route']) }}" title="{{ $page['display_name_plural'] }}">
								<i class="text-center mr-2 fa {{ $page['icon'] }}" aria-hidden="true"></i>
								{{ $page['display_name_plural'] }}
							</a>
						</li>
					@endforeach
				@else
					@php
						$groupIsCurrent = collect($group['pages'])->contains(fn ($p) => $isCurrentPage($p['route']));
					@endphp
					<li class="position-relative menu-dropdown-wrapper {{ $groupIsCurrent ? 'active open' : '' }}">
						<a class="d-block" role="button" tabindex="0" aria-expanded="{{ $groupIsCurrent ? 'true' : 'false' }}" title="{{ $group['title'] }}">
							<i class="text-center mr-2 fa {{ $group['icon'] }}" aria-hidden="true"></i>
							{{ $group['title'] }}
							<i class="fa fa-caret-down" aria-hidden="true"></i>
						</a>
						<div class="menu-dropdown" @if ($groupIsCurrent) style="display: block" @endif>
							@foreach($group['pages'] as $page)
								@if (!$page['display_name_plural']) @continue @endif
								<a class="{{ $isCurrentPage($page['route']) ? 'active' : '' }}" href="{{ url($cmsPrefix . '/' . $page['route']) }}" title="{{ $page['display_name_plural'] }}">{{ $page['display_name_plural'] }}</a>
							@endforeach
						</div>
					</li>
				@endif
			@endforeach
		</ul>
	</div>
</nav>

<div id="content">
    <div class="position-relative">
        <div class="header-bg"></div>
        <header class="position-relative py-5 px-3 px-sm-5">
            <div class="row align-items-center">
                <div class="col-7 col-lg-6">
                    @yield('breadcrumb')
                </div>
                <div class="col-5 col-lg-6">
                    <div class="bc-header-actions">
                        <div class="user-info">
                            <span class="bc-user-name mr-2 d-none d-lg-inline">{{ request()->get('admin')['name'] }}</span>
                            <img src="{{ request()->get('admin')['image'] ? Storage::url(request()->get('admin')['image']) : url('asset?path=images/default.png') }}" alt="{{ request()->get('admin')['name'] }}">
                            <ul class="list-group text-left">
                                <li class="list-group-item">
                                    <a href="{{ route('admin-profile') }}">
                                        <i class="fa fa-user mr-2" aria-hidden="true"></i>
                                        My Profile
                                    </a>
                                </li>
                                <li class="list-group-item">
                                    <a href="{{ route('admin-logout') }}">
                                        <i class="fa fa-sign-out mr-2" aria-hidden="true"></i>
                                        Logout
                                    </a>
                                </li>
                            </ul>
                        </div>
                        <div id="burger" class="d-inline-block d-lg-none">
                            <label></label>
                            <label></label>
                            <label></label>
                        </div>
                    </div>
                </div>
            </div>
            @if (Session::has('success'))
                <div class="toast text-center px-5 py-3">
                    {{ Session::get('success') }}
                </div>
            @elseif (Session::has('error'))
                <div class="toast bc-toast-error text-center px-5 py-3">
                    {{ Session::get('error') }}
                </div>
            @endif
            <div class="toast text-center error transition px-5 py-3">
                <i class="fa fa-times" aria-hidden="true"></i>
                <ul class="list-unstyled text-center m-0"></ul>
            </div>
        </header>

        <div id="dashboard-content">
            @yield('dashboard-content')
        </div>

        <div id="content-overlay"></div>

        @include('cms::components/footer')

    </div>
</div>

@endsection
