<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Files — One folder tree for your whole team</title>
        <meta name="description" content="Every file your team owns, in one recursive folder tree. Upload, browse, search, rename, and download — through a single fast, team-scoped view.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            :root {
                --paper: #fbf7ee;
                --paper-soft: #f3ecd9;
                --ink: #2b2a25;
                --ink-soft: #58564c;
                --ink-faint: #8a8778;
                --gold: #d9a018;
                --gold-deep: #a97710;
                --mint: #3f9c79;
                --mint-deep: #2d7a5d;
                --line: rgba(43, 42, 37, 0.12);
                --font-display: 'Fraunces', Georgia, serif;
                --font-body: 'Work Sans', system-ui, sans-serif;
                --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(43, 42, 37, 0.03); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header id="site-header" class="fixed top-0 left-0 right-0 z-50 border-b border-transparent transition-colors duration-300">
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center gap-2.5 press">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Files" class="h-16 sm:h-20 w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="#capabilities" class="link-underline hover:text-[var(--ink)] pb-0.5">Product</a>
                    <a href="#features" class="link-underline hover:text-[var(--ink)] pb-0.5">Features</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/files') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--mint)] hover:bg-[var(--mint-deep)] text-white text-sm font-display font-semibold rounded-lg transition-colors">
                                My Files
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--ink-soft)] hover:text-[var(--ink)] transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-deep)] text-[#2b2a25] text-sm font-display font-semibold rounded-lg transition-colors">
                                    Create account
                                </a>
                            @endif
                        @endauth

                        <button id="mobile-menu-toggle" class="md:hidden press p-2 -mr-2 text-[var(--ink)]" aria-label="Toggle menu" aria-expanded="false">
                            <svg id="icon-menu-open" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                            </svg>
                            <svg id="icon-menu-close" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div id="mobile-menu" class="hidden md:hidden border-t border-[var(--line)] bg-[#fbf7ee]">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#capabilities" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Product</a>
                    <a href="#features" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Features</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative min-h-[100dvh] flex items-end overflow-hidden bg-[var(--paper)]">
            <!-- Photo: rows of grey filing cabinet drawers, by Maksym Kaharlytskyi, unsplash.com/photos/file-cabinet-Q9y3LRuuxmg -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1569235186275-626cb53b83ce?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(251,247,238,0.5) 0%, rgba(251,247,238,0.8) 45%, #fbf7ee 94%);"></div>
            <div class="absolute inset-0" style="background: linear-gradient(90deg, #fbf7ee 0%, rgba(251,247,238,0.68) 40%, transparent 72%);"></div>

            <!-- Signature element: line-art open folder + document, echoing the real logo's folder icon -->
            <svg class="hidden lg:block absolute right-[4%] top-[14%] h-[70%] w-auto opacity-[0.09] pointer-events-none" viewBox="0 0 260 260" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M40 210V70a10 10 0 0 1 10-10h44l18 20h88a10 10 0 0 1 10 10v20" stroke="#2b2a25" stroke-width="3" stroke-linejoin="round"/>
                <path d="M40 210l24-98a10 10 0 0 1 9.7-7.5H210a10 10 0 0 1 9.7 12.5l-22 93a10 10 0 0 1-9.7 7.5H50a10 10 0 0 1-10-7.5Z" stroke="#2b2a25" stroke-width="3" stroke-linejoin="round"/>
                <path d="M140 40h60a8 8 0 0 1 8 8v56M180 100v-40M208 84l-14 16-14-16" stroke="#2b2a25" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
            </svg>

            <div class="relative z-10 max-w-[1400px] mx-auto px-5 sm:px-8 pt-32 pb-16 sm:pb-20 w-full">
                <div class="max-w-2xl reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--mint-deep)] mb-6">
                        Team file storage &amp; management
                    </p>

                    <h1 class="font-display font-semibold text-4xl sm:text-5xl lg:text-6xl leading-[1.05] tracking-tight text-[var(--ink)] mb-6">
                        Open the folder.<br>Find the file.
                    </h1>

                    <p class="text-lg text-[var(--ink-soft)] leading-relaxed max-w-xl mb-10">
                        Every team gets one recursive folder tree the moment it's created. Upload, browse, search, rename, and download — through a single fast view, scoped to the people who are actually on your team.
                    </p>

                    @guest
                        <div class="flex flex-wrap items-center gap-4">
                            <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-deep)] text-[#2b2a25] font-display font-semibold rounded-lg transition-colors">
                                Create account
                            </a>
                            <a href="#features" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--ink)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--ink-faint)] transition-colors">
                                See what it does
                            </a>
                        </div>
                    @endguest
                </div>
            </div>

            <!-- Capability strip -->
            <div class="relative z-10 w-full border-t border-[var(--line)] bg-[var(--paper-soft)]">
                <div class="max-w-[1400px] mx-auto px-5 sm:px-8 py-4 flex flex-wrap gap-x-8 gap-y-2 font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--ink-soft)]">
                    <span>Recursive folders</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>Full-text search</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>Drag &amp; drop upload</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>Policy-gated downloads</span>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section id="features" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--mint-deep)] mb-4">What it does</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight">
                        Everything the file browser handles, in one place
                    </h2>
                </div>

                <div class="grid md:grid-cols-2 border-t border-[var(--line)]">
                    @php
                        $features = [
                            ['tag' => 'Browse', 'title' => 'One tree per team', 'body' => 'Files and folders both hang off a single recursive tree. Every team gets its own root folder automatically, the moment the team is created.'],
                            ['tag' => 'Upload', 'title' => 'Drag-and-drop upload', 'body' => 'A FilePond-driven widget streams uploads straight in, with live progress, no page reloads — the file browser updates in place.'],
                            ['tag' => 'Search', 'title' => 'Full-text search', 'body' => 'Search by file name or folder path, powered by Laravel Scout, filtered to your team so you only ever see what your team owns.'],
                            ['tag' => 'Organize', 'title' => 'Rename &amp; folder structure', 'body' => 'Create folders, rename files and folders in place, and navigate breadcrumbs back up the tree exactly the way you left it.'],
                            ['tag' => 'Download', 'title' => 'Policy-gated downloads', 'body' => 'Every download is checked against team membership before a single byte is returned — no file ID gives access on its own.'],
                            ['tag' => 'Access', 'title' => 'One sign-on for the ecosystem', 'body' => 'A one-time token handoff from the Dot hub logs you straight into your files — no separate password to keep track of.'],
                        ];
                    @endphp
                    @foreach ($features as $i => $f)
                        <div class="row-hover border-b border-[var(--line)] {{ $i % 2 === 0 ? 'md:border-r' : '' }} px-1 py-8 sm:py-10 transition-colors reveal" data-reveal>
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--gold-deep)] mb-3">{{ $f['tag'] }}</p>
                            <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-2.5">{!! $f['title'] !!}</h3>
                            <p class="text-[var(--ink-soft)] leading-relaxed max-w-md">{!! $f['body'] !!}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Capabilities -->
        <section id="capabilities" class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--paper-soft)] border-y border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--mint-deep)] mb-4">Built for the team</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-5">
                            One shared tree, scoped to who's actually on the team
                        </h2>
                        <p class="text-[var(--ink-soft)] leading-relaxed max-w-sm">
                            Sign in through the ecosystem, land straight in your team's folder tree. Nothing to install, nothing to configure.
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-x-10">
                        @php
                            $capabilities = [
                                ['title' => 'Team-scoped by default', 'body' => 'Files, folders, and every query on them are scoped to your current team automatically — no accidental cross-team reads.'],
                                ['title' => 'Single-view file browser', 'body' => 'One Livewire component drives browsing, search, upload, rename, and delete — no page reloads between actions.'],
                                ['title' => 'Shared ecosystem database', 'body' => 'Runs on the same PostgreSQL instance as the rest of the Dot ecosystem, with its own team-scoped tables.'],
                                ['title' => 'Ecosystem single sign-on', 'body' => 'A one-time, expiring token handoff from the Dot hub authenticates you — no separate Dot.Files password.'],
                                ['title' => 'Straightforward by design', 'body' => 'No previews, no version history, no per-file sharing yet — a deliberately thin, fast file tree, not a full document suite.'],
                                ['title' => 'Download, gated every time', 'body' => 'Every file download passes a policy check against your team membership before any bytes are served.'],
                            ];
                        @endphp
                        @foreach ($capabilities as $c)
                            <div class="py-6 border-t border-[var(--line)] reveal" data-reveal>
                                <h3 class="font-display font-medium text-base text-[var(--ink)] mb-1.5">{{ $c['title'] }}</h3>
                                <p class="text-sm text-[var(--ink-soft)] leading-relaxed">{{ $c['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden bg-[var(--ink)]">
            <!-- Photo: red file folders neatly arranged on a shelf, by Zulfugar Karimov, unsplash.com/photos/red-file-folders-are-neatly-arranged-on-a-shelf-SiJt15u6Yw4 -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1750935578389-6e1445f5fd8d?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, #2b2a25 0%, rgba(43,42,37,0.86) 50%, #2b2a25 100%);"></div>

            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight mb-5">
                    Your team's files belong in one place, not six
                </h2>
                <p class="text-[#c9c6ba] leading-relaxed mb-10 max-w-lg mx-auto">
                    Create your team's folder tree in a moment. No hardware, no migration project, no separate password to remember.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-deep)] text-[#2b2a25] font-display font-semibold rounded-lg transition-colors">
                            Create account
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--paper)] font-medium rounded-lg border border-[rgba(251,247,238,0.18)] hover:border-[rgba(251,247,238,0.4)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)] bg-[var(--paper)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center gap-2.5">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Files" class="h-11 w-auto opacity-90">
                </a>
                <p class="font-mono text-xs tracking-wide text-[var(--ink-soft)]">
                    &copy; {{ date('Y') }} Dot.Files. Team file storage and management.
                </p>
            </div>
        </footer>

        <script>
            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }

            // Header scroll state (no framework — this guest page loads before Livewire/Alpine boot)
            const header = document.getElementById('site-header');
            const setScrolled = () => {
                const scrolled = window.pageYOffset > 24;
                header.classList.toggle('bg-[#fbf7ee]/95', scrolled);
                header.classList.toggle('backdrop-blur-md', scrolled);
                header.classList.toggle('border-b', true);
                header.classList.toggle('border-[var(--line)]', scrolled);
                header.classList.toggle('border-transparent', !scrolled);
            };
            setScrolled();
            window.addEventListener('scroll', setScrolled, { passive: true });

            // Mobile menu toggle
            const menuToggle = document.getElementById('mobile-menu-toggle');
            const menu = document.getElementById('mobile-menu');
            const iconOpen = document.getElementById('icon-menu-open');
            const iconClose = document.getElementById('icon-menu-close');
            if (menuToggle && menu) {
                menuToggle.addEventListener('click', () => {
                    const isOpen = !menu.classList.contains('hidden');
                    menu.classList.toggle('hidden', isOpen);
                    iconOpen.classList.toggle('hidden', !isOpen);
                    iconClose.classList.toggle('hidden', isOpen);
                    menuToggle.setAttribute('aria-expanded', String(!isOpen));
                });
            }
        </script>
    </body>
</html>
