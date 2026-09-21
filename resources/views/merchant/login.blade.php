<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in - Ledgerflow</title>
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; margin: 0; display: grid; place-items: center; min-height: 100vh; background: #f6f7f9; }
        form { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.12); width: min(22rem, 90vw); }
        h1 { font-size: 1.25rem; margin: 0 0 1.5rem; }
        label { display: block; margin-bottom: 1rem; }
        input { display: block; width: 100%; box-sizing: border-box; padding: .5rem; margin-top: .25rem; border: 1px solid #c8ccd4; border-radius: 4px; }
        button { width: 100%; padding: .6rem; border: 0; border-radius: 4px; background: #1f2937; color: #fff; font-size: 1rem; cursor: pointer; }
        ul { color: #b42318; padding-left: 1.1rem; margin: 0 0 1rem; }
    </style>
</head>
<body>
    <form method="POST" action="{{ route('login.attempt') }}" autocomplete="on">
        @csrf
        <h1>Sign in to Ledgerflow</h1>

        @if ($errors->any())
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <label>
            Email
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </label>

        <label>
            Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>

        <button type="submit">Sign in</button>
    </form>
</body>
</html>
