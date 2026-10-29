{{--
    Mobile camera. On a phone: the picture field below opens its camera. On a computer: a QR code (and its link)
    opens the camera on any phone; the photo taken there arrives here by itself.
--}}
@php($token = $this->data['capture_token'] ?? null)
@php($received = filled($this->data['capture_file_id'] ?? null))
<div class="rounded-lg border border-gray-200 p-4 text-sm dark:border-white/10">
    @if ($received)
        <p class="font-medium text-success-600">Photo received from the phone. Save the page to read it.</p>
    @elseif ($token)
        <div class="flex flex-wrap items-start gap-4" wire:poll.3s="checkCapture">
            <div class="rounded bg-white p-2">{!! \App\Domain\Accounts\RoznamchaCapture::qr($token) !!}</div>
            <div class="max-w-md space-y-2">
                <p class="font-medium">Scan this with the phone's camera, take the photo and send it.</p>
                <p class="text-gray-500">Or open this link on the phone (it works for 30 minutes, for this page only):</p>
                <p class="break-all font-mono text-xs"><a class="text-primary-600 underline" href="{{ \App\Domain\Accounts\RoznamchaCapture::url($token) }}" target="_blank">{{ \App\Domain\Accounts\RoznamchaCapture::url($token) }}</a></p>
                <p class="text-gray-500">Waiting for the photo…</p>
            </div>
        </div>
    @else
        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="button" icon="heroicon-o-qr-code" wire:click="startCapture">Use a phone's camera</x-filament::button>
            <span class="text-gray-500">Shows a QR code: scan it with a phone, take the photo, and it comes here. On a phone, use the picture field below instead.</span>
        </div>
    @endif
</div>
