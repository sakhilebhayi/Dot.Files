<div align="center">

<img src="public/img/logo.png" alt="Dot.Files" width="200" />

<h1>Dot.Files</h1>

<p>Secure, team-based digital file storage and sharing — organise, collaborate, and access your documents anywhere.</p>

[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![Livewire](https://img.shields.io/badge/Livewire-3.x-4E56A6?style=flat-square)](https://livewire.laravel.com)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?style=flat-square&logo=postgresql&logoColor=white)](https://postgresql.org)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE)

</div>

---

## Overview

Dot.Files is the team file storage and management platform in the Dot ecosystem. Each team gets a recursive folder tree (`objects` table, adjacency-list model) that files and folders both hang off of; teams upload, organise, search, rename, and delete files and folders, and download individual files — with single sign-on from InfoDot.

**Status:** this is a working application — real models, migrations, a Livewire file browser, and the ecosystem SSO handoff all exist and are wired together — but the domain is intentionally small. There is no S3 integration, no file previews, no version history, no sharing/collaboration layer, and no real-time notifications yet, despite earlier drafts of this README describing them. See "Features" below for what's actually implemented versus not.

---

## Features

**Implemented:**
- Recursive folder hierarchy per team, backed by a self-referential `objects` table (`staudenmeir/laravel-adjacency-list`)
- Drag-and-drop file upload (FilePond) with progress, size-limited to 100MB and restricted to an explicit set of document/image/archive/media MIME types
- Create folder, rename file/folder, delete file/folder (all scoped to the current team via `Obj::forCurrentTeam()`)
- File download, gated by `FilePolicy::download` (team-membership check)
- Full-text search across files and folders via Laravel Scout (TNTSearch driver by default)
- Ecosystem SSO — authenticate from InfoDot via `/auth/ecosystem` with a single-use Sanctum token

**Not implemented (despite being modeled or referenced elsewhere):**
- File storage is local disk (`Storage::disk('local')`) everywhere in the codebase — the `.env.example` AWS keys are present but no S3/Flysystem-S3 code exists in `app/`
- No file previews (images, PDFs, or otherwise) — the file browser only renders a download link
- No version history or restore
- No granular per-file permissions (view/edit/share) — the only authorization check is team-membership on download
- No real-time collaboration or notifications — Reverb env vars are present in `.env.example` but nothing in `app/` broadcasts or listens on them

---

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 + PHP 8.4 |
| Frontend | Livewire 3 + Vite 6 + Tailwind CSS 3.4 (plus legacy Bootstrap/now-ui-kit assets still loaded on some pages) |
| Auth | Jetstream 5 + Sanctum (ecosystem SSO) |
| Database | PostgreSQL 16 (shared `infodot` instance — see `DB_DATABASE` in `.env.example`) |
| Storage | Local disk (`storage/app`) via Laravel's `local` filesystem disk — no S3 integration exists in this repo |
| Search | Laravel Scout (`SCOUT_DRIVER=tntsearch` by default) |
| WebSockets | Laravel Reverb — configured via env vars only, not wired to any broadcast event |

---

## Quick Start

\`\`\`bash
git clone https://github.com/sakhileb/Dot.Files.git && cd Dot.Files
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate && npm run dev & php artisan serve
\`\`\`

\`\`\`bash
bash bin/test.sh   # Run tests
\`\`\`

---

## Part of the Dot Ecosystem

Dot.Files connects to [InfoDot](https://github.com/sakhileb/InfoDot) — the central hub. Log in to InfoDot once and navigate here without re-authenticating via \`/auth/ecosystem\`.

---

MIT — © SK Digital / BluPin Incorporated
