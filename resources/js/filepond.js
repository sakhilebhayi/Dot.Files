// FilePond is bundled with the app (npm, pinned by package-lock.json) instead of loaded from a CDN, so uploading works
// offline and under a strict Content-Security-Policy. The upload tray in livewire/file-browser.blade.php calls FilePond.create().
import * as FilePond from 'filepond'
import 'filepond/dist/filepond.min.css'

window.FilePond = FilePond
