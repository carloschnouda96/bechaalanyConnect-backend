<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CMS route prefix
    |--------------------------------------------------------------------------
    */

    'cms_route_prefix' => 'admin',


    /*
    |--------------------------------------------------------------------------
    | API route prefix
    |--------------------------------------------------------------------------
    */

    'api_route_prefix' => 'api',


    /*
    |--------------------------------------------------------------------------
    | CMS assets
    |--------------------------------------------------------------------------
    */

    'cms_assets' => [

    	/*
	    |--------------------------------------------------------------------------
	    | Used styles. You can add or remove any
	    |--------------------------------------------------------------------------
	    */

	    'styles' => [
	    	'asset?path=css/font-awesome/css/font-awesome.min.css',
	    	'asset?path=css/bootstrap.min.css',
	    	'asset?path=css/dataTables.min.css',
	    	'asset?path=css/select2.min.css',
	    	'asset?path=css/main.min.css',
	    	// Bechaalany Connect theme. Stays last so it wins over the vendor stylesheet.
	    	'cms-assets/css/brand.css',
	    ],


    	/*
	    |--------------------------------------------------------------------------
	    | Used scripts. You can add or remove any
	    |--------------------------------------------------------------------------
	    */

	    'scripts' => [
	    	'asset?path=js/jquery.min.js',
	    	'asset?path=js/jquery-ui.min.js',
	    	'asset?path=js/dataTables.min.js',
	    	'asset?path=js/dataTables.buttons.min.js',
	    	'asset?path=js/jszip.min.js',
	    	'asset?path=js/pdfmake.min.js',
	    	'asset?path=js/vfs_fonts.js',
	    	'asset?path=js/buttons.html5.min.js',
	    	'asset?path=js/select2.min.js',
	    	// CKEditor 4.22.1 from the ckeditor4 npm package, plugins for third-party services removed.
	    	'cms-assets/ckeditor/ckeditor.js',
	    	'cms-assets/js/main.js',
	    ],
    ],


    /*
    |--------------------------------------------------------------------------
    | CMS branding
    |--------------------------------------------------------------------------
    */

    'favicon' => [
        'apple-icon-57x57' => 'cms-assets/favicon/apple-icon-57x57.png',
        'apple-icon-60x60' => 'cms-assets/favicon/apple-icon-60x60.png',
        'apple-icon-72x72' => 'cms-assets/favicon/apple-icon-72x72.png',
        'apple-icon-76x76' => 'cms-assets/favicon/apple-icon-76x76.png',
        'apple-icon-114x114' => 'cms-assets/favicon/apple-icon-114x114.png',
        'apple-icon-120x120' => 'cms-assets/favicon/apple-icon-120x120.png',
        'apple-icon-144x144' => 'cms-assets/favicon/apple-icon-144x144.png',
        'apple-icon-152x152' => 'cms-assets/favicon/apple-icon-152x152.png',
        'apple-icon-180x180' => 'cms-assets/favicon/apple-icon-180x180.png',
        'android-icon-192x192' => 'cms-assets/favicon/android-icon-192x192.png',
        'favicon-32x32' => 'cms-assets/favicon/favicon-32x32.png',
        'favicon-96x96' => 'cms-assets/favicon/favicon-96x96.png',
        'favicon-16x16' => 'cms-assets/favicon/favicon-16x16.png',
        'manifest' => 'cms-assets/favicon/manifest.json',
        'msapplication-TileColor' => '#070707',
        'msapplication-TileImage' => 'cms-assets/favicon/ms-icon-144x144.png',
        'theme-color' => '#070707',
    ],
    'logo' => 'cms-assets/images/logo.png',
    // White-text version for the black sidebar (layouts/dashboard.blade.php).
    'logo_sidebar' => 'cms-assets/images/logo-light.png',
    'loading' => 'cms-assets/images/mark.png',
    'footer_slogan' => 'Bechaalany Connect Admin',
    'footer_copyright' => 'All rights reserved &copy; Bechaalany Connect',
    'tab_title' => 'Bechaalany Connect | Admin',
    'home_title' => 'Bechaalany Connect',
    'home_content' => '',


    /*
    |--------------------------------------------------------------------------
    | CKEditor
    |--------------------------------------------------------------------------
    */

    'ckeditor' => [
        'colors' => ['E73828', 'D63224', '070707', '2A2A2A', '8E8E8E', 'E0E0E0', 'F7F7F7', 'FFFFFF'],
    ],


    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    'use_original_name' => false,


    /*
    |--------------------------------------------------------------------------
    | Tinify
    |--------------------------------------------------------------------------
    */

    'tinify' => [
        'key' => null
    ],

];