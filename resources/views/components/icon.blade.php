@props(['name' => 'home'])
<svg {{ $attributes->class(['ui-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
@case('wallet')<path d="M20 8V5a2 2 0 0 0-2-2L5 6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h15V8H5"/><path d="M20 12h-5v5h5M16 14.5h1"/>@break
@case('news')<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h3v4H8zM14 11h2M14 15h2M8 18h8"/>@break
@case('calendar')<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4M17 3v4M3 10h18M8 14h2M14 14h2M8 17h2"/>@break
@case('settings')<path d="M4 6h16M4 12h16M4 18h16"/><rect x="7" y="4" width="4" height="4" rx="1" fill="currentColor"/><rect x="14" y="10" width="4" height="4" rx="1" fill="currentColor"/><rect x="8" y="16" width="4" height="4" rx="1" fill="currentColor"/>@break
@case('history')<path d="M3 11a9 9 0 1 1 2 7M3 5v6h6M12 7v5l3 2"/>@break
@case('profile')<path d="M4 21h16M6 21V8l6-5 6 5v13M10 21v-6h4v6M9 10h6"/>@break
@default<path d="m3 10 9-7 9 7M5 9v11h5v-6h4v6h5V9"/>
@endswitch
</svg>
