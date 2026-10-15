<svg class="icon {{ $class ?? '' }}" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name ?? '')
    @case('dashboard')<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>@break
    @case('monitor')
    @case('assets')<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>@break
    @case('locations')<path d="M5 21V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v17M3 21h18M9 21v-5h6v5M9 7h1m4 0h1M9 11h1m4 0h1"/>@break
    @case('inventory')<path d="M9 4H6a2 2 0 0 0-2 2v14a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V6a2 2 0 0 0-2-2h-3"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="m8 13 3 3 5-6"/>@break
    @case('maintenance')<path d="M14.5 5.5a5 5 0 0 0-6 6L3 17a2.8 2.8 0 0 0 4 4l5.5-5.5a5 5 0 0 0 6-6L15 13l-4-4 3.5-3.5Z"/>@break
    @case('software')<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>@break
    @case('licenses')<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8Zm0 0v5h5M9 12h6M9 16h4"/>@break
    @case('labels')<path d="M7 8V3h10v5M7 17v4h10v-4"/><path d="M5 8h14a2 2 0 0 1 2 2v7h-4v-4H7v4H3v-7a2 2 0 0 1 2-2ZM17 11h1"/>@break
    @case('settings')<path d="M4 7h7m4 0h5M4 17h5m4 0h7"/><circle cx="13" cy="7" r="2"/><circle cx="11" cy="17" r="2"/>@break
    @case('search')<circle cx="10.5" cy="10.5" r="7"/><path d="m16 16 5 5"/>@break
    @case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
    @case('close')<path d="m6 6 12 12M18 6 6 18"/>@break
    @case('sun')<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.4 1.4m11.2 11.2L19 19M5 19l1.4-1.4M17.6 6.4 19 5"/>@break
    @case('moon')<path d="M20.7 14.2A9 9 0 0 1 9.8 3.3a9 9 0 1 0 10.9 10.9Z"/>@break
    @case('logout')<path d="M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4M14 7l5 5-5 5M8 12h11"/>@break
    @case('user')<circle cx="12" cy="7" r="4"/><path d="M4 22v-2a8 8 0 0 1 16 0v2"/>@break
    @case('check')<path d="m5 12 4 4L19 6"/>@break
    @case('warning')<path d="m12 3 10 18H2L12 3Z"/><path d="M12 9v5m0 3h.01"/>@break
    @case('info')<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10h.01"/>@break
    @case('arrow-right')<path d="M4 12h16m-6-6 6 6-6 6"/>@break
    @case('plus')<path d="M12 5v14M5 12h14"/>@break
    @case('qr')<rect x="3" y="3" width="6" height="6" rx=".5"/><rect x="15" y="3" width="6" height="6" rx=".5"/><rect x="3" y="15" width="6" height="6" rx=".5"/><path d="M15 15h3v3h3m-6 3v-3m6-3v-3m-9 0h3M12 3v5m0 10v3M3 12h6"/>@break
    @case('camera')<path d="M7 6 9 3h6l2 3h3a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="4"/>@break
    @case('flash')<path d="m13 2-9 12h7l-1 8 10-13h-7l1-7Z"/>@break
    @case('rack')<rect x="5" y="2" width="14" height="20" rx="1.5"/><path d="M8 6h8M8 10h8M8 14h8M8 18h1"/>@break
    @case('audio')<rect x="5" y="2" width="14" height="20" rx="2"/><circle cx="12" cy="7" r="2"/><circle cx="12" cy="16" r="4"/>@break
    @default<circle cx="12" cy="12" r="8"/>
@endswitch
</svg>
