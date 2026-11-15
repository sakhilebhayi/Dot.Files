import forms from '@tailwindcss/forms'
import dotPreset from './dot-ui/tailwind/tailwind-preset.cjs'

/** @type {import('tailwindcss').Config} */
export default {
    presets: [dotPreset],
    content: [
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],
    theme: {
        extend: {},
    },
    plugins: [forms],
}
