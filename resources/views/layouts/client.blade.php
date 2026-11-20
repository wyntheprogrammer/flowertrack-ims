<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Gemma’s Flower Shop</title>
    @vite(['resources/css/storefront.css', 'resources/js/client/navigation.js'])
    @stack('page-assets')
</head>
<body id="top" class="@yield('body-class')">
    <a class="visually-hidden-focusable shop-skip" href="#main">Skip to content</a>
    @include('client.partials.navbar')
    <main id="main" tabindex="-1">
        @if ($errors->any())
            <p class="shop-width alert alert-danger" role="alert">{{ $errors->first() }}</p>
        @endif
        @yield('content')
    </main>
    @include('client.partials.footer')
</body>
</html>
