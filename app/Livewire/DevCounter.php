<?php

namespace App\Livewire;

use Livewire\Component;

class DevCounter extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function resetCounter(): void
    {
        $this->count = 0;
    }

    public function render()
    {
        return view('livewire.dev-counter')
            ->layout('layouts.dev-test');
    }
}