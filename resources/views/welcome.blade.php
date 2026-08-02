<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1" />
    <title>Dot.Files</title>
    <meta content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, shrink-to-fit=no' name='viewport' />
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@200;400;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/latest/css/font-awesome.min.css" />
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Montserrat', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: #fff;
        }

        /* ── Hero ─────────────────────────────────────── */
        .hero {
            flex: 1;
            position: relative;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .hero-bg {
            position: absolute;
            inset: 0;
            background-image: url('img/header2.jpg');
            background-size: cover;
            background-position: center;
            z-index: 0;
        }

        /* dark overlay to keep text readable */
        .hero-bg::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.52);
        }

        /* ── Navigation ───────────────────────────────── */
        .navbar {
            position: relative;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.125rem 2rem;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            text-decoration: none;
        }

        .navbar-brand img {
            height: 36px;
            width: auto;
        }

        .navbar-brand span {
            font-size: 1.15rem;
            font-weight: 700;
            color: #f1c62d;
            letter-spacing: 0.03em;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* ── Buttons ──────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 1.4rem;
            border-radius: 50px;
            font-family: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 2px solid transparent;
            transition: background 0.2s, color 0.2s, border-color 0.2s, transform 0.15s;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-primary {
            background: #f1c62d;
            color: #1a1a1a;
            border-color: #f1c62d;
        }
        .btn-primary:hover { background: #e0b620; border-color: #e0b620; }

        .btn-outline {
            background: transparent;
            color: #fff;
            border-color: rgba(255,255,255,0.6);
        }
        .btn-outline:hover { background: rgba(255,255,255,0.12); border-color: #fff; }

        /* ── Hero content ────────────────────────────── */
        .hero-body {
            flex: 1;
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 4rem 1.5rem 3rem;
        }

        .hero-logo {
            width: 110px;
            height: 110px;
            object-fit: contain;
            margin-bottom: 1.75rem;
            filter: drop-shadow(0 4px 24px rgba(0,0,0,0.4));
        }

        .hero-tagline {
            display: inline-block;
            background: #f1c62d;
            color: #1a1a1a;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            padding: 0.3rem 1rem;
            border-radius: 50px;
            margin-bottom: 1.25rem;
        }

        .hero-title {
            font-size: clamp(2.2rem, 5vw, 3.8rem);
            font-weight: 700;
            line-height: 1.15;
            margin-bottom: 1rem;
            letter-spacing: -0.01em;
        }

        .hero-title span { color: #f1c62d; }

        .hero-subtitle {
            font-size: clamp(0.95rem, 2vw, 1.15rem);
            font-weight: 300;
            color: rgba(255,255,255,0.82);
            max-width: 480px;
            line-height: 1.65;
            margin-bottom: 2.5rem;
        }

        .hero-cta {
            display: flex;
            gap: 0.9rem;
            flex-wrap: wrap;
            justify-content: center;
            margin-bottom: 3.5rem;
        }

        /* ── Feature pills ───────────────────────────── */
        .features {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .feature-pill {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(6px);
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: 50px;
            padding: 0.45rem 1rem;
            font-size: 0.8rem;
            font-weight: 500;
            color: rgba(255,255,255,0.9);
        }

        .feature-pill i {
            color: #f1c62d;
            font-size: 0.85rem;
        }

        /* ── Footer ───────────────────────────────────── */
        footer {
            position: relative;
            z-index: 10;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            padding: 1.1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        footer nav {
            display: flex;
            gap: 1.5rem;
        }

        footer nav a {
            font-size: 0.78rem;
            color: rgba(255,255,255,0.55);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        footer nav a:hover { color: #f1c62d; }

        .footer-copy {
            font-size: 0.78rem;
            color: rgba(255,255,255,0.45);
        }

        .footer-copy a {
            color: #f1c62d;
            text-decoration: none;
        }

        @media (max-width: 520px) {
            .navbar { padding: 1rem; }
            footer { justify-content: center; text-align: center; }
            footer nav { justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="hero">
        <div class="hero-bg"></div>

        <!-- Navigation -->
        <nav class="navbar">
            <a href="/" class="navbar-brand">
                <img src="{{ asset('images/logo.png') }}" alt="Dot.Files logo">
                <span>Dot.Files</span>
            </a>
            <div class="nav-actions">
                @if (Route::has('login'))
                    @auth
                        <a href="{{ url('/files') }}" class="btn btn-primary">
                            <i class="fa fa-folder-open"></i> My Files
                        </a>
                    @else
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn btn-outline">Create Account</a>
                        @endif
                        <a href="{{ route('login') }}" class="btn btn-primary">
                            <i class="fa fa-sign-in"></i> Sign In
                        </a>
                    @endauth
                @endif
            </div>
        </nav>

        <!-- Hero Body -->
        <div class="hero-body">
            <img src="{{ asset('images/logo.png') }}" alt="Dot.Files" class="hero-logo">
            <span class="hero-tagline">Secure Cloud File Management</span>
            <h1 class="hero-title">Your files,<br><span>always within reach.</span></h1>
            <p class="hero-subtitle">
                Upload, organise, and share your documents with ease.
                Dot.Files keeps everything in one secure place, accessible from anywhere.
            </p>

            <div class="hero-cta">
                @if (Route::has('login'))
                    @auth
                        <a href="{{ url('/files') }}" class="btn btn-primary">
                            <i class="fa fa-folder-open"></i> Go to My Files
                        </a>
                    @else
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn btn-primary">
                                <i class="fa fa-user-plus"></i> Get Started — It's Free
                            </a>
                        @endif
                        <a href="{{ route('login') }}" class="btn btn-outline">
                            <i class="fa fa-sign-in"></i> Sign In
                        </a>
                    @endauth
                @endif
            </div>

            <div class="features">
                <div class="feature-pill"><i class="fa fa-lock"></i> End-to-end security</div>
                <div class="feature-pill"><i class="fa fa-upload"></i> Easy uploads</div>
                <div class="feature-pill"><i class="fa fa-folder"></i> Folder organisation</div>
                <div class="feature-pill"><i class="fa fa-users"></i> Team sharing</div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <nav>
            <a href="https://www.files.infodot.co.za/about">About</a>
            <a href="https://www.files.infodot.co.za/terms">Terms &amp; Conditions</a>
        </nav>
        <p class="footer-copy">
            Dot.Files is a product of <a href="https://www.infodot.co.za" target="_blank">InfoDot</a>.
        </p>
    </footer>
</body>
</html>
