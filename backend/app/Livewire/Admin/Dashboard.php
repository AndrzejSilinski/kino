<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pulpit panelu (Etap 7). W bloku B3 szkielet: potwierdza, że logowanie,
 * layout i Livewire działają razem. Metryki i feed dojdą w bloku L.
 *
 * authorize() w mount() mimo middleware can:panel.access na trasie:
 * sprawdzenie w samym komponencie nie zależy od tego, jak do niego trafiono.
 */
#[Title('Pulpit')]
final class Dashboard extends Component
{
    public function mount(): void
    {
        $this->authorize('panel.access');
    }

    public function render(): View
    {
        return view('livewire.admin.dashboard');
    }
}
