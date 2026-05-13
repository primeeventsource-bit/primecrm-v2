<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Prime CRM') }}</title>

        {{-- Inline SVG favicon — orange Floor OS dot. Inlining as a data
             URI avoids the 404 we'd otherwise get for /favicon.ico
             (we don't have a binary favicon to ship). --}}
        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='6' fill='%230b0f17'/%3E%3Crect x='6' y='6' width='20' height='20' rx='3' fill='%23f5a524'/%3E%3C/svg%3E">

        {{-- Inter (sans) + JetBrains Mono (numbers / timestamps / codes) --}}
        <link rel="preconnect" href="https://rsms.me/">
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap">

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="h-full bg-deck-bg text-deck-text antialiased">
        @inertia
    </body>
</html>
