<x-filament-panels::page>
    @if (! $this->millChosen())
        <x-filament::section>
            <p class="text-sm">The register is for one mill. <a class="text-primary-600 underline" href="{{ \App\Filament\Pages\SwitchContext::getUrl() }}">Choose the working entity first</a>.</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex items-end gap-1">
                    <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previousDay" label="Previous day" color="gray" />
                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-medium text-gray-500">Day</span>
                        <input type="date" wire:model.live="day" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    </label>
                    <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="nextDay" label="Next day" color="gray" />
                </div>
                <x-filament::tabs>
                    <x-filament::tabs.item :active="$side === 'inbound'" wire:click="$set('side', 'inbound')" icon="heroicon-o-arrow-down-tray">Vehicles in</x-filament::tabs.item>
                    <x-filament::tabs.item :active="$side === 'outbound'" wire:click="$set('side', 'outbound')" icon="heroicon-o-arrow-up-tray">Vehicles out</x-filament::tabs.item>
                    <x-filament::tabs.item :active="$side === 'all'" wire:click="$set('side', 'all')">Both</x-filament::tabs.item>
                </x-filament::tabs>
                <x-filament::button tag="a" :href="$this->dayPrintUrl()" target="_blank" icon="heroicon-o-printer">Print day register (all vehicles)</x-filament::button>
                <x-filament::button tag="a" :href="$this->allSlipsUrl()" target="_blank" color="gray" icon="heroicon-o-document-duplicate">Print all vehicle slips (full details)</x-filament::button>
                <x-filament::button wire:click="downloadExcel" color="gray" icon="heroicon-o-table-cells">Excel</x-filament::button>
            </div>
            <p class="mt-2 text-xs text-gray-500">Click a vehicle (or <b>Details</b>) to see everything about it — driver, licence, owner, every weighment, lab test, deductions, price working and timeline. <b>Print</b> on a row prints that one vehicle.</p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($this->summary() as $key => $t)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="text-xs font-medium text-gray-500">{{ $t['title'] }} · {{ $this->date()->format('d M Y') }}</div>
                        <div class="mt-1 flex flex-wrap gap-x-5 gap-y-1 text-sm">
                            <span><b>{{ $t['vehicles'] }}</b> vehicles</span>
                            <span><b>{{ $t['inside'] }}</b> still inside</span>
                            <span><b>{{ number_format($t['bags']) }}</b> bags</span>
                            <span>Net <b>{{ \App\Domain\Gate\VehicleRegister::kg($t['net_kg']) }}</b></span>
                            <span>{{ $key === 'inbound' ? 'Payable' : 'Billed' }} <b>{{ \App\Domain\Gate\VehicleRegister::maund($t['payable_kg']) }}</b> maund</span>
                            <span>{{ $key === 'inbound' ? 'To pay' : 'To receive' }} <b>{{ \App\Support\Values\Money::paise($t['amount_paise'])->format() }}</b></span>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{ $this->table }}

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">How each vehicle's weight and payable price are worked out</x-slot>
            <div class="grid gap-6 text-sm lg:grid-cols-2">
                <div>
                    <h3 class="mb-2 font-semibold">Weight</h3>
                    <ol class="list-decimal space-y-1 pl-5">
                        <li><b>Vehicle in (paddy):</b> the loaded truck is weighed on arrival — <b>gross</b>. A sample goes to the lab.</li>
                        <li>After unloading into the godown, the empty truck is weighed — <b>tare</b>.</li>
                        <li><b>Vehicle out (dispatch):</b> the other way round — empty truck first (<b>tare</b>), loaded truck before it leaves (<b>gross</b>).</li>
                        <li>Weights come straight from the weighbridge indicator (only a steady reading is accepted). A hand-typed weight needs a reason and a second person's approval, and is marked on the slip.</li>
                        <li>If a vehicle is weighed again, the newest weighment replaces the old one; both stay on record.</li>
                        <li><b>Net = gross − tare.</b></li>
                        <li><b>Deduction</b> (inbound only), from the lab decision with the deduction rules as they were that moment:
                            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                <li>bag weight: grams per bag × number of bags;</li>
                                <li>moisture / foreign matter over the free limit: net × % over the limit × the rule's factor;</li>
                                <li>above a reject limit the lot is rejected (nothing is bought).</li>
                            </ul>
                        </li>
                        <li><b>Payable weight = net − deduction.</b> Stock receives the full net (what is physically in the godown); the deduction only changes what is paid.</li>
                        <li>1 maund = 40 kg.</li>
                    </ol>
                </div>
                <div>
                    <h3 class="mb-2 font-semibold">Payable price</h3>
                    <ol class="list-decimal space-y-1 pl-5">
                        <li>The <b>rate per maund</b> is fixed in the sauda (deal) the vehicle comes against; the gate cannot change it.</li>
                        <li><b>Gross amount = payable kg ÷ 40 × rate per maund.</b></li>
                        <li><b>Quality rate cut</b>: for each full 1 % a lab figure is over its free limit, the rule's rupees per maund are cut — <b>rate cut = payable kg ÷ 40 × cut per maund</b>.</li>
                        <li><b>Net payable = gross − rate cut</b>, rounded to the rupee.</li>
                        <li>When the slip is issued the purchase bill is made from these frozen figures; income tax u/s 153 is withheld on it when it applies (filer / non-filer rate), and a broker's brokerage (kg ÷ 40 × brokerage per maund) is booked separately.</li>
                        <li><b>Vehicle out:</b> value = net kg ÷ 40 × the sauda rate; the sales invoice adds sales tax (and further tax for a buyer without STRN).</li>
                        <li>On this page: <b>Estimate</b> = before the bill exists; once the bill is made its own figures are shown (draft or posted). Hover over an amount to see the working.</li>
                    </ol>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
