<?php

namespace App\Domain\Airports\Policies;

use App\Models\User;
use App\Domain\Airports\Models\Airport;

class AirportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('airports.view');
    }

    public function view(User $user, Airport $airport): bool
    {
        return $user->can('airports.view');
    }

    public function create(User $user): bool
    {
        return $user->can('airports.create');
    }

    public function update(User $user, Airport $airport): bool
    {
        return $user->can('airports.update');
    }

    public function delete(User $user, Airport $airport): bool
    {
        return $user->can('airports.delete');
    }
}
