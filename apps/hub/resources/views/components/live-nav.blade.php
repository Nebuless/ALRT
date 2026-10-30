@props(['active' => null, 'attention' => 0, 'variant' => 'dock'])
<div class="{{ $variant === 'sky' ? 'owner-navigation' : 'nav-dock' }}">
    <nav class="soft-block-nav" aria-label="Main navigation">
        <a href="{{ route('home') }}" @if($active === 'orbit') aria-current="page" @endif>Orbit</a>
        <a href="{{ route('citizens') }}" @if($active === 'citizens') aria-current="page" @endif>Citizens</a>
        <a href="{{ route('reporting') }}" @if($active === 'reporting') aria-current="page" @endif>Reporting</a>
    </nav>
</div>
