@extends('layouts.kfos-app')

@section('title', 'K-FOS Development Preview')

@section('content')
    <h2 class="text-2xl font-bold">
        K-FOS Application Foundation
    </h2>

    <p class="mt-3 text-slate-600">
        Shared Blade layout and Tailwind CSS
        integration preview.
    </p>

    <div class="mt-6 rounded-lg border border-blue-200
                bg-blue-50 p-4 text-blue-900">
        Development-only interface.

        No authentication or business functionality
        is implemented on this page.
    </div>
@endsection
