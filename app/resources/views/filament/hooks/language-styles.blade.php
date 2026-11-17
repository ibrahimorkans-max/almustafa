{{-- Urdu, Pashto and Sindhi: right to left (Filament turns the layout round), in a font made for their script.
     Fonts on the PC or phone are used (no font is fetched from the internet). --}}
<style>
    html[lang="ur"] body, html[lang="ur"] input, html[lang="ur"] select, html[lang="ur"] textarea, html[lang="ur"] button {
        font-family: 'Noto Nastaliq Urdu', 'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Naskh Arabic', 'Segoe UI', Tahoma, sans-serif;
    }
    html[lang="ps"] body, html[lang="ps"] input, html[lang="ps"] select, html[lang="ps"] textarea, html[lang="ps"] button,
    html[lang="sd"] body, html[lang="sd"] input, html[lang="sd"] select, html[lang="sd"] textarea, html[lang="sd"] button {
        font-family: 'Noto Naskh Arabic', 'Noto Sans Arabic', 'Segoe UI', Tahoma, 'Arial', sans-serif;
    }
    /* Nastaliq is tall: a little more room per line so words are not cut. */
    html[lang="ur"] body { line-height: 1.9; }
    html[dir="rtl"] body { font-size: 1.02rem; }
    /* Numbers, codes and vehicle numbers stay left to right inside right-to-left text. */
    html[dir="rtl"] input[type="number"], html[dir="rtl"] .fi-ta-text-item-label, html[dir="rtl"] code { unicode-bidi: plaintext; }
</style>
