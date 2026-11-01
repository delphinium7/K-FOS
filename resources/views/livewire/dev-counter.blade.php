
<div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="text-xl font-semibold text-slate-900">
        Livewire Counter Test
    </h2>

    <p class="mt-2 text-sm text-slate-600">
        Click Increment to test Livewire communication.
    </p>

    <div class="mt-6 flex items-center gap-4">
        <button
            type="button"
            wire:click="increment"
            class="rounded-lg bg-blue-700 px-4 py-2 font-medium text-white hover:bg-blue-800"
        >
            Increment
        </button>

        <span
            id="livewire-counter-value"
            class="text-2xl font-bold text-slate-900"
        >
            {{ $count }}
        </span>

        <button
            type="button"
            wire:click="resetCounter"
            class="rounded-lg border border-slate-300 px-4 py-2 font-medium text-slate-700 hover:bg-slate-50"
        >
            Reset
        </button>
    </div>
</div>
