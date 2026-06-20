@if(usersettings('darkmode_setting') === 1 || (auth()->guest() && guestsettings('darkmode_setting') === 1))
    <link href="{{ Vite::asset('resources/assets/sass/app-dark.scss') }}" rel="stylesheet">
@elseif(usersettings('darkmode_setting') === 2 || (auth()->guest() && guestsettings('darkmode_setting') === 2))
    <link rel="stylesheet" media="(prefers-color-scheme: light)" href="{{ Vite::asset('resources/assets/sass/app.scss') }}">
    <link rel="stylesheet" media="(prefers-color-scheme: dark)" href="{{ Vite::asset('resources/assets/sass/app-dark.scss') }}">
@else
    <link href="{{ Vite::asset('resources/assets/sass/app.scss') }}" rel="stylesheet">
@endif
