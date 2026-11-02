{{-- Bunga jepun (kamboja): lima kelopak dengan pusat kuning. Dekoratif. --}}
<svg viewBox="-30 -30 60 60" xmlns="http://www.w3.org/2000/svg" focusable="false" class="{{ $class ?? '' }}">
    <defs>
        <radialGradient id="jepun-{{ $id ?? 'a' }}" cx="0" cy="0" r="26" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#f6c453"/>
            <stop offset=".35" stop-color="#fbe6b0"/>
            <stop offset="1" stop-color="#fffaf0"/>
        </radialGradient>
    </defs>
    <g fill="url(#jepun-{{ $id ?? 'a' }})" stroke="#e2c99c" stroke-width=".8">
        @foreach([0, 72, 144, 216, 288] as $angle)
        <path transform="rotate({{ $angle }})" d="M0 0 C -9 -6 -12 -19 -4 -26 C 2 -29 9 -25 8 -17 C 7 -10 3 -4 0 0 Z"/>
        @endforeach
    </g>
    <circle r="3.2" fill="#f2b33d"/>
</svg>
