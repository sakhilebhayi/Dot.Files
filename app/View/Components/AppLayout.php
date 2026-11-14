<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * The page shell. Null is the ordinary document page every other screen uses. "desk" (R-51) opts
     * one page into the locked working viewport: bar, one region whose panes scroll, and a foot.
     */
    public function __construct(public ?string $shell = null) {}

    /**
     * Get the view / contents that represents the component.
     *
     * @return View
     */
    public function render()
    {
        return view('layouts.app');
    }
}
