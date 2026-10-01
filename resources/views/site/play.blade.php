@php($rtl = app()->getLocale() === 'ar')
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $gameName }} - Wegas</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; background: #000; overflow: hidden; }
        .play-bar { position: fixed; top: 0; left: 0; right: 0; z-index: 10; height: calc(46px + env(safe-area-inset-top, 0px));
            padding: env(safe-area-inset-top, 0px) 12px 0; display: flex; align-items: center; justify-content: space-between; gap: 12px;
            background: linear-gradient(90deg, #0b0f17, #161b26); border-bottom: 1px solid #262d3b;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Arabic", Arial, sans-serif; }
        .play-name { color: #e8ecf1; font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
        .play-back { flex: 0 0 auto; color: #1a1205; background: linear-gradient(180deg, #f5c84c, #d9a21b); text-decoration: none;
            font-size: 13px; font-weight: 800; padding: 7px 14px; border-radius: 8px; }
        .play-back:active { transform: scale(0.97); }
        .play-frame { position: fixed; left: 0; right: 0; bottom: 0; top: calc(46px + env(safe-area-inset-top, 0px));
            width: 100%; height: calc(100% - 46px - env(safe-area-inset-top, 0px)); border: 0; background: #000; }
    </style>
</head>
<body>
    <div class="play-bar">
        <a class="play-back" href="{{ $backUrl }}">{{ $rtl ? '→' : '←' }} {{ __('site.game_back') }}</a>
        <span class="play-name">{{ $gameName }}</span>
    </div>
    {{-- Canlı casino (Evolution vb.) mobilde iframe'den kaçıyor: kendiliğinden üst sayfa yönlendirmesi engellenir,
         üye dokunarak (ev/çıkış ikonu) yine dönebilir. Slot/mini oyunlarda sandbox yok. --}}
    <iframe class="play-frame" src="{{ $gameUrl }}" allowfullscreen
        allow="autoplay; fullscreen; encrypted-media; clipboard-write; screen-wake-lock"
        @if ($isLive) sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-orientation-lock allow-pointer-lock allow-presentation allow-top-navigation-by-user-activation" @endif
    ></iframe>
</body>
</html>
