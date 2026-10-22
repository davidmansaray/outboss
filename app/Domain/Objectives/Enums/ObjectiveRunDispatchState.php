<?php

namespace App\Domain\Objectives\Enums;

enum ObjectiveRunDispatchState: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Consumed = 'consumed';
    case Cancelled = 'cancelled';
}
