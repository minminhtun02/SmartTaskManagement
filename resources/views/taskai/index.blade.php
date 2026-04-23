@extends('layouts.taskai', ['title' => 'TaskAI'])

@section('content')
    <div id="app" class="app"></div>
@endsection

@push('scripts')
    <script>
        window.__TASKAI_API_BASE__ = @json(url('/api'));
    </script>
    <script src="{{ asset('js/taskai-app.js') }}" defer></script>
@endpush
